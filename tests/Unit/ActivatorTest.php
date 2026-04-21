<?php
/**
 * Unit tests for Activator class.
 *
 * @package WpDsgvoForm\Tests\Unit
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit;

use WpDsgvoForm\Activator;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for plugin activation logic.
 */
class ActivatorTest extends TestCase {

	/**
	 * Set up common stubs needed by every Activator test.
	 *
	 * @param string[] $skip Function names to NOT stub (handled by test).
	 */
	private function stub_activation_deps( array $skip = array() ): void {
		$wpdb                = \Mockery::mock( 'wpdb' );
		$wpdb->prefix        = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );

		$GLOBALS['wpdb'] = $wpdb;

		$defaults = array(
			'__'                  => 'returnArg',
			'dbDelta'             => array(),
			'add_role'            => null,
			'get_role'            => null,
			'wp_next_scheduled'   => false,
			'wp_schedule_event'   => true,
			'add_option'          => true,
			'update_option'       => true,
			'delete_option'       => true,
			'flush_rewrite_rules' => null,
			'get_users'           => array(),
			'get_current_user_id' => 1,
		);

		foreach ( $defaults as $func => $return ) {
			if ( in_array( $func, $skip, true ) ) {
				continue;
			}
			if ( 'returnArg' === $return ) {
				Functions\when( $func )->returnArg();
			} elseif ( null === $return ) {
				Functions\when( $func )->justReturn( null );
			} else {
				Functions\when( $func )->justReturn( $return );
			}
		}
	}

	/**
	 * @test
	 */
	public function test_activate_creates_six_database_tables(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$tables_created = array();

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$tables_created ): array {
					if ( preg_match( '/CREATE TABLE (\S+)/', $sql, $matches ) ) {
						$tables_created[] = $matches[1];
					}
					return array();
				}
			);

		Activator::activate();

		$expected_tables = array(
			'wp_dsgvo_forms',
			'wp_dsgvo_fields',
			'wp_dsgvo_submissions',
			'wp_dsgvo_submission_files',
			'wp_dsgvo_form_recipients',
			'wp_dsgvo_audit_log',
		);

		foreach ( $expected_tables as $table ) {
			$this->assertContains( $table, $tables_created, "Table {$table} was not created." );
		}
	}

	/**
	 * @test
	 */
	public function test_activate_creates_reader_role(): void {
		$this->stub_activation_deps( array( 'add_role' ) );

		$roles_created = array();

		Functions\expect( 'add_role' )
			->andReturnUsing(
				function ( string $role, string $display_name, array $capabilities ) use ( &$roles_created ): void {
					$roles_created[ $role ] = $capabilities;
				}
			);

		Activator::activate();

		$this->assertArrayHasKey( 'wp_dsgvo_form_reader', $roles_created );
		$this->assertTrue( $roles_created['wp_dsgvo_form_reader']['read'] );
		$this->assertTrue( $roles_created['wp_dsgvo_form_reader']['dsgvo_form_view_submissions'] );
	}

	/**
	 * @test
	 */
	public function test_activate_creates_supervisor_role(): void {
		$this->stub_activation_deps( array( 'add_role' ) );

		$roles_created = array();

		Functions\expect( 'add_role' )
			->andReturnUsing(
				function ( string $role, string $display_name, array $capabilities ) use ( &$roles_created ): void {
					$roles_created[ $role ] = $capabilities;
				}
			);

		Activator::activate();

		$this->assertArrayHasKey( 'wp_dsgvo_form_supervisor', $roles_created );
		$this->assertTrue( $roles_created['wp_dsgvo_form_supervisor']['read'] );
		$this->assertTrue( $roles_created['wp_dsgvo_form_supervisor']['dsgvo_form_view_submissions'] );
		$this->assertTrue( $roles_created['wp_dsgvo_form_supervisor']['dsgvo_form_view_all_submissions'] );
	}

	/**
	 * @test
	 */
	public function test_activate_grants_admin_all_dsgvo_capabilities(): void {
		$this->stub_activation_deps( array( 'get_role' ) );

		$admin_caps = array();
		$admin_role = \Mockery::mock( 'WP_Role' );
		$admin_role->shouldReceive( 'add_cap' )
			->andReturnUsing(
				function ( string $cap ) use ( &$admin_caps ): void {
					$admin_caps[] = $cap;
				}
			);

		Functions\expect( 'get_role' )
			->with( 'administrator' )
			->andReturn( $admin_role );

		Activator::activate();

		$expected_caps = array(
			'dsgvo_form_manage',
			'dsgvo_form_view_submissions',
			'dsgvo_form_view_all_submissions',
			'dsgvo_form_delete_submissions',
			'dsgvo_form_export',
		);

		foreach ( $expected_caps as $cap ) {
			$this->assertContains( $cap, $admin_caps, "Admin capability {$cap} was not granted." );
		}
	}

	/**
	 * @test
	 */
	public function test_activate_schedules_hourly_cron(): void {
		$this->stub_activation_deps( array( 'wp_next_scheduled', 'wp_schedule_event' ) );

		Functions\expect( 'wp_next_scheduled' )
			->with( 'dsgvo_form_cleanup' )
			->andReturn( false );

		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'hourly', 'dsgvo_form_cleanup' )
			->andReturn( true );

		Activator::activate();
	}

	/**
	 * @test
	 */
	public function test_activate_does_not_reschedule_existing_cron(): void {
		$this->stub_activation_deps( array( 'wp_next_scheduled', 'wp_schedule_event' ) );

		Functions\expect( 'wp_next_scheduled' )
			->with( 'dsgvo_form_cleanup' )
			->andReturn( 1713350400 );

		Functions\expect( 'wp_schedule_event' )->never();

		Activator::activate();
	}

	/**
	 * @test
	 */
	public function test_activate_stores_db_version_option(): void {
		$this->stub_activation_deps( array( 'update_option' ) );

		Functions\expect( 'update_option' )
			->once()
			->with( 'wpdsgvo_db_version', WPDSGVO_VERSION )
			->andReturn( true );

		Activator::activate();
	}

	/**
	 * @test
	 */
	public function test_submissions_table_has_encryption_columns(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$submissions_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$submissions_sql ): array {
					if ( str_contains( $sql, 'dsgvo_submissions' ) && ! str_contains( $sql, 'submission_files' ) ) {
						$submissions_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'encrypted_data', $submissions_sql );
		$this->assertStringContainsString( 'iv', $submissions_sql );
		$this->assertStringContainsString( 'auth_tag', $submissions_sql );
		$this->assertStringContainsString( 'email_lookup_hash', $submissions_sql );
	}

	/**
	 * @test
	 */
	public function test_submissions_table_has_dsgvo_columns(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$submissions_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$submissions_sql ): array {
					if ( str_contains( $sql, 'dsgvo_submissions' ) && ! str_contains( $sql, 'submission_files' ) ) {
						$submissions_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'is_restricted', $submissions_sql );
		$this->assertStringContainsString( 'consent_timestamp', $submissions_sql );
		$this->assertStringContainsString( 'consent_text_version', $submissions_sql );
		$this->assertStringContainsString( 'expires_at', $submissions_sql );
	}

	/**
	 * @test
	 */
	public function test_forms_table_has_consent_versioning(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$forms_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$forms_sql ): array {
					if ( str_contains( $sql, 'dsgvo_forms' ) && ! str_contains( $sql, 'form_recipients' ) ) {
						$forms_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'legal_basis', $forms_sql );
		$this->assertStringContainsString( 'consent_text', $forms_sql );
		$this->assertStringContainsString( 'consent_version', $forms_sql );
	}

	/**
	 * @test
	 * Regression: purpose column must be in dsgvo_forms CREATE TABLE.
	 */
	public function test_forms_table_has_purpose_column(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$forms_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$forms_sql ): array {
					if ( str_contains( $sql, 'dsgvo_forms' ) && ! str_contains( $sql, 'form_recipients' ) ) {
						$forms_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'purpose text', $forms_sql );
	}

	/**
	 * @test
	 * Regression: maybe_upgrade() triggers dbDelta with purpose column present.
	 */
	public function test_maybe_upgrade_runs_create_tables_with_purpose_column(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_db_version', '0' )
			->andReturn( '0' );

		$forms_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$forms_sql ): array {
					if ( str_contains( $sql, 'dsgvo_forms' ) && ! str_contains( $sql, 'form_recipients' ) ) {
						$forms_sql = $sql;
					}
					return array();
				}
			);

		// migrate_consent_locale_default needs $wpdb->query.
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		Functions\expect( 'update_option' )
			->once()
			->with( 'wpdsgvo_db_version', WPDSGVO_VERSION )
			->andReturn( true );

		Activator::maybe_upgrade();

		$this->assertStringContainsString( 'purpose text', $forms_sql );
	}

	/**
	 * @test
	 * maybe_upgrade() skips when DB version matches plugin version.
	 */
	public function test_maybe_upgrade_skips_when_version_matches(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_db_version', '0' )
			->andReturn( WPDSGVO_VERSION );

		Functions\expect( 'dbDelta' )->never();

		Activator::maybe_upgrade();
	}

	/**
	 * @test
	 * Task #278: maybe_upgrade() deletes deprecated CAPTCHA options.
	 */
	public function test_maybe_upgrade_deletes_deprecated_captcha_options(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option', 'delete_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_db_version', '0' )
			->andReturn( '0' );

		Functions\when( 'dbDelta' )->justReturn( array() );

		// migrate_consent_locale_default needs $wpdb->query.
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		Functions\when( 'update_option' )->justReturn( true );

		$deleted_options = array();

		Functions\expect( 'delete_option' )
			->times( 4 )
			->andReturnUsing(
				function ( string $option ) use ( &$deleted_options ): bool {
					$deleted_options[] = $option;
					return true;
				}
			);

		Activator::maybe_upgrade();

		$this->assertContains( 'wpdsgvo_captcha_provider', $deleted_options );
		$this->assertContains( 'wpdsgvo_captcha_base_url', $deleted_options );
		$this->assertContains( 'wpdsgvo_captcha_sitekey', $deleted_options );
		$this->assertContains( 'wpdsgvo_captcha_sri_hash', $deleted_options );
	}

	/**
	 * @test
	 * Activator creates 7 tables (including consent_versions).
	 */
	public function test_activate_creates_seven_database_tables(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$tables_created = array();

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$tables_created ): array {
					if ( preg_match( '/CREATE TABLE (\S+)/', $sql, $matches ) ) {
						$tables_created[] = $matches[1];
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertCount( 7, $tables_created );
		$this->assertContains( 'wp_dsgvo_consent_versions', $tables_created );
	}

	// ──────────────────────────────────────────────────
	// Fields table — width column (FEP-01, Task #347)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Fields table must include the width column with DEFAULT 'full'.
	 */
	public function test_fields_table_has_width_column(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$fields_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$fields_sql ): array {
					if ( str_contains( $sql, 'dsgvo_fields' ) ) {
						$fields_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'width', $fields_sql );
		$this->assertStringContainsString( "DEFAULT 'full'", $fields_sql );
	}

	/**
	 * @test
	 * Fields table schema via maybe_upgrade also includes width column.
	 */
	public function test_maybe_upgrade_fields_table_has_width_column(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_db_version', '0' )
			->andReturn( '0' );

		$fields_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$fields_sql ): array {
					if ( str_contains( $sql, 'dsgvo_fields' ) ) {
						$fields_sql = $sql;
					}
					return array();
				}
			);

		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		Functions\expect( 'update_option' )
			->once()
			->with( 'wpdsgvo_db_version', WPDSGVO_VERSION )
			->andReturn( true );

		Activator::maybe_upgrade();

		$this->assertStringContainsString( 'width', $fields_sql );
		$this->assertStringContainsString( "DEFAULT 'full'", $fields_sql );
	}

	// ──────────────────────────────────────────────────
	// Forms table — locale_override column (FEP-03, Task #347)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Forms table must include the locale_override column.
	 */
	public function test_forms_table_has_locale_override_column(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$forms_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$forms_sql ): array {
					if ( str_contains( $sql, 'dsgvo_forms' ) && ! str_contains( $sql, 'form_recipients' ) ) {
						$forms_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'locale_override', $forms_sql );
		$this->assertStringContainsString( 'DEFAULT NULL', $forms_sql );
	}

	// ──────────────────────────────────────────────────
	// migrate_capabilities() — Version guard (DPO-SOLL-F06)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * migrate_capabilities() skips when cap_migration_version >= 1.
	 */
	public function test_maybe_upgrade_skips_capability_migration_when_already_done(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option', 'delete_option' ) );

		$option_calls = array();

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $key, $default = false ) use ( &$option_calls ) {
					$option_calls[] = $key;
					if ( $key === 'wpdsgvo_db_version' ) {
						return '0'; // Trigger upgrade.
					}
					if ( $key === 'wpdsgvo_cap_migration_version' ) {
						return '1'; // Already migrated.
					}
					return $default;
				}
			);

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		// migrate_consent_locale_default needs $wpdb->query.
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		// get_users should NOT be called — migration skipped.
		Functions\expect( 'get_users' )->never();

		Activator::maybe_upgrade();
	}

	/**
	 * @test
	 * migrate_capabilities() runs when cap_migration_version = '0'.
	 */
	public function test_maybe_upgrade_runs_capability_migration_when_version_zero(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option', 'get_role', 'get_users', 'delete_option' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $key, $default = false ) {
					if ( $key === 'wpdsgvo_db_version' ) {
						return '0'; // Trigger upgrade.
					}
					if ( $key === 'wpdsgvo_cap_migration_version' ) {
						return '0'; // Not yet migrated.
					}
					return $default;
				}
			);

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );

		$updated_options = array();
		Functions\expect( 'update_option' )
			->andReturnUsing(
				function ( string $key, $value ) use ( &$updated_options ): bool {
					$updated_options[ $key ] = $value;
					return true;
				}
			);

		// Phase 1: admin role gets dsgvo_form_recipient.
		$admin_role = Mockery::mock( 'WP_Role' );
		$admin_caps = array();
		$admin_role->shouldReceive( 'add_cap' )
			->andReturnUsing(
				function ( string $cap ) use ( &$admin_caps ): void {
					$admin_caps[] = $cap;
				}
			);

		Functions\expect( 'get_role' )
			->with( 'administrator' )
			->andReturn( $admin_role );

		// Phase 2: No users to migrate (empty).
		Functions\expect( 'get_users' )->andReturn( array() );

		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		Activator::maybe_upgrade();

		// Phase 1: admin role gets dsgvo_form_recipient cap.
		$this->assertContains( 'dsgvo_form_recipient', $admin_caps );

		// Version stamped.
		$this->assertSame( '1', $updated_options['wpdsgvo_cap_migration_version'] );
	}

	/**
	 * @test
	 * migrate_capabilities() maps reader role to correct capabilities.
	 */
	public function test_maybe_upgrade_migrates_reader_role_capabilities(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option', 'get_role', 'get_users', 'get_userdata', 'delete_option' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $key, $default = false ) {
					if ( $key === 'wpdsgvo_db_version' ) {
						return '0';
					}
					if ( $key === 'wpdsgvo_cap_migration_version' ) {
						return '0';
					}
					return $default;
				}
			);

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$admin_role = Mockery::mock( 'WP_Role' );
		$admin_role->shouldReceive( 'add_cap' );

		Functions\expect( 'get_role' )
			->with( 'administrator' )
			->andReturn( $admin_role );

		// Track capability grants.
		$grants = array();

		$reader_user = Mockery::mock( 'WP_User' );
		$reader_user->ID         = 10;
		$reader_user->user_login = 'reader1';
		$reader_user->shouldReceive( 'add_cap' )
			->andReturnUsing(
				function ( string $cap ) use ( &$grants ): void {
					$grants[] = array( 'user_id' => 10, 'cap' => $cap );
				}
			);

		Functions\expect( 'get_users' )
			->andReturnUsing(
				function ( array $args ) use ( $reader_user ): array {
					if ( $args['role'] === 'wp_dsgvo_form_reader' && $args['offset'] === 0 ) {
						return array( 10 ); // Return user ID.
					}
					return array();
				}
			);

		Functions\expect( 'get_userdata' )
			->andReturnUsing(
				function ( int $uid ) use ( $reader_user ) {
					return $uid === 10 ? $reader_user : false;
				}
			);

		// AuditLogger needs current_time + $wpdb->insert for audit log entries.
		Functions\when( 'current_time' )->justReturn( '2026-04-21 12:00:00' );

		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );
		$GLOBALS['wpdb']->shouldReceive( 'insert' )->andReturn( 1 );

		Activator::maybe_upgrade();

		// Reader should get view_submissions + recipient.
		$reader_caps = array_column(
			array_filter( $grants, fn( $g ) => $g['user_id'] === 10 ),
			'cap'
		);

		$this->assertContains( 'dsgvo_form_view_submissions', $reader_caps );
		$this->assertContains( 'dsgvo_form_recipient', $reader_caps );
		// SEC-ARCH-03: delete_submissions must NOT be migrated.
		$this->assertNotContains( 'dsgvo_form_delete_submissions', $reader_caps );
	}

	/**
	 * @test
	 * migrate_capabilities() maps supervisor role to correct capabilities.
	 */
	public function test_maybe_upgrade_migrates_supervisor_role_capabilities(): void {
		$this->stub_activation_deps( array( 'dbDelta', 'get_option', 'update_option', 'get_role', 'get_users', 'get_userdata', 'delete_option' ) );

		Functions\expect( 'get_option' )
			->andReturnUsing(
				function ( string $key, $default = false ) {
					if ( $key === 'wpdsgvo_db_version' ) {
						return '0';
					}
					if ( $key === 'wpdsgvo_cap_migration_version' ) {
						return '0';
					}
					return $default;
				}
			);

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$admin_role = Mockery::mock( 'WP_Role' );
		$admin_role->shouldReceive( 'add_cap' );

		Functions\expect( 'get_role' )
			->with( 'administrator' )
			->andReturn( $admin_role );

		$grants = array();

		$supervisor_user = Mockery::mock( 'WP_User' );
		$supervisor_user->ID         = 20;
		$supervisor_user->user_login = 'supervisor1';
		$supervisor_user->shouldReceive( 'add_cap' )
			->andReturnUsing(
				function ( string $cap ) use ( &$grants ): void {
					$grants[] = array( 'user_id' => 20, 'cap' => $cap );
				}
			);

		Functions\expect( 'get_users' )
			->andReturnUsing(
				function ( array $args ) use ( $supervisor_user ): array {
					if ( $args['role'] === 'wp_dsgvo_form_supervisor' && $args['offset'] === 0 ) {
						return array( 20 );
					}
					return array();
				}
			);

		Functions\expect( 'get_userdata' )
			->andReturnUsing(
				function ( int $uid ) use ( $supervisor_user ) {
					return $uid === 20 ? $supervisor_user : false;
				}
			);

		// AuditLogger needs current_time + $wpdb->insert for audit log entries.
		Functions\when( 'current_time' )->justReturn( '2026-04-21 12:00:00' );

		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );
		$GLOBALS['wpdb']->shouldReceive( 'insert' )->andReturn( 1 );

		Activator::maybe_upgrade();

		$sup_caps = array_column(
			array_filter( $grants, fn( $g ) => $g['user_id'] === 20 ),
			'cap'
		);

		$this->assertContains( 'dsgvo_form_view_submissions', $sup_caps );
		$this->assertContains( 'dsgvo_form_view_all_submissions', $sup_caps );
		$this->assertContains( 'dsgvo_form_export', $sup_caps );
		$this->assertContains( 'dsgvo_form_recipient', $sup_caps );
		// SEC-ARCH-03: delete_submissions must NOT be migrated.
		$this->assertNotContains( 'dsgvo_form_delete_submissions', $sup_caps );
	}

	/**
	 * @test
	 * Recipients table has access_level and role_justification columns.
	 */
	public function test_recipients_table_has_access_level_and_justification_columns(): void {
		$this->stub_activation_deps( array( 'dbDelta' ) );

		$recipients_sql = '';

		Functions\expect( 'dbDelta' )
			->andReturnUsing(
				function ( string $sql ) use ( &$recipients_sql ): array {
					if ( str_contains( $sql, 'form_recipients' ) ) {
						$recipients_sql = $sql;
					}
					return array();
				}
			);

		Activator::activate();

		$this->assertStringContainsString( 'access_level', $recipients_sql );
		$this->assertStringContainsString( "'reader'", $recipients_sql );
		$this->assertStringContainsString( 'role_justification', $recipients_sql );
	}
}
