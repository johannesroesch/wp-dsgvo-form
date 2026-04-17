<?php
/**
 * Unit tests for AuditLogger class.
 *
 * @package WpDsgvoForm\Tests\Unit\Audit
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Audit;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for audit logging (SEC-AUDIT-01 through SEC-AUDIT-05).
 */
class AuditLoggerTest extends TestCase {

	/**
	 * Setup wpdb mock for all tests.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function mock_wpdb(): \Mockery\MockInterface {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	/**
	 * @test
	 * SEC-AUDIT-01: Valid action inserts a log entry.
	 */
	public function test_log_inserts_entry_for_valid_action(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_dsgvo_audit_log',
				\Mockery::on(
					function ( array $data ): bool {
						return $data['user_id'] === 1
							&& $data['action'] === 'view'
							&& $data['submission_id'] === 42
							&& $data['form_id'] === 5;
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		$logger = new AuditLogger();

		$this->assertTrue( $logger->log( 1, 'view', 42, 5 ) );
	}

	/**
	 * @test
	 * Invalid action type is rejected.
	 */
	public function test_log_rejects_invalid_action(): void {
		$logger = new AuditLogger();

		$this->assertFalse( $logger->log( 1, 'hack', 42 ) );
	}

	/**
	 * @test
	 * SEC-AUDIT-01: All four valid action types are accepted.
	 */
	public function test_log_accepts_all_valid_actions(): void {
		$this->assertSame(
			array( 'view', 'export', 'delete', 'restrict' ),
			AuditLogger::ALLOWED_ACTIONS
		);
	}

	/**
	 * @test
	 * SOLL-FIX: 'restrict' action inserts audit entry (Art. 18 DSGVO).
	 */
	public function test_log_inserts_restrict_action_event(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_dsgvo_audit_log',
				\Mockery::on(
					function ( array $data ): bool {
						return $data['user_id'] === 3
							&& $data['action'] === 'restrict'
							&& $data['submission_id'] === 99
							&& $data['form_id'] === 2;
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		$logger = new AuditLogger();

		$this->assertTrue( $logger->log( 3, 'restrict', 99, 2 ) );
	}

	/**
	 * @test
	 * SEC-AUDIT-02: get_logs retrieves filtered results.
	 */
	public function test_get_logs_returns_filtered_results(): void {
		$wpdb = $this->mock_wpdb();

		$expected_row          = new \stdClass();
		$expected_row->user_id = 1;
		$expected_row->action  = 'view';

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array( $expected_row ) );

		$logger = new AuditLogger();
		$logs   = $logger->get_logs( array( 'user_id' => 1 ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'view', $logs[0]->action );
	}

	/**
	 * @test
	 * SEC-AUDIT-02: get_logs returns empty array on no results.
	 */
	public function test_get_logs_returns_empty_array_on_no_results(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$logger = new AuditLogger();

		$this->assertSame( array(), $logger->get_logs() );
	}

	/**
	 * @test
	 * count_logs returns integer count.
	 */
	public function test_count_logs_returns_integer_count(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( '7' );

		$logger = new AuditLogger();

		$this->assertSame( 7, $logger->count_logs() );
	}

	/**
	 * @test
	 * SEC-AUDIT-04: cleanup_ip_addresses runs UPDATE query and returns count.
	 */
	public function test_cleanup_ip_addresses_returns_affected_rows(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 5 );
		$wpdb->rows_affected = 5;

		$logger = new AuditLogger();

		$this->assertSame( 5, $logger->cleanup_ip_addresses() );
	}

	/**
	 * @test
	 * SEC-AUDIT-03: cleanup_old_entries runs DELETE query and returns count.
	 */
	public function test_cleanup_old_entries_returns_affected_rows(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );
		$wpdb->rows_affected = 3;

		$logger = new AuditLogger();

		$this->assertSame( 3, $logger->cleanup_old_entries() );
	}

	/**
	 * @test
	 * SEC-AUDIT-02: get_client_ip uses REMOTE_ADDR and validates.
	 */
	public function test_log_captures_remote_addr_ip(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

		$captured_ip = '';

		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_dsgvo_audit_log',
				\Mockery::on(
					function ( array $data ) use ( &$captured_ip ): bool {
						$captured_ip = $data['ip_address'];
						return true;
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		$logger = new AuditLogger();
		$logger->log( 1, 'view', 42 );

		$this->assertSame( '192.168.1.1', $captured_ip );

		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
