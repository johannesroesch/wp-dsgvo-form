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
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Tests for RecipientPage: hook registration, query vars, admin bar hiding,
 * handle_request auth flow, and static URL helpers.
 */
class RecipientPageTest extends TestCase {

	private AccessControl $access_control;
	private RecipientPage $page;

	protected function setUp(): void {
		parent::setUp();

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->page           = new RecipientPage( $this->access_control );
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

		$this->access_control->shouldReceive( 'has_plugin_role' )
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

		$this->access_control->shouldReceive( 'has_plugin_role' )
			->with( 1 )->andReturn( true );
		$this->access_control->shouldReceive( 'is_admin' )
			->with( 1 )->andReturn( true );

		Functions\expect( 'show_admin_bar' )->never();

		$this->page->maybe_hide_admin_bar();
	}

	public function test_maybe_hide_admin_bar_does_not_hide_for_non_plugin_role(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );

		$this->access_control->shouldReceive( 'has_plugin_role' )
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

		$this->access_control->shouldReceive( 'has_plugin_role' )
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
}
