<?php
/**
 * Cascading deletion of submissions and associated files.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Api;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Upload\FileHandler;

/**
 * Orchestrates cascading deletion of submissions and their files.
 *
 * DPO requirement: Physical files MUST be deleted BEFORE the DB record
 * is removed (file paths live in the DB, so we query them first).
 * DB file records are cleaned up via FK CASCADE on submission deletion.
 *
 * Used by:
 * - Admin UI (single/bulk deletion)
 * - Cron cleanup (expired submissions)
 * - DSGVO Art. 17 erasure requests
 *
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung
 * @security-critical SEC-FILE-09 — Files must be deleted with submissions
 */
class SubmissionDeleter {

	private FileHandler $file_handler;

	public function __construct( FileHandler $file_handler ) {
		$this->file_handler = $file_handler;
	}

	/**
	 * Deletes a single submission with cascading file cleanup.
	 *
	 * Order: query file paths → delete physical files → delete DB record.
	 *
	 * @param int $submission_id The submission to delete.
	 * @return bool True if the DB record was deleted.
	 */
	public function delete( int $submission_id ): bool {
		$file_paths = $this->get_file_paths( $submission_id );

		foreach ( $file_paths as $file_path ) {
			$this->file_handler->delete_file( $file_path );
		}

		return Submission::delete( $submission_id );
	}

	/**
	 * Deletes expired submissions with cascading file cleanup.
	 *
	 * Wraps Submission::delete_expired() and handles physical file removal.
	 *
	 * @param int $batch_size Max submissions per batch (default 200).
	 * @return array{count: int, file_paths: string[]} Deleted count and cleaned file paths.
	 *
	 * @privacy-relevant SEC-DSGVO-08 — Automatische Loeschung nach Aufbewahrungsfrist
	 */
	public function delete_expired( int $batch_size = 200 ): array {
		$result = Submission::delete_expired( $batch_size );

		foreach ( $result['file_paths'] as $file_path ) {
			$this->file_handler->delete_file( $file_path );
		}

		return $result;
	}

	/**
	 * Fetches file paths for a submission from the files table.
	 *
	 * @param int $submission_id The submission ID.
	 * @return string[] Relative file paths within the upload directory.
	 */
	private function get_file_paths( int $submission_id ): array {
		global $wpdb;
		$table = Submission::get_files_table_name();

		$result = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT file_path FROM `{$table}` WHERE submission_id = %d",
				$submission_id
			)
		);

		return $result ? $result : array();
	}
}
