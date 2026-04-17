<?php
/**
 * ConsentVersion model — CRUD for the dsgvo_consent_versions table.
 *
 * Stores versioned consent texts per form and locale, enabling
 * full audit trail of consent wording changes (Art. 7 Abs. 1 DSGVO).
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 7 Abs. 1 DSGVO — Nachweis der exakten Einwilligungsversion
 */

declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined('ABSPATH') || exit;

class ConsentVersion {

	/**
	 * Supported locales for consent versioning (LEGAL-I18N-04).
	 *
	 * Extensible via the 'wpdsgvo_supported_locales' filter.
	 */
	public const SUPPORTED_LOCALES = array( 'de_DE', 'en_US', 'fr_FR', 'es_ES', 'it_IT', 'sv_SE' );

	public int $id                    = 0;
	public int $form_id               = 0;
	public string $locale             = 'de_DE';
	public int $version               = 1;
	public string $consent_text       = '';
	public ?string $privacy_policy_url = null;
	public string $valid_from         = '';
	public string $created_at         = '';

	/**
	 * Returns the full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsgvo_consent_versions';
	}

	/**
	 * Finds a consent version by ID.
	 */
	public static function find( int $id ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Finds the current (latest) consent version for a form and locale.
	 *
	 * Used at submission time to determine which consent text version
	 * the user is agreeing to.
	 *
	 * @param int    $form_id The form ID.
	 * @param string $locale  The locale (e.g. de_DE).
	 * @return self|null The latest version, or null if none exists.
	 */
	public static function get_current_version( int $form_id, string $locale ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d AND locale = %s ORDER BY version DESC LIMIT 1",
				$form_id,
				$locale
			),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Finds a specific consent version by form, locale, and version number.
	 *
	 * Used to retrieve the exact consent text a user agreed to
	 * (for DSGVO compliance proof).
	 *
	 * @param int    $form_id The form ID.
	 * @param string $locale  The locale (e.g. de_DE).
	 * @param int    $version The version number.
	 * @return self|null The matching version, or null.
	 *
	 * @privacy-relevant Art. 7 Abs. 1 DSGVO — Exakter Einwilligungstext abrufbar
	 */
	public static function find_by_form_and_locale( int $form_id, string $locale, int $version ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d AND locale = %s AND version = %d",
				$form_id,
				$locale,
				$version
			),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Returns all consent versions for a form, ordered by locale and version.
	 *
	 * @param int $form_id The form ID.
	 * @return self[]
	 */
	public static function find_all_by_form( int $form_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d ORDER BY locale ASC, version DESC",
				$form_id
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Saves the consent version (insert only — versions are immutable).
	 *
	 * Auto-increments version number based on existing versions for
	 * the same form and locale. Set $this->version to 0 to auto-increment;
	 * the default value (1) is used as-is for explicit version assignment.
	 *
	 * @return int The consent version ID.
	 * @throws \RuntimeException On validation failure or insert error.
	 *
	 * @privacy-relevant LEGAL-I18N-04 — Per-locale consent versioning
	 */
	public function save(): int {
		$this->validate();

		global $wpdb;
		$table = self::get_table_name();

		// Auto-increment version for this form + locale combination.
		if ( $this->version < 1 ) {
			$max_version = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(version) FROM `{$table}` WHERE form_id = %d AND locale = %s",
					$this->form_id,
					$this->locale
				)
			);
			$this->version = $max_version + 1;
		}

		if ( $this->valid_from === '' ) {
			$this->valid_from = current_time( 'mysql', true );
		}

		$data = $this->to_db_array();

		$wpdb->insert( $table, $data, self::get_formats( $data ) );

		if ( $wpdb->insert_id === 0 ) {
			throw new \RuntimeException( 'Failed to insert consent version: ' . $wpdb->last_error );
		}

		$this->id = (int) $wpdb->insert_id;

		return $this->id;
	}

	/**
	 * Validates consent version data before save.
	 *
	 * @throws \RuntimeException On validation failure.
	 */
	private function validate(): void {
		if ( $this->form_id < 1 ) {
			throw new \RuntimeException( 'ConsentVersion must belong to a form (form_id required).' );
		}

		if ( trim( $this->consent_text ) === '' ) {
			throw new \RuntimeException( 'ConsentVersion must contain consent text (DPO-FINDING-13).' );
		}

		if ( ! preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $this->locale ) ) {
			throw new \RuntimeException( 'ConsentVersion locale must match format xx_XX.' );
		}

		$supported = apply_filters( 'wpdsgvo_supported_locales', self::SUPPORTED_LOCALES );

		if ( ! in_array( $this->locale, $supported, true ) ) {
			throw new \RuntimeException(
				sprintf( 'ConsentVersion locale "%s" is not in the supported locales list.', $this->locale )
			);
		}
	}

	/**
	 * Creates a ConsentVersion instance from a database row.
	 */
	private static function from_row( array $row ): self {
		$cv                     = new self();
		$cv->id                 = (int) ( $row['id'] ?? 0 );
		$cv->form_id            = (int) ( $row['form_id'] ?? 0 );
		$cv->locale             = (string) ( $row['locale'] ?? 'de_DE' );
		$cv->version            = (int) ( $row['version'] ?? 1 );
		$cv->consent_text       = (string) ( $row['consent_text'] ?? '' );
		$cv->privacy_policy_url = $row['privacy_policy_url'] ?? null;
		$cv->valid_from         = (string) ( $row['valid_from'] ?? '' );
		$cv->created_at         = (string) ( $row['created_at'] ?? '' );

		return $cv;
	}

	/**
	 * Converts properties to an associative array for DB operations.
	 */
	private function to_db_array(): array {
		$data = [
			'form_id'      => $this->form_id,
			'locale'       => $this->locale,
			'version'      => $this->version,
			'consent_text' => $this->consent_text,
			'valid_from'   => $this->valid_from,
		];

		if ( $this->privacy_policy_url !== null ) {
			$data['privacy_policy_url'] = $this->privacy_policy_url;
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
