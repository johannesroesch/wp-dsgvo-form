<?php
/**
 * Unit tests for RecipientPage.
 *
 * @package WpDsgvoForm\Tests\Unit\Recipient
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Recipient;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Recipient\RecipientPage;
use WpDsgvoForm\ServiceContainer;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Tests for RecipientPage: hook registration, query vars, admin bar hiding,
 * handle_request auth flow, static URL helpers, and A11Y skip-link output.
 */
class RecipientPageTest extends TestCase {

	private AccessControl $access_control;
	private ServiceContainer $container;
	private RecipientPage $page;

	protected function setUp(): void {
		parent::setUp();

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->container      = Mockery::mock( ServiceContainer::class );
		$this->page           = new RecipientPage( $this->access_control, $this->container );
	}

	// ------------------------------------------------------------------
	// register — hook registration
	// ------------------------------------------------------------------

	public function test_register_adds_init_action_for_rewrite_rules(): void {
		Actions\expectAdded( 'init' )
			->with( [ $this->page, 'register_rewrite_rules' ] )
			->once();

		Actions\expectAdded( 'init' )
			->with( [ $this->page, 'maybe_hide_admin_bar' ] )
			->once();

		Filters\expectAdded( 'query_vars' )
			->with( [ $this->page, 'register_query_vars' ] )
			->once();

		Actions\expectAdded( 'template_redirect' )
			->with( [ $this->page, 'handle_request' ] )
			->once();

		$this->page->register();
	}

	// ------------------------------------------------------------------
	// register_rewrite_rules
	// ------------------------------------------------------------------

	public function test_register_rewrite_rules_adds_two_rules(): void {
		$rules_added = [];

		Functions\when( 'add_rewrite_rule' )->alias(
			function ( string $regex, string $query, string $after ) use ( &$rules_added ): void {
				$rules_added[] = [
					'regex' => $regex,
					'query' => $query,
					'after' => $after,
				];
			}
		);

		$this->page->register_rewrite_rules();

		$this->assertCount( 2, $rules_added );

		// First rule: detail view /dsgvo-empfaenger/view/{id}.
		$this->assertStringContainsString( 'dsgvo-empfaenger/view', $rules_added[0]['regex'] );
		$this->assertStringContainsString( 'dsgvo_recipient_page=1', $rules_added[0]['query'] );
		$this->assertStringContainsString( 'dsgvo_recipient_action=view', $rules_added[0]['query'] );
		$this->assertStringContainsString( 'dsgvo_submission_id=$matches[1]', $rules_added[0]['query'] );
		$this->assertSame( 'top', $rules_added[0]['after'] );

		// Second rule: list view /dsgvo-empfaenger/.
		$this->assertStringContainsString( 'dsgvo-empfaenger', $rules_added[1]['regex'] );
		$this->assertStringContainsString( 'dsgvo_recipient_action=list', $rules_added[1]['query'] );
		$this->assertSame( 'top', $rules_added[1]['after'] );
	}

	// ------------------------------------------------------------------
	// register_query_vars
	// ------------------------------------------------------------------

	public function test_register_query_vars_adds_three_vars(): void {
		$result = $this->page->register_query_vars( [ 'existing_var' ] );

		$this->assertCount( 4, $result );
		$this->assertContains( 'existing_var', $result );
		$this->assertContains( 'dsgvo_recipient_page', $result );
		$this->assertContains( 'dsgvo_recipient_action', $result );
		$this->assertContains( 'dsgvo_submission_id', $result );
	}

	public function test_register_query_vars_appends_to_existing(): void {
		$existing = [ 'foo', 'bar' ];
		$result   = $this->page->register_query_vars( $existing );

		$this->assertCount( 5, $result );
		$this->assertSame( 'foo', $result[0] );
		$this->assertSame( 'bar', $result[1] );
	}

	// ------------------------------------------------------------------
	// maybe_hide_admin_bar
	// ------------------------------------------------------------------

	public function test_maybe_hide_admin_bar_does_nothing_when_not_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		// show_admin_bar should NOT be called.
		Functions\expect( 'show_admin_bar' )->never();

		$this->page->maybe_hide_admin_bar();
	}

	public function test_maybe_hide_admin_bar_hides_for_plugin_role_non_admin(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'user_can' )->justReturn( false );

		$this->access_control->shouldReceive( 'has_plugin_access' )
			->with( 42 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 42 )->andReturn( false );

		Functions\expect( 'show_admin_bar' )
			->once()
			->with( false );

		$this->page->maybe_hide_admin_bar();
	}

	public function test_maybe_hide_admin_bar_does_not_hide_for_admin(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->access_control->shouldReceive( 'has_plugin_access' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( true );

		Functions\expect( 'show_admin_bar' )->never();

		$this->page->maybe_hide_admin_bar();
	}

	public function test_maybe_hide_admin_bar_does_not_hide_for_non_plugin_role(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );

		$this->access_control->shouldReceive( 'has_plugin_access' )
			->with( 99 )->andReturn( false );

		Functions\expect( 'show_admin_bar' )->never();

		$this->page->maybe_hide_admin_bar();
	}

	// ------------------------------------------------------------------
	// handle_request — early exit when not our page
	// ------------------------------------------------------------------

	public function test_handle_request_returns_early_when_not_recipient_page(): void {
		Functions\when( 'get_query_var' )->alias(
			function ( string $var, $default = '' ) {
				if ( $var === 'dsgvo_recipient_page' ) {
					return '';
				}
				return $default;
			}
		);

		// No exit, no redirect — method just returns.
		$this->page->handle_request();

		// If we reach this assertion, the method returned early as expected.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// handle_request — redirect when not logged in
	// ------------------------------------------------------------------

	public function test_handle_request_redirects_when_not_logged_in(): void {
		Functions\when( 'get_query_var' )->alias(
			function ( string $var, $default = '' ) {
				if ( $var === 'dsgvo_recipient_page' ) {
					return '1';
				}
				return $default;
			}
		);

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/dsgvo-empfaenger/' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.com/wp-login.php?redirect_to=...' );

		// wp_safe_redirect is called before exit — use exception to simulate exit.
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function ( string $url ): void {
					throw new \RuntimeException( 'redirect:' . $url );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/redirect:/' );

		$this->page->handle_request();
	}

	// ------------------------------------------------------------------
	// handle_request — wp_die when no plugin role
	// ------------------------------------------------------------------

	public function test_handle_request_wp_die_when_no_access(): void {
		Functions\when( 'get_query_var' )->alias(
			function ( string $var, $default = '' ) {
				if ( $var === 'dsgvo_recipient_page' ) {
					return '1';
				}
				return $default;
			}
		);

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 50 );

		$this->access_control->shouldReceive( 'has_plugin_access' )
			->with( 50 )->andReturn( false );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 50 )->andReturn( false );

		Functions\expect( 'esc_html__' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				function ( string $message, string $title, array $args ): void {
					throw new \RuntimeException( 'wp_die:' . $args['response'] );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die:403' );

		$this->page->handle_request();
	}

	// ------------------------------------------------------------------
	// get_base_url — static helper
	// ------------------------------------------------------------------

	public function test_get_base_url_returns_correct_url(): void {
		Functions\when( 'home_url' )->alias(
			function ( string $path ): string {
				return 'https://example.com' . $path;
			}
		);

		$this->assertSame(
			'https://example.com/dsgvo-empfaenger/',
			RecipientPage::get_base_url()
		);
	}

	// ------------------------------------------------------------------
	// get_view_url — static helper
	// ------------------------------------------------------------------

	public function test_get_view_url_returns_correct_url(): void {
		Functions\when( 'home_url' )->alias(
			function ( string $path ): string {
				return 'https://example.com' . $path;
			}
		);

		$this->assertSame(
			'https://example.com/dsgvo-empfaenger/view/123/',
			RecipientPage::get_view_url( 123 )
		);
	}

	public function test_get_view_url_handles_zero_id(): void {
		Functions\when( 'home_url' )->alias(
			function ( string $path ): string {
				return 'https://example.com' . $path;
			}
		);

		$this->assertSame(
			'https://example.com/dsgvo-empfaenger/view/0/',
			RecipientPage::get_view_url( 0 )
		);
	}

	// ------------------------------------------------------------------
	// render_page_template — A11Y-01 Skip-Link (UX-A11Y-01, #286)
	// ------------------------------------------------------------------

	/**
	 * Stubs all WP functions needed by render_page_template() and captures its output.
	 *
	 * @param string $title Page title.
	 * @return string Captured HTML output.
	 */
	private function capture_rendered_template( string $title = 'Test Page' ): string {
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'language_attributes' )->justReturn( null );
		Functions\when( 'bloginfo' )->alias(
			function ( string $show ): void {
				if ( 'charset' === $show ) {
					echo 'UTF-8';
				}
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Blog' );
		Functions\when( 'wp_head' )->justReturn( null );
		Functions\when( 'wp_footer' )->justReturn( null );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text ): void {
				echo $text;
			}
		);
		Functions\when( 'wp_logout_url' )->justReturn( 'https://example.com/wp-login.php?action=logout' );

		$user               = Mockery::mock( 'WP_User' );
		$user->display_name = 'Test User';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$method = new \ReflectionMethod( RecipientPage::class, 'render_page_template' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			$this->page,
			$title,
			function (): void {
				echo '<p>Test content</p>';
			}
		);
		return ob_get_clean();
	}

	public function test_render_template_contains_skip_link_with_correct_attributes(): void {
		$html = $this->capture_rendered_template();

		$this->assertStringContainsString( '<a class="skip-link screen-reader-text" href="#main-content">', $html );
		$this->assertStringContainsString( 'Zum Inhalt springen', $html );
	}

	public function test_render_template_contains_main_content_target_id(): void {
		$html = $this->capture_rendered_template();

		$this->assertMatchesRegularExpression( '/id=["\']main-content["\']/', $html );
	}

	public function test_render_template_contains_screen_reader_text_css_with_focus_state(): void {
		$html = $this->capture_rendered_template();

		// Hidden state: .skip-link.screen-reader-text with clip: rect.
		$this->assertStringContainsString( '.skip-link.screen-reader-text', $html );
		$this->assertStringContainsString( 'clip: rect(1px, 1px, 1px, 1px)', $html );
		$this->assertStringContainsString( 'position: absolute', $html );

		// Focus state: visible on focus (WCAG 2.1 AA).
		$this->assertStringContainsString( '.skip-link.screen-reader-text:focus', $html );
		$this->assertStringContainsString( 'clip: auto', $html );
	}
}
