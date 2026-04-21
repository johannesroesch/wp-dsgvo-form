<?php
/**
 * Unit tests for AccessControl class.
 *
 * @package WpDsgvoForm\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Auth;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for authorization checks (SEC-AUTH-03, SEC-AUTH-10, SEC-AUTH-14).
 */
class AccessControlTest extends TestCase {

	/**
	 * Helper: mock user_can to return results based on a capability map.
	 *
	 * @param array<string, bool> $cap_map  Capability => result mapping.
	 */
	private function mock_user_can( array $cap_map ): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ) use ( $cap_map ): bool {
				return $cap_map[ $cap ] ?? false;
			}
		);
	}

	/**
	 * @test
	 * SEC-AUTH-03: Admin with dsgvo_form_manage can view any form.
	 */
	public function test_can_view_form_returns_true_for_admin(): void {
		$this->mock_user_can( array( 'dsgvo_form_manage' => true ) );

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_view_form( 1, 42 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-DSGVO-03: Supervisor can view all forms.
	 */
	public function test_can_view_form_returns_true_for_supervisor(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => true,
			)
		);

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_view_form( 2, 42 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-10: Reader can only view forms they are a recipient of.
	 */
	public function test_can_view_form_returns_true_for_recipient_reader(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => false,
				'dsgvo_form_view_submissions'    => true,
			)
		);

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );
		$GLOBALS['wpdb'] = $wpdb;

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_view_form( 3, 42 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-10: Reader denied access to forms they are NOT a recipient of.
	 */
	public function test_can_view_form_returns_false_for_non_recipient_reader(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => false,
				'dsgvo_form_view_submissions'    => true,
			)
		);

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$GLOBALS['wpdb'] = $wpdb;

		$ac = new AccessControl();

		$this->assertFalse( $ac->can_view_form( 3, 42 ) );
	}

	/**
	 * @test
	 * User without any capability is denied.
	 */
	public function test_can_view_form_returns_false_for_unauthorized_user(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => false,
				'dsgvo_form_view_submissions'    => false,
			)
		);

		$ac = new AccessControl();

		$this->assertFalse( $ac->can_view_form( 99, 42 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-14: IDOR — admin can view any submission.
	 */
	public function test_can_view_submission_returns_true_for_admin(): void {
		$this->mock_user_can( array( 'dsgvo_form_manage' => true ) );

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_view_submission( 1, 100 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-14: IDOR — reader can only view submissions of assigned forms.
	 */
	public function test_can_view_submission_checks_form_assignment_for_reader(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => false,
				'dsgvo_form_view_submissions'    => true,
			)
		);

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// First get_var: form_id for submission = 42.
		// Second get_var: is_recipient_of_form count = 1.
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42', '1' );
		$GLOBALS['wpdb'] = $wpdb;

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_view_submission( 3, 100 ) );
	}

	/**
	 * @test
	 * SEC-AUTH-14: IDOR — submission not found returns false.
	 */
	public function test_can_view_submission_returns_false_when_submission_not_found(): void {
		$this->mock_user_can(
			array(
				'dsgvo_form_manage'              => false,
				'dsgvo_form_view_all_submissions' => false,
				'dsgvo_form_view_submissions'    => true,
			)
		);

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$ac = new AccessControl();

		$this->assertFalse( $ac->can_view_submission( 3, 999 ) );
	}

	/**
	 * @test
	 * Only users with dsgvo_form_delete_submissions can delete.
	 */
	public function test_can_delete_submission_checks_capability(): void {
		$this->mock_user_can( array( 'dsgvo_form_delete_submissions' => true ) );

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_delete_submission( 1 ) );
	}

	/**
	 * @test
	 * Users without delete capability are denied.
	 */
	public function test_can_delete_submission_denied_without_capability(): void {
		$this->mock_user_can( array( 'dsgvo_form_delete_submissions' => false ) );

		$ac = new AccessControl();

		$this->assertFalse( $ac->can_delete_submission( 2 ) );
	}

	/**
	 * @test
	 * Only users with dsgvo_form_export can export.
	 */
	public function test_can_export_checks_capability(): void {
		$this->mock_user_can( array( 'dsgvo_form_export' => true ) );

		$ac = new AccessControl();

		$this->assertTrue( $ac->can_export( 1 ) );
	}

	/**
	 * @test
	 * has_plugin_role detects plugin roles via get_userdata.
	 */
	public function test_has_plugin_role_returns_true_for_plugin_user(): void {
		$user        = new \stdClass();
		$user->roles = array( 'wp_dsgvo_form_reader' );

		Functions\when( '_deprecated_function' )->justReturn();
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$ac = new AccessControl();

		$this->assertTrue( $ac->has_plugin_role( 5 ) );
	}

	/**
	 * @test
	 * has_plugin_role returns false for non-existent user.
	 */
	public function test_has_plugin_role_returns_false_for_unknown_user(): void {
		Functions\when( '_deprecated_function' )->justReturn();
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );

		$ac = new AccessControl();

		$this->assertFalse( $ac->has_plugin_role( 999 ) );
	}

	/**
	 * @test
	 * has_plugin_role returns false for regular WP user without plugin role.
	 */
	public function test_has_plugin_role_returns_false_for_regular_user(): void {
		$user        = new \stdClass();
		$user->roles = array( 'subscriber' );

		Functions\when( '_deprecated_function' )->justReturn();
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$ac = new AccessControl();

		$this->assertFalse( $ac->has_plugin_role( 10 ) );
	}

	/**
	 * @test
	 * is_supervisor checks dsgvo_form_view_all_submissions capability.
	 */
	public function test_is_supervisor_checks_correct_capability(): void {
		$this->mock_user_can( array( 'dsgvo_form_view_all_submissions' => true ) );

		$ac = new AccessControl();

		$this->assertTrue( $ac->is_supervisor( 2 ) );
	}

	/**
	 * @test
	 * PLUGIN_ROLES constant contains expected roles.
	 */
	public function test_plugin_roles_constant_contains_expected_roles(): void {
		$this->assertContains( 'wp_dsgvo_form_reader', AccessControl::PLUGIN_ROLES );
		$this->assertContains( 'wp_dsgvo_form_supervisor', AccessControl::PLUGIN_ROLES );
		$this->assertCount( 2, AccessControl::PLUGIN_ROLES );
	}

	// ------------------------------------------------------------------
	// RECIPIENT_CAPABILITY constant
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_recipient_capability_constant_value(): void {
		$this->assertSame( 'dsgvo_form_recipient', AccessControl::RECIPIENT_CAPABILITY );
	}

	// ------------------------------------------------------------------
	// has_plugin_access() — Dual-check: capability-based OR role-based
	// ------------------------------------------------------------------

	/**
	 * @test
	 * has_plugin_access returns true when user has dsgvo_form_recipient capability.
	 */
	public function test_has_plugin_access_returns_true_for_capability(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ): bool {
				return $cap === 'dsgvo_form_recipient';
			}
		);

		$ac = new AccessControl();

		$this->assertTrue( $ac->has_plugin_access( 5 ) );
	}

	/**
	 * @test
	 * has_plugin_access returns true for user with legacy plugin role but no capability.
	 */
	public function test_has_plugin_access_returns_true_for_legacy_role(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$user        = new \stdClass();
		$user->roles = array( 'wp_dsgvo_form_reader' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$ac = new AccessControl();

		$this->assertTrue( $ac->has_plugin_access( 5 ) );
	}

	/**
	 * @test
	 * has_plugin_access returns true for supervisor legacy role.
	 */
	public function test_has_plugin_access_returns_true_for_supervisor_legacy_role(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$user        = new \stdClass();
		$user->roles = array( 'wp_dsgvo_form_supervisor' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$ac = new AccessControl();

		$this->assertTrue( $ac->has_plugin_access( 5 ) );
	}

	/**
	 * @test
	 * has_plugin_access returns false for regular user without capability or role.
	 */
	public function test_has_plugin_access_returns_false_for_regular_user(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$user        = new \stdClass();
		$user->roles = array( 'subscriber' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$ac = new AccessControl();

		$this->assertFalse( $ac->has_plugin_access( 5 ) );
	}

	/**
	 * @test
	 * has_plugin_access returns false when user does not exist.
	 */
	public function test_has_plugin_access_returns_false_for_nonexistent_user(): void {
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );

		$ac = new AccessControl();

		$this->assertFalse( $ac->has_plugin_access( 999 ) );
	}

	/**
	 * @test
	 * has_plugin_access prefers capability check — does not query userdata if cap is present.
	 */
	public function test_has_plugin_access_short_circuits_on_capability(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ): bool {
				return $cap === 'dsgvo_form_recipient';
			}
		);

		// get_userdata should NOT be called.
		Functions\expect( 'get_userdata' )->never();

		$ac = new AccessControl();
		$ac->has_plugin_access( 5 );
	}

	// ------------------------------------------------------------------
	// has_plugin_role() — deprecated wrapper
	// ------------------------------------------------------------------

	/**
	 * @test
	 * has_plugin_role triggers _deprecated_function.
	 */
	public function test_has_plugin_role_triggers_deprecation_notice(): void {
		Functions\expect( '_deprecated_function' )
			->once()
			->with(
				\Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'has_plugin_role' );
				} ),
				'1.2.0',
				\Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'has_plugin_access' );
				} )
			);

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );

		$ac = new AccessControl();
		$ac->has_plugin_role( 1 );
	}

	/**
	 * @test
	 * has_plugin_role delegates to has_plugin_access.
	 */
	public function test_has_plugin_role_delegates_to_has_plugin_access(): void {
		Functions\when( '_deprecated_function' )->justReturn();

		// user_can with RECIPIENT_CAPABILITY returns true → has_plugin_access = true.
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ): bool {
				return $cap === 'dsgvo_form_recipient';
			}
		);

		$ac = new AccessControl();

		$this->assertTrue( $ac->has_plugin_role( 5 ) );
	}
}
