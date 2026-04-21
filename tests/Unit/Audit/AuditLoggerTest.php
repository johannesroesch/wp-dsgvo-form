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
	 * SEC-AUDIT-01 / Task #371: All valid action types including kek_rotation actions.
	 */
	public function test_log_accepts_all_valid_actions(): void {
		$this->assertSame(
			array( 'view', 'export', 'delete', 'restrict', 'privacy_notice_acknowledged', 'kek_rotation', 'kek_rotation_rehash', 'capability_granted', 'capability_revoked' ),
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
	 * Task #371: kek_rotation action is accepted and inserts audit entry.
	 */
	public function test_log_inserts_kek_rotation_action(): void {
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
							&& $data['action'] === 'kek_rotation'
							&& $data['details'] === 'KEK rotated successfully: 3 forms re-encrypted';
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		$logger = new AuditLogger();

		$this->assertTrue( $logger->log( 1, 'kek_rotation', null, null, 'KEK rotated successfully: 3 forms re-encrypted' ) );
	}

	/**
	 * @test
	 * Task #371: kek_rotation_rehash action is accepted and inserts audit entry.
	 */
	public function test_log_inserts_kek_rotation_rehash_action(): void {
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
							&& $data['action'] === 'kek_rotation_rehash'
							&& $data['details'] === 'Lookup hashes recomputed: 50 rehashed, 2 skipped, 0 errors';
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		$logger = new AuditLogger();

		$this->assertTrue( $logger->log( 1, 'kek_rotation_rehash', null, null, 'Lookup hashes recomputed: 50 rehashed, 2 skipped, 0 errors' ) );
	}

	/**
	 * @test
	 * SEC-KANN-01: AuditLogger accepts optional IpResolver via constructor.
	 */
	public function test_log_uses_injected_ip_resolver(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$ip_resolver = \Mockery::mock( \WpDsgvoForm\Security\IpResolver::class );
		$ip_resolver->shouldReceive( 'resolve' )->once()->andReturn( '10.20.30.40' );

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

		$logger = new AuditLogger( $ip_resolver );
		$logger->log( 1, 'view', 42 );

		$this->assertSame( '10.20.30.40', $captured_ip );
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
	 * SEC-AUDIT-03 + PERF-SOLL-04: cleanup_old_entries uses batch-DELETE (do/while LIMIT 500).
	 * Single batch: all entries fit within one LIMIT 500 pass.
	 */
	public function test_cleanup_old_entries_single_batch(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$wpdb->shouldReceive( 'prepare' )
			->andReturn( 'SQL' );

		// First query: 3 rows deleted. Second query: 0 → loop ends.
		$wpdb->shouldReceive( 'query' )
			->twice()
			->andReturn( 3, 0 );

		$logger = new AuditLogger();

		$this->assertSame( 3, $logger->cleanup_old_entries() );
	}

	/**
	 * @test
	 * PERF-SOLL-04: Multiple batches — accumulates total across iterations.
	 */
	public function test_cleanup_old_entries_multiple_batches(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$wpdb->shouldReceive( 'prepare' )
			->andReturn( 'SQL' );

		// Three batches: 500 + 500 + 200 + 0 = 1200 total.
		$wpdb->shouldReceive( 'query' )
			->times( 4 )
			->andReturn( 500, 500, 200, 0 );

		$logger = new AuditLogger();

		$this->assertSame( 1200, $logger->cleanup_old_entries() );
	}

	/**
	 * @test
	 * PERF-SOLL-04: No expired entries — loop exits after first iteration.
	 */
	public function test_cleanup_old_entries_returns_zero_when_nothing_to_delete(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$wpdb->shouldReceive( 'prepare' )
			->andReturn( 'SQL' );

		// First query: 0 rows → loop ends immediately.
		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 0 );

		$logger = new AuditLogger();

		$this->assertSame( 0, $logger->cleanup_old_entries() );
	}

	/**
	 * @test
	 * PERF-SOLL-04: Batch DELETE uses LIMIT 500 in SQL.
	 */
	public function test_cleanup_old_entries_uses_limit_500(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$captured_sql = '';

		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( function ( string $sql ) use ( &$captured_sql ): string {
				$captured_sql = $sql;
				return 'SQL';
			} );

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 0 );

		$logger = new AuditLogger();
		$logger->cleanup_old_entries();

		$this->assertStringContainsString( 'LIMIT 500', $captured_sql );
	}

	/**
	 * @test
	 * SEC-AUDIT-03: Retention period is 365 days.
	 */
	public function test_cleanup_old_entries_uses_365_day_retention(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );

		$captured_sql    = '';
		$captured_days   = 0;

		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( function ( string $sql, ...$args ) use ( &$captured_sql, &$captured_days ): string {
				$captured_sql  = $sql;
				$captured_days = $args[1] ?? 0;
				return 'SQL';
			} );

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 0 );

		$logger = new AuditLogger();
		$logger->cleanup_old_entries();

		$this->assertStringContainsString( 'INTERVAL', $captured_sql );
		$this->assertSame( 365, $captured_days );
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
		Functions\when( 'get_option' )->justReturn( '' );

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
