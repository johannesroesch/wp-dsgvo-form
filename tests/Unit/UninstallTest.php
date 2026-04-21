<?php
/**
 * Unit tests for uninstall.php.
 *
 * Each test runs in a separate process because uninstall.php
 * checks for the WP_UNINSTALL_PLUGIN constant and executes
 * procedurally when included.
 *
 * @package WpDsgvoForm\Tests\Unit
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the plugin uninstall handler.
 */
class UninstallTest extends TestCase {

	/**
	 * Path to uninstall.php.
	 */
	private const UNINSTALL_FILE = __DIR__ . '/../../uninstall.php';

	/**
	 * Set up common mocks for uninstall execution.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 * @return object Mocked $wpdb.
	 */
	private function stub_uninstall_deps( array $skip = array() ): object {
		// Define the WordPress uninstall constant.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';

		$GLOBALS['wpdb'] = $wpdb;

		// Mock WP_Filesystem initialization (#259 AlternativeFunctions fix).
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$fs = \Mockery::mock( 'WP_Filesystem_Base' );
		$fs->shouldReceive( 'rmdir' )
			->andReturnUsing(
				function ( string $path ): bool {
					return is_dir( $path ) ? rmdir( $path ) : false;
				}
			);
		$GLOBALS['wp_filesystem'] = $fs;

		$defaults = array(
			'remove_role'              => null,
			'get_role'                 => null,
			'delete_option'            => true,
			'delete_metadata'          => true,
			'wp_upload_dir'            => array( 'basedir' => '/tmp/wp-uploads' ),
			'wp_delete_file'           => null,
			'wp_clear_scheduled_hook'  => 0,
			'get_users'                => array(),
		);

		foreach ( $defaults as $func => $return ) {
			if ( in_array( $func, $skip, true ) ) {
				continue;
			}
			if ( null === $return ) {
				Functions\when( $func )->justReturn( null );
			} else {
				Functions\when( $func )->justReturn( $return );
			}
		}

		return $wpdb;
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_drops_all_seven_tables_in_correct_order(): void {
		$wpdb = $this->stub_uninstall_deps();

		$tables_dropped = array();

		$wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( string $sql ) use ( &$tables_dropped ): int {
					if ( preg_match( '/DROP TABLE IF EXISTS (\S+)/', $sql, $matches ) ) {
						$tables_dropped[] = $matches[1];
					}
					return 1;
				}
			);

		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		require self::UNINSTALL_FILE;

		$expected_tables = array(
			'wp_dsgvo_audit_log',
			'wp_dsgvo_submission_files',
			'wp_dsgvo_form_recipients',
			'wp_dsgvo_submissions',
			'wp_dsgvo_consent_versions',
			'wp_dsgvo_fields',
			'wp_dsgvo_forms',
		);

		$this->assertSame( $expected_tables, $tables_dropped, 'Tables must be dropped child-first.' );
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_removes_custom_roles(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'remove_role' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		$roles_removed = array();

		Functions\expect( 'remove_role' )
			->twice()
			->andReturnUsing(
				function ( string $role ) use ( &$roles_removed ): void {
					$roles_removed[] = $role;
				}
			);

		require self::UNINSTALL_FILE;

		$this->assertContains( 'wp_dsgvo_form_reader', $roles_removed );
		$this->assertContains( 'wp_dsgvo_form_supervisor', $roles_removed );
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_removes_admin_capabilities(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'get_role' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		$removed_caps = array();
		$admin_role   = \Mockery::mock( 'WP_Role' );
		$admin_role->shouldReceive( 'remove_cap' )
			->andReturnUsing(
				function ( string $cap ) use ( &$removed_caps ): void {
					$removed_caps[] = $cap;
				}
			);

		Functions\expect( 'get_role' )
			->with( 'administrator' )
			->andReturn( $admin_role );

		require self::UNINSTALL_FILE;

		$expected_caps = array(
			'dsgvo_form_manage',
			'dsgvo_form_view_submissions',
			'dsgvo_form_view_all_submissions',
			'dsgvo_form_delete_submissions',
			'dsgvo_form_export',
		);

		foreach ( $expected_caps as $cap ) {
			$this->assertContains( $cap, $removed_caps, "Admin cap {$cap} was not removed." );
		}
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_deletes_plugin_options(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'delete_option' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		$deleted_options = array();

		Functions\expect( 'delete_option' )
			->andReturnUsing(
				function ( string $option ) use ( &$deleted_options ): bool {
					$deleted_options[] = $option;
					return true;
				}
			);

		require self::UNINSTALL_FILE;

		$expected_options = array(
			'wpdsgvo_version',
			'wpdsgvo_db_version',
			'wpdsgvo_captcha_secret',
			'wpdsgvo_default_retention_days',
			'wpdsgvo_controller_name',
			'wpdsgvo_controller_email',
		);

		foreach ( $expected_options as $option ) {
			$this->assertContains( $option, $deleted_options, "Option {$option} was not deleted." );
		}
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_clears_cron_events(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'wp_clear_scheduled_hook' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'dsgvo_form_cleanup' )
			->andReturn( 1 );

		require self::UNINSTALL_FILE;
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_deletes_transients(): void {
		$wpdb = $this->stub_uninstall_deps();

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$transient_query_executed = false;

		$wpdb->shouldReceive( 'esc_like' )
			->andReturnUsing(
				function ( string $text ): string {
					return $text;
				}
			);

		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $query, string ...$args ) use ( &$transient_query_executed ): string {
					if ( str_contains( $query, 'DELETE FROM' ) && str_contains( $args[0] ?? '', '_transient_dsgvo_' ) ) {
						$transient_query_executed = true;
					}
					return $query;
				}
			);

		require self::UNINSTALL_FILE;

		$this->assertTrue( $transient_query_executed, 'Transient cleanup query was not executed.' );
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_removes_upload_directory(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'wp_upload_dir', 'wp_delete_file' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		// Create a temporary upload directory structure.
		$upload_base = sys_get_temp_dir() . '/wp-test-uploads-' . uniqid();
		$dsgvo_dir   = $upload_base . '/dsgvo-form-files';
		$sub_dir     = $dsgvo_dir . '/submissions';

		mkdir( $sub_dir, 0755, true );
		file_put_contents( $dsgvo_dir . '/test-file.enc', 'encrypted-content' );
		file_put_contents( $sub_dir . '/upload.enc', 'encrypted-upload' );

		Functions\expect( 'wp_upload_dir' )
			->andReturn( array( 'basedir' => $upload_base ) );

		$deleted_files = array();

		Functions\expect( 'wp_delete_file' )
			->andReturnUsing(
				function ( string $path ) use ( &$deleted_files ): void {
					$deleted_files[] = $path;
					if ( file_exists( $path ) ) {
						unlink( $path );
					}
				}
			);

		require self::UNINSTALL_FILE;

		$this->assertNotEmpty( $deleted_files, 'Files should have been deleted.' );
		$this->assertFalse( is_dir( $dsgvo_dir ), 'Upload directory should be removed.' );

		// Cleanup.
		if ( is_dir( $upload_base ) ) {
			rmdir( $upload_base );
		}
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_skips_upload_removal_when_no_directory(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'wp_upload_dir', 'wp_delete_file' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		Functions\expect( 'wp_upload_dir' )
			->andReturn( array( 'basedir' => '/nonexistent/path' ) );

		Functions\expect( 'wp_delete_file' )->never();

		require self::UNINSTALL_FILE;
	}

	/**
	 * @test
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_deletes_privacy_notice_user_meta(): void {
		$wpdb = $this->stub_uninstall_deps( array( 'delete_metadata' ) );

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		$metadata_calls = array();

		Functions\expect( 'delete_metadata' )
			->andReturnUsing(
				function ( string $type, int $object_id, string $meta_key, $meta_value, bool $delete_all ) use ( &$metadata_calls ): bool {
					$metadata_calls[] = array(
						'type'       => $type,
						'object_id'  => $object_id,
						'meta_key'   => $meta_key,
						'delete_all' => $delete_all,
					);
					return true;
				}
			);

		require self::UNINSTALL_FILE;

		$this->assertCount( 3, $metadata_calls, 'delete_metadata should be called three times.' );

		// Privacy notice acknowledgment (UX-REC-02).
		$this->assertSame( 'user', $metadata_calls[0]['type'] );
		$this->assertSame( 0, $metadata_calls[0]['object_id'] );
		$this->assertSame( 'wpdsgvo_privacy_notice_ack', $metadata_calls[0]['meta_key'] );
		$this->assertTrue( $metadata_calls[0]['delete_all'], 'delete_all must be true to remove for all users.' );

		// DSFA notice dismissed.
		$this->assertSame( 'user', $metadata_calls[1]['type'] );
		$this->assertSame( 'wpdsgvo_dsfa_notice_dismissed', $metadata_calls[1]['meta_key'] );
		$this->assertTrue( $metadata_calls[1]['delete_all'] );

		// Capability migration notice dismissed (KANN-ARCH-01).
		$this->assertSame( 'user', $metadata_calls[2]['type'] );
		$this->assertSame( 'wpdsgvo_cap_migration_notice_dismissed', $metadata_calls[2]['meta_key'] );
		$this->assertTrue( $metadata_calls[2]['delete_all'] );
	}
}
