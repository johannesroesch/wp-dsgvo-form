<?php
/**
 * Content-Security-Policy headers for plugin admin pages.
 *
 * SEC-KANN-02: Adds CSP headers to all DSGVO Form admin pages
 * to mitigate XSS, clickjacking, and data injection attacks.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Sends Content-Security-Policy headers on plugin admin pages.
 *
 * Policy directives:
 * - script-src 'self' 'unsafe-inline' (WP-Admin requires inline scripts)
 * - style-src 'self' 'unsafe-inline' (WP-Admin requires inline styles)
 * - frame-ancestors 'self' (prevents clickjacking / iframe embedding)
 * - base-uri 'self' (prevents base tag injection)
 * - form-action 'self' (prevents form hijacking)
 */
class CspHeaders {

	/**
	 * Plugin menu slug prefix used to identify plugin admin pages.
	 */
	private const MENU_SLUG = 'dsgvo-form';

	/**
	 * Registers the header-sending hook.
	 *
	 * Must be called during plugin init (before admin output).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_send_headers' ] );
	}

	/**
	 * Sends CSP headers if we are on a plugin admin page.
	 *
	 * Fires on admin_init — early enough to send headers before output,
	 * but late enough that the current screen context is available.
	 *
	 * @return void
	 */
	public function maybe_send_headers(): void {
		// Headers already sent — nothing we can do.
		if ( headers_sent() ) {
			return;
		}

		if ( ! $this->is_plugin_page() ) {
			return;
		}

		$this->send_csp_header();
	}

	/**
	 * Sends the Content-Security-Policy header.
	 *
	 * Uses 'unsafe-inline' for scripts because WordPress core relies
	 * heavily on inline scripts and does not yet provide a global CSP nonce.
	 *
	 * @return void
	 */
	private function send_csp_header(): void {
		$directives = [
			"default-src 'self'",
			"script-src 'self' 'unsafe-inline'",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data:",
			"font-src 'self' data:",
			"frame-ancestors 'self'",
			"base-uri 'self'",
			"form-action 'self'",
		];

		$policy = implode( '; ', $directives );

		header( 'Content-Security-Policy: ' . $policy );
	}

	/**
	 * Checks whether the current request is for a plugin admin page.
	 *
	 * @return bool
	 */
	private function is_plugin_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page === '' ) {
			return false;
		}

		return str_starts_with( $page, self::MENU_SLUG );
	}
}
