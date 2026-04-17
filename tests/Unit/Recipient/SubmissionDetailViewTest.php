<?php
/**
 * Unit tests for SubmissionDetailView.
 *
 * @package WpDsgvoForm\Tests\Unit\Recipient
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Recipient;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Recipient\RecipientPage;
use WpDsgvoForm\Recipient\SubmissionDetailView;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionDetailView: IDOR protection, error rendering,
 * restrict/unrestrict actions, audit logging, and mark-as-read.
 */
class SubmissionDetailViewTest extends TestCase {

	private AccessControl $access_control;
	private SubmissionDetailView $view;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->access_control->shouldReceive( 'is_supervisor' )->byDefault()->andReturn( false );
		$this->access_control->shouldReceive( 'is_admin' )->byDefault()->andReturn( false );
		$this->view           = new SubmissionDetailView( $this->access_control );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Common WP function stubs for output rendering.
		Functions\stubs(
			[
				'esc_html'      => function ( string $text ): string {
					return $text;
				},
				'esc_html__'    => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'esc_html_e'    => function ( string $text, string $domain = '' ): void {
					echo $text;
				},
				'esc_url'       => function ( string $url ): string {
					return $url;
				},
				'esc_attr'      => function ( string $text ): string {
					return $text;
				},
				'esc_attr__'    => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'__'            => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'home_url'      => function ( string $path = '' ): string {
					return 'https://example.com' . $path;
				},
				'get_transient' => false,
				'set_transient' => true,
			]
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_GET = [];
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// render — zero submission ID
	// ------------------------------------------------------------------

	public function test_render_shows_error_for_zero_submission_id(): void {
		ob_start();
		$this->view->render( 1, 0 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Keine Einsendung angegeben.', $output );
		$this->assertStringContainsString( 'dsgvo-empfaenger', $output ); // back link
	}

	// ------------------------------------------------------------------
	// render — IDOR protection (SEC-AUTH-14)
	// ------------------------------------------------------------------

	public function test_render_shows_error_when_idor_check_fails(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 42, 5 )
			->andReturn( false );

		ob_start();
		$this->view->render( 42, 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'keine Berechtigung', $output );
	}

	// ------------------------------------------------------------------
	// render — submission not found
	// ------------------------------------------------------------------

	public function test_render_shows_error_when_submission_not_found(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 999 )
			->andReturn( true );

		// Submission::find(999) returns null.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		// handle_actions needs no GET params.
		$_GET = [];

		Functions\when( 'sanitize_text_field' )->returnArg();

		ob_start();
		$this->view->render( 1, 999 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Einsendung nicht gefunden.', $output );
	}

	// ------------------------------------------------------------------
	// render — form not found
	// ------------------------------------------------------------------

	public function test_render_shows_error_when_form_not_found(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 10 )
			->andReturn( true );

		$_GET = [];
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Submission::find(10) returns a submission.
		$sub_row = $this->make_submission_row( [ 'id' => '10', 'form_id' => '99' ] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, null ); // first call for Submission, second for Form.

		// AuditLogger::log needs wpdb for insert.
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		// mark_as_read needs wpdb->update.
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_unslash' )->returnArg();

		ob_start();
		$this->view->render( 1, 10 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Formular nicht gefunden', $output );
	}

	// ------------------------------------------------------------------
	// handle_actions — restrict with valid nonce
	// ------------------------------------------------------------------

	public function test_handle_actions_restrict_with_valid_nonce(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );
		$this->access_control->shouldReceive( 'is_supervisor' )->with( 1 )->andReturn( true );

		$_GET = [
			'do'       => 'restrict',
			'_wpnonce' => 'valid_nonce',
		];

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		// Submission::set_restricted is called.
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		// AuditLogger::log (restrict + view).
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Submission::find calls (handle_actions calls find, then render calls find).
		$sub_row = $this->make_submission_row( [
			'id'            => '5',
			'form_id'       => '10',
			'is_restricted' => '1',
			'is_read'       => '1',
		] );

		$form_row = $this->make_form_row( [ 'id' => '10' ] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $sub_row, $form_row );

		// Field::find_by_form_id.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		// Various WP stubs for rendering.
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url, string $action ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		// Restrict action notice should be displayed.
		$this->assertStringContainsString( 'Art. 18 DSGVO', $output );
	}

	// ------------------------------------------------------------------
	// handle_actions — unrestrict with valid nonce
	// ------------------------------------------------------------------

	public function test_handle_actions_unrestrict_with_valid_nonce(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );
		$this->access_control->shouldReceive( 'is_supervisor' )->with( 1 )->andReturn( true );

		$_GET = [
			'do'       => 'unrestrict',
			'_wpnonce' => 'valid_nonce',
		];

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$sub_row = $this->make_submission_row( [
			'id'            => '5',
			'form_id'       => '10',
			'is_restricted' => '0',
			'is_read'       => '1',
		] );

		$form_row = $this->make_form_row( [ 'id' => '10' ] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $sub_row, $form_row );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Sperre aufgehoben', $output );
	}

	// ------------------------------------------------------------------
	// handle_actions — skip when no nonce
	// ------------------------------------------------------------------

	public function test_handle_actions_skips_without_nonce(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );

		$_GET = [
			'do' => 'restrict',
			// No _wpnonce.
		];

		Functions\when( 'sanitize_text_field' )->returnArg();

		$sub_row = $this->make_submission_row( [
			'id'      => '5',
			'form_id' => '10',
			'is_read' => '1',
		] );
		$form_row = $this->make_form_row( [ 'id' => '10' ] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit log

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_unslash' )->returnArg();

		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		// No restrict/unrestrict notice should appear.
		$this->assertStringNotContainsString( 'Sperre aufgehoben', $output );
		$this->assertStringNotContainsString( 'Gesperrt (Art. 18 DSGVO).', $output );
	}

	// ------------------------------------------------------------------
	// handle_actions — invalid nonce rejected
	// ------------------------------------------------------------------

	public function test_handle_actions_rejects_invalid_nonce(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );

		$_GET = [
			'do'       => 'restrict',
			'_wpnonce' => 'invalid',
		];

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$sub_row = $this->make_submission_row( [
			'id'      => '5',
			'form_id' => '10',
			'is_read' => '1',
		] );
		$form_row = $this->make_form_row( [ 'id' => '10' ] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit log

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		// Action should NOT have been performed.
		$this->assertStringNotContainsString( 'Sperre aufgehoben', $output );
	}

	// ------------------------------------------------------------------
	// render — decryption failure shows error
	// ------------------------------------------------------------------

	public function test_render_shows_decryption_error_when_key_missing(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );

		$_GET = [];
		Functions\when( 'sanitize_text_field' )->returnArg();

		$sub_row = $this->make_submission_row( [
			'id'      => '5',
			'form_id' => '10',
			'is_read' => '1',
		] );
		$form_row = $this->make_form_row( [ 'id' => '10' ] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit log

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_unslash' )->returnArg();

		// DSGVO_FORM_ENCRYPTION_KEY is not defined in tests → decrypt returns null.
		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Entschluesselung fehlgeschlagen', $output );
	}

	// ------------------------------------------------------------------
	// render — restricted submission shows warning
	// ------------------------------------------------------------------

	public function test_render_shows_restricted_warning(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );
		$this->access_control->shouldReceive( 'is_supervisor' )->with( 1 )->andReturn( true );

		$_GET = [];
		Functions\when( 'sanitize_text_field' )->returnArg();

		$sub_row = $this->make_submission_row( [
			'id'            => '5',
			'form_id'       => '10',
			'is_read'       => '1',
			'is_restricted' => '1',
		] );
		$form_row = $this->make_form_row( [ 'id' => '10' ] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_unslash' )->returnArg();

		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Gesperrt (Art. 18 DSGVO)', $output );
		// DSGVO action button should show "unrestrict".
		$this->assertStringContainsString( 'Sperre aufheben', $output );
	}

	// ------------------------------------------------------------------
	// render — metadata section includes form title and legal_basis
	// ------------------------------------------------------------------

	public function test_render_includes_metadata_section(): void {
		$this->access_control->shouldReceive( 'can_view_submission' )
			->with( 1, 5 )
			->andReturn( true );

		$_GET = [];
		Functions\when( 'sanitize_text_field' )->returnArg();

		$sub_row = $this->make_submission_row( [
			'id'      => '5',
			'form_id' => '10',
			'is_read' => '1',
		] );
		$form_row = $this->make_form_row( [
			'id'          => '10',
			'title'       => 'Kontaktformular',
			'legal_basis' => 'consent',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'wp_date' )->justReturn( '01.01.2026 00:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_unslash' )->returnArg();

		ob_start();
		$this->view->render( 1, 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Metadaten', $output );
		$this->assertStringContainsString( 'Kontaktformular', $output );
		$this->assertStringContainsString( 'consent', $output );
		$this->assertStringContainsString( 'Rechtsgrundlage', $output );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Creates a fake submission row (ARRAY_A format for Submission::from_row).
	 */
	private function make_submission_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                   => '1',
				'form_id'              => '10',
				'encrypted_data'       => 'encrypted_blob',
				'iv'                   => 'iv_value',
				'auth_tag'             => 'tag_value',
				'submitted_at'        => '2026-01-01 12:00:00',
				'is_read'              => '0',
				'expires_at'           => '2026-07-01 12:00:00',
				'consent_text_version' => '1',
				'consent_timestamp'    => '2026-01-01 12:00:00',
				'email_lookup_hash'    => null,
				'consent_locale'       => null,
				'is_restricted'        => '0',
			],
			$overrides
		);
	}

	/**
	 * Creates a fake form row (ARRAY_A format for Form::from_row).
	 */
	private function make_form_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                   => '10',
				'title'                => 'Test Form',
				'slug'                 => 'test-form',
				'is_active'            => '1',
				'retention_days'       => '90',
				'legal_basis'          => 'consent',
				'consent_text'         => 'Ich stimme zu.',
				'consent_text_version' => '1',
				'consent_locale'       => 'de_DE',
				'recipient_email'      => null,
				'encrypted_dek'        => null,
				'dek_iv'               => null,
				'created_at'           => '2026-01-01 00:00:00',
				'updated_at'           => '2026-01-01 00:00:00',
			],
			$overrides
		);
	}
}
