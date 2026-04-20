<?php
/**
 * Plugin activator.
 *
 * Creates database tables, custom roles/capabilities,
 * and schedules the DSGVO cleanup cron job.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation.
 */
class Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::create_roles();
		self::schedule_cron();
		self::set_default_options();
		flush_rewrite_rules();
	}

	/**
	 * Checks DB version and runs schema upgrades if needed.
	 *
	 * Called on admin_init to handle plugin updates without re-activation.
	 * dbDelta() safely applies only the differences to existing tables.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored_version = get_option( 'wpdsgvo_db_version', '0' );

		if ( $stored_version === WPDSGVO_VERSION ) {
			return;
		}

		self::create_tables();
		self::migrate_consent_locale_default();

		// Remove deprecated CAPTCHA settings (Task #278 — now hardcoded as WPDSGVO_CAPTCHA_URL constant).
		delete_option( 'wpdsgvo_captcha_provider' );
		delete_option( 'wpdsgvo_captcha_base_url' );
		delete_option( 'wpdsgvo_captcha_sitekey' );
		delete_option( 'wpdsgvo_captcha_sri_hash' );
	}

	/**
	 * Migrates existing empty consent_locale values to 'de_DE'.
	 *
	 * One-time data migration for DPO-FINDING-13: Submissions created
	 * before the DEFAULT change retain an empty consent_locale. This
	 * backfills them with the site default.
	 *
	 * @return void
	 */
	private static function migrate_consent_locale_default(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'dsgvo_submissions';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET consent_locale = %s WHERE consent_locale = ''",
				'de_DE'
			)
		);
	}

	/**
	 * Create all custom database tables via dbDelta.
	 *
	 * Tables: dsgvo_forms, dsgvo_fields, dsgvo_submissions,
	 * dsgvo_submission_files, dsgvo_form_recipients, dsgvo_audit_log.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Forms.
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_forms (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL,
				slug varchar(255) NOT NULL,
				description text,
				success_message text,
				email_subject varchar(255),
				email_template text,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				captcha_enabled tinyint(1) NOT NULL DEFAULT 1,
				retention_days int unsigned NOT NULL DEFAULT 90,
				legal_basis varchar(50) NOT NULL DEFAULT 'consent',
				purpose text,
				consent_text text,
				consent_version int unsigned NOT NULL DEFAULT 1,
				encrypted_dek text NOT NULL,
				dek_iv varchar(48) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY is_active (is_active)
			) {$charset_collate};"
		);

		// 2. Fields.
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_fields (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				field_type varchar(50) NOT NULL,
				label varchar(255) NOT NULL,
				name varchar(100) NOT NULL,
				placeholder varchar(255),
				is_required tinyint(1) NOT NULL DEFAULT 0,
				options text,
				validation_rules text,
				static_content text,
				file_config text,
				css_class varchar(255),
				sort_order int unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY form_sort (form_id,sort_order)
			) {$charset_collate};"
		);

		// 3. Submissions (encrypted).
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_submissions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				encrypted_data longtext NOT NULL,
				iv varchar(48) NOT NULL,
				auth_tag varchar(48) NOT NULL,
				email_lookup_hash varchar(64) DEFAULT NULL,
				is_read tinyint(1) NOT NULL DEFAULT 0,
				is_restricted tinyint(1) NOT NULL DEFAULT 0,
				consent_timestamp datetime DEFAULT NULL,
				consent_text_version int unsigned DEFAULT NULL,
				consent_locale varchar(10) NOT NULL DEFAULT 'de_DE',
				consent_version_id bigint(20) unsigned DEFAULT NULL,
				submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY form_submitted (form_id,submitted_at),
				KEY expires_at (expires_at),
				KEY is_read (is_read),
				KEY email_lookup_hash (email_lookup_hash),
				KEY is_restricted (is_restricted)
			) {$charset_collate};"
		);

		// 4. Submission files (encrypted).
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_submission_files (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL,
				field_id bigint(20) unsigned NOT NULL,
				file_path varchar(512) NOT NULL,
				original_name varchar(255) NOT NULL,
				mime_type varchar(100) NOT NULL,
				file_size bigint unsigned NOT NULL,
				encrypted_key text NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id)
			) {$charset_collate};"
		);

		// 5. Form-recipient assignments.
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_form_recipients (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				notify_email tinyint(1) NOT NULL DEFAULT 1,
				role_justification text DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY form_user (form_id,user_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		// 6. Audit log (DSGVO compliance, SEC-AUDIT-01/02).
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_audit_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				action varchar(100) NOT NULL,
				submission_id bigint(20) unsigned DEFAULT NULL,
				form_id bigint(20) unsigned DEFAULT NULL,
				ip_address varchar(45) DEFAULT NULL,
				details text,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_action (user_id,action),
				KEY submission_id (submission_id),
				KEY form_id (form_id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		// 7. Consent versions — per-locale versioned consent texts (Art. 7 Abs. 1 DSGVO).
		dbDelta(
			"CREATE TABLE {$prefix}dsgvo_consent_versions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				locale varchar(5) NOT NULL,
				version int unsigned NOT NULL,
				consent_text text NOT NULL,
				privacy_policy_url varchar(500) DEFAULT NULL,
				valid_from datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY form_locale_version (form_id,locale,version),
				KEY form_id (form_id)
			) {$charset_collate};"
		);

		update_option( 'wpdsgvo_db_version', WPDSGVO_VERSION );
	}

	/**
	 * Create custom roles and capabilities.
	 *
	 * @return void
	 */
	private static function create_roles(): void {
		// Reader role for form recipients (SEC-AUTH-01).
		add_role(
			'wp_dsgvo_form_reader',
			__( 'DSGVO-Formular Empfaenger', 'wp-dsgvo-form' ),
			array(
				'read'                             => true,
				'dsgvo_form_view_submissions'       => true,
			)
		);

		// Supervisor role for cross-form access (SEC-AUTH-01).
		add_role(
			'wp_dsgvo_form_supervisor',
			__( 'DSGVO-Formular Supervisor', 'wp-dsgvo-form' ),
			array(
				'read'                                 => true,
				'dsgvo_form_view_submissions'           => true,
				'dsgvo_form_view_all_submissions'       => true,
				'dsgvo_form_export'                     => true,
			)
		);

		// Grant all DSGVO capabilities to administrators (SEC-AUTH-03).
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'dsgvo_form_manage' );
			$admin_role->add_cap( 'dsgvo_form_view_submissions' );
			$admin_role->add_cap( 'dsgvo_form_view_all_submissions' );
			$admin_role->add_cap( 'dsgvo_form_delete_submissions' );
			$admin_role->add_cap( 'dsgvo_form_export' );
		}
	}

	/**
	 * Schedule the hourly DSGVO cleanup cron job.
	 *
	 * @return void
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'dsgvo_form_cleanup' ) ) {
			wp_schedule_event( time(), 'hourly', 'dsgvo_form_cleanup' );
		}
	}

	/**
	 * Store default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		add_option( 'wpdsgvo_version', WPDSGVO_VERSION );
	}
}
