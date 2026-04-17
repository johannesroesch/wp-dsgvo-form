<?php
/**
 * Integration tests for LoginRedirect + AccessControl + WP Roles.
 *
 * Unlike the unit tests in LoginRedirectTest which mock AccessControl,
 * these use a REAL AccessControl instance to verify the full interaction
 * chain: LoginRedirect -> AccessControl -> WP functions (user_can, get_userdata).
 *
 * @package WpDsgvoForm\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Auth;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Auth\LoginRedirect;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

// Already defined in LoginRedirectTest.php, but guard anyway.
if ( ! defined( 'HOUR_IN_SECS' ) ) {
	define( 'HOUR_IN_SECS', 3600 );
}

/**
 * Integration tests: LoginRedirect x AccessControl x WP Roles.
 *
 * Verifies the full interaction chain with real (unmocked) AccessControl.
 *
 * Security requirements: SEC-AUTH-06, SEC-AUTH-08, SEC-AUTH-09, SEC-AUTH-12, SEC-AUTH-14.
 */
class LoginRedirectIntegrationTest extends TestCase {

	private AccessControl $access_control;
	private LoginRedirect $redirect;
	private array $original_get = [];

	protected function setUp(): void {
		parent::setUp();

		// REAL AccessControl — not mocked. This is the integration aspect.
		$this->access_control = new AccessControl();
		$this->redirect       = new LoginRedirect( $this->access_control );
		$this->original_get   = $_GET;

		// Common mock: admin_url (used by get_submissions_url).
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers — simulate WP role system via real AccessControl
	// ------------------------------------------------------------------

	/**
	 * Mocks WP functions to simulate a reader user.
	 * AccessControl will call get_userdata() and user_can() on this user.
	 */
	private function setup_reader_user( int $user_id ): \WP_User {
		$user        = new \WP_User( $user_id );
		$user->roles = [ 'wp_dsgvo_form_reader' ];

		Functions\when( 'get_userdata' )->alias(
			function ( int $id ) use ( $user, $user_id ) {
				return $id === $user_id ? $user : false;
			}
		);

		Functions\when( 'user_can' )->alias(
			function ( int $id, string $cap ) use ( $user_id ): bool {
				if ( $id !== $user_id ) {
					return false;
				}
				return $cap === 'dsgvo_form_view_submissions';
			}
		);

		return $user;
	}

	/**
	 * Mocks WP functions to simulate a supervisor user.
	 */
	private function setup_supervisor_user( int $user_id ): \WP_User {
		$user        = new \WP_User( $user_id );
		$user->roles = [ 'wp_dsgvo_form_supervisor' ];

		Functions\when( 'get_userdata' )->alias(
			function ( int $id ) use ( $user, $user_id ) {
				return $id === $user_id ? $user : false;
			}
		);

		Functions\when( 'user_can' )->alias(
			function ( int $id, string $cap ) use ( $user_id ): bool {
				if ( $id !== $user_id ) {
					return false;
				}
				return in_array(
					$cap,
					[ 'dsgvo_form_view_all_submissions', 'dsgvo_form_view_submissions' ],
					true
				);
			}
		);

		return $user;
	}

	/**
	 * Mocks WP functions to simulate a regular (non-plugin) user.
	 */
	private function setup_regular_user( int $user_id ): \WP_User {
		$user        = new \WP_User( $user_id );
		$user->roles = [ 'subscriber' ];

		Functions\when( 'get_userdata' )->alias(
			function ( int $id ) use ( $user, $user_id ) {
				return $id === $user_id ? $user : false;
			}
		);

		Functions\when( 'user_can' )->alias(
			function (): bool {
				return false;
			}
		);

		return $user;
	}

	/**
	 * Mocks WP functions to simulate an admin user with dsgvo_form_manage.
	 * Admin may also carry a plugin role — is_admin() takes precedence.
	 */
	private function setup_admin_user( int $user_id ): \WP_User {
		$user        = new \WP_User( $user_id );
		$user->roles = [ 'administrator', 'wp_dsgvo_form_reader' ];

		Functions\when( 'get_userdata' )->alias(
			function ( int $id ) use ( $user, $user_id ) {
				return $id === $user_id ? $user : false;
			}
		);

		Functions\when( 'user_can' )->alias(
			function ( int $id, string $cap ) use ( $user_id ): bool {
				if ( $id !== $user_id ) {
					return false;
				}
				// Admin has all plugin capabilities.
				return true;
			}
		);

		return $user;
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-06: Reader login -> submissions redirect
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-06 — Reader is redirected to submissions page after login.
	 */
	public function test_reader_login_redirects_to_submissions_page(): void {
		$user = $this->setup_reader_user( 20 );

		$result = $this->redirect->handle_login_redirect( '/wp-admin/', '', $user );

		$this->assertStringContainsString( 'dsgvo-form-submissions', $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-06: Supervisor login -> submissions redirect
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-06 — Supervisor is redirected to submissions page after login.
	 */
	public function test_supervisor_login_redirects_to_submissions_page(): void {
		$user = $this->setup_supervisor_user( 30 );

		$result = $this->redirect->handle_login_redirect( '/wp-admin/', '', $user );

		$this->assertStringContainsString( 'dsgvo-form-submissions', $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-06: Non-plugin user keeps default redirect
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_non_plugin_user_keeps_default_redirect(): void {
		$user = $this->setup_regular_user( 40 );

		$result = $this->redirect->handle_login_redirect( '/wp-admin/', '', $user );

		$this->assertSame( '/wp-admin/', $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-06: WP_Error during login — no redirect
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_login_error_passes_through_without_redirect(): void {
		$error = new \WP_Error( 'invalid_username', 'Invalid username.' );

		$result = $this->redirect->handle_login_redirect( '/wp-admin/', '', $error );

		$this->assertSame( '/wp-admin/', $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-12: Reader cookie expiration reduced to 2h
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-12 — Reader gets reduced cookie lifetime (2 hours).
	 */
	public function test_reader_cookie_expiration_reduced_to_two_hours(): void {
		$this->setup_reader_user( 20 );

		$result = $this->redirect->handle_cookie_expiration( 1209600, 20, true );

		$this->assertSame( 2 * HOUR_IN_SECS, $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-12: Supervisor cookie expiration reduced to 2h
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-12 — Supervisor gets reduced cookie lifetime (2 hours).
	 */
	public function test_supervisor_cookie_expiration_reduced_to_two_hours(): void {
		$this->setup_supervisor_user( 30 );

		$result = $this->redirect->handle_cookie_expiration( 1209600, 30, true );

		$this->assertSame( 2 * HOUR_IN_SECS, $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-12: Regular user cookie expiration unchanged
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_regular_user_cookie_expiration_unchanged(): void {
		$this->setup_regular_user( 40 );

		$default = 1209600;
		$result  = $this->redirect->handle_cookie_expiration( $default, 40, false );

		$this->assertSame( $default, $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-07: Reader admin menu restricted (real AccessControl)
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-07 — Reader sees no standard WP admin menus.
	 */
	public function test_reader_admin_menu_restricted(): void {
		$this->setup_reader_user( 20 );
		Functions\when( 'get_current_user_id' )->justReturn( 20 );

		$removed = [];
		Functions\when( 'remove_menu_page' )->alias(
			function ( string $slug ) use ( &$removed ): void {
				$removed[] = $slug;
			}
		);

		$this->redirect->restrict_admin_menu();

		$this->assertContains( 'index.php', $removed );
		$this->assertContains( 'edit.php', $removed );
		$this->assertContains( 'plugins.php', $removed );
		$this->assertContains( 'options-general.php', $removed );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-07: Admin with plugin role NOT restricted
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-07 — Admin user is never restricted, even with plugin role.
	 */
	public function test_admin_user_menu_not_restricted_despite_plugin_role(): void {
		$this->setup_admin_user( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\expect( 'remove_menu_page' )->never();

		$this->redirect->restrict_admin_menu();

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-08: Reader admin bar stripped to essentials
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-08 — Reader admin bar shows only essential items.
	 */
	public function test_reader_admin_bar_stripped_to_essentials(): void {
		$this->setup_reader_user( 20 );
		Functions\when( 'get_current_user_id' )->justReturn( 20 );

		$admin_bar = new \WP_Admin_Bar();
		$admin_bar->add_node( [ 'id' => 'wp-logo' ] );
		$admin_bar->add_node( [ 'id' => 'site-name' ] );
		$admin_bar->add_node( [ 'id' => 'comments' ] );
		$admin_bar->add_node( [ 'id' => 'new-content' ] );
		$admin_bar->add_node( [ 'id' => 'my-account' ] ); // Should survive.

		$this->redirect->restrict_admin_bar( $admin_bar );

		$this->assertNull( $admin_bar->get_node( 'wp-logo' ) );
		$this->assertNull( $admin_bar->get_node( 'site-name' ) );
		$this->assertNull( $admin_bar->get_node( 'comments' ) );
		$this->assertNull( $admin_bar->get_node( 'new-content' ) );
		$this->assertNotNull( $admin_bar->get_node( 'my-account' ) );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-09: Reader blocked from WP dashboard
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-09 — Reader is redirected when accessing the dashboard.
	 */
	public function test_reader_blocked_from_dashboard_redirects_to_submissions(): void {
		$this->setup_reader_user( 20 );
		Functions\when( 'get_current_user_id' )->justReturn( 20 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET = [];

		$screen = \WP_Screen::get( 'dashboard' );

		$redirected_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ) use ( &$redirected_url ): void {
				$redirected_url = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		try {
			$this->redirect->block_unauthorized_access( $screen );
			$this->fail( 'Expected redirect did not occur.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'dsgvo-form-submissions', $redirected_url );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-09: Reader allowed on plugin submissions page
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-09 — Reader can access plugin pages.
	 */
	public function test_reader_allowed_on_plugin_submissions_page(): void {
		$this->setup_reader_user( 20 );
		Functions\when( 'get_current_user_id' )->justReturn( 20 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET = [ 'page' => 'dsgvo-form-submissions' ];

		$screen = \WP_Screen::get( 'dsgvo-form-submissions' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$this->redirect->block_unauthorized_access( $screen );

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-09: Non-logged-in user not restricted by LoginRedirect
	// ------------------------------------------------------------------

	/**
	 * @test
	 * LoginRedirect does not restrict non-logged-in users
	 * (WordPress natively redirects them to wp-login.php).
	 */
	public function test_non_logged_in_user_not_restricted_by_login_redirect(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$_GET = [];

		$screen = \WP_Screen::get( 'dashboard' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$this->redirect->block_unauthorized_access( $screen );

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-14: Reader IDOR — denied for non-assigned form
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-14 — Reader cannot view submission from non-assigned form.
	 */
	public function test_reader_idor_denied_for_non_assigned_form(): void {
		$this->setup_reader_user( 20 );

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

		// get_form_id_for_submission: submission 50 belongs to form 10.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '10' );

		// is_recipient_of_form: reader 20 is NOT a recipient of form 10.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '0' );

		$GLOBALS['wpdb'] = $wpdb;

		$result = $this->access_control->can_view_submission( 20, 50 );

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-14: Reader IDOR — allowed for assigned form
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-14 — Reader can view submission from assigned form.
	 */
	public function test_reader_idor_allowed_for_assigned_form(): void {
		$this->setup_reader_user( 20 );

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

		// get_form_id_for_submission: submission 50 belongs to form 10.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '10' );

		// is_recipient_of_form: reader 20 IS a recipient of form 10.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '1' );

		$GLOBALS['wpdb'] = $wpdb;

		$result = $this->access_control->can_view_submission( 20, 50 );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-14: Supervisor bypasses IDOR check entirely
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-14 — Supervisor has full access without DB recipient check.
	 */
	public function test_supervisor_bypasses_idor_check(): void {
		$this->setup_supervisor_user( 30 );

		// No wpdb mock needed — supervisor gets access without DB queries.
		$result = $this->access_control->can_view_submission( 30, 50 );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// SEC-AUTH-14: Admin bypasses IDOR check entirely
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-AUTH-14 — Admin has full access without DB recipient check.
	 */
	public function test_admin_bypasses_idor_check(): void {
		$this->setup_admin_user( 1 );

		$result = $this->access_control->can_view_submission( 1, 50 );

		$this->assertTrue( $result );
	}
}
