<?php
/**
 * Plugin deactivator.
 *
 * Removes scheduled cron jobs and cleans up transients.
 * Does NOT remove data — that happens in uninstall.php.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::unschedule_cron();
		self::cleanup_transients();
	}

	/**
	 * Remove the DSGVO cleanup cron job.
	 *
	 * @return void
	 */
	private static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( 'dsgvo_form_cleanup' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'dsgvo_form_cleanup' );
		}
	}

	/**
	 * Delete all plugin-related transients (rate-limiting, caches).
	 *
	 * @return void
	 */
	private static function cleanup_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_dsgvo_rate_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_dsgvo_rate_' ) . '%'
			)
		);
	}
}
