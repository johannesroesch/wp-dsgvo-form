<?php
/**
 * Unit tests for AdminBarNotification.
 *
 * Covers: hook registration, capability gate, role-based count routing
 * (Admin/Supervisor → global, Reader → per-user), transient caching,
 * count cap (99+), badge HTML with accessibility, CSS output, and
 * DSGVO Art. 18 restricted-submission exclusion.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\AdminBarNotification;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for AdminBarNotification.
 */
class AdminBarNotificationTest extends TestCase {

	private AdminBarNotification $notification;
	private AccessControl $access_control;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->access_control = Mockery::mock( AccessControl::class );
		$this->notification   = new AdminBarNotification( $this->access_control );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing(
			function ( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%d', '%s' ], [ '%s', "'%s'" ], $query ), $args );
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;

		// Default WP function stubs.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			function ( string $single, string $plural, int $number, string $domain = '' ): string {
				return $number === 1 ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Creates a mock WP_Admin_Bar that records add_node calls.
	 *
	 * @return \Mockery\MockInterface&\WP_Admin_Bar
	 */
	private function mock_admin_bar(): \Mockery\MockInterface {
		return Mockery::mock( 'WP_Admin_Bar' );
	}

	/**
	 * Stubs WP functions for a logged-in user with a given capability.
	 *
	 * @param int  $user_id      User ID to return.
	 * @param bool $has_cap      Whether the user has view_submissions capability.
	 */
	private function stub_logged_in_user( int $user_id, bool $has_cap = true ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'user_can' )->alias(
			function ( int $uid, string $cap ) use ( $user_id, $has_cap ): bool {
				if ( $uid === $user_id && $cap === 'dsgvo_form_view_submissions' ) {
					return $has_cap;
				}
				return false;
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
	}

	/**
	 * Stubs transient functions: get returns false (cache miss), set is no-op.
	 */
	private function stub_transient_miss(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Stubs get_transient to return a cached value.
	 *
	 * @param int $count Cached count value.
	 */
	private function stub_transient_hit( int $count ): void {
		Functions\when( 'get_transient' )->justReturn( $count );
	}

	// ──────────────────────────────────────────────────
	// register_hooks
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_register_hooks_adds_admin_bar_menu_action(): void {
		Actions\expectAdded( 'admin_bar_menu' )
			->once()
			->with( [ $this->notification, 'add_notification_node' ], 80 );

		Actions\expectAdded( 'admin_head' )
			->once()
			->with( [ $this->notification, 'render_badge_styles' ] );

		Actions\expectAdded( 'wp_head' )
			->once()
			->with( [ $this->notification, 'render_badge_styles' ] );

		$this->notification->register_hooks();
	}

	// ──────────────────────────────────────────────────
	// add_notification_node — Visibility / Capability
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_node_hidden_for_logged_out_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$bar = $this->mock_admin_bar();
		$bar->shouldNotReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );
	}

	/**
	 * @test
	 */
	public function test_node_hidden_for_user_without_capability(): void {
		$this->stub_logged_in_user( 10, false );

		$bar = $this->mock_admin_bar();
		$bar->shouldNotReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );
	}

	/**
	 * @test
	 */
	public function test_node_hidden_when_count_is_zero(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		// Admin user, global count = 0.
		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );
		$this->access_control->shouldReceive( 'is_supervisor' )->never();

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$bar = $this->mock_admin_bar();
		$bar->shouldNotReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );
	}

	// ──────────────────────────────────────────────────
	// Admin/Supervisor — Global unread count
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-08 — Restricted submissions excluded from count
	 */
	public function test_admin_sees_global_unread_count(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		// SQL returns 5 unread, non-restricted.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertSame( 'wpdsgvo-unread', $node_args['id'] );
		$this->assertStringContainsString( '5', $node_args['title'] );
		$this->assertStringContainsString( 'dashicons-email-alt', $node_args['title'] );
		$this->assertStringContainsString( 'dsgvo-form-submissions', $node_args['href'] );
	}

	/**
	 * @test
	 */
	public function test_supervisor_sees_global_unread_count(): void {
		$this->stub_logged_in_user( 2 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( false );
		$this->access_control->shouldReceive( 'is_supervisor' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '3', $node_args['title'] );
	}

	// ──────────────────────────────────────────────────
	// Reader — Per-user unread count via Recipient
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 5 DSGVO — Reader sieht nur Einsendungen zugewiesener Formulare
	 */
	public function test_reader_sees_only_assigned_form_count(): void {
		$user_id = 50;
		$this->stub_logged_in_user( $user_id );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( false );
		$this->access_control->shouldReceive( 'is_supervisor' )->andReturn( false );

		// Recipient::get_form_ids_for_user() → forms 1, 3.
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( [ '1', '3' ] );

		// Submission::count_by_form_ids() → 2 unread.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '2', $node_args['title'] );
	}

	/**
	 * @test
	 */
	public function test_reader_with_no_assigned_forms_sees_nothing(): void {
		$user_id = 60;
		$this->stub_logged_in_user( $user_id );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( false );
		$this->access_control->shouldReceive( 'is_supervisor' )->andReturn( false );

		// No assigned forms.
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( [] );

		$bar = $this->mock_admin_bar();
		$bar->shouldNotReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );
	}

	// ──────────────────────────────────────────────────
	// Transient caching
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @performance Performance-Req §7.6.4 — Transient cache prevents DB query per page load
	 */
	public function test_global_count_uses_transient_cache(): void {
		$this->stub_logged_in_user( 1 );

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		// Cache hit: transient returns 7 → no DB query expected.
		$this->stub_transient_hit( 7 );

		// DB should NOT be queried.
		$this->wpdb->shouldNotReceive( 'get_var' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '7', $node_args['title'] );
	}

	/**
	 * @test
	 */
	public function test_reader_count_uses_per_user_transient(): void {
		$user_id = 50;
		$this->stub_logged_in_user( $user_id );

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( false );
		$this->access_control->shouldReceive( 'is_supervisor' )->andReturn( false );

		// Cache hit for per-user key.
		$this->stub_transient_hit( 4 );

		// DB should NOT be queried.
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->wpdb->shouldNotReceive( 'get_var' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '4', $node_args['title'] );
	}

	/**
	 * @test
	 */
	public function test_global_count_stores_transient_on_cache_miss(): void {
		$this->stub_logged_in_user( 1 );

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		Functions\when( 'get_transient' )->justReturn( false );

		$stored_key   = null;
		$stored_value = null;
		$stored_ttl   = null;
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl ) use ( &$stored_key, &$stored_value, &$stored_ttl ): bool {
				$stored_key   = $key;
				$stored_value = $value;
				$stored_ttl   = $ttl;
				return true;
			}
		);

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );

		$bar = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );

		$this->assertSame( 'wpdsgvo_unread_count', $stored_key );
		$this->assertSame( 5, $stored_value );
		$this->assertSame( 120, $stored_ttl );
	}

	// ──────────────────────────────────────────────────
	// Count cap — 99+
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_count_above_99_displays_as_99_plus(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '150' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '99+', $node_args['title'] );
		// Screen reader text should still use exact count for accessibility.
		$this->assertStringContainsString( 'screen-reader-text', $node_args['title'] );
	}

	/**
	 * @test
	 */
	public function test_count_exactly_99_shows_99_not_99_plus(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '99' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '99', $node_args['title'] );
		$this->assertStringNotContainsString( '99+', $node_args['title'] );
	}

	/**
	 * @test
	 */
	public function test_count_100_shows_99_plus(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '100' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertStringContainsString( '99+', $node_args['title'] );
	}

	// ──────────────────────────────────────────────────
	// Badge HTML structure — Accessibility (WCAG 2.1 AA)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_badge_html_contains_dashicon_and_screen_reader_text(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$title = $node_args['title'];

		// Dashicon.
		$this->assertStringContainsString( 'dashicons-email-alt', $title );
		// Count badge.
		$this->assertStringContainsString( 'wpdsgvo-unread-count', $title );
		// WCAG screen reader text.
		$this->assertStringContainsString( 'screen-reader-text', $title );
	}

	/**
	 * @test
	 */
	public function test_node_has_correct_id_and_href(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertSame( 'wpdsgvo-unread', $node_args['id'] );
		$this->assertStringContainsString( 'admin.php?page=dsgvo-form-submissions', $node_args['href'] );
	}

	/**
	 * @test
	 */
	public function test_node_meta_has_tooltip_class_and_title(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$node_args = null;
		$bar       = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' )
			->once()
			->withArgs( function ( array $args ) use ( &$node_args ): bool {
				$node_args = $args;
				return true;
			} );

		$this->notification->add_notification_node( $bar );

		$this->assertSame( 'wpdsgvo-admin-bar-notification', $node_args['meta']['class'] );
		$this->assertNotEmpty( $node_args['meta']['title'] );
	}

	// ──────────────────────────────────────────────────
	// render_badge_styles — CSS output
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_render_badge_styles_outputs_css_for_authorized_user(): void {
		$this->stub_logged_in_user( 1 );

		ob_start();
		$this->notification->render_badge_styles();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<style>', $output );
		$this->assertStringContainsString( '.wpdsgvo-unread-count', $output );
		$this->assertStringContainsString( '#d63638', $output );
		$this->assertStringContainsString( '.wpdsgvo-admin-bar-notification', $output );
	}

	/**
	 * @test
	 */
	public function test_render_badge_styles_skipped_for_logged_out_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		ob_start();
		$this->notification->render_badge_styles();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * @test
	 */
	public function test_render_badge_styles_skipped_for_unauthorized_user(): void {
		$this->stub_logged_in_user( 10, false );

		ob_start();
		$this->notification->render_badge_styles();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// ──────────────────────────────────────────────────
	// DSGVO-relevant: Restricted submissions excluded
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 18 DSGVO — Restricted submissions must not appear in badge count
	 */
	public function test_global_count_sql_excludes_restricted(): void {
		$this->stub_logged_in_user( 1 );
		$this->stub_transient_miss();

		$this->access_control->shouldReceive( 'is_admin' )->andReturn( true );

		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->withArgs( function ( string $sql ): bool {
				// SQL must filter is_restricted = 0 to exclude Art. 18 restricted rows.
				return str_contains( $sql, 'is_restricted' ) && str_contains( $sql, 'is_read' );
			} )
			->andReturn( '2' );

		$bar = $this->mock_admin_bar();
		$bar->shouldReceive( 'add_node' );

		$this->notification->add_notification_node( $bar );
	}
}
