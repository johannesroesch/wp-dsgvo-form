<?php
/**
 * Login redirect and admin isolation for plugin roles.
 *
 * Restricts wp_dsgvo_form_reader and wp_dsgvo_form_supervisor
 * to the submissions page — no WordPress Dashboard access.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Handles login redirects and admin area restrictions for plugin roles.
 *
 * Plugin roles (Reader, Supervisor) are redirected to the submissions
 * page after login and blocked from accessing other admin pages.
 * The admin bar is stripped down to show only essential items.
 *
 * Security requirements: SEC-AUTH-06 through SEC-AUTH-09, SEC-AUTH-12.
 */
class LoginRedirect {

	/**
	 * The submissions page slug used for redirects.
	 */
	private const SUBMISSIONS_PAGE = 'dsgvo-form-submissions';

	/**
	 * Allowed admin page slugs for plugin roles (SEC-AUTH-09).
	 *
	 * Exact-match whitelist prevents prefix-based bypass
	 * (e.g. 'dsgvo-form-malicious' would match a prefix check).
	 *
	 * @var string[]
	 */
	private const ALLOWED_PAGES = [
		'dsgvo-form',
		'dsgvo-form-submissions',
		'dsgvo-form-recipients',
		'dsgvo-form-settings',
		'dsgvo-form-acknowledge',
	];

	/**
	 * Auth cookie expiration for plugin roles: 2 hours in seconds.
	 *
	 * SEC-AUTH-12: Reduced session lifetime for plugin roles.
	 */
	private const COOKIE_EXPIRATION = 2 * HOUR_IN_SECONDS;

	private AccessControl $access_control;

	/**
	 * @param AccessControl $access_control Access control service.
	 */
	public function __construct( AccessControl $access_control ) {
		$this->access_control = $access_control;
	}

	/**
	 * Registers all WordPress hooks for login redirect and admin isolation.
	 *
	 * Should be called once during plugin initialization.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// SEC-AUTH-06: Redirect after login.
		add_filter( 'login_redirect', [ $this, 'handle_login_redirect' ], 10, 3 );

		// SEC-AUTH-12: Shorter cookie expiration for plugin roles.
		add_filter( 'auth_cookie_expiration', [ $this, 'handle_cookie_expiration' ], 10, 3 );

		// SEC-AUTH-07: Remove admin menu items.
		add_action( 'admin_menu', [ $this, 'restrict_admin_menu' ], 999 );

		// SEC-AUTH-08: Restrict admin bar.
		add_action( 'admin_bar_menu', [ $this, 'restrict_admin_bar' ], 999 );

		// SEC-AUTH-09: Block direct access to unauthorized admin pages.
		add_action( 'current_screen', [ $this, 'block_unauthorized_access' ] );
	}

	/**
	 * Redirects plugin roles to the submissions page after login.
	 *
	 * SEC-AUTH-06: Plugin roles must NOT see the WordPress Dashboard.
	 *
	 * @param string           $redirect_to The default redirect URL.
	 * @param string           $request     The requested redirect URL (from login form).
	 * @param \WP_User|\WP_Error $user      The logged-in user or error.
	 * @return string The filtered redirect URL.
	 */
	public function handle_login_redirect( string $redirect_to, string $request, $user ): string {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		if ( ! $this->access_control->has_plugin_role( $user->ID ) ) {
			return $redirect_to;
		}

		return $this->get_submissions_url();
	}

	/**
	 * Reduces auth cookie expiration for plugin roles.
	 *
	 * SEC-AUTH-12: 2-hour session timeout for Reader and Supervisor.
	 *
	 * @param int  $expiration Default expiration in seconds.
	 * @param int  $user_id    User ID.
	 * @param bool $remember   Whether "Remember Me" was checked.
	 * @return int Filtered expiration in seconds.
	 */
	public function handle_cookie_expiration( int $expiration, int $user_id, bool $remember ): int {
		if ( ! $this->access_control->has_plugin_role( $user_id ) ) {
			return $expiration;
		}

		// SEC-AUTH-12: Override even if "Remember Me" is checked.
		return self::COOKIE_EXPIRATION;
	}

	/**
	 * Removes all non-plugin admin menu items for plugin roles.
	 *
	 * SEC-AUTH-07: Plugin roles only see "Einsendungen" in the menu.
	 *
	 * @return void
	 */
	public function restrict_admin_menu(): void {
		if ( ! $this->is_restricted_user() ) {
			return;
		}

		// Standard WordPress top-level menu pages to remove.
		$pages_to_remove = [
			'index.php',                // Dashboard
			'edit.php',                 // Posts
			'upload.php',               // Media
			'edit.php?post_type=page',  // Pages
			'edit-comments.php',        // Comments
			'themes.php',              // Appearance
			'plugins.php',            // Plugins
			'users.php',              // Users
			'tools.php',              // Tools
			'options-general.php',    // Settings
			'profile.php',           // Profile (top-level)
		];

		foreach ( $pages_to_remove as $page ) {
			remove_menu_page( $page );
		}
	}

	/**
	 * Strips the admin bar down to essential items for plugin roles.
	 *
	 * SEC-AUTH-08: Only "Einsendungen" and "Abmelden" visible.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function restrict_admin_bar( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! $this->is_restricted_user() ) {
			return;
		}

		// Remove standard admin bar nodes.
		$nodes_to_remove = [
			'wp-logo',          // WordPress logo + links
			'site-name',        // Site name with dashboard link
			'comments',         // Comments link
			'new-content',      // "+ New" dropdown
			'edit',             // Edit link for current page
			'search',           // Search
			'customize',        // Customizer
			'updates',          // Update notifications
			'my-sites',         // My Sites (multisite)
		];

		foreach ( $nodes_to_remove as $node ) {
			$wp_admin_bar->remove_node( $node );
		}
	}

	/**
	 * Blocks direct URL access to unauthorized admin pages.
	 *
	 * SEC-AUTH-09: If a plugin role tries to access edit.php, index.php,
	 * or any non-plugin admin page, redirect to the submissions page.
	 *
	 * @param \WP_Screen $screen The current admin screen.
	 * @return void
	 */
	public function block_unauthorized_access( \WP_Screen $screen ): void {
		if ( ! $this->is_restricted_user() ) {
			return;
		}

		// SEC-AUTH-09: Allow only exact plugin page slugs (no prefix match).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( in_array( $page, self::ALLOWED_PAGES, true ) ) {
			return;
		}

		// Allow the profile page (users need to update their password).
		if ( 'profile' === $screen->id ) {
			return;
		}

		// All other admin pages: redirect to submissions.
		wp_safe_redirect( $this->get_submissions_url() );
		exit;
	}

	/**
	 * Checks whether the current user is a restricted plugin role.
	 *
	 * A user is restricted if they have a plugin role but are NOT an admin.
	 *
	 * @return bool True if the current user should be restricted.
	 */
	private function is_restricted_user(): bool {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return false;
		}

		// Admins are never restricted, even if they also have a plugin role.
		if ( $this->access_control->is_admin( $user_id ) ) {
			return false;
		}

		return $this->access_control->has_plugin_role( $user_id );
	}

	/**
	 * Returns the URL to the submissions admin page.
	 *
	 * @return string Admin URL for the submissions page.
	 */
	private function get_submissions_url(): string {
		return admin_url( 'admin.php?page=' . self::SUBMISSIONS_PAGE );
	}
}
