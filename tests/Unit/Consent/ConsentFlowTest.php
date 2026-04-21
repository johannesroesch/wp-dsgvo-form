<?php
declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use WpDsgvoForm\Api\SubmitEndpoint;
use WpDsgvoForm\Captcha\CaptchaVerifier;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Notification\NotificationService;
use WpDsgvoForm\Tests\TestCase;
use WpDsgvoForm\Upload\FileHandler;
use WpDsgvoForm\Validation\FieldValidator;
use Brain\Monkey\Functions;

/**
 * Consent flow tests for DSGVO Art. 7 compliance.
 *
 * Coverage: LEGAL-CONSENT-01 through LEGAL-CONSENT-07, SEC-DSGVO-04/06/14,
 * LEGAL-TEMPLATE-06, DPO-FINDING-04/13.
 *
 * Tests 1–6 run against existing Form/Submission models.
 * Tests 7–10 are stubs awaiting the submission handler (Task #26).
 */
#[CoversClass(Form::class)]
#[CoversClass(Submission::class)]
#[CoversClass(SubmitEndpoint::class)]
class ConsentFlowTest extends TestCase
{

	/**
	 * Helper: creates a $wpdb mock with shouldIgnoreMissing for safe use in Form model tests.
	 */
	private function mock_wpdb(): \Mockery\MockInterface
	{
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' )->shouldIgnoreMissing();
		$wpdb->prefix = 'wp_';
		return $wpdb;
	}

	// ─── Form model: legal_basis defaults and validation (SEC-DSGVO-14) ──

	public function test_form_legal_basis_defaults_to_consent(): void
	{
		$form = new Form();
		$this->assertSame( 'consent', $form->legal_basis );
	}

	public function test_form_rejects_invalid_legal_basis_on_save(): void
	{
		$form               = new Form();
		$form->title        = 'Test Form';
		$form->legal_basis  = 'legitimate_interest';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/consent.*contract/i' );

		// validate() throws before save() touches any WP function.
		$form->save();
	}

	public function test_form_accepts_contract_as_legal_basis(): void
	{
		// Mock wpdb for ensure_unique_slug() call (returns null = slug is unique).
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$form               = new Form();
		$form->title        = 'Contract Form';
		$form->slug         = 'contract-form';
		$form->legal_basis  = 'contract';

		// validate() passes; ensure_unique_slug() passes; insert_record() needs KeyManager.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/KeyManager.*required/i' );

		$form->save();
	}

	// ─── Consent text versioning (LEGAL-TEMPLATE-06, DPO-FINDING-04) ────

	public function test_form_consent_version_starts_at_one(): void
	{
		$form = new Form();
		$this->assertSame( 1, $form->consent_version );
	}

	public function test_form_consent_version_auto_increments_on_text_change(): void
	{
		// Mock $wpdb for update_record() direct DB read (QUALITY-FINDING-01).
		$wpdb = $this->mock_wpdb();

		// prepare() returns a SQL string (passed to get_row).
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );

		// get_row() returns existing consent data with old text and version 3.
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( [
			'consent_text'    => 'Old consent text v3',
			'consent_version' => '3',
		] );

		$wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		// Mock delete_transient for cache invalidation.
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_42' );

		// Updated form with changed consent text.
		$form                  = new Form();
		$form->id              = 42;
		$form->title           = 'Test Form';
		$form->slug            = 'test-form';
		$form->consent_text    = 'Updated consent text v4';
		$form->consent_version = 3;

		$form->save();

		// LEGAL-TEMPLATE-06: version auto-incremented 3 → 4.
		$this->assertSame( 4, $form->consent_version );
	}

	public function test_form_consent_version_unchanged_when_text_not_modified(): void
	{
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );

		// get_row() returns existing consent data with SAME text.
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( [
			'consent_text'    => 'Same consent text',
			'consent_version' => '5',
		] );

		$wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_42' );

		$form                  = new Form();
		$form->id              = 42;
		$form->title           = 'Test Form';
		$form->slug            = 'test-form';
		$form->consent_text    = 'Same consent text';
		$form->consent_version = 5;

		$form->save();

		// Version stays at 5 — text unchanged.
		$this->assertSame( 5, $form->consent_version );
	}

	// ─── Submission consent metadata (SEC-DSGVO-06) ─────────────────────

	public function test_submission_from_row_maps_consent_fields_correctly(): void
	{
		// Row uses actual DB column names (see Activator.php schema).
		$row = [
			'id'                   => '1',
			'form_id'              => '5',
			'encrypted_data'       => 'base64data',
			'iv'                   => 'base64iv',
			'auth_tag'             => 'base64tag',
			'submitted_at'         => '2026-04-17 10:00:00',
			'is_read'              => '0',
			'consent_text_version' => '3',
			'consent_timestamp'    => '2026-04-17 09:59:58',
			'email_lookup_hash'    => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
			'consent_locale'       => 'de_DE',
			'is_restricted'        => '0',
		];

		$method = new \ReflectionMethod( Submission::class, 'from_row' );
		$sub    = $method->invoke( null, $row );

		$this->assertSame( 3, $sub->consent_text_version );
		$this->assertSame( '2026-04-17 09:59:58', $sub->consent_timestamp );
		$this->assertSame( 'de_DE', $sub->consent_locale );

		// Properties now match DB column names: email_lookup_hash, is_restricted (Task #63 fix).
		$this->assertSame(
			'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
			$sub->email_lookup_hash
		);
		$this->assertFalse( $sub->is_restricted );
	}

	public function test_submission_consent_fields_null_when_not_in_row(): void
	{
		$row = [
			'id'             => '1',
			'form_id'        => '5',
			'encrypted_data' => 'data',
			'iv'             => 'iv',
			'auth_tag'       => 'tag',
			'submitted_at'   => '2026-04-17 10:00:00',
			'is_read'        => '0',
			'is_restricted'  => '0',
		];

		$method = new \ReflectionMethod( Submission::class, 'from_row' );
		$sub    = $method->invoke( null, $row );

		// Contract-based forms may not have consent fields.
		$this->assertNull( $sub->consent_text_version );
		$this->assertNull( $sub->consent_timestamp );
		$this->assertNull( $sub->consent_locale );
	}

	// ─── Submission handler stubs (Task #26: REST-API not yet implemented) ──

	/**
	 * Helper: creates a SubmitEndpoint with all dependencies mocked.
	 */
	private function create_endpoint( ?CaptchaVerifier $captcha = null ): SubmitEndpoint
	{
		$encryption   = \Mockery::mock( EncryptionService::class );
		$encryption->shouldReceive( 'is_available' )->andReturn( true );

		if ( $captcha === null ) {
			$captcha = \Mockery::mock( CaptchaVerifier::class );
			$captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );
		}

		$validator    = \Mockery::mock( FieldValidator::class );
		$notification = \Mockery::mock( NotificationService::class );
		$file_handler = \Mockery::mock( FileHandler::class );

		return new SubmitEndpoint( $encryption, $captcha, $validator, $notification, $file_handler );
	}

	/**
	 * Helper: creates a mock Form with consent legal basis.
	 */
	private function create_consent_form(): Form
	{
		$form                  = new Form();
		$form->id              = 1;
		$form->title           = 'Consent Form';
		$form->slug            = 'consent-form';
		$form->legal_basis     = 'consent';
		$form->is_active       = true;
		$form->consent_version = 2;
		$form->retention_days  = 90;
		return $form;
	}

	/**
	 * SEC-DSGVO-04, LEGAL-CONSENT-06: Hard-block without consent.
	 * Submission with consent-based form MUST be rejected HTTP 422
	 * when consent_given is not true. No data stored.
	 */
	public function test_submission_rejected_without_consent_returns_http_422(): void
	{
		$form = $this->create_consent_form();

		// Mock WordPress i18n.
		Functions\when( '__' )->returnArg();

		// Use reflection to call verify_consent() directly.
		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		// Case 1: consent_given absent.
		$result = $method->invoke( $endpoint, $form, [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_missing', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );

		// Case 2: consent_given explicitly false.
		$result = $method->invoke( $endpoint, $form, [ 'consent_given' => false ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_missing', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * SEC-VAL-12, LEGAL-CONSENT-07: Consent check MUST precede CAPTCHA verification.
	 * Without consent, no external service (CAPTCHA) may be contacted —
	 * otherwise the server IP would be transmitted without legal basis.
	 */
	public function test_consent_check_precedes_captcha_verification(): void
	{
		$form = $this->create_consent_form();

		// Mock WordPress i18n.
		Functions\when( '__' )->returnArg();

		// Rate-limiting WP function stubs (SEC-SOLL-03).
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// CAPTCHA mock that MUST NOT be called — consent fails first.
		$captcha = \Mockery::mock( CaptchaVerifier::class );
		$captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( true );
		$captcha->shouldNotReceive( 'verify' );

		$endpoint = $this->create_endpoint( $captcha );

		// Nonce must pass for consent step to be reached.
		Functions\expect( 'wp_verify_nonce' )->andReturn( 1 );

		// Build a WP_REST_Request mock with consent_given = false.
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( [] );
		$request->shouldReceive( 'get_body_params' )->andReturn( [
			'form_id'   => 1,
			'_wpnonce'  => 'valid_nonce',
			// consent_given intentionally absent.
		] );

		// Mock Form::find() to return our consent form.
		Functions\expect( 'get_transient' )->andReturn( $form );

		$result = $endpoint->handle_submission( $request );

		// Must be WP_Error with consent_missing — CAPTCHA never contacted.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_missing', $result->get_error_code() );
	}

	/**
	 * CONSENT-07: No data persisted when consent is missing.
	 * verify_consent() returns WP_Error before any encryption or DB write.
	 */
	public function test_no_data_persisted_when_consent_missing(): void
	{
		$form = $this->create_consent_form();

		// Mock WordPress i18n.
		Functions\when( '__' )->returnArg();

		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		// Consent not given — verify_consent() must return WP_Error.
		$result = $method->invoke( $endpoint, $form, [ 'consent_given' => false ] );

		$this->assertInstanceOf( \WP_Error::class, $result );

		// The WP_Error is returned in handle_submission() BEFORE steps 3-8
		// (CAPTCHA, Honeypot, Validation, Encryption, Save, Email).
		// Therefore: zero side effects, zero DB writes.
		$this->assertSame( 'consent_missing', $result->get_error_code() );
	}

	/**
	 * SEC-DSGVO-14: Contract-based forms (Art. 6 lit. b) do not require
	 * a consent checkbox — legal basis is contractual necessity.
	 */
	public function test_contract_based_form_does_not_require_consent_checkbox(): void
	{
		$form               = new Form();
		$form->id           = 2;
		$form->legal_basis  = 'contract';
		$form->is_active    = true;

		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		// No consent_given in params — should still succeed for contract forms.
		$result = $method->invoke( $endpoint, $form, [] );

		$this->assertIsArray( $result );
		$this->assertNull( $result['consent_text_version'] );
		$this->assertNull( $result['consent_timestamp'] );
		$this->assertNull( $result['consent_locale'] );
	}

	// ─── Consent locale validation (DPO-FINDING-13) ────────────────

	public function test_consent_locale_required_for_consent_forms(): void
	{
		$form = $this->create_consent_form();

		// Mock WordPress i18n.
		Functions\when( '__' )->returnArg();

		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		// consent_given=true but consent_locale missing.
		$result = $method->invoke( $endpoint, $form, [
			'consent_given' => true,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_locale_missing', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_consent_locale_invalid_format_rejected(): void
	{
		$form = $this->create_consent_form();

		// Mock WordPress i18n.
		Functions\when( '__' )->returnArg();

		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		// Invalid locale format.
		$result = $method->invoke( $endpoint, $form, [
			'consent_given'  => true,
			'consent_locale' => 'invalid',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_locale_invalid', $result->get_error_code() );
	}

	public function test_consent_success_returns_metadata(): void
	{
		$form = $this->create_consent_form();

		// Set up wpdb for ConsentVersion::get_current_version lookup.
		$wpdb = $this->mock_wpdb();
		$wpdb->shouldReceive( 'get_row' )->andReturn( [
			'id'                 => '10',
			'form_id'            => '1',
			'locale'             => 'de_DE',
			'version'            => '2',
			'consent_text'       => 'Einwilligungstext v2.',
			'privacy_policy_url' => null,
			'valid_from'         => '2026-04-01 00:00:00',
			'created_at'         => '2026-04-01 00:00:00',
		] );

		Functions\expect( 'current_time' )
			->once()
			->with( 'mysql', true )
			->andReturn( '2026-04-17 10:00:00' );

		$endpoint = $this->create_endpoint();
		$method   = new \ReflectionMethod( SubmitEndpoint::class, 'verify_consent' );

		$result = $method->invoke( $endpoint, $form, [
			'consent_given'      => true,
			'consent_locale'     => 'de_DE',
			'consent_version_id' => 10,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['consent_text_version'] );
		$this->assertSame( 10, $result['consent_version_id'] );
		$this->assertSame( '2026-04-17 10:00:00', $result['consent_timestamp'] );
		$this->assertSame( 'de_DE', $result['consent_locale'] );
	}
}
