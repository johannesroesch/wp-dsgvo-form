<?php
/**
 * Unit tests for LoginRedirect class.
 *
 * @package WpDsgvoForm\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Auth;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Auth\LoginRedirect;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

// WordPress constants needed by LoginRedirect.
if ( ! defined( 'HOUR_IN_SECS' ) ) {
	define( 'HOUR_IN_SECS', 3600 );
}

/**
 * Tests for login redirect, cookie expiration, and admin isolation
 * (SEC-AUTH-06 through SEC-AUTH-12).
 *
 * WP_Screen, WP_User, WP_Admin_Bar stubs are in tests/stubs/wordpress.php.
 */
class LoginRedirectTest extends TestCase {

	private array $original_get    = array();
	private array $original_server = array();

	protected function setUp(): void {
		parent::setUp();
		$this->original_get    = $_GET;
		$this->original_server = $_SERVER;
	}

	protected function tearDown(): void {
		$_GET    = $this->original_get;
		$_SERVER = $this->original_server;
		parent::tearDown();
	}

	/**
	 * @test
	 * register_hooks adds expected filters and actions.
	 */
	public function test_register_hooks_adds_filters_and_actions(): void {
		Filters\expectAdded( 'login_redirect' )->once();
		Filters\expectAdded( 'auth_cookie_expiration' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_bar_menu' )->once();
		Actions\expectAdded( 'current_screen' )->once();

		$ac       = \Mockery::mock( AccessControl::class );
		$redirect = new LoginRedirect( $ac );
		$redirect->register_hooks();
	}

	/**
	 * @test
	 * SEC-AUTH-06: Plugin role user is redirected to submissions page.
	 */
	public function test_handle_login_redirect_redirects_plugin_role_user(): void {
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$user     = \Mockery::mock( 'WP_User' );
		$user->ID = 5;

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		Functions\when( 'user_can' )->justReturn( false );

		$redirect = new LoginRedirect( $ac );
		$result   = $redirect->handle_login_redirect( '/wp-admin/', '', $user );

		$this->assertStringContainsString( 'dsgvo-form-submissions', $result );
	}

	/**
	 * @test
	 * SEC-AUTH-06: Non-plugin user keeps original redirect.
	 */
	public function test_handle_login_redirect_keeps_default_for_regular_user(): void {
		$user     = \Mockery::mock( 'WP_User' );
		$user->ID = 10;

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 10 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 10 )->andReturn( false );

		Functions\when( 'user_can' )->justReturn( true );

		$redirect = new LoginRedirect( $ac );
		$result   = $redirect->handle_login_redirect( '/wp-admin/', '', $user );

		$this->assertSame( '/wp-admin/', $result );
	}

	/**
	 * @test
	 * SEC-AUTH-06: WP_Error during login does not redirect.
	 */
	public function test_handle_login_redirect_ignores_wp_error(): void {
		$error = new \WP_Error( 'invalid_username', 'Invalid username' );

		$ac       = \Mockery::mock( AccessControl::class );
		$redirect = new LoginRedirect( $ac );
		$result   = $redirect->handle_login_redirect( '/wp-admin/', '', $error );

		$this->assertSame( '/wp-admin/', $result );
	}

	/**
	 * @test
	 * SEC-AUTH-12: Plugin role gets reduced cookie expiration (2 hours).
	 */
	public function test_handle_cookie_expiration_reduces_for_plugin_role(): void {
		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		Functions\when( 'user_can' )->justReturn( false );

		$redirect = new LoginRedirect( $ac );
		$result   = $redirect->handle_cookie_expiration( 1209600, 5, true );

		$this->assertSame( 2 * HOUR_IN_SECS, $result );
	}

	/**
	 * @test
	 * SEC-AUTH-12: Regular user keeps default cookie expiration.
	 */
	public function test_handle_cookie_expiration_unchanged_for_regular_user(): void {
		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 10 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 10 )->andReturn( false );

		Functions\when( 'user_can' )->justReturn( true );

		$redirect   = new LoginRedirect( $ac );
		$default    = 1209600;
		$result     = $redirect->handle_cookie_expiration( $default, 10, false );

		$this->assertSame( $default, $result );
	}

	/**
	 * @test
	 * SEC-AUTH-07: restrict_admin_menu removes standard menus for plugin role.
	 */
	public function test_restrict_admin_menu_removes_pages_for_plugin_role(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		$removed_pages = array();
		Functions\when( 'remove_menu_page' )->alias(
			function ( string $slug ) use ( &$removed_pages ): void {
				$removed_pages[] = $slug;
			}
		);

		$redirect = new LoginRedirect( $ac );
		$redirect->restrict_admin_menu();

		$this->assertContains( 'index.php', $removed_pages );
		$this->assertContains( 'edit.php', $removed_pages );
		$this->assertContains( 'options-general.php', $removed_pages );
		$this->assertContains( 'plugins.php', $removed_pages );
	}

	/**
	 * @test
	 * SEC-AUTH-07: Admin user is not restricted.
	 */
	public function test_restrict_admin_menu_does_nothing_for_admin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 1 )->andReturn( true );

		Functions\expect( 'remove_menu_page' )->never();

		$redirect = new LoginRedirect( $ac );
		$redirect->restrict_admin_menu();
	}

	/**
	 * @test
	 * SEC-AUTH-08: restrict_admin_bar strips standard nodes for plugin role.
	 */
	public function test_restrict_admin_bar_removes_nodes_for_plugin_role(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		// Use Mockery to track remove_node calls.
		$admin_bar    = \Mockery::mock( 'WP_Admin_Bar' );
		$removed_ids  = array();
		$admin_bar->shouldReceive( 'remove_node' )
			->andReturnUsing(
				function ( string $id ) use ( &$removed_ids ): void {
					$removed_ids[] = $id;
				}
			);

		$redirect = new LoginRedirect( $ac );
		$redirect->restrict_admin_bar( $admin_bar );

		$this->assertContains( 'wp-logo', $removed_ids );
		$this->assertContains( 'site-name', $removed_ids );
		$this->assertContains( 'comments', $removed_ids );
		$this->assertContains( 'new-content', $removed_ids );
	}

	/**
	 * @test
	 * SEC-AUTH-09: Plugin page access is allowed for plugin role.
	 */
	public function test_block_unauthorized_access_allows_plugin_pages(): void {
		$_GET['page'] = 'dsgvo-form-submissions';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'user_can' )->justReturn( false );

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		$screen     = \WP_Screen::get( 'dsgvo-form-submissions' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$redirect = new LoginRedirect( $ac );
		$redirect->block_unauthorized_access( $screen );

		// No redirect = allowed.
		$this->assertTrue( true );
	}

	/**
	 * @test
	 * SEC-AUTH-09: Profile page is allowed for plugin role (password change).
	 */
	public function test_block_unauthorized_access_allows_profile_page(): void {
		$_GET = array();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		$screen = \WP_Screen::get( 'profile' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$redirect = new LoginRedirect( $ac );
		$redirect->block_unauthorized_access( $screen );

		$this->assertTrue( true );
	}

	/**
	 * @test
	 * SEC-AUTH-09: Non-plugin page triggers redirect for plugin role.
	 * Note: exit; in production code is simulated via RuntimeException.
	 */
	public function test_block_unauthorized_access_redirects_on_non_plugin_page(): void {
		$_GET = array();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'user_can' )->justReturn( false );

		$ac = \Mockery::mock( AccessControl::class );
		$ac->shouldReceive( 'is_admin' )->with( 5 )->andReturn( false );
		$ac->shouldReceive( 'has_plugin_access' )->with( 5 )->andReturn( true );

		$screen = \WP_Screen::get( 'dashboard' );

		$redirected_url = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ) use ( &$redirected_url ): void {
				$redirected_url = $url;
				// Throw to simulate exit; and prevent actual exit.
				throw new \RuntimeException( 'redirect' );
			}
		);

		try {
			$redirect = new LoginRedirect( $ac );
			$redirect->block_unauthorized_access( $screen );
			$this->fail( 'Expected redirect did not occur.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'dsgvo-form-submissions', $redirected_url );
	}
}
