<?php
/**
 * WordPress Privacy Data Exporter and Eraser (Art. 15 + 17 DSGVO).
 *
 * Integrates with the WordPress privacy tools (Tools → Export/Erase Personal Data)
 * to allow site admins to fulfill data subject requests.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht (Exporter)
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung (Eraser)
 * @privacy-relevant Art. 18 DSGVO — Einschraenkung der Verarbeitung (Eraser guard)
 */

declare(strict_types=1);

namespace WpDsgvoForm\Privacy;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;

/**
 * Registers WordPress privacy data exporter and eraser for DSGVO form submissions.
 *
 * LEGAL-F01: Uses the HMAC-SHA256 email_lookup_hash (SEC-ENC-13/14) to find
 * submissions belonging to a data subject without exposing plaintext email
 * addresses in the database.
 *
 * Processing is batched (BATCH_SIZE = 20) to handle large datasets without
 * exceeding PHP memory/time limits.
 *
 * Security requirements: SEC-ENC-09, SEC-ENC-13, SEC-DSGVO-13.
 */
class PrivacyHandler {

	/**
	 * Number of submissions to process per batch.
	 */
	private const BATCH_SIZE = 20;

	/**
	 * Plugin display name for the privacy tools UI.
	 */
	private const PLUGIN_NAME = 'WP DSGVO Form';

	private EncryptionService $encryption;
	private SubmissionDeleter $deleter;
	private AuditLogger $audit_logger;

	public function __construct(
		EncryptionService $encryption,
		SubmissionDeleter $deleter,
		AuditLogger $audit_logger
	) {
		$this->encryption   = $encryption;
		$this->deleter      = $deleter;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Registers the exporter and eraser with WordPress privacy tools.
	 *
	 * Must be called outside is_admin() — these filters fire during
	 * WP-CLI and REST API contexts as well.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Adds the plugin's data exporter to the WordPress exporter list.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters.
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['wp-dsgvo-form'] = [
			'exporter_friendly_name' => self::PLUGIN_NAME,
			'callback'               => [ $this, 'export_personal_data' ],
		];

		return $exporters;
	}

	/**
	 * Adds the plugin's data eraser to the WordPress eraser list.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['wp-dsgvo-form'] = [
			'eraser_friendly_name' => self::PLUGIN_NAME,
			'callback'             => [ $this, 'erase_personal_data' ],
		];

		return $erasers;
	}

	/**
	 * Exports personal data for a given email address (Art. 15 DSGVO).
	 *
	 * Looks up submissions via HMAC-SHA256 email_lookup_hash (blind index),
	 * decrypts each submission, and returns field values in WordPress export format.
	 *
	 * On decryption failure, a placeholder text is returned instead of failing
	 * the entire export — partial data is better than no data for Art. 15.
	 *
	 * @param string $email_address The data subject's email address.
	 * @param int    $page          Batch page number (1-based).
	 * @return array{data: array, done: bool} WordPress exporter response.
	 *
	 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht
	 * @security-critical SEC-ENC-09 — On-the-fly decryption
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$lookup_hash = $this->encryption->calculate_email_lookup_hash( $email_address );
		$submissions = Submission::find_by_email_lookup_hash( $lookup_hash );

		$total = count( $submissions );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;
		$batch  = array_slice( $submissions, $offset, self::BATCH_SIZE );

		$export_items = [];

		foreach ( $batch as $submission ) {
			$form = Form::find( $submission->form_id );

			if ( $form === null ) {
				continue;
			}

			$data = $this->decrypt_submission_safe( $submission, $form );

			$export_data = [];

			foreach ( $data as $field_name => $field_value ) {
				$export_data[] = [
					'name'  => $field_name,
					'value' => is_string( $field_value ) ? $field_value : wp_json_encode( $field_value ),
				];
			}

			// Add metadata fields.
			$export_data[] = [
				'name'  => __( 'Formular', 'wp-dsgvo-form' ),
				'value' => $form->title,
			];
			$export_data[] = [
				'name'  => __( 'Eingereicht am', 'wp-dsgvo-form' ),
				'value' => $submission->submitted_at,
			];

			if ( $submission->consent_timestamp !== null ) {
				$export_data[] = [
					'name'  => __( 'Einwilligung erteilt am', 'wp-dsgvo-form' ),
					'value' => $submission->consent_timestamp,
				];
			}

			$export_items[] = [
				'group_id'          => 'wp-dsgvo-form-submissions',
				'group_label'       => __( 'Formulareinsendungen', 'wp-dsgvo-form' ),
				'group_description' => __( 'Ueber Kontaktformulare uebermittelte Daten.', 'wp-dsgvo-form' ),
				'item_id'           => 'submission-' . $submission->id,
				'data'              => $export_data,
			];
		}

		return [
			'data' => $export_items,
			'done' => ( $offset + self::BATCH_SIZE ) >= $total,
		];
	}

	/**
	 * Erases personal data for a given email address (Art. 17 DSGVO).
	 *
	 * Looks up submissions via HMAC-SHA256 email_lookup_hash, then deletes
	 * each submission using SubmissionDeleter (cascading file cleanup).
	 *
	 * Art. 18 guard: Restricted submissions (is_restricted = true) are
	 * NOT deleted — they are retained per SEC-DSGVO-13.
	 *
	 * Audit log entries are intentionally NOT deleted — they serve as
	 * proof of lawful processing (Art. 5 Abs. 2 DSGVO accountability).
	 *
	 * @param string $email_address The data subject's email address.
	 * @param int    $page          Batch page number (1-based).
	 * @return array{items_removed: int, items_retained: bool, messages: string[], done: bool}
	 *
	 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung
	 * @privacy-relevant Art. 18 DSGVO — Einschraenkung der Verarbeitung (guard)
	 * @privacy-relevant Art. 5 Abs. 2 DSGVO — Audit-Logs bleiben erhalten
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$lookup_hash = $this->encryption->calculate_email_lookup_hash( $email_address );
		$submissions = Submission::find_by_email_lookup_hash( $lookup_hash );

		$total  = count( $submissions );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;
		$batch  = array_slice( $submissions, $offset, self::BATCH_SIZE );

		$items_removed  = 0;
		$items_retained = false;
		$messages       = [];

		foreach ( $batch as $submission ) {
			// Art. 18 DSGVO: Do not delete restricted submissions (SEC-DSGVO-13).
			if ( $submission->is_restricted ) {
				$items_retained = true;
				$messages[] = sprintf(
					/* translators: %d: submission ID */
					__( 'Einsendung #%d ist eingeschraenkt (Art. 18 DSGVO) und wurde nicht geloescht.', 'wp-dsgvo-form' ),
					$submission->id
				);
				continue;
			}

			$deleted = $this->deleter->delete( $submission->id );

			if ( $deleted ) {
				++$items_removed;

				// SEC-AUDIT-01: Log the erasure action.
				$this->audit_logger->log(
					get_current_user_id(),
					'delete',
					$submission->id,
					$submission->form_id,
					'Privacy erasure request (Art. 17 DSGVO)'
				);
			} else {
				$items_retained = true;
				$messages[] = sprintf(
					/* translators: %d: submission ID */
					__( 'Einsendung #%d konnte nicht geloescht werden.', 'wp-dsgvo-form' ),
					$submission->id
				);
			}
		}

		$done = ( $offset + self::BATCH_SIZE ) >= $total;

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Decrypts a submission, returning a placeholder on failure.
	 *
	 * Art. 15 DSGVO: A partial export is better than a failed export.
	 * If decryption fails (e.g. KEK rotation, corrupted data), we return
	 * a single placeholder entry instead of throwing an exception.
	 *
	 * @param Submission $submission The submission to decrypt.
	 * @param Form       $form      The parent form (provides DEK).
	 * @return array<string, mixed> Decrypted field values or placeholder.
	 */
	private function decrypt_submission_safe( Submission $submission, Form $form ): array {
		try {
			return $submission->decrypt_data( $this->encryption, $form );
		} catch ( \RuntimeException $e ) {
			return [
				__( 'Hinweis', 'wp-dsgvo-form' ) => __(
					'Die Daten dieser Einsendung konnten nicht entschluesselt werden. '
					. 'Bitte wenden Sie sich an den Website-Administrator.',
					'wp-dsgvo-form'
				),
			];
		}
	}
}
