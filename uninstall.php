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
$tables = array(
	$wpdb->prefix . 'dsgvo_audit_log',
	$wpdb->prefix . 'dsgvo_submission_files',
	$wpdb->prefix . 'dsgvo_form_recipients',
	$wpdb->prefix . 'dsgvo_submissions',
	$wpdb->prefix . 'dsgvo_consent_versions',
	$wpdb->prefix . 'dsgvo_fields',
	$wpdb->prefix . 'dsgvo_forms',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

/*
 * 2. Remove custom roles (SEC-AUTH-15).
 */
remove_role( 'wp_dsgvo_form_reader' );
remove_role( 'wp_dsgvo_form_supervisor' );

/*
 * 3. Remove capabilities from administrator (SEC-AUTH-16).
 */
$admin_role   = get_role( 'administrator' );
$capabilities = array(
	'dsgvo_form_manage',
	'dsgvo_form_view_submissions',
	'dsgvo_form_view_all_submissions',
	'dsgvo_form_delete_submissions',
	'dsgvo_form_export',
);

if ( $admin_role ) {
	foreach ( $capabilities as $cap ) {
		$admin_role->remove_cap( $cap );
	}
}

/*
 * 4. Delete all plugin options.
 */
delete_option( 'wpdsgvo_version' );
delete_option( 'wpdsgvo_db_version' );

/*
 * 5. Delete all plugin transients.
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
 * 6. Delete encrypted upload directory.
 *
 * @security-critical Kaskadierte Loeschung verschluesselter Dateien.
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung.
 */
$upload_dir       = wp_upload_dir();
$dsgvo_upload_dir = $upload_dir['basedir'] . '/dsgvo-form-files';

if ( is_dir( $dsgvo_upload_dir ) ) {
	$iterator = new RecursiveDirectoryIterator(
		$dsgvo_upload_dir,
		RecursiveDirectoryIterator::SKIP_DOTS
	);
	$files    = new RecursiveIteratorIterator(
		$iterator,
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			wp_delete_file( $file->getRealPath() );
		}
	}

	rmdir( $dsgvo_upload_dir );
}

/*
 * 7. Clear scheduled cron events.
 */
wp_clear_scheduled_hook( 'dsgvo_form_cleanup' );
