<?php
/**
 * Unit tests for AdminMenu class.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\AdminMenu;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests for admin menu registration, asset enqueueing, and page routing.
 */
class AdminMenuTest extends TestCase {

	/**
	 * Backup of $_GET superglobal.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get = array();

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_get = $_GET;
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		$_GET = $this->original_get;
		parent::tearDown();
	}

	/**
	 * Stub common WordPress functions used by AdminMenu.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 */
	private function stub_admin_functions( array $skip = array() ): void {
		$return_arg = array( '__', 'esc_html__', 'esc_html', 'esc_url', 'esc_attr', 'esc_textarea' );

		$aliases = array(
			'esc_html_e'    => function ( string $text, string $domain = '' ): void {
				echo $text;
			},
			'esc_attr_e'    => function ( string $text, string $domain = '' ): void {
				echo $text;
			},
			'admin_url'     => function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			},
			'absint'        => function ( $val ): int {
				return abs( (int) $val );
			},
			'selected'      => function ( $selected, $current = true, bool $echo = true ): string {
				$result = $selected == $current ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			},
			'checked'       => function ( $checked, $current = true, bool $echo = true ): string {
				$result = $checked == $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			},
			'submit_button' => function ( string $text = 'Save' ): void {
				echo '<input type="submit" value="' . $text . '">';
			},
		);

		$null_returns = array(
			'add_menu_page',
			'add_submenu_page',
			'wp_enqueue_script',
			'wp_enqueue_style',
			'wp_localize_script',
			'rest_url',
			'wp_create_nonce',
			'settings_errors',
			'wp_nonce_field',
		);

		foreach ( $return_arg as $func ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->returnArg();
			}
		}

		foreach ( $aliases as $func => $callback ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->alias( $callback );
			}
		}

		foreach ( $null_returns as $func ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->justReturn( null );
			}
		}

		if ( ! in_array( 'current_user_can', $skip, true ) ) {
			Functions\when( 'current_user_can' )->justReturn( true );
		}
	}

	/**
	 * @test
	 */
	public function test_register_hooks_adds_admin_menu_and_enqueue_actions(): void {
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();

		$menu = new AdminMenu();
		$menu->register_hooks();
	}

	/**
	 * @test
	 */
	public function test_register_menus_creates_main_menu_with_manage_capability(): void {
		$this->stub_admin_functions( array( 'add_menu_page', 'add_submenu_page' ) );

		$captured_capability = '';

		Functions\expect( 'add_menu_page' )
			->once()
			->andReturnUsing(
				function () use ( &$captured_capability ): string {
					$args                = func_get_args();
					$captured_capability = $args[2];
					return 'toplevel_page_dsgvo-form';
				}
			);

		Functions\expect( 'add_submenu_page' )
			->andReturn( 'submenu_hook' );

		$menu = new AdminMenu();
		$menu->register_menus();

		$this->assertSame( 'dsgvo_form_manage', $captured_capability );
	}

	/**
	 * @test
	 */
	public function test_register_menus_creates_four_submenus(): void {
		$this->stub_admin_functions( array( 'add_menu_page', 'add_submenu_page' ) );

		Functions\expect( 'add_menu_page' )
			->once()
			->andReturn( 'toplevel_page_dsgvo-form' );

		$submenu_slugs = array();

		Functions\expect( 'add_submenu_page' )
			->times( 4 )
			->andReturnUsing(
				function () use ( &$submenu_slugs ): string {
					$args            = func_get_args();
					$submenu_slugs[] = $args[4];
					return 'submenu_hook';
				}
			);

		$menu = new AdminMenu();
		$menu->register_menus();

		$this->assertContains( 'dsgvo-form', $submenu_slugs );
		$this->assertContains( 'dsgvo-form-submissions', $submenu_slugs );
		$this->assertContains( 'dsgvo-form-recipients', $submenu_slugs );
		$this->assertContains( 'dsgvo-form-settings', $submenu_slugs );
	}

	/**
	 * @test
	 */
	public function test_register_menus_submissions_submenu_requires_view_submissions_capability(): void {
		$this->stub_admin_functions( array( 'add_menu_page', 'add_submenu_page' ) );

		Functions\expect( 'add_menu_page' )
			->andReturn( 'toplevel_page_dsgvo-form' );

		$submenu_capabilities = array();

		Functions\expect( 'add_submenu_page' )
			->andReturnUsing(
				function () use ( &$submenu_capabilities ): string {
					$args                              = func_get_args();
					$submenu_capabilities[ $args[4] ] = $args[3];
					return 'submenu_hook';
				}
			);

		$menu = new AdminMenu();
		$menu->register_menus();

		$this->assertSame(
			'dsgvo_form_view_submissions',
			$submenu_capabilities['dsgvo-form-submissions'],
			'Submissions submenu must require dsgvo_form_view_submissions capability.'
		);
	}

	/**
	 * @test
	 */
	public function test_enqueue_assets_skips_non_plugin_pages(): void {
		$this->stub_admin_functions( array( 'wp_enqueue_script', 'wp_enqueue_style' ) );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$menu = new AdminMenu();
		$menu->enqueue_assets( 'edit-post' );
	}

	/**
	 * @test
	 */
	public function test_enqueue_assets_skips_on_non_plugin_page(): void {
		$this->stub_admin_functions( array( 'wp_enqueue_script', 'wp_enqueue_style' ) );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$menu = new AdminMenu();
		$menu->enqueue_assets( 'edit.php' );
	}

	/**
	 * @test
	 *
	 * FormEditPage is now PHP-based — enqueue_assets() no longer loads
	 * React admin scripts even on plugin pages.
	 */
	public function test_enqueue_assets_no_scripts_on_plugin_page(): void {
		$this->stub_admin_functions( array( 'wp_enqueue_script', 'wp_enqueue_style' ) );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$menu = new AdminMenu();
		$menu->enqueue_assets( 'toplevel_page_dsgvo-form' );
	}

	/**
	 * @test
	 *
	 * Legacy test removed — React admin assets no longer loaded.
	 * See test_enqueue_assets_no_scripts_on_plugin_page() above.
	 */

	/**
	 * @test
	 */
	public function test_render_form_list_page_delegates_to_edit_page_on_edit_action(): void {
		$this->stub_admin_functions();

		// Mocks needed by Form::find() and Field::find_by_form_id() inside FormEditPage.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn();

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$_GET['action']  = 'edit';
		$_GET['form_id'] = '5';

		$menu   = new AdminMenu();
		$output = $this->capture_render( array( $menu, 'render_form_list_page' ) );

		$this->assertStringContainsString( 'form-table', $output );
		$this->assertStringContainsString( 'dsgvo-fields-container', $output );
	}

	/**
	 * @test
	 */
	public function test_render_form_list_page_shows_list_when_no_action(): void {
		$this->stub_admin_functions();

		// Additional stubs needed by FormListPage and FormListTable.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'settings_errors' )->justReturn( null );

		// Mock wpdb for Form::find_all() (returns empty list).
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$GLOBALS['wpdb'] = $wpdb;

		unset( $_GET['action'] );

		$menu   = new AdminMenu();
		$output = $this->capture_render( array( $menu, 'render_form_list_page' ) );

		$this->assertStringContainsString( 'Formulare', $output );
		$this->assertStringContainsString( 'Neues Formular', $output );
		$this->assertStringNotContainsString( 'dsgvo-form-builder', $output );
	}

	/**
	 * @test
	 */
	public function test_render_submission_list_page_routes_to_view_and_handles_not_found(): void {
		$this->stub_admin_functions();

		// Additional stubs needed by SubmissionViewPage.
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Mock wpdb so Submission::find returns null.
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$_GET['action']        = 'view';
		$_GET['submission_id'] = '42';

		// SubmissionViewPage should call wp_die when submission not found.
		Functions\expect( 'wp_die' )
			->once()
			->with( 'Einsendung nicht gefunden.' )
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'wp_die: not found' );
				}
			);

		$this->expectException( \RuntimeException::class );

		$menu = new AdminMenu();
		$menu->render_submission_list_page();
	}

	// ──────────────────────────────────────────────────
	// PRG pattern: load-{page} hook + handle_form_page_load (Bug-Fix 1)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_register_menus_adds_load_hook_for_forms_page(): void {
		$this->stub_admin_functions( array( 'add_menu_page', 'add_submenu_page' ) );

		Functions\expect( 'add_menu_page' )
			->once()
			->andReturn( 'toplevel_page_dsgvo-form' );

		Functions\expect( 'add_submenu_page' )
			->andReturn( 'submenu_hook' );

		Actions\expectAdded( 'load-toplevel_page_dsgvo-form' )->once();

		$menu = new AdminMenu();
		$menu->register_menus();
	}

	/**
	 * @test
	 */
	public function test_handle_form_page_load_skips_when_no_edit_action(): void {
		$_GET['action'] = 'list';

		$menu = new AdminMenu();
		$menu->handle_form_page_load();

		// No exception, no side effects — method returns silently.
		$this->assertTrue( true );
	}

	/**
	 * @test
	 */
	public function test_handle_form_page_load_skips_when_action_not_set(): void {
		unset( $_GET['action'] );

		$menu = new AdminMenu();
		$menu->handle_form_page_load();

		$this->assertTrue( true );
	}

	/**
	 * @test
	 */
	public function test_handle_form_page_load_creates_shared_form_edit_page_on_edit_action(): void {
		$this->stub_admin_functions();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn();

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$_GET['action']            = 'edit';
		$_GET['form_id']           = '0';
		$_SERVER['REQUEST_METHOD'] = 'GET'; // so maybe_save_and_redirect returns early.

		$menu = new AdminMenu();
		$menu->handle_form_page_load();

		// Shared FormEditPage instance set — render_form_list_page should reuse it.
		$output = $this->capture_render( array( $menu, 'render_form_list_page' ) );

		$this->assertStringContainsString( 'form-table', $output );
		$this->assertStringContainsString( 'dsgvo-fields-container', $output );
	}

	/**
	 * Capture output from a render callback.
	 *
	 * @param callable $callback The render method to invoke.
	 * @return string Captured output.
	 */
	private function capture_render( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
