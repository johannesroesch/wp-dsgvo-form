<?php
/**
 * Uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all custom tables, roles, capabilities, options,
 * transients, cron jobs, and uploaded files.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

// Abort if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * 1. Drop all custom tables.
 *
 * Order matters: child tables first to avoid FK issues on strict engines.
 */
$wpdsgvo_tables = array(
	$wpdb->prefix . 'dsgvo_audit_log',
	$wpdb->prefix . 'dsgvo_submission_files',
	$wpdb->prefix . 'dsgvo_form_recipients',
	$wpdb->prefix . 'dsgvo_submissions',
	$wpdb->prefix . 'dsgvo_consent_versions',
	$wpdb->prefix . 'dsgvo_fields',
	$wpdb->prefix . 'dsgvo_forms',
);

foreach ( $wpdsgvo_tables as $wpdsgvo_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdsgvo_table}" );
}

/*
 * 2. Remove custom roles (SEC-AUTH-15).
 */
remove_role( 'wp_dsgvo_form_reader' );
remove_role( 'wp_dsgvo_form_supervisor' );

/*
 * 3. Remove capabilities from administrator (SEC-AUTH-16).
 */
$wpdsgvo_admin_role   = get_role( 'administrator' );
$wpdsgvo_capabilities = array(
	'dsgvo_form_manage',
	'dsgvo_form_view_submissions',
	'dsgvo_form_view_all_submissions',
	'dsgvo_form_delete_submissions',
	'dsgvo_form_export',
);

if ( $wpdsgvo_admin_role ) {
	foreach ( $wpdsgvo_capabilities as $wpdsgvo_cap ) {
		$wpdsgvo_admin_role->remove_cap( $wpdsgvo_cap );
	}
}

/*
 * 4. Delete all plugin options.
 */
delete_option( 'wpdsgvo_version' );
delete_option( 'wpdsgvo_db_version' );
delete_option( 'wpdsgvo_captcha_secret' );
delete_option( 'wpdsgvo_default_retention_days' );
delete_option( 'wpdsgvo_controller_name' );
delete_option( 'wpdsgvo_controller_email' );

/*
 * 5. Delete all plugin user meta.
 */
delete_metadata( 'user', 0, 'wpdsgvo_privacy_notice_ack', '', true );
delete_metadata( 'user', 0, 'wpdsgvo_dsfa_notice_dismissed', '', true );

/*
 * 7. Delete all plugin transients.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_dsgvo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_dsgvo_' ) . '%'
	)
);

/*
 * 8. Delete encrypted upload directory.
 *
 * @security-critical Kaskadierte Loeschung verschluesselter Dateien.
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung.
 */
$wpdsgvo_upload_dir  = wp_upload_dir();
$wpdsgvo_upload_path = $wpdsgvo_upload_dir['basedir'] . '/dsgvo-form-files';

if ( is_dir( $wpdsgvo_upload_path ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;

	$wpdsgvo_iterator = new RecursiveDirectoryIterator(
		$wpdsgvo_upload_path,
		RecursiveDirectoryIterator::SKIP_DOTS
	);
	$wpdsgvo_files    = new RecursiveIteratorIterator(
		$wpdsgvo_iterator,
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $wpdsgvo_files as $wpdsgvo_file ) {
		if ( $wpdsgvo_file->isDir() ) {
			$wp_filesystem->rmdir( $wpdsgvo_file->getRealPath() );
		} else {
			wp_delete_file( $wpdsgvo_file->getRealPath() );
		}
	}

	$wp_filesystem->rmdir( $wpdsgvo_upload_path );
}

/*
 * 9. Clear scheduled cron events.
 */
wp_clear_scheduled_hook( 'dsgvo_form_cleanup' );
