<?php
/**
 * REST API endpoint for public form submissions.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Api;

defined('ABSPATH') || exit;

use WpDsgvoForm\Captcha\CaptchaVerifier;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Notification\NotificationService;
use WpDsgvoForm\Upload\FileHandler;
use WpDsgvoForm\Validation\FieldValidator;

/**
 * REST API endpoint for public form submissions.
 *
 * Handles POST /wp-json/dsgvo-form/v1/submit with strict validation
 * following the security-mandated processing order (SEC-VAL-12):
 *
 *   1. Nonce (CSRF)
 *   2. Consent (before any external request)
 *   3. CAPTCHA (external verification)
 *   4. Honeypot (bot detection)
 *   5. Field validation
 *   6. Encryption (AES-256-GCM)
 *   7. Save to DB
 *   8. Email notification
 *
 * @security-critical SEC-VAL-12 — Validation order is non-negotiable.
 * @privacy-relevant  SEC-DSGVO-04 — Hard-block on missing consent.
 */
class SubmitEndpoint {

	private const NAMESPACE    = 'dsgvo-form/v1';
	private const ROUTE        = '/submit';

	/**
	 * Honeypot field name — hidden field that must remain empty.
	 */
	private const HONEYPOT_FIELD = 'website_url';

	private EncryptionService $encryption;
	private CaptchaVerifier $captcha;
	private FieldValidator $validator;
	private NotificationService $notification;
	private FileHandler $file_handler;

	public function __construct(
		EncryptionService $encryption,
		CaptchaVerifier $captcha,
		FieldValidator $validator,
		NotificationService $notification,
		FileHandler $file_handler
	) {
		$this->encryption   = $encryption;
		$this->captcha      = $captcha;
		$this->validator    = $validator;
		$this->notification = $notification;
		$this->file_handler = $file_handler;
	}

	/**
	 * Registers the REST API route.
	 */
	public function register(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_submission' ],
			'permission_callback' => '__return_true', // Public endpoint.
		] );
	}

	/**
	 * Handles a form submission request.
	 *
	 * Processing order strictly follows SEC-VAL-12:
	 * Nonce → Consent → CAPTCHA → Honeypot → Fields → Encrypt → Save → Email
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_submission( \WP_REST_Request $request ) {
		// ----- 0. Pre-check: Encryption available ----- //
		if ( ! $this->encryption->is_available() ) {
			return new \WP_Error(
				'encryption_unavailable',
				__( 'Das Formular ist derzeit nicht verfuegbar. Bitte kontaktieren Sie den Administrator.', 'wp-dsgvo-form' ),
				[ 'status' => 503 ]
			);
		}

		// Supports both JSON (text-only forms) and multipart/form-data (file uploads).
		// get_json_params() handles application/json, get_body_params() handles $_POST.
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$form_id = (int) ( $params['form_id'] ?? 0 );

		// ----- Load form configuration ----- //
		$form = Form::find( $form_id );

		if ( $form === null || ! $form->is_active ) {
			return new \WP_Error(
				'form_not_found',
				__( 'Das Formular wurde nicht gefunden oder ist inaktiv.', 'wp-dsgvo-form' ),
				[ 'status' => 404 ]
			);
		}

		// ----- 1. NONCE (CSRF protection, SEC-CSRF-01/02) ----- //
		$nonce = (string) ( $params['_wpnonce'] ?? '' );

		if ( ! wp_verify_nonce( $nonce, 'dsgvo_form_submit_' . $form_id ) ) {
			return new \WP_Error(
				'nonce_invalid',
				__( 'Sicherheitspruefung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'wp-dsgvo-form' ),
				[ 'status' => 403 ]
			);
		}

		// ----- 2. CONSENT (before any external request, SEC-DSGVO-04) ----- //
		$consent_result = $this->verify_consent( $form, $params );

		if ( is_wp_error( $consent_result ) ) {
			return $consent_result;
		}

		// ----- 3. CAPTCHA (external verification, SEC-CAP-01) ----- //
		if ( $this->captcha->is_enabled_for_form( $form_id ) ) {
			$captcha_token = (string) ( $params['captcha_token'] ?? '' );

			if ( ! $this->captcha->verify( $captcha_token ) ) {
				return new \WP_Error(
					'captcha_failed',
					__( 'CAPTCHA-Verifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.', 'wp-dsgvo-form' ),
					[ 'status' => 422 ]
				);
			}
		}

		// ----- 4. HONEYPOT (bot detection) ----- //
		$honeypot_value = (string) ( $params[ self::HONEYPOT_FIELD ] ?? '' );

		if ( $honeypot_value !== '' ) {
			// Silently reject — bots filled the hidden field.
			// Return success to not reveal the honeypot mechanism.
			return new \WP_REST_Response( [
				'success' => true,
				'message' => $form->success_message ?: __( 'Ihre Einsendung wurde erfolgreich uebermittelt.', 'wp-dsgvo-form' ),
			], 201 );
		}

		// ----- 5. FIELD VALIDATION (SEC-VAL-01 to SEC-VAL-09) ----- //
		$fields         = Field::find_by_form_id( $form_id );
		$submitted_data = (array) ( $params['fields'] ?? [] );
		$validation     = $this->validator->validate( $submitted_data, $fields );

		if ( ! empty( $validation['errors'] ) ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Bitte korrigieren Sie die markierten Felder.', 'wp-dsgvo-form' ),
				[
					'status' => 422,
					'errors' => $validation['errors'],
				]
			);
		}

		$sanitized_data = $validation['sanitized'];

		// ----- 5b. FILE UPLOADS (SEC-FILE-01 to SEC-FILE-10) ----- //
		$file_results = $this->process_file_uploads( $request, $form, $fields );

		if ( is_wp_error( $file_results ) ) {
			return $file_results;
		}

		// ----- 6. ENCRYPTION (AES-256-GCM, SEC-ENC-05 to SEC-ENC-10) ----- //
		try {
			$encrypted = $this->encryption->encrypt_submission(
				$sanitized_data,
				$form->encrypted_dek,
				$form->dek_iv
			);
		} catch ( \RuntimeException $e ) {
			return new \WP_Error(
				'encryption_failed',
				__( 'Ein interner Fehler ist aufgetreten. Bitte versuchen Sie es spaeter erneut.', 'wp-dsgvo-form' ),
				[ 'status' => 500 ]
			);
		}

		// ----- 7. SAVE (SEC-SQL-01 via $wpdb->prepare in Model) ----- //
		global $wpdb;

		try {
			$submission = $this->build_submission(
				$form,
				$encrypted,
				$consent_result,
				$sanitized_data
			);

			// Transaction: submission + file records are atomic (SEC-FILE-11).
			$wpdb->query( 'START TRANSACTION' );

			$submission->save();

			// Save file records if any uploads were processed.
			$this->save_file_records( $submission->id, $file_results );

			$wpdb->query( 'COMMIT' );

		} catch ( \RuntimeException $e ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error(
				'save_failed',
				__( 'Ein interner Fehler ist aufgetreten. Bitte versuchen Sie es spaeter erneut.', 'wp-dsgvo-form' ),
				[ 'status' => 500 ]
			);
		}

		// ----- 8. EMAIL NOTIFICATION (SEC-MAIL-01 to SEC-MAIL-05) ----- //
		$this->notification->notify( $form_id, $submission->id, $form->title );

		// ----- SUCCESS ----- //
		$success_message = $form->success_message;

		if ( $success_message === '' ) {
			$success_message = __( 'Ihre Einsendung wurde erfolgreich uebermittelt.', 'wp-dsgvo-form' );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'message' => $success_message,
		], 201 );
	}

	/**
	 * Verifies consent based on the form's legal basis.
	 *
	 * SEC-DSGVO-04: Hard-block if consent is required but not given.
	 * Must run BEFORE CAPTCHA (SEC-VAL-12 step 2) to avoid external
	 * requests without legal basis.
	 *
	 * DPO-FINDING-14: Uses the client-provided consent_version_id
	 * (from the hidden input rendered at page load time) instead of
	 * looking up the latest version at submit time. This ensures the
	 * recorded version matches what the user actually saw (Art. 7 Abs. 1).
	 *
	 * @param Form  $form   The form configuration.
	 * @param array $params The submitted parameters.
	 * @return array|\WP_Error Consent metadata on success, WP_Error on failure.
	 *
	 * @privacy-relevant SEC-DSGVO-04, SEC-DSGVO-06, DPO-FINDING-13, DPO-FINDING-14
	 */
	private function verify_consent( Form $form, array $params ) {
		// SEC-DSGVO-14: Only require consent checkbox for legal_basis = 'consent'.
		// ARCH-v104-03: Consent parameters (consent_version_id, consent_locale, consent_given)
		// are explicitly ignored for non-consent legal bases. No consent data is stored.
		if ( $form->legal_basis !== 'consent' ) {
			return [
				'consent_text_version' => null,
				'consent_version_id'   => null,
				'consent_timestamp'    => null,
				'consent_locale'       => null,
			];
		}

		$consent_given = (bool) ( $params['consent_given'] ?? false );

		if ( ! $consent_given ) {
			// SEC-DSGVO-04: Hard-block — no data stored, no external requests.
			return new \WP_Error(
				'consent_missing',
				__( 'Sie muessen der Datenverarbeitung zustimmen, um das Formular absenden zu koennen.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		// DPO-FINDING-13: consent_locale is required — fail-closed.
		$consent_locale = (string) ( $params['consent_locale'] ?? '' );

		if ( $consent_locale === '' ) {
			return new \WP_Error(
				'consent_locale_missing',
				__( 'Die Sprache der Einwilligungserklaerung konnte nicht ermittelt werden.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		// Validate locale format (e.g. de_DE, en_US).
		if ( ! preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $consent_locale ) ) {
			return new \WP_Error(
				'consent_locale_invalid',
				__( 'Ungueltiges Locale-Format.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		// ARCH-v104-02 / DECISION-LOCALE-01: Validate against supported locales whitelist.
		$supported_locales = apply_filters( 'wpdsgvo_supported_locales', ConsentVersion::SUPPORTED_LOCALES );

		if ( ! array_key_exists( $consent_locale, $supported_locales ) ) {
			return new \WP_Error(
				'consent_locale_unsupported',
				__( 'Die angegebene Sprache wird nicht unterstuetzt.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		// DPO-FINDING-14: Use client-provided consent_version_id (rendered at page load).
		// This ensures we record the version the user actually saw, not the latest at submit time.
		$client_version_id = (int) ( $params['consent_version_id'] ?? 0 );

		if ( $client_version_id < 1 ) {
			// Fail-closed: client did not send a consent version.
			return new \WP_Error(
				'consent_version_missing',
				__( 'Die Einwilligungsversion konnte nicht ermittelt werden. Bitte laden Sie die Seite neu.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		// Validate: consent version must exist and belong to this form + locale.
		$consent_version = ConsentVersion::find( $client_version_id );

		if ( $consent_version === null ) {
			return new \WP_Error(
				'consent_version_invalid',
				__( 'Die angegebene Einwilligungsversion existiert nicht. Bitte laden Sie die Seite neu.', 'wp-dsgvo-form' ),
				[ 'status' => 409 ]
			);
		}

		if ( $consent_version->form_id !== $form->id ) {
			return new \WP_Error(
				'consent_version_invalid',
				__( 'Die angegebene Einwilligungsversion gehoert nicht zu diesem Formular.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		if ( $consent_version->locale !== $consent_locale ) {
			// DPO-FINDING-15: Locale-spoofing mitigated — version implies locale.
			return new \WP_Error(
				'consent_locale_mismatch',
				__( 'Die Sprache der Einwilligungserklaerung stimmt nicht mit der Version ueberein.', 'wp-dsgvo-form' ),
				[ 'status' => 422 ]
			);
		}

		return [
			'consent_text_version' => $consent_version->version,
			'consent_version_id'   => $consent_version->id,
			'consent_timestamp'    => current_time( 'mysql', true ),
			'consent_locale'       => $consent_locale,
		];
	}

	/**
	 * Processes file uploads for file-type fields.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @param Form             $form    The form configuration.
	 * @param Field[]          $fields  The field definitions.
	 * @return array|\WP_Error Array of file results or WP_Error.
	 */
	private function process_file_uploads( \WP_REST_Request $request, Form $form, array $fields ) {
		$file_results = [];
		$files        = $request->get_file_params();

		foreach ( $fields as $field ) {
			if ( $field->field_type !== 'file' ) {
				continue;
			}

			if ( ! isset( $files[ $field->name ] ) || $files[ $field->name ]['error'] === UPLOAD_ERR_NO_FILE ) {
				if ( $field->is_required ) {
					return new \WP_Error(
						'file_required',
						sprintf(
							/* translators: %s: field label */
							__( 'Bitte laden Sie eine Datei fuer "%s" hoch.', 'wp-dsgvo-form' ),
							$field->label
						),
						[ 'status' => 422 ]
					);
				}
				continue;
			}

			$file_config   = $field->get_file_config();
			$allowed_mimes = $this->parse_allowed_mimes( $file_config );
			$max_size      = (int) ( $file_config['max_size_mb'] ?? 0 ) * 1024 * 1024;

			try {
				$result = $this->file_handler->handle_upload(
					$files[ $field->name ],
					$form->id,
					$form->encrypted_dek,
					$form->dek_iv,
					$allowed_mimes,
					$max_size
				);

				$result['field_id'] = $field->id;
				$file_results[]     = $result;

			} catch ( \RuntimeException $e ) {
				return new \WP_Error(
					'file_upload_failed',
					sprintf(
						/* translators: %s: field label */
						__( 'Fehler beim Hochladen der Datei fuer "%s".', 'wp-dsgvo-form' ),
						$field->label
					),
					[ 'status' => 422 ]
				);
			}
		}

		return $file_results;
	}

	/**
	 * Parses allowed MIME types from field configuration.
	 *
	 * @param array $file_config The field's file configuration.
	 * @return array<string, string> Map of extension => MIME type.
	 */
	private function parse_allowed_mimes( array $file_config ): array {
		if ( empty( $file_config['allowed_types'] ) ) {
			return []; // FileHandler will use defaults.
		}

		$mime_map = [
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		];

		$allowed = [];

		foreach ( (array) $file_config['allowed_types'] as $type ) {
			$type = strtolower( trim( $type ) );

			if ( isset( $mime_map[ $type ] ) ) {
				$allowed[ $type ] = $mime_map[ $type ];
			}
		}

		return $allowed;
	}

	/**
	 * Builds a Submission model from validated and encrypted data.
	 *
	 * @param Form   $form            The form configuration.
	 * @param array  $encrypted       Encrypted data components.
	 * @param array  $consent_result  Consent metadata from verify_consent().
	 * @param array  $sanitized_data  Sanitized field values (for lookup hash).
	 * @return Submission Populated submission model (not yet saved).
	 *
	 * @privacy-relevant SEC-ENC-13/14 — Lookup hash for data subject requests.
	 *                   SEC-DSGVO-06 — Consent metadata stored unencrypted.
	 */
	private function build_submission(
		Form $form,
		array $encrypted,
		array $consent_result,
		array $sanitized_data
	): Submission {
		$submission                       = new Submission();
		$submission->form_id              = $form->id;
		$submission->encrypted_data       = $encrypted['encrypted_data'];
		$submission->iv                   = $encrypted['iv'];
		$submission->auth_tag             = $encrypted['auth_tag'];
		$submission->is_read              = false;
		$submission->is_restricted        = false;

		// SEC-DSGVO-06: Consent data stored unencrypted for compliance proof (SEC-ENC-11).
		// ARCH-v104-03: Defense-in-depth — only store consent metadata when legal_basis is 'consent'.
		// verify_consent() already returns nulls for non-consent bases, but this guard ensures
		// no consent data leaks into the DB even if the upstream return value changes.
		if ( $form->legal_basis === 'consent' ) {
			$submission->consent_text_version = $consent_result['consent_text_version'];
			$submission->consent_version_id   = $consent_result['consent_version_id'] ?? null;
			$submission->consent_timestamp    = $consent_result['consent_timestamp'];
			$submission->consent_locale       = $consent_result['consent_locale'];
		}

		// SEC-DSGVO-08: Calculate expiry date from retention_days.
		if ( $form->retention_days > 0 ) {
			$expiry = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			$expiry->modify( '+' . $form->retention_days . ' days' );
			$submission->expires_at = $expiry->format( 'Y-m-d H:i:s' );
		}

		// SEC-ENC-13/14: Lookup hash for DSGVO data subject requests (Art. 15, 17, 20).
		$email_value = $this->find_email_in_data( $sanitized_data );

		if ( $email_value !== null ) {
			$submission->email_lookup_hash = $this->encryption->calculate_email_lookup_hash( $email_value );
		}

		return $submission;
	}

	/**
	 * Saves file records associated with a submission.
	 *
	 * @param int   $submission_id The saved submission ID.
	 * @param array $file_results  Array of file upload results from process_file_uploads().
	 */
	private function save_file_records( int $submission_id, array $file_results ): void {
		if ( empty( $file_results ) ) {
			return;
		}

		global $wpdb;
		$table = Submission::get_files_table_name();

		foreach ( $file_results as $file ) {
			$inserted = $wpdb->insert(
				$table,
				[
					'submission_id'  => $submission_id,
					'field_id'       => $file['field_id'],
					'file_path'      => $file['file_path'],
					'original_name'  => $file['original_name'],
					'mime_type'      => $file['mime_type'],
					'file_size'      => $file['file_size'],
					'encrypted_key'  => $file['encrypted_key'],
				],
				[ '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( false === $inserted ) {
				throw new \RuntimeException(
					sprintf( 'Failed to save file record for submission %d: %s', $submission_id, esc_html( $wpdb->last_error ) )
				);
			}
		}
	}

	/**
	 * Searches sanitized data for an email field value.
	 *
	 * Used for generating the HMAC-SHA256 lookup hash (SEC-ENC-13).
	 *
	 * @param array $data Sanitized field values.
	 * @return string|null The first email address found, or null.
	 */
	private function find_email_in_data( array $data ): ?string {
		foreach ( $data as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return null;
	}
}
