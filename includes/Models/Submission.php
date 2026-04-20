<?php
declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined('ABSPATH') || exit;

use WpDsgvoForm\Encryption\EncryptionService;

/**
 * Submission model — CRUD for the dsgvo_submissions table.
 *
 * Handles encrypted submission storage, DSGVO email_lookup_hash for data subject requests,
 * consent tracking, pagination, and automatic expiry cleanup.
 *
 * @privacy-relevant Art. 15, 17, 18, 20 DSGVO — Betroffenenrechte, Loeschung, Einschraenkung
 * @privacy-relevant Art. 7 Abs. 1 DSGVO — consent_locale fuer Nachweis der exakten Einwilligungsversion (DPO-FINDING-13)
 * @security-critical Encrypted data handling, email_lookup_hash for data subject requests
 */
class Submission {

	private const MAX_PER_PAGE  = 20;
	private const FILES_TABLE   = 'dsgvo_submission_files';

	public int $id                    = 0;
	public int $form_id               = 0;
	public string $encrypted_data     = '';
	public string $iv                 = '';
	public string $auth_tag           = '';
	public string $submitted_at       = '';
	public bool $is_read              = false;
	public ?string $expires_at        = null;
	public ?int $consent_text_version = null;
	public ?string $consent_timestamp = null;
	public ?string $email_lookup_hash  = null;
	public ?string $consent_locale    = null;
	public ?int $consent_version_id   = null;
	public bool $is_restricted        = false;

	/**
	 * Returns the full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsgvo_submissions';
	}

	/**
	 * Returns the submission files table name.
	 */
	public static function get_files_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::FILES_TABLE;
	}

	/**
	 * Finds a submission by ID.
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
	 * Returns paginated submissions for a form (list view).
	 *
	 * Excludes encrypted_data, iv, auth_tag from SELECT to avoid
	 * loading large encrypted blobs in list views (Performance-Req §3).
	 * Use find() for detail view with decryption.
	 *
	 * Pagination is capped at MAX_PER_PAGE (20) items per page.
	 * Restricted submissions (Art. 18 DSGVO) are excluded by default.
	 *
	 * @param int       $form_id        The form to query.
	 * @param int       $page           Page number (1-based).
	 * @param int       $per_page       Items per page (max 20).
	 * @param bool|null $is_read        Filter: true=read, false=unread, null=all.
	 * @param bool      $include_restricted Include restricted submissions (Art. 18 DSGVO).
	 * @return self[]
	 *
	 * @privacy-relevant SEC-DSGVO-13 — Restricted submissions hidden by default
	 */
	public static function find_by_form_id(
		int $form_id,
		int $page = 1,
		int $per_page = 20,
		?bool $is_read = null,
		bool $include_restricted = false
	): array {
		global $wpdb;
		$table = self::get_table_name();

		$per_page = min( max( $per_page, 1 ), self::MAX_PER_PAGE );
		$page     = max( $page, 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// List view: exclude encrypted_data, iv, auth_tag (Performance-Req §3).
		$columns = 'id, form_id, submitted_at, is_read, expires_at, '
			. 'consent_text_version, consent_timestamp, email_lookup_hash, consent_locale, consent_version_id, is_restricted';

		$where  = [ 'form_id = %d' ];
		$values = [ $form_id ];

		if ( ! $include_restricted ) {
			$where[]  = 'is_restricted = %d';
			$values[] = 0;
		}

		if ( $is_read !== null ) {
			$where[]  = 'is_read = %d';
			$values[] = (int) $is_read;
		}

		$where_clause = implode( ' AND ', $where );
		$values[]     = $per_page;
		$values[]     = $offset;

		// SEC-SQL-01: Query assembled from hardcoded fragments only; all user-facing values use %d placeholders.
		$sql = "SELECT {$columns} FROM `{$table}` WHERE {$where_clause} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from hardcoded column list, table name, and %d-only WHERE; safe by construction.
			$wpdb->prepare( $sql, ...$values ),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Counts submissions for a form (for pagination).
	 */
	public static function count_by_form_id(
		int $form_id,
		?bool $is_read = null,
		bool $include_restricted = false
	): int {
		global $wpdb;
		$table = self::get_table_name();

		$where  = [ 'form_id = %d' ];
		$values = [ $form_id ];

		if ( ! $include_restricted ) {
			$where[]  = 'is_restricted = %d';
			$values[] = 0;
		}

		if ( $is_read !== null ) {
			$where[]  = 'is_read = %d';
			$values[] = (int) $is_read;
		}

		$where_clause = implode( ' AND ', $where );

		// SEC-SQL-01: Query assembled from hardcoded fragments only; all user-facing values use %d placeholders.
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}";

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from hardcoded table name and %d-only WHERE; safe by construction.
			$wpdb->prepare( $sql, ...$values )
		);
	}

	/**
	 * Finds submissions across multiple forms with SQL-level pagination.
	 *
	 * Used by the recipient list view when no form filter is active.
	 * Replaces the previous in-memory aggregation that used PHP_INT_MAX.
	 *
	 * @param int[]     $form_ids           Array of form IDs to query.
	 * @param int       $page               Page number (1-based).
	 * @param int       $per_page           Items per page (max MAX_PER_PAGE).
	 * @param bool|null $is_read            Filter: true=read, false=unread, null=all.
	 * @param bool      $include_restricted Include restricted submissions (Art. 18 DSGVO).
	 * @return self[]
	 */
	public static function find_by_form_ids(
		array $form_ids,
		int $page = 1,
		int $per_page = 20,
		?bool $is_read = null,
		bool $include_restricted = false
	): array {
		if ( empty( $form_ids ) ) {
			return [];
		}

		global $wpdb;
		$table = self::get_table_name();

		$per_page = min( max( $per_page, 1 ), self::MAX_PER_PAGE );
		$page     = max( $page, 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$columns = 'id, form_id, submitted_at, is_read, expires_at, '
			. 'consent_text_version, consent_timestamp, email_lookup_hash, consent_locale, consent_version_id, is_restricted';

		// SEC-SQL-01: Use %d placeholders for each form ID.
		$id_placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
		$where           = [ "form_id IN ({$id_placeholders})" ];
		$values          = array_map( 'intval', $form_ids );

		if ( ! $include_restricted ) {
			$where[]  = 'is_restricted = %d';
			$values[] = 0;
		}

		if ( $is_read !== null ) {
			$where[]  = 'is_read = %d';
			$values[] = (int) $is_read;
		}

		$where_clause = implode( ' AND ', $where );
		$values[]     = $per_page;
		$values[]     = $offset;

		// SEC-SQL-01: Query assembled from hardcoded fragments only; IN() uses %d placeholders per form ID.
		$sql = "SELECT {$columns} FROM `{$table}` WHERE {$where_clause} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from hardcoded column list, table name, and %d-only WHERE with IN(); safe by construction.
			$wpdb->prepare( $sql, ...$values ),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Counts submissions across multiple forms.
	 *
	 * @param int[]     $form_ids           Array of form IDs to query.
	 * @param bool|null $is_read            Filter: true=read, false=unread, null=all.
	 * @param bool      $include_restricted Include restricted submissions (Art. 18 DSGVO).
	 * @return int
	 */
	public static function count_by_form_ids(
		array $form_ids,
		?bool $is_read = null,
		bool $include_restricted = false
	): int {
		if ( empty( $form_ids ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::get_table_name();

		$id_placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
		$where           = [ "form_id IN ({$id_placeholders})" ];
		$values          = array_map( 'intval', $form_ids );

		if ( ! $include_restricted ) {
			$where[]  = 'is_restricted = %d';
			$values[] = 0;
		}

		if ( $is_read !== null ) {
			$where[]  = 'is_read = %d';
			$values[] = (int) $is_read;
		}

		$where_clause = implode( ' AND ', $where );

		// SEC-SQL-01: Query assembled from hardcoded fragments only; IN() uses %d placeholders per form ID.
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}";

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from hardcoded table name and %d-only WHERE with IN(); safe by construction.
			$wpdb->prepare( $sql, ...$values )
		);
	}

	/**
	 * Finds submissions by HMAC-SHA256 email lookup hash (for DSGVO data subject requests).
	 *
	 * @return self[]
	 *
	 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht via Blind Index (LEGAL-RIGHTS-02)
	 */
	public static function find_by_email_lookup_hash( string $email_lookup_hash ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE email_lookup_hash = %s ORDER BY submitted_at DESC",
				$email_lookup_hash
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Saves the submission (insert or update).
	 *
	 * @return int The submission ID.
	 * @throws \RuntimeException On failure.
	 */
	public function save(): int {
		$this->validate();

		global $wpdb;
		$table = self::get_table_name();
		$data  = $this->to_db_array();

		if ( $this->id === 0 ) {
			$wpdb->insert( $table, $data, self::get_formats( $data ) );

			if ( $wpdb->insert_id === 0 ) {
				throw new \RuntimeException( 'Failed to insert submission: ' . esc_html( $wpdb->last_error ) );
			}

			$this->id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update(
				$table,
				$data,
				[ 'id' => $this->id ],
				self::get_formats( $data ),
				[ '%d' ]
			);
		}

		return $this->id;
	}

	/**
	 * Deletes a submission and its associated file records.
	 *
	 * Physical file cleanup is the responsibility of the caller (FileHandler).
	 * DB file records are deleted via FK CASCADE.
	 *
	 * @privacy-relevant Art. 17 DSGVO — Echte Loeschung, kein Soft-Delete (DEL-01)
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		return $result !== false;
	}

	/**
	 * Marks a submission as read.
	 */
	public static function mark_as_read( int $id ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->update(
			$table,
			[ 'is_read' => 1 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Sets or removes the processing restriction on a submission (Art. 18 DSGVO).
	 *
	 * @privacy-relevant SEC-DSGVO-13 — Einschraenkung der Verarbeitung
	 */
	public static function set_restricted( int $id, bool $restricted ): bool {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->update(
			$table,
			[ 'is_restricted' => (int) $restricted ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Deletes expired, non-restricted submissions and returns associated file paths.
	 *
	 * Called by the dsgvo_form_cleanup cron job (Architecture §7.4).
	 * Restricted submissions (Art. 18 DSGVO) are excluded (SEC-DSGVO-13).
	 * Batch size prevents long table locks (Performance-Req §13.2).
	 *
	 * @param int $batch_size Max submissions to delete per call (default 200).
	 * @return array{count: int, file_paths: string[]} Deleted count and file paths to clean up.
	 *
	 * @privacy-relevant Art. 17, SEC-DSGVO-08 — Automatische Loeschung nach Aufbewahrungsfrist
	 */
	public static function delete_expired( int $batch_size = 200 ): array {
		global $wpdb;
		$table       = self::get_table_name();
		$files_table = self::get_files_table_name();

		// Find expired, non-restricted submission IDs (batched to prevent long locks).
		$expired_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at < %s AND is_restricted = %d ORDER BY expires_at ASC LIMIT %d",
				current_time( 'mysql', true ),
				0,
				$batch_size
			)
		);

		if ( empty( $expired_ids ) ) {
			return [ 'count' => 0, 'file_paths' => [] ];
		}

		// Collect file paths before deletion (for physical cleanup by caller).
		$id_placeholders = implode( ',', array_fill( 0, count( $expired_ids ), '%d' ) );

		// SEC-SQL-01: Queries assembled from hardcoded fragments; IN() uses %d placeholders per expired ID.
		$file_sql   = "SELECT file_path FROM `{$files_table}` WHERE submission_id IN ({$id_placeholders})";
		$delete_sql = "DELETE FROM `{$table}` WHERE id IN ({$id_placeholders})";

		$file_paths = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $file_sql built from hardcoded table name and %d-only IN(); safe by construction.
			$wpdb->prepare( $file_sql, ...array_map( 'intval', $expired_ids ) )
		);

		// Delete submissions (FK CASCADE deletes file records).
		$deleted = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $delete_sql built from hardcoded table name and %d-only IN(); safe by construction.
			$wpdb->prepare( $delete_sql, ...array_map( 'intval', $expired_ids ) )
		);

		return [
			'count'      => (int) $deleted,
			'file_paths' => $file_paths ?: [],
		];
	}

	/**
	 * Decrypts the submission data using the form's DEK.
	 *
	 * @param EncryptionService $encryption The encryption service.
	 * @param Form              $form       The parent form (provides DEK).
	 * @return array<string, mixed> Decrypted field values.
	 * @throws \RuntimeException If decryption fails.
	 *
	 * @security-critical On-the-fly decryption (SEC-ENC-09)
	 */
	public function decrypt_data( EncryptionService $encryption, Form $form ): array {
		return $encryption->decrypt_submission(
			$this->encrypted_data,
			$this->iv,
			$this->auth_tag,
			$form->encrypted_dek,
			$form->dek_iv
		);
	}

	/**
	 * Validates submission data before save.
	 *
	 * @throws \RuntimeException On validation failure.
	 */
	private function validate(): void {
		if ( $this->form_id < 1 ) {
			throw new \RuntimeException( 'Submission must belong to a form (form_id required).' );
		}

		if ( $this->encrypted_data === '' ) {
			throw new \RuntimeException( 'Submission must contain encrypted data.' );
		}

		if ( $this->iv === '' || $this->auth_tag === '' ) {
			throw new \RuntimeException( 'Submission must contain IV and authentication tag.' );
		}

		// DPO-FINDING-01: No unlimited storage — expires_at required on insert.
		if ( $this->id === 0 && $this->expires_at === null ) {
			throw new \RuntimeException(
				'New submissions must have an expires_at date (DPO retention requirement).'
			);
		}
	}

	/**
	 * Creates a Submission instance from a database row.
	 */
	private static function from_row( array $row ): self {
		$sub                       = new self();
		$sub->id                   = (int) ( $row['id'] ?? 0 );
		$sub->form_id              = (int) ( $row['form_id'] ?? 0 );
		$sub->encrypted_data       = (string) ( $row['encrypted_data'] ?? '' );
		$sub->iv                   = (string) ( $row['iv'] ?? '' );
		$sub->auth_tag             = (string) ( $row['auth_tag'] ?? '' );
		$sub->submitted_at         = (string) ( $row['submitted_at'] ?? '' );
		$sub->is_read              = (bool) ( $row['is_read'] ?? false );
		$sub->expires_at           = $row['expires_at'] ?? null;
		$sub->consent_text_version = isset( $row['consent_text_version'] ) ? (int) $row['consent_text_version'] : null;
		$sub->consent_timestamp    = $row['consent_timestamp'] ?? null;
		$sub->email_lookup_hash    = $row['email_lookup_hash'] ?? null;
		$sub->consent_locale       = $row['consent_locale'] ?? null;
		$sub->consent_version_id   = isset( $row['consent_version_id'] ) ? (int) $row['consent_version_id'] : null;
		$sub->is_restricted        = (bool) ( $row['is_restricted'] ?? false );

		return $sub;
	}

	/**
	 * Converts submission properties to an associative array for DB operations.
	 */
	private function to_db_array(): array {
		$data = [
			'form_id'        => $this->form_id,
			'encrypted_data' => $this->encrypted_data,
			'iv'             => $this->iv,
			'auth_tag'       => $this->auth_tag,
			'is_read'        => (int) $this->is_read,
			'is_restricted'  => (int) $this->is_restricted,
		];

		if ( $this->expires_at !== null ) {
			$data['expires_at'] = $this->expires_at;
		}

		if ( $this->consent_text_version !== null ) {
			$data['consent_text_version'] = $this->consent_text_version;
		}

		if ( $this->consent_timestamp !== null ) {
			$data['consent_timestamp'] = $this->consent_timestamp;
		}

		if ( $this->email_lookup_hash !== null ) {
			$data['email_lookup_hash'] = $this->email_lookup_hash;
		}

		if ( $this->consent_locale !== null ) {
			$data['consent_locale'] = $this->consent_locale;
		}

		if ( $this->consent_version_id !== null ) {
			$data['consent_version_id'] = $this->consent_version_id;
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
