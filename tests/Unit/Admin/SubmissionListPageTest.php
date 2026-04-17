<?php
/**
 * Unit tests for SubmissionListPage — is_restricted Deletion Guard (LEGAL-F01).
 *
 * Tests that Art. 18 DSGVO restricted submissions cannot be deleted
 * via single-delete row action, and that unrestricted submissions
 * are deleted normally with audit logging.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\SubmissionListPage;
use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionListPage single-delete: is_restricted guard,
 * capability checks, nonce verification, and audit logging.
 *
 * Security: LEGAL-F01 / SEC-DSGVO-13 (Art. 18 DSGVO deletion protection).
 */
class SubmissionListPageTest extends TestCase {

	private SubmissionDeleter $deleter;
	private AuditLogger $audit_logger;
	private SubmissionListPage $page;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->deleter      = Mockery::mock( SubmissionDeleter::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );
		$this->page         = new SubmissionListPage( $this->deleter, $this->audit_logger );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Common WP function stubs.
		Functions\stubs(
			[
				'esc_html'   => function ( string $text ): string {
					return $text;
				},
				'esc_html__' => function ( string $text, string $domain = '' ): string {
					return $text;
				},
				'esc_html_e' => function ( string $text, string $domain = '' ): void {
					echo $text;
				},
				'esc_url'    => function ( string $url ): string {
					return $url;
				},
				'esc_attr'   => function ( string $text ): string {
					return $text;
				},
				'__'         => function ( string $text, string $domain = '' ): string {
					return $text;
				},
			]
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_GET  = [];
		$_POST = [];
		parent::tearDown();
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
				'submitted_at'         => '2026-01-01 12:00:00',
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
	 * Set up $_GET for single-delete action.
	 */
	private function setup_delete_get( int $submission_id ): void {
		$_GET['action']        = 'delete';
		$_GET['submission_id'] = (string) $submission_id;
	}

	/**
	 * Invokes the private handle_single_delete() method via Reflection.
	 */
	private function invoke_handle_single_delete(): void {
		$method = new \ReflectionMethod( SubmissionListPage::class, 'handle_single_delete' );
		$method->setAccessible( true );
		$method->invoke( $this->page );
	}

	// ------------------------------------------------------------------
	// LEGAL-F01: Single delete blocked for restricted submission
	// ------------------------------------------------------------------

	public function test_single_delete_blocked_for_restricted_submission(): void {
		$this->setup_delete_get( 42 );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );

		// Submission::find(42) returns a restricted submission.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '1',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );

		// add_settings_error should be called with the locked error.
		$error_captured = null;
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$error_captured ): void {
				$error_captured = [
					'setting' => $setting,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				];
			}
		);

		// Deleter must NOT be called for restricted submissions.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_handle_single_delete();

		$this->assertNotNull( $error_captured, 'add_settings_error should have been called.' );
		$this->assertSame( 'submission_locked', $error_captured['code'] );
		$this->assertStringContainsString( 'gesperrt', $error_captured['message'] );
		$this->assertStringContainsString( 'Art. 18 DSGVO', $error_captured['message'] );
		$this->assertSame( 'error', $error_captured['type'] );
	}

	// ------------------------------------------------------------------
	// Single delete succeeds for unrestricted submission
	// ------------------------------------------------------------------

	public function test_single_delete_succeeds_for_unrestricted_submission(): void {
		$this->setup_delete_get( 42 );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Submission::find(42) returns an unrestricted submission.
		$sub_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '0',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $sub_row );

		// Audit logger should be called BEFORE deletion.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 7, 'delete', 42, 10 )
			->andReturn( true );

		// Deleter should be called for unrestricted submission.
		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 42 )
			->andReturn( true );

		// Capture success notice.
		$success_captured = null;
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$success_captured ): void {
				$success_captured = [
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				];
			}
		);

		$this->invoke_handle_single_delete();

		$this->assertNotNull( $success_captured, 'add_settings_error should have been called for success.' );
		$this->assertSame( 'submission_deleted', $success_captured['code'] );
		$this->assertSame( 'success', $success_captured['type'] );
	}

	// ------------------------------------------------------------------
	// Single delete requires capability
	// ------------------------------------------------------------------

	public function test_single_delete_requires_delete_capability(): void {
		$this->setup_delete_get( 42 );

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );

		// wp_die should be called when capability check fails.
		Functions\when( 'wp_die' )->alias(
			function ( string $message ): void {
				throw new \LogicException( 'wp_die: ' . $message );
			}
		);

		// Deleter must NOT be called.
		$this->deleter->shouldNotReceive( 'delete' );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$this->invoke_handle_single_delete();
	}

	// ------------------------------------------------------------------
	// Single delete skips when submission not found
	// ------------------------------------------------------------------

	public function test_single_delete_skips_when_submission_not_found(): void {
		$this->setup_delete_get( 999 );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );

		// Submission::find(999) returns null.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		// Neither deleter nor audit logger should be called.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_handle_single_delete();

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// No action when action is not 'delete'
	// ------------------------------------------------------------------

	public function test_no_delete_when_action_is_not_delete(): void {
		$_GET = []; // No action parameter.

		// Deleter must NOT be called.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_handle_single_delete();

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Single delete skips zero submission ID
	// ------------------------------------------------------------------

	public function test_single_delete_skips_zero_submission_id(): void {
		$this->setup_delete_get( 0 );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );

		// Deleter must NOT be called for ID 0.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_handle_single_delete();

		$this->assertTrue( true );
	}
}
