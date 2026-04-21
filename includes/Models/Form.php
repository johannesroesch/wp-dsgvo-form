<?php
declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined('ABSPATH') || exit;

use WpDsgvoForm\Encryption\KeyManager;

/**
 * Form model — CRUD for the dsgvo_forms table.
 *
 * Handles form configuration, DEK management for envelope encryption,
 * consent text versioning (LEGAL-TEMPLATE-06), and transient caching.
 *
 * @privacy-relevant Art. 6, Art. 7 DSGVO — Rechtsgrundlage + Einwilligungstext-Versionierung
 * @security-critical Envelope Encryption DEK management (SEC-ENC-01 to SEC-ENC-04)
 */
class Form {

	private const CACHE_PREFIX = 'dsgvo_form_';
	private const CACHE_TTL    = 3600;

	public int $id               = 0;
	public string $title         = '';
	public string $slug          = '';
	public string $description   = '';
	public string $success_message = '';
	public string $email_subject = '';
	public string $email_template = '';
	public bool $is_active       = true;
	public bool $captcha_enabled = true;
	public ?string $locale_override = null;
	public int $retention_days   = 90;
	public string $encrypted_dek = '';
	public string $dek_iv        = '';
	public string $legal_basis   = 'consent';
	public string $purpose       = '';
	public string $consent_text  = '';
	public int $consent_version  = 1;
	public string $created_at    = '';
	public string $updated_at    = '';

	/**
	 * Returns the full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsgvo_forms';
	}

	/**
	 * Finds a form by ID with transient caching (1h TTL).
	 */
	public static function find( int $id ): ?self {
		$cache_key = self::CACHE_PREFIX . $id;
		$cached    = get_transient( $cache_key );

		if ( $cached instanceof self ) {
			return $cached;
		}

		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		$form = self::from_row( $row );
		set_transient( $cache_key, $form, self::CACHE_TTL );

		return $form;
	}

	/**
	 * Finds a form by its unique slug.
	 */
	public static function find_by_slug( string $slug ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE slug = %s", $slug ),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Returns all forms, optionally filtered by active status.
	 *
	 * @return self[]
	 */
	public static function find_all( bool $active_only = false ): array {
		global $wpdb;
		$table = self::get_table_name();

		if ( $active_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE is_active = %d ORDER BY created_at DESC",
					1
				),
				ARRAY_A
			);
		} else {
			// SEC-SQL-01: All queries via $wpdb->prepare() — no exceptions.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE 1 = %d ORDER BY created_at DESC",
					1
				),
				ARRAY_A
			);
		}

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Saves the form (insert or update).
	 *
	 * Insert: generates a DEK via KeyManager for envelope encryption.
	 * Update: auto-increments consent_version when consent_text changes (LEGAL-TEMPLATE-06).
	 *
	 * @param KeyManager|null $key_manager Required for new forms (DEK generation).
	 * @return int The form ID.
	 * @throws \RuntimeException On validation failure or missing KeyManager for insert.
	 *
	 * @privacy-relevant Art. 7 DSGVO — Einwilligungstext versioniert gespeichert
	 * @security-critical DEK generation for new forms (SEC-ENC-03)
	 */
	public function save( ?KeyManager $key_manager = null ): int {
		$this->validate();

		if ( $this->slug === '' ) {
			$this->slug = sanitize_title( $this->title );
		}

		// Ensure slug uniqueness (append suffix if needed).
		if ( $this->id === 0 ) {
			$this->slug = self::ensure_unique_slug( $this->slug );
		}

		if ( $this->id === 0 ) {
			return $this->insert_record( $key_manager );
		}

		return $this->update_record();
	}

	/**
	 * Ensures a slug is unique by appending a numeric suffix if necessary.
	 *
	 * @param string $slug     The desired slug.
	 * @param int    $exclude_id Form ID to exclude from check (for updates).
	 * @return string Unique slug.
	 */
	private static function ensure_unique_slug( string $slug, int $exclude_id = 0 ): string {
		global $wpdb;
		$table    = self::get_table_name();
		$original = $slug;
		$counter  = 1;

		while ( true ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE slug = %s AND id != %d",
					$slug,
					$exclude_id
				)
			);

			if ( $existing === null ) {
				return $slug;
			}

			++$counter;
			$slug = $original . '-' . $counter;
		}
	}

	/**
	 * Deletes a form by ID and invalidates cache.
	 *
	 * Associated fields, submissions, recipients are deleted via FK CASCADE.
	 * DEK deletion makes all encrypted submissions of this form unrecoverable (Crypto-Erasure).
	 *
	 * ConsentVersions (dsgvo_consent_versions) are intentionally NOT deleted.
	 * DPO decision: consent version records must be retained as proof of the
	 * exact consent wording shown to data subjects (Art. 7 Abs. 1 DSGVO).
	 * Retention obligation outweighs data minimisation for these records.
	 *
	 * @privacy-relevant Art. 17 DSGVO — Loeschung inkl. Crypto-Erasure (DPO-FINDING-05)
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		self::invalidate_cache( $id );

		return $result !== false;
	}

	/**
	 * Clears the transient cache for a form.
	 */
	public static function invalidate_cache( int $id ): void {
		delete_transient( self::CACHE_PREFIX . $id );
	}

	/**
	 * Validates form data before save.
	 *
	 * @throws \RuntimeException On validation failure.
	 *
	 * @privacy-relevant DPO-FINDING-01 — retention_days MUSS zwischen 1 und 3650 liegen
	 */
	private function validate(): void {
		if ( trim( $this->title ) === '' ) {
			throw new \RuntimeException( 'Form title must not be empty.' );
		}

		// DPO-FINDING-01: No unlimited storage. Min 1 day, max 10 years.
		if ( $this->retention_days < 1 || $this->retention_days > 3650 ) {
			throw new \RuntimeException(
				'Retention days must be between 1 and 3650 (DPO-FINDING-01).'
			);
		}

		$valid_bases = [ 'consent', 'contract' ];
		if ( ! in_array( $this->legal_basis, $valid_bases, true ) ) {
			throw new \RuntimeException(
				'Legal basis must be "consent" or "contract" (SEC-DSGVO-14).'
			);
		}

		// LEGAL-I18N-04: Validate locale_override against supported locales.
		if ( $this->locale_override !== null && $this->locale_override !== '' ) {
			$supported = apply_filters( 'wpdsgvo_supported_locales', ConsentVersion::SUPPORTED_LOCALES );

			if ( ! array_key_exists( $this->locale_override, $supported ) ) {
				throw new \RuntimeException(
					sprintf(
						'Locale override "%s" is not in the supported locales list (LEGAL-I18N-04).',
						esc_html( $this->locale_override )
					)
				);
			}
		}
	}

	/**
	 * Inserts a new form with DEK generation.
	 *
	 * @security-critical Generates per-form DEK via KeyManager (Architecture §3.2)
	 */
	private function insert_record( ?KeyManager $key_manager ): int {
		if ( $key_manager === null ) {
			throw new \RuntimeException(
				'KeyManager is required when creating a new form (DEK generation).'
			);
		}

		global $wpdb;
		$table = self::get_table_name();

		$dek       = $key_manager->generate_dek();
		$encrypted = $key_manager->encrypt_dek( $dek );

		$this->encrypted_dek   = $encrypted['encrypted_dek'];
		$this->dek_iv          = $encrypted['dek_iv'];
		$this->consent_version = 1;

		$data   = $this->to_db_array();
		$data['encrypted_dek'] = $this->encrypted_dek;
		$data['dek_iv']        = $this->dek_iv;

		$wpdb->insert( $table, $data, self::get_formats( $data ) );

		if ( $wpdb->insert_id === 0 ) {
			throw new \RuntimeException( 'Failed to insert form: ' . esc_html( $wpdb->last_error ) );
		}

		$this->id = (int) $wpdb->insert_id;
		return $this->id;
	}

	/**
	 * Updates an existing form with automatic consent versioning.
	 *
	 * Reads consent_text directly from DB (not cache) to ensure
	 * correct version incrementing even with concurrent edits.
	 *
	 * @privacy-relevant LEGAL-TEMPLATE-06 — Consent text version auto-incremented on change
	 */
	private function update_record(): int {
		global $wpdb;
		$table = self::get_table_name();

		// Direct DB read (not cache) for reliable consent comparison (QUALITY-FINDING-01).
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT consent_text, consent_version FROM `{$table}` WHERE id = %d",
				$this->id
			),
			ARRAY_A
		);

		if ( $existing_row !== null && $existing_row['consent_text'] !== $this->consent_text ) {
			$this->consent_version = (int) $existing_row['consent_version'] + 1;
		}

		$data = $this->to_db_array();

		$wpdb->update(
			$table,
			$data,
			[ 'id' => $this->id ],
			self::get_formats( $data ),
			[ '%d' ]
		);

		self::invalidate_cache( $this->id );

		return $this->id;
	}

	/**
	 * Creates a Form instance from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private static function from_row( array $row ): self {
		$form                  = new self();
		$form->id              = (int) ( $row['id'] ?? 0 );
		$form->title           = (string) ( $row['title'] ?? '' );
		$form->slug            = (string) ( $row['slug'] ?? '' );
		$form->description     = (string) ( $row['description'] ?? '' );
		$form->success_message = (string) ( $row['success_message'] ?? '' );
		$form->email_subject   = (string) ( $row['email_subject'] ?? '' );
		$form->email_template  = (string) ( $row['email_template'] ?? '' );
		$form->is_active       = (bool) ( $row['is_active'] ?? true );
		$form->captcha_enabled = (bool) ( $row['captcha_enabled'] ?? true );
		$form->locale_override = isset( $row['locale_override'] ) ? (string) $row['locale_override'] : null;
		$form->retention_days  = (int) ( $row['retention_days'] ?? 90 );
		$form->encrypted_dek   = (string) ( $row['encrypted_dek'] ?? '' );
		$form->dek_iv          = (string) ( $row['dek_iv'] ?? '' );
		$form->legal_basis     = (string) ( $row['legal_basis'] ?? 'consent' );
		$form->purpose         = (string) ( $row['purpose'] ?? '' );
		$form->consent_text    = (string) ( $row['consent_text'] ?? '' );
		$form->consent_version = (int) ( $row['consent_version'] ?? 1 );
		$form->created_at      = (string) ( $row['created_at'] ?? '' );
		$form->updated_at      = (string) ( $row['updated_at'] ?? '' );

		return $form;
	}

	/**
	 * Converts editable form properties to an associative array for DB operations.
	 *
	 * Excludes id, timestamps (managed by MySQL), and encrypted_dek/dek_iv (set on insert only).
	 *
	 * @return array<string, mixed>
	 */
	private function to_db_array(): array {
		$data = [
			'title'           => $this->title,
			'slug'            => $this->slug,
			'description'     => $this->description,
			'success_message' => $this->success_message,
			'email_subject'   => $this->email_subject,
			'email_template'  => $this->email_template,
			'is_active'       => (int) $this->is_active,
			'captcha_enabled' => (int) $this->captcha_enabled,
			'retention_days'  => $this->retention_days,
			'legal_basis'     => $this->legal_basis,
			'purpose'         => $this->purpose,
			'consent_text'    => $this->consent_text,
			'consent_version' => $this->consent_version,
		];

		if ( $this->locale_override !== null ) {
			$data['locale_override'] = $this->locale_override;
		}

		return $data;
	}

	/**
	 * Returns wpdb format specifiers matching the data array.
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return string[]
	 */
	private static function get_formats( array $data ): array {
		$formats = [];
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		return $formats;
	}
}
