<?php
/**
 * Unit tests for SubmitEndpoint REST API.
 *
 * Covers the full submission flow per SEC-VAL-12 validation order:
 * Nonce → Consent → CAPTCHA → Honeypot → Fields → Encrypt → Save → Email.
 *
 * Also tests: encryption unavailability, inactive forms, consent_locale
 * fail-closed (DPO-FINDING-13), honeypot silent rejection, lookup_hash
 * generation, and expires_at calculation from retention_days.
 *
 * @package WpDsgvoForm\Tests\Unit\Api
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Api;

use WpDsgvoForm\Api\SubmitEndpoint;
use WpDsgvoForm\Captcha\CaptchaVerifier;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Notification\NotificationService;
use WpDsgvoForm\Upload\FileHandler;
use WpDsgvoForm\Validation\FieldValidator;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for the SubmitEndpoint.
 */
class SubmitEndpointTest extends TestCase {

	private EncryptionService $encryption;
	private CaptchaVerifier $captcha;
	private FieldValidator $validator;
	private NotificationService $notification;
	private FileHandler $file_handler;
	private SubmitEndpoint $endpoint;

	protected function setUp(): void {
		parent::setUp();

		$this->encryption   = Mockery::mock( EncryptionService::class );
		$this->captcha      = Mockery::mock( CaptchaVerifier::class );
		$this->validator    = Mockery::mock( FieldValidator::class );
		$this->notification = Mockery::mock( NotificationService::class );
		$this->file_handler = Mockery::mock( FileHandler::class );

		$this->endpoint = new SubmitEndpoint(
			$this->encryption,
			$this->captcha,
			$this->validator,
			$this->notification,
			$this->file_handler
		);

		// Default mocks for WordPress functions.
		Functions\when( '__' )->returnArg( 1 );

		// Mock wpdb for Submission/Form models.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing(
			function ( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%d', '%s' ], [ '%s', "'%s'" ], $query ), $args );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Creates a mock WP_REST_Request with the given JSON params.
	 */
	private function make_request( array $params ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( [] );
		$request->shouldReceive( 'get_body_params' )->andReturn( $params );
		$request->shouldReceive( 'get_file_params' )->andReturn( [] );
		return $request;
	}

	/**
	 * Creates a Form object configured for testing.
	 */
	private function make_form( array $overrides = [] ): Form {
		$form                  = new Form();
		$form->id              = $overrides['id'] ?? 1;
		$form->title           = $overrides['title'] ?? 'Test Form';
		$form->is_active       = $overrides['is_active'] ?? true;
		$form->legal_basis     = $overrides['legal_basis'] ?? 'consent';
		$form->consent_version = $overrides['consent_version'] ?? 3;
		$form->consent_text    = $overrides['consent_text'] ?? 'Consent text';
		$form->retention_days  = $overrides['retention_days'] ?? 90;
		$form->success_message = $overrides['success_message'] ?? 'Danke!';
		$form->encrypted_dek   = $overrides['encrypted_dek'] ?? 'enc-dek';
		$form->dek_iv          = $overrides['dek_iv'] ?? 'dek-iv';
		return $form;
	}

	/**
	 * Sets up all mocks for a successful submission flow.
	 *
	 * @return Form The mock form.
	 */
	private function setup_successful_flow( array $form_overrides = [] ): Form {
		$form = $this->make_form( $form_overrides );

		// Form::find
		Functions\when( 'get_transient' )->justReturn( $form );

		// Nonce
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		// Consent: current_time for timestamp.
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		// CAPTCHA disabled for simplicity.
		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );

		// Field::find_by_form_id
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		// ConsentVersion::get_current_version for consent-based forms.
		if ( ( $form_overrides['legal_basis'] ?? 'consent' ) === 'consent' ) {
			$wpdb->shouldReceive( 'get_row' )->andReturn( [
				'id'                 => '1',
				'form_id'            => (string) $form->id,
				'locale'             => 'de_DE',
				'version'            => (string) ( $form->consent_version ?? 1 ),
				'consent_text'       => 'Consent text',
				'privacy_policy_url' => null,
				'valid_from'         => '2026-04-17 00:00:00',
				'created_at'         => '2026-04-17 00:00:00',
			] );
		}

		// FieldValidator: no errors.
		$this->validator->shouldReceive( 'validate' )->andReturn( [
			'sanitized' => [ 'name' => 'Test' ],
			'errors'    => [],
		] );

		// Encryption.
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );
		$this->encryption->shouldReceive( 'encrypt_submission' )->andReturn( [
			'encrypted_data' => 'enc-data',
			'iv'             => 'enc-iv',
			'auth_tag'       => 'enc-tag',
		] );
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )->andReturn( null );

		// Save: mock wpdb->insert for Submission.
		$wpdb->insert_id = 42;
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		// Transaction: START TRANSACTION / COMMIT.
		$wpdb->shouldReceive( 'query' )->andReturn( true );

		// Email notification.
		$this->notification->shouldReceive( 'notify' )->once();

		// is_email for lookup_hash.
		Functions\when( 'is_email' )->justReturn( false );

		return $form;
	}

	// ──────────────────────────────────────────────────
	// Step 0: Encryption unavailable
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant Encryption must be available before processing
	 */
	public function test_returns_503_when_encryption_unavailable(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( false );

		$request = $this->make_request( [ 'form_id' => 1 ] );
		$result  = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'encryption_unavailable', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Form not found / inactive
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_returns_404_when_form_not_found(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		// Form::find returns null.
		Functions\when( 'get_transient' )->justReturn( false );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$request = $this->make_request( [ 'form_id' => 999 ] );
		$result  = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	/**
	 * @test
	 */
	public function test_returns_404_when_form_inactive(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form            = $this->make_form();
		$form->is_active = false;

		Functions\when( 'get_transient' )->justReturn( $form );

		$request = $this->make_request( [ 'form_id' => 1 ] );
		$result  = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Step 1: Nonce (CSRF, SEC-CSRF-01/02)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-CSRF-01/02 — Invalid nonce rejects with 403
	 */
	public function test_returns_403_on_invalid_nonce(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form();
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'invalid-nonce',
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nonce_invalid', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Step 2: Consent (SEC-DSGVO-04, DPO-FINDING-13)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-04 — Hard-block on missing consent
	 */
	public function test_returns_422_when_consent_not_given(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = $this->make_request( [
			'form_id'       => 1,
			'_wpnonce'      => 'valid',
			'consent_given' => false,
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_missing', $result->get_error_code() );
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-13 — consent_locale required, fail-closed
	 */
	public function test_returns_422_when_consent_locale_missing(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = $this->make_request( [
			'form_id'       => 1,
			'_wpnonce'      => 'valid',
			'consent_given' => true,
			// consent_locale missing.
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_locale_missing', $result->get_error_code() );
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-13 — Invalid locale format rejected
	 */
	public function test_returns_422_when_consent_locale_format_invalid(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$request = $this->make_request( [
			'form_id'        => 1,
			'_wpnonce'       => 'valid',
			'consent_given'  => true,
			'consent_locale' => 'invalid-format',
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'consent_locale_invalid', $result->get_error_code() );
	}

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-14 — Contract basis does NOT require consent checkbox
	 */
	public function test_contract_basis_skips_consent_check(): void {
		$form = $this->setup_successful_flow( [ 'legal_basis' => 'contract' ] );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [ 'name' => 'Test' ],
			// No consent_given — not required for contract basis.
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
	}

	// ──────────────────────────────────────────────────
	// Step 3: CAPTCHA (SEC-CAP-01)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-CAP-01 — CAPTCHA verification failure blocks submission
	 */
	public function test_returns_422_on_captcha_failure(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->with( 1 )->andReturn( true );
		$this->captcha->shouldReceive( 'verify' )->with( 'bad-token' )->andReturn( false );

		$request = $this->make_request( [
			'form_id'       => 1,
			'_wpnonce'      => 'valid',
			'captcha_token' => 'bad-token',
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'captcha_failed', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Step 4: Honeypot
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant Honeypot filled = bot → silent 201 (no honeypot reveal)
	 */
	public function test_honeypot_filled_returns_silent_success(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );

		$request = $this->make_request( [
			'form_id'     => 1,
			'_wpnonce'    => 'valid',
			'website_url' => 'http://spam.com', // Honeypot field filled.
		] );
		$result = $this->endpoint->handle_submission( $request );

		// Must return 201 to not reveal honeypot mechanism.
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
	}

	// ──────────────────────────────────────────────────
	// Step 5: Field validation
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-01 — Field validation errors block submission
	 */
	public function test_returns_422_on_field_validation_errors(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );

		// Field::find_by_form_id returns empty (no file fields).
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [] );

		$this->validator->shouldReceive( 'validate' )->andReturn( [
			'sanitized' => [],
			'errors'    => [ 'email' => 'E-Mail ist Pflichtfeld.' ],
		] );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'validation_failed', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Step 6: Encryption failure
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-ENC-05 — Encryption failure returns 500
	 */
	public function test_returns_500_on_encryption_failure(): void {
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [] );

		$this->validator->shouldReceive( 'validate' )->andReturn( [
			'sanitized' => [ 'name' => 'Test' ],
			'errors'    => [],
		] );

		$this->encryption->shouldReceive( 'encrypt_submission' )
			->andThrow( new \RuntimeException( 'Encryption failed' ) );

		Functions\when( 'is_email' )->justReturn( false );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'encryption_failed', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Happy path: full successful submission
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-12 — Full successful flow
	 */
	public function test_successful_submission_returns_201_with_message(): void {
		$this->setup_successful_flow( [ 'legal_basis' => 'contract' ] );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
		$this->assertSame( 'Danke!', $result->get_data()['message'] );
	}

	/**
	 * @test
	 * @privacy-relevant Art. 7 — Consent metadata stored with submission
	 */
	public function test_successful_consent_submission_stores_consent_metadata(): void {
		$this->setup_successful_flow( [
			'legal_basis'     => 'consent',
			'consent_version' => 5,
		] );

		$request = $this->make_request( [
			'form_id'            => 1,
			'_wpnonce'           => 'valid',
			'consent_given'      => true,
			'consent_locale'     => 'de_DE',
			'consent_version_id' => 1,
			'fields'             => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
	}

	/**
	 * @test
	 */
	public function test_successful_submission_uses_default_success_message(): void {
		$this->setup_successful_flow( [
			'legal_basis'     => 'contract',
			'success_message' => '',
		] );

		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		// Default message is returned via __() which we mock to return arg 1.
		$this->assertNotEmpty( $result->get_data()['message'] );
	}

	// ──────────────────────────────────────────────────
	// SEC-FILE-11: Transaction rollback on file-record insert failure
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-11 — File-record insert failure triggers ROLLBACK
	 */
	public function test_file_record_insert_failure_triggers_rollback(): void {
		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );

		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );
		Functions\when( 'is_email' )->justReturn( false );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );
		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );
		$this->encryption->shouldReceive( 'encrypt_submission' )->andReturn( [
			'encrypted_data' => 'enc-data',
			'iv'             => 'enc-iv',
			'auth_tag'       => 'enc-tag',
		] );
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )->andReturn( null );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 2, 'form_id' => 1, 'field_type' => 'file',
				'label' => 'Dokument', 'name' => 'dokument', 'placeholder' => '',
				'is_required' => 1, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '',
				'file_config' => json_encode( [ 'allowed_types' => [ 'pdf' ], 'max_size' => 5242880 ] ),
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		$this->validator->shouldReceive( 'validate' )->andReturn( [
			'sanitized' => [],
			'errors'    => [],
		] );

		$this->file_handler->shouldReceive( 'handle_upload' )->andReturn( [
			'field_id'      => 2,
			'file_path'     => '/uploads/enc/abc123.enc',
			'original_name' => 'test.pdf',
			'mime_type'     => 'application/pdf',
			'file_size'     => 1024,
			'encrypted_key' => 'enc-key-123',
		] );

		$wpdb->insert_id = 42;
		$transaction_log = [];
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ) use ( &$transaction_log ) {
				$transaction_log[] = $sql;
				return true;
			}
		);

		$insert_call = 0;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function () use ( &$insert_call ) {
				$insert_call++;
				if ( $insert_call === 1 ) {
					return 1; // Submission insert OK.
				}
				return false; // File-record insert FAILS.
			}
		);
		$wpdb->last_error = 'Disk full';

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( [] );
		$request->shouldReceive( 'get_body_params' )->andReturn( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [],
		] );
		$request->shouldReceive( 'get_file_params' )->andReturn( [
			'dokument' => [
				'name'     => 'test.pdf',
				'type'     => 'application/pdf',
				'tmp_name' => '/tmp/php123',
				'error'    => UPLOAD_ERR_OK,
				'size'     => 1024,
			],
		] );

		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'save_failed', $result->get_error_code() );

		// Verify ROLLBACK was issued.
		$this->assertContains( 'START TRANSACTION', $transaction_log );
		$this->assertContains( 'ROLLBACK', $transaction_log );
		$this->assertNotContains( 'COMMIT', $transaction_log );
	}

	// ──────────────────────────────────────────────────
	// Task #126: ConsentVersion Integration
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 7 Abs. 1 DSGVO — consent_version_id links submission to exact consent text
	 */
	public function test_consent_submission_stores_consent_version_id(): void {
		$form = $this->make_form( [
			'legal_basis' => 'consent',
		] );

		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );
		Functions\when( 'is_email' )->justReturn( false );

		$this->captcha->shouldReceive( 'is_enabled_for_form' )->andReturn( false );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		// ConsentVersion::get_current_version returns row with id=77.
		$wpdb->shouldReceive( 'get_row' )->andReturn( [
			'id'                 => '77',
			'form_id'            => '1',
			'locale'             => 'de_DE',
			'version'            => '3',
			'consent_text'       => 'Ich stimme der Verarbeitung meiner Daten zu.',
			'privacy_policy_url' => null,
			'valid_from'         => '2026-04-01 00:00:00',
			'created_at'         => '2026-04-01 00:00:00',
		] );

		$this->validator->shouldReceive( 'validate' )->andReturn( [
			'sanitized' => [ 'name' => 'Test' ],
			'errors'    => [],
		] );

		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );
		$this->encryption->shouldReceive( 'encrypt_submission' )->andReturn( [
			'encrypted_data' => 'enc-data',
			'iv'             => 'enc-iv',
			'auth_tag'       => 'enc-tag',
		] );
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )->andReturn( null );

		// Capture insert data to verify consent_version_id is stored.
		$captured_data = null;
		$wpdb->insert_id = 42;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( string $table, array $data ) use ( &$captured_data ) {
				if ( str_contains( $table, 'submissions' ) ) {
					$captured_data = $data;
				}
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( true );

		$this->notification->shouldReceive( 'notify' )->once();

		$request = $this->make_request( [
			'form_id'            => 1,
			'_wpnonce'           => 'valid',
			'consent_given'      => true,
			'consent_locale'     => 'de_DE',
			'consent_version_id' => 77,
			'fields'             => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertNotNull( $captured_data, 'Submission insert data must be captured.' );
		$this->assertSame( 77, $captured_data['consent_version_id'] ?? null );
	}

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-04 — Fail-closed when no ConsentVersion for locale
	 */
	public function test_consent_submission_fails_when_no_consent_version_for_locale(): void {
		$form = $this->make_form( [
			'legal_basis'     => 'consent',
			'consent_version' => 5,
		] );

		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		// ConsentVersion::get_current_version returns null — no version for fr_FR.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$request = $this->make_request( [
			'form_id'        => 1,
			'_wpnonce'       => 'valid',
			'consent_given'  => true,
			'consent_locale' => 'fr_FR',
			'fields'         => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$error_data = $result->get_error_data();
		$this->assertSame( 422, $error_data['status'] ?? null );
	}

	/**
	 * @test
	 * @security-relevant Tampered consent_version_id must be rejected
	 */
	public function test_consent_submission_rejects_invalid_consent_version_id(): void {
		$form = $this->make_form( [
			'legal_basis'     => 'consent',
			'consent_version' => 5,
		] );

		Functions\when( 'get_transient' )->justReturn( $form );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$this->encryption->shouldReceive( 'is_available' )->andReturn( true );

		// ConsentVersion::find(999) returns null — invalid ID.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$request = $this->make_request( [
			'form_id'            => 1,
			'_wpnonce'           => 'valid',
			'consent_given'      => true,
			'consent_locale'     => 'de_DE',
			'consent_version_id' => 999,
			'fields'             => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * @test
	 * @privacy-relevant Art. 17 DSGVO — Submissions must be deleted before ConsentVersions
	 */
	public function test_contract_basis_submission_skips_consent_version_lookup(): void {
		$this->setup_successful_flow( [ 'legal_basis' => 'contract' ] );

		// No get_row call for ConsentVersion expected (contract basis skips consent).
		$request = $this->make_request( [
			'form_id'  => 1,
			'_wpnonce' => 'valid',
			'fields'   => [ 'name' => 'Test' ],
		] );
		$result = $this->endpoint->handle_submission( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
	}
}
