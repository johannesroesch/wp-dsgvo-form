<?php
/**
 * Unit tests for RecipientListPage.
 *
 * DPO-SOLL-F06: Auto capability grant/revoke.
 * DPO-MUSS-F16: Revoke only when 0 remaining assignments.
 * PERF: User-Limit 200 in get_eligible_users().
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\RecipientListPage;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Auth\CapabilityManager;
use WpDsgvoForm\Models\Recipient;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for RecipientListPage — grant/revoke logic, eligible users, badges.
 */
class RecipientListPageTest extends TestCase {

	/**
	 * Mock CapabilityManager.
	 *
	 * @var \Mockery\MockInterface&CapabilityManager
	 */
	private $cap_manager;

	/**
	 * Mock AuditLogger.
	 *
	 * @var \Mockery\MockInterface&AuditLogger
	 */
	private $audit_logger;

	/**
	 * RecipientListPage under test.
	 *
	 * @var RecipientListPage
	 */
	private RecipientListPage $page;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $value ): int {
				return abs( (int) $value );
			}
		);

		$this->cap_manager  = Mockery::mock( CapabilityManager::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );
		$this->page         = new RecipientListPage( $this->cap_manager, $this->audit_logger );
	}

	protected function tearDown(): void {
		unset( $_GET['do'], $_GET['recipient_id'], $_GET['page'], $_GET['form_id'] );
		unset( $_POST['dsgvo_action'], $_POST['form_id'], $_POST['user_id'] );
		unset( $_POST['access_level'], $_POST['role_justification'], $_POST['notify_email'] );
		parent::tearDown();
	}

	/**
	 * Invoke a private/protected method via Reflection.
	 *
	 * @param object $object     The object instance.
	 * @param string $method     Method name.
	 * @param array  $args       Arguments to pass.
	 * @return mixed
	 */
	private function invoke_private( object $object, string $method, array $args = [] ) {
		$reflection = new \ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $object, ...$args );
	}

	/**
	 * Set a private property on an object.
	 *
	 * @param object $object   The object instance.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( $object, $value );
	}

	// ──────────────────────────────────────────────────
	// grant_capabilities_for_level — auto-grant
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * DPO-SOLL-F06: Reader gets view_submissions + recipient capabilities.
	 */
	public function test_grant_capabilities_for_reader_level(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$granted = [];

		$this->cap_manager->shouldReceive( 'grant' )
			->andReturnUsing(
				function ( int $user_id, string $cap, int $by, string $ctx ) use ( &$granted ): void {
					$granted[] = [ 'user' => $user_id, 'cap' => $cap, 'ctx' => $ctx ];
				}
			);

		$this->invoke_private( $this->page, 'grant_capabilities_for_level', [ 10, Recipient::ACCESS_LEVEL_READER ] );

		$caps = array_column( $granted, 'cap' );

		$this->assertContains( 'dsgvo_form_view_submissions', $caps );
		$this->assertContains( AccessControl::RECIPIENT_CAPABILITY, $caps );
		$this->assertNotContains( 'dsgvo_form_view_all_submissions', $caps );
		$this->assertNotContains( 'dsgvo_form_export', $caps );

		// All grants use auto_grant context.
		foreach ( $granted as $g ) {
			$this->assertSame( 'auto_grant', $g['ctx'] );
		}
	}

	/**
	 * @test
	 * DPO-SOLL-F06: Supervisor gets additional view_all + export capabilities.
	 */
	public function test_grant_capabilities_for_supervisor_level(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$granted = [];

		$this->cap_manager->shouldReceive( 'grant' )
			->andReturnUsing(
				function ( int $user_id, string $cap, int $by, string $ctx ) use ( &$granted ): void {
					$granted[] = [ 'user' => $user_id, 'cap' => $cap, 'ctx' => $ctx ];
				}
			);

		$this->invoke_private( $this->page, 'grant_capabilities_for_level', [ 10, Recipient::ACCESS_LEVEL_SUPERVISOR ] );

		$caps = array_column( $granted, 'cap' );

		$this->assertContains( 'dsgvo_form_view_submissions', $caps );
		$this->assertContains( AccessControl::RECIPIENT_CAPABILITY, $caps );
		$this->assertContains( 'dsgvo_form_view_all_submissions', $caps );
		$this->assertContains( 'dsgvo_form_export', $caps );
	}

	// ──────────────────────────────────────────────────
	// revoke_all_plugin_capabilities — DPO-MUSS-F16
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * DPO-MUSS-F16: All plugin caps revoked when user has no remaining assignments.
	 */
	public function test_revoke_all_plugin_capabilities_revokes_all(): void {
		$revoked = [];

		// user_can returns true for all caps → all should be revoked.
		Functions\when( 'user_can' )->justReturn( true );

		$this->cap_manager->shouldReceive( 'revoke' )
			->andReturnUsing(
				function ( int $user_id, string $cap, int $by, string $ctx ) use ( &$revoked ): void {
					$revoked[] = [ 'user' => $user_id, 'cap' => $cap, 'ctx' => $ctx ];
				}
			);

		$this->invoke_private( $this->page, 'revoke_all_plugin_capabilities', [ 10, 1 ] );

		$caps = array_column( $revoked, 'cap' );

		$this->assertContains( 'dsgvo_form_view_submissions', $caps );
		$this->assertContains( 'dsgvo_form_view_all_submissions', $caps );
		$this->assertContains( 'dsgvo_form_export', $caps );
		$this->assertContains( AccessControl::RECIPIENT_CAPABILITY, $caps );

		// All revocations use auto_revoke context.
		foreach ( $revoked as $r ) {
			$this->assertSame( 'auto_revoke', $r['ctx'] );
		}
	}

	/**
	 * @test
	 * DPO-MUSS-F16: Only caps the user actually has are revoked.
	 */
	public function test_revoke_all_skips_caps_user_does_not_have(): void {
		$revoked = [];

		// user_can only returns true for view_submissions.
		Functions\expect( 'user_can' )
			->andReturnUsing(
				function ( int $user_id, string $cap ): bool {
					return $cap === 'dsgvo_form_view_submissions';
				}
			);

		$this->cap_manager->shouldReceive( 'revoke' )
			->andReturnUsing(
				function ( int $user_id, string $cap, int $by, string $ctx ) use ( &$revoked ): void {
					$revoked[] = $cap;
				}
			);

		$this->invoke_private( $this->page, 'revoke_all_plugin_capabilities', [ 10, 1 ] );

		$this->assertSame( [ 'dsgvo_form_view_submissions' ], $revoked );
	}

	// ──────────────────────────────────────────────────
	// maybe_downgrade_supervisor
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Supervisor downgrade: removes supervisor caps when no supervisor assignment remains.
	 */
	public function test_maybe_downgrade_removes_supervisor_caps(): void {
		// Remaining assignments are all reader-level.
		$reader_recipient               = new Recipient();
		$reader_recipient->access_level = 'reader';

		// Mock Recipient::find_by_user_id to return reader-only.
		Functions\expect( 'user_can' )->andReturn( true );

		$revoked = [];
		$this->cap_manager->shouldReceive( 'revoke' )
			->andReturnUsing(
				function ( int $user_id, string $cap, int $by, string $ctx ) use ( &$revoked ): void {
					$revoked[] = $cap;
				}
			);

		// We need to mock the static Recipient::find_by_user_id.
		// Since it's a static method, we use Brain\Monkey to intercept the $wpdb calls.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id'                 => 5,
					'form_id'            => 2,
					'user_id'            => 10,
					'notify_email'       => 1,
					'access_level'       => 'reader',
					'role_justification' => '',
					'created_at'         => '2026-04-21 10:00:00',
				],
			] );
		$GLOBALS['wpdb'] = $wpdb;

		$this->invoke_private( $this->page, 'maybe_downgrade_supervisor', [ 10, 1 ] );

		$this->assertContains( 'dsgvo_form_view_all_submissions', $revoked );
		$this->assertContains( 'dsgvo_form_export', $revoked );
	}

	/**
	 * @test
	 * No downgrade when supervisor assignment still exists.
	 */
	public function test_maybe_downgrade_keeps_caps_when_supervisor_remains(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id'                 => 5,
					'form_id'            => 2,
					'user_id'            => 10,
					'notify_email'       => 1,
					'access_level'       => 'supervisor',
					'role_justification' => 'DSB',
					'created_at'         => '2026-04-21 10:00:00',
				],
			] );
		$GLOBALS['wpdb'] = $wpdb;

		// revoke should NOT be called.
		$this->cap_manager->shouldNotReceive( 'revoke' );

		$this->invoke_private( $this->page, 'maybe_downgrade_supervisor', [ 10, 1 ] );
	}

	// ──────────────────────────────────────────────────
	// get_eligible_users — PERF + read cap filter
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * PERF: get_eligible_users limits to 200 users.
	 */
	public function test_get_eligible_users_limits_to_200(): void {
		$this->set_private_property( $this->page, 'selected_form_id', 1 );

		// Mock Recipient::find_by_form_id via $wpdb.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$captured_args = null;

		Functions\expect( 'get_users' )
			->once()
			->andReturnUsing(
				function ( array $args ) use ( &$captured_args ): array {
					$captured_args = $args;
					return [];
				}
			);

		$this->invoke_private( $this->page, 'get_eligible_users' );

		$this->assertSame( 200, $captured_args['number'] );
	}

	/**
	 * @test
	 * get_eligible_users filters by read capability.
	 */
	public function test_get_eligible_users_filters_by_read_cap(): void {
		$this->set_private_property( $this->page, 'selected_form_id', 1 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] ); // No existing recipients.
		$GLOBALS['wpdb'] = $wpdb;

		$user_with_read = Mockery::mock( 'WP_User' );
		$user_with_read->ID = 10;
		$user_with_read->shouldReceive( 'has_cap' )->with( 'read' )->andReturn( true );

		$user_without_read = Mockery::mock( 'WP_User' );
		$user_without_read->ID = 20;
		$user_without_read->shouldReceive( 'has_cap' )->with( 'read' )->andReturn( false );

		Functions\when( 'get_users' )->justReturn( [ $user_with_read, $user_without_read ] );

		$result = $this->invoke_private( $this->page, 'get_eligible_users' );

		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]->ID );
	}

	/**
	 * @test
	 * get_eligible_users excludes already-assigned users.
	 */
	public function test_get_eligible_users_excludes_assigned(): void {
		$this->set_private_property( $this->page, 'selected_form_id', 1 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// Return one existing recipient with user_id=10.
		$wpdb->shouldReceive( 'get_results' )->andReturn( [
			[
				'id'                 => 1,
				'form_id'            => 1,
				'user_id'            => 10,
				'notify_email'       => 1,
				'access_level'       => 'reader',
				'role_justification' => '',
				'created_at'         => '2026-04-21 10:00:00',
			],
		] );
		$GLOBALS['wpdb'] = $wpdb;

		$user_assigned = Mockery::mock( 'WP_User' );
		$user_assigned->ID = 10;

		$user_available = Mockery::mock( 'WP_User' );
		$user_available->ID = 20;
		$user_available->shouldReceive( 'has_cap' )->with( 'read' )->andReturn( true );

		Functions\when( 'get_users' )->justReturn( [ $user_assigned, $user_available ] );

		$result = $this->invoke_private( $this->page, 'get_eligible_users' );

		$ids = array_map( fn( $u ) => $u->ID, $result );
		$this->assertNotContains( 10, $ids );
		$this->assertContains( 20, $ids );
	}

	/**
	 * @test
	 * get_eligible_users returns empty when no form selected.
	 */
	public function test_get_eligible_users_returns_empty_without_form(): void {
		$this->set_private_property( $this->page, 'selected_form_id', 0 );

		$result = $this->invoke_private( $this->page, 'get_eligible_users' );

		$this->assertSame( [], $result );
	}

	// ──────────────────────────────────────────────────
	// render_access_level_badge
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Supervisor badge contains "Erweitert" text.
	 */
	public function test_access_level_badge_supervisor(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$badge = $this->invoke_private( $this->page, 'render_access_level_badge', [ 'supervisor' ] );

		$this->assertStringContainsString( 'Erweitert', $badge );
		$this->assertStringContainsString( '#1a56db', $badge );
	}

	/**
	 * @test
	 * Reader badge contains "Eingeschraenkt" text.
	 */
	public function test_access_level_badge_reader(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$badge = $this->invoke_private( $this->page, 'render_access_level_badge', [ 'reader' ] );

		$this->assertStringContainsString( 'Eingeschraenkt', $badge );
		$this->assertStringContainsString( '#b45309', $badge );
	}

	// ──────────────────────────────────────────────────
	// handle_dismiss_migration_notice
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Dismiss notice stores user meta and requires dsgvo_form_manage cap.
	 */
	public function test_dismiss_migration_notice_stores_user_meta(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$meta_updated = false;
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 5, 'wpdsgvo_cap_migration_notice_dismissed', 1 )
			->andReturnUsing(
				function () use ( &$meta_updated ): bool {
					$meta_updated = true;
					return true;
				}
			);

		Functions\expect( 'wp_die' )
			->once()
			->with()
			->andReturn( null );

		RecipientListPage::handle_dismiss_migration_notice();

		$this->assertTrue( $meta_updated );
	}

	// ──────────────────────────────────────────────────
	// Constructor — dependency injection
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Constructor accepts CapabilityManager and AuditLogger.
	 */
	public function test_constructor_accepts_dependencies(): void {
		$page = new RecipientListPage( $this->cap_manager, $this->audit_logger );

		$this->assertInstanceOf( RecipientListPage::class, $page );
	}
}
