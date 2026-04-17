<?php
/**
 * Unit tests for SubmissionListTable — bulk delete is_restricted guard (LEGAL-F01).
 *
 * Tests that Art. 18 DSGVO restricted submissions are silently skipped
 * during bulk-delete, while unrestricted submissions are deleted normally.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\SubmissionListTable;
use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionListTable bulk-delete: is_restricted guard
 * silently skips restricted submissions per Art. 18 DSGVO.
 *
 * Security: LEGAL-F01 / SEC-DSGVO-13 (Art. 18 DSGVO deletion protection).
 */
class SubmissionListTableTest extends TestCase {

	private SubmissionDeleter $deleter;
	private AuditLogger $audit_logger;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->deleter      = Mockery::mock( SubmissionDeleter::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );

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
				'__'         => function ( string $text, string $domain = '' ): string {
					return $text;
				},
			]
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Creates a SubmissionListTable instance with necessary WP function mocks.
	 */
	private function create_table(): SubmissionListTable {
		// Constructor calls absint() for filter_form_id.
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );

		$_GET['form_id'] = '0';

		return new SubmissionListTable( $this->deleter, $this->audit_logger );
	}

	/**
	 * Invokes the private process_bulk_action() method via Reflection.
	 */
	private function invoke_process_bulk_action( SubmissionListTable $table ): void {
		$method = new \ReflectionMethod( SubmissionListTable::class, 'process_bulk_action' );
		$method->setAccessible( true );
		$method->invoke( $table );
	}

	/**
	 * Creates a fake submission row.
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
	 * Sets up $_REQUEST and $_POST for a bulk-delete action.
	 *
	 * @param int[] $submission_ids IDs to include in bulk action.
	 */
	private function setup_bulk_delete( array $submission_ids ): void {
		$_REQUEST['action'] = 'delete';
		$_POST['submission_ids'] = array_map( 'strval', $submission_ids );

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Make AccessControl::can_view_submission return true (admin path).
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	// ------------------------------------------------------------------
	// LEGAL-F01: Bulk delete skips restricted submissions
	// ------------------------------------------------------------------

	public function test_bulk_delete_skips_restricted_submission(): void {
		$table = $this->create_table();
		$this->setup_bulk_delete( [ 42 ] );

		// Submission::find(42) returns a restricted submission.
		$restricted_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '1',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $restricted_row );

		// Deleter and audit logger must NOT be called for restricted submissions.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_process_bulk_action( $table );

		// Mockery will verify shouldNotReceive in tearDown.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Bulk delete proceeds for unrestricted submissions
	// ------------------------------------------------------------------

	public function test_bulk_delete_deletes_unrestricted_submission(): void {
		$table = $this->create_table();
		$this->setup_bulk_delete( [ 42 ] );

		// Submission::find(42) returns an unrestricted submission.
		$unrestricted_row = $this->make_submission_row( [
			'id'            => '42',
			'form_id'       => '10',
			'is_restricted' => '0',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $unrestricted_row );

		// Audit logger and deleter should be called for unrestricted submission.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 7, 'delete', 42, 10 )
			->andReturn( true );

		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 42 )
			->andReturn( true );

		$this->invoke_process_bulk_action( $table );
	}

	// ------------------------------------------------------------------
	// Bulk delete with mix: deletes unrestricted, skips restricted
	// ------------------------------------------------------------------

	public function test_bulk_delete_mixed_restricted_and_unrestricted(): void {
		$table = $this->create_table();
		$this->setup_bulk_delete( [ 10, 20, 30 ] );

		// Submission 10: unrestricted -> should be deleted.
		$sub_10 = $this->make_submission_row( [
			'id'            => '10',
			'form_id'       => '5',
			'is_restricted' => '0',
		] );

		// Submission 20: restricted -> should be skipped.
		$sub_20 = $this->make_submission_row( [
			'id'            => '20',
			'form_id'       => '5',
			'is_restricted' => '1',
		] );

		// Submission 30: unrestricted -> should be deleted.
		$sub_30 = $this->make_submission_row( [
			'id'            => '30',
			'form_id'       => '8',
			'is_restricted' => '0',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $sub_10, $sub_20, $sub_30 );

		// Only unrestricted submissions (10 and 30) should be deleted.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 7, 'delete', 10, 5 )
			->andReturn( true );
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 7, 'delete', 30, 8 )
			->andReturn( true );

		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 10 )
			->andReturn( true );
		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 30 )
			->andReturn( true );

		$this->invoke_process_bulk_action( $table );
	}

	// ------------------------------------------------------------------
	// Bulk delete skips submission ID 0
	// ------------------------------------------------------------------

	public function test_bulk_delete_skips_zero_id(): void {
		$table = $this->create_table();
		$this->setup_bulk_delete( [ 0 ] );

		// Neither Submission::find nor deleter should be called for ID 0.
		$this->wpdb->shouldNotReceive( 'get_row' );
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_process_bulk_action( $table );

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Bulk mark_read does NOT trigger delete
	// ------------------------------------------------------------------

	public function test_bulk_mark_read_does_not_delete(): void {
		$table = $this->create_table();

		$_REQUEST['action'] = 'mark_read';
		$_POST['submission_ids'] = [ '42' ];

		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Submission::mark_as_read needs wpdb->update.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		// Deleter should NOT be called for mark_read action.
		$this->deleter->shouldNotReceive( 'delete' );

		$this->invoke_process_bulk_action( $table );

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// No action when no bulk action selected
	// ------------------------------------------------------------------

	public function test_no_action_when_no_bulk_action_selected(): void {
		$table = $this->create_table();

		// No $_REQUEST['action'] set.
		$_REQUEST = [];

		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$this->invoke_process_bulk_action( $table );

		$this->assertTrue( true );
	}
}
