<?php
/**
 * Unit tests for SubmissionViewPage class.
 *
 * Tests render() error paths and handle_export() security controls
 * (Art. 20 DSGVO — Recht auf Datenuebertragbarkeit).
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\SubmissionViewPage;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for submission detail view page rendering and export.
 *
 * Security: LEGAL-F02 (Art. 20 Export), SEC-AUTH-14 (IDOR), SEC-DSGVO-13 (Art. 18).
 */
class SubmissionViewPageTest extends TestCase {

	/**
	 * Backup of $_GET superglobal.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get = array();

	private object $wpdb;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_get = $_GET;

		$this->wpdb         = \Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Stub common WordPress functions used by page rendering.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 */
	private function stub_page_functions( array $skip = array() ): void {
		$return_arg = array( '__', 'esc_html__', 'esc_html', 'esc_url', 'esc_attr', 'wp_unslash' );
		$aliases    = array(
			'esc_html_e' => function ( string $text, string $domain = '' ): void {
				echo $text;
			},
			'admin_url'  => function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			},
			'absint'     => function ( $val ): int {
				return abs( (int) $val );
			},
		);
		$stubs = array(
			'get_transient' => false,
			'set_transient' => true,
		);

		foreach ( $return_arg as $func ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->returnArg();
			}
		}

		foreach ( $aliases as $func => $callback ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->alias( $callback );
			}
		}

		foreach ( $stubs as $func => $value ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->justReturn( $value );
			}
		}
	}

	/**
	 * Creates a fake submission row.
	 */
	private function make_submission_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                   => '42',
				'form_id'              => '10',
				'encrypted_data'       => 'encrypted_blob',
				'iv'                   => 'iv_value',
				'auth_tag'             => 'tag_value',
				'submitted_at'         => '2026-01-01 12:00:00',
				'is_read'              => '1',
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
	 * Creates a fake form row.
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

	/**
	 * Mock wp_die to throw RuntimeException (prevents actual exit).
	 *
	 * @param string &$captured_message Reference to capture the wp_die message.
	 */
	private function mock_wp_die_throws( string &$captured_message = '' ): void {
		Functions\when( 'wp_die' )->alias(
			function () use ( &$captured_message ): never {
				$args             = func_get_args();
				$captured_message = $args[0] ?? '';
				throw new \RuntimeException( 'wp_die: ' . $captured_message );
			}
		);
	}

	// ==================================================================
	// render() tests (existing)
	// ==================================================================

	/**
	 * @test
	 */
	public function test_render_calls_wp_die_when_no_submission_id(): void {
		$this->stub_page_functions( array( 'wp_die' ) );

		unset( $_GET['submission_id'] );

		Functions\expect( 'wp_die' )
			->once()
			->with( 'Keine Einsendung angegeben.' )
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'wp_die called' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die called' );

		$page = new SubmissionViewPage();
		$page->render();
	}

	/**
	 * @test
	 */
	public function test_render_calls_wp_die_when_submission_not_found(): void {
		$this->stub_page_functions( array( 'wp_die' ) );

		Functions\when( 'sanitize_text_field' )->returnArg();

		// Mock wpdb so Submission::find returns null.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$_GET['submission_id'] = '42';

		Functions\expect( 'wp_die' )
			->once()
			->with( 'Einsendung nicht gefunden.' )
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'wp_die: not found' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: not found' );

		$page = new SubmissionViewPage();
		$page->render();
	}

	// ==================================================================
	// handle_export() — Art. 20 DSGVO tests
	// ==================================================================

	// ------------------------------------------------------------------
	// Export: no submission ID
	// ------------------------------------------------------------------

	public function test_export_dies_when_no_submission_id(): void {
		$this->stub_page_functions();

		unset( $_GET['submission_id'] );

		$message = '';
		$this->mock_wp_die_throws( $message );

		$this->expectException( \RuntimeException::class );

		$page = new SubmissionViewPage();
		$page->handle_export();
	}

	// ------------------------------------------------------------------
	// Export: nonce verification
	// ------------------------------------------------------------------

	public function test_export_verifies_nonce_with_correct_action(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		// check_admin_referer should be called with the per-submission action.
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( 'dsgvo_submission_action_42' )
			->andReturnUsing(
				function (): never {
					// Simulate nonce failure (WordPress would wp_die here).
					throw new \RuntimeException( 'wp_die: nonce_failed' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'nonce_failed' );

		$page = new SubmissionViewPage();
		$page->handle_export();
	}

	// ------------------------------------------------------------------
	// Export: capability check
	// ------------------------------------------------------------------

	public function test_export_dies_when_no_export_capability(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Berechtigung', $message );
			$this->assertStringContainsString( 'Export', $message );
		}
	}

	// ------------------------------------------------------------------
	// Export: submission not found
	// ------------------------------------------------------------------

	public function test_export_dies_when_submission_not_found(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Submission::find(42) returns null.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'nicht gefunden', $message );
		}
	}

	// ------------------------------------------------------------------
	// Export: IDOR protection (SEC-AUTH-14)
	// ------------------------------------------------------------------

	public function test_export_dies_when_idor_check_fails(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Submission::find(42) returns a valid submission.
		$sub_row = $this->make_submission_row( [ 'id' => '42' ] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );

		// AccessControl::can_view_submission uses user_can().
		// Return false for all capabilities → IDOR check fails.
		Functions\when( 'user_can' )->justReturn( false );

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Berechtigung', $message );
			$this->assertStringContainsString( 'Einsendung', $message );
		}
	}

	// ------------------------------------------------------------------
	// Export: is_restricted guard (Art. 18 DSGVO / LEGAL-F01)
	// ------------------------------------------------------------------

	public function test_export_dies_when_submission_is_restricted(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Admin user → can_view_submission returns true.
		Functions\when( 'user_can' )->justReturn( true );

		// Restricted submission.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'is_restricted' => '1',
		] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Gesperrte', $message );
			$this->assertStringContainsString( 'Art. 18 DSGVO', $message );
		}
	}

	// ------------------------------------------------------------------
	// Export: form not found
	// ------------------------------------------------------------------

	public function test_export_dies_when_form_not_found(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );

		// Submission found (unrestricted), but Form not found.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '0',
		] );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, null ); // Submission found, Form null.

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Formular nicht gefunden', $message );
		}
	}

	// ------------------------------------------------------------------
	// Export: decryption failure
	// ------------------------------------------------------------------

	public function test_export_dies_when_decryption_fails(): void {
		$this->stub_page_functions();

		$_GET['submission_id'] = '42';

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );

		// Both submission and form found.
		$sub_row  = $this->make_submission_row( [ 'id' => '42', 'form_id' => '10' ] );
		$form_row = $this->make_form_row( [ 'id' => '10' ] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_row, $form_row );

		// DSGVO_FORM_ENCRYPTION_KEY is not defined → decrypt returns null.
		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$page->handle_export();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Entschluesselung fehlgeschlagen', $message );
		}
	}

	// ==================================================================
	// handle_actions() — unrestrict privilege check (SEC-FINDING-08)
	// ==================================================================

	/**
	 * Invokes the private handle_actions() method via Reflection.
	 */
	private function invoke_handle_actions( SubmissionViewPage $page, int $submission_id ): void {
		$method = new \ReflectionMethod( SubmissionViewPage::class, 'handle_actions' );
		$method->setAccessible( true );
		$method->invoke( $page, $submission_id );
	}

	// ------------------------------------------------------------------
	// SEC-FINDING-08: Unrestrict dies without supervisor capability
	// ------------------------------------------------------------------

	public function test_unrestrict_dies_without_supervisor_capability(): void {
		$this->stub_page_functions();

		$_GET['do'] = 'unrestrict';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );

		// User does NOT have dsgvo_form_view_all_submissions capability.
		Functions\when( 'current_user_can' )->justReturn( false );

		$message = '';
		$this->mock_wp_die_throws( $message );

		try {
			$page = new SubmissionViewPage();
			$this->invoke_handle_actions( $page, 42 );
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Berechtigung', $message );
			$this->assertStringContainsString( 'Sperre aufzuheben', $message );
		}
	}

	// ------------------------------------------------------------------
	// SEC-FINDING-08: Unrestrict succeeds with supervisor capability
	// ------------------------------------------------------------------

	public function test_unrestrict_succeeds_with_supervisor_capability(): void {
		$this->stub_page_functions();

		$_GET['do'] = 'unrestrict';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// set_restricted and Submission::find need wpdb.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 ); // set_restricted
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		// Submission::find after set_restricted.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '0',
		] );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );

		// AuditLogger::log calls wpdb->insert.
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		// Capture the success notice.
		$notice_captured = null;
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$notice_captured ): void {
				$notice_captured = [
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				];
			}
		);

		$page = new SubmissionViewPage();
		$this->invoke_handle_actions( $page, 42 );

		$this->assertNotNull( $notice_captured, 'Success notice should have been set.' );
		$this->assertSame( 'submission_unrestricted', $notice_captured['code'] );
		$this->assertStringContainsString( 'Sperre aufgehoben', $notice_captured['message'] );
		$this->assertSame( 'success', $notice_captured['type'] );
	}

	// ------------------------------------------------------------------
	// SEC-FINDING-08: Restrict works for any viewer (no privilege check)
	// ------------------------------------------------------------------

	public function test_restrict_works_without_special_capability(): void {
		$this->stub_page_functions();

		$_GET['do'] = 'restrict';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		// set_restricted and Submission::find need wpdb.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '1',
		] );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 ); // audit log

		$notice_captured = null;
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$notice_captured ): void {
				$notice_captured = [
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				];
			}
		);

		// No current_user_can mock — restrict does NOT check capability.
		$page = new SubmissionViewPage();
		$this->invoke_handle_actions( $page, 42 );

		$this->assertNotNull( $notice_captured, 'Restrict notice should have been set.' );
		$this->assertSame( 'submission_restricted', $notice_captured['code'] );
		$this->assertStringContainsString( 'Gesperrt', $notice_captured['message'] );
	}

	// ------------------------------------------------------------------
	// SEC-FINDING-08: Unrestrict button hidden for non-privileged users
	// ------------------------------------------------------------------

	public function test_unrestrict_button_hidden_for_non_privileged_user(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url, string $action ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'esc_js' )->returnArg();

		// Non-privileged user (no dsgvo_form_view_all_submissions or delete).
		Functions\when( 'current_user_can' )->justReturn( false );

		// Create a restricted submission.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '1',
		] );
		$submission = $this->hydrate_submission( $sub_row );

		// Invoke render_actions_box via reflection.
		$page   = new SubmissionViewPage();
		$method = new \ReflectionMethod( SubmissionViewPage::class, 'render_actions_box' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, $submission );
		$output = ob_get_clean();

		// Unrestrict button should NOT be visible.
		$this->assertStringNotContainsString( 'Sperre aufheben', $output );
		$this->assertStringNotContainsString( 'unrestrict', $output );
	}

	// ------------------------------------------------------------------
	// SEC-FINDING-08: Unrestrict button shown for privileged users
	// ------------------------------------------------------------------

	public function test_unrestrict_button_shown_for_privileged_user(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias(
			function ( string $url, string $action ): string {
				return $url . '&_wpnonce=xxx';
			}
		);
		Functions\when( 'esc_js' )->returnArg();

		// Privileged user (has dsgvo_form_view_all_submissions).
		Functions\when( 'current_user_can' )->justReturn( true );

		// Create a restricted submission.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '1',
		] );
		$submission = $this->hydrate_submission( $sub_row );

		$page   = new SubmissionViewPage();
		$method = new \ReflectionMethod( SubmissionViewPage::class, 'render_actions_box' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, $submission );
		$output = ob_get_clean();

		// Unrestrict button should be visible.
		$this->assertStringContainsString( 'Sperre aufheben', $output );
		$this->assertStringContainsString( 'unrestrict', $output );
	}

	// ------------------------------------------------------------------
	// No action when $_GET['do'] is empty
	// ------------------------------------------------------------------

	public function test_handle_actions_skips_when_no_action(): void {
		$this->stub_page_functions();

		$_GET = []; // No 'do' parameter.

		Functions\when( 'sanitize_text_field' )->returnArg();

		$page = new SubmissionViewPage();
		$this->invoke_handle_actions( $page, 42 );

		// No wp_die, no notices — just returns.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Helper: Hydrate a Submission from row data
	// ------------------------------------------------------------------

	/**
	 * Creates a Submission instance from a row array via Reflection.
	 *
	 * @param array $row The row data.
	 * @return \WpDsgvoForm\Models\Submission
	 */
	private function hydrate_submission( array $row ): \WpDsgvoForm\Models\Submission {
		$submission                       = new \WpDsgvoForm\Models\Submission();
		$submission->id                   = (int) $row['id'];
		$submission->form_id              = (int) $row['form_id'];
		$submission->encrypted_data       = $row['encrypted_data'];
		$submission->iv                   = $row['iv'];
		$submission->auth_tag             = $row['auth_tag'];
		$submission->submitted_at         = $row['submitted_at'];
		$submission->is_read              = (bool) $row['is_read'];
		$submission->expires_at           = $row['expires_at'];
		$submission->consent_text_version = $row['consent_text_version'] !== null ? (int) $row['consent_text_version'] : null;
		$submission->consent_timestamp    = $row['consent_timestamp'];
		$submission->email_lookup_hash    = $row['email_lookup_hash'];
		$submission->consent_locale       = $row['consent_locale'];
		$submission->is_restricted        = (bool) $row['is_restricted'];

		return $submission;
	}
}
