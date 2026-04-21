<?php
/**
 * Submission detail view page.
 *
 * Displays a single decrypted submission with all fields,
 * file download links, consent metadata, and DSGVO action buttons.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Encryption\EncryptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Displays a single submission detail page.
 */
class SubmissionViewPage {

	private EncryptionService $encryption;
	private AuditLogger $audit_logger;

	public function __construct( EncryptionService $encryption, AuditLogger $audit_logger ) {
		$this->encryption   = $encryption;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Render the submission detail page.
	 *
	 * @return void
	 */
	public function render(): void {
		$submission_id = absint( $_GET['submission_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 === $submission_id ) {
			wp_die( esc_html__( 'Keine Einsendung angegeben.', 'wp-dsgvo-form' ) );
		}

		$submission = Submission::find( $submission_id );

		if ( null === $submission ) {
			wp_die( esc_html__( 'Einsendung nicht gefunden.', 'wp-dsgvo-form' ) );
		}

		// SEC-AUTH-14: IDOR protection — verify user can view THIS submission.
		$access_control = new AccessControl();
		$current_user   = get_current_user_id();

		if ( ! $access_control->can_view_submission( $current_user, $submission_id ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Einsendung anzuzeigen.', 'wp-dsgvo-form' ), 403 );
		}

		// Handle restrict/unrestrict actions (after IDOR check).
		$this->handle_actions( $submission_id );

		// SEC-AUDIT-01: Log every submission view.
		$this->audit_logger->log( $current_user, 'view', $submission_id, $submission->form_id );

		// Reload submission after possible action (restrict/unrestrict).
		$submission = Submission::find( $submission_id );

		// Mark as read on first view.
		if ( ! $submission->is_read ) {
			Submission::mark_as_read( $submission_id );
			$submission->is_read = true;
		}

		$form = Form::find( $submission->form_id );

		if ( null === $form ) {
			wp_die( esc_html__( 'Zugehoeriges Formular nicht gefunden.', 'wp-dsgvo-form' ) );
		}

		$fields    = Field::find_by_form_id( $form->id );
		$decrypted = $this->decrypt_submission( $submission, $form );
		$back_url  = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '-submissions' );

		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %d: submission ID */
					esc_html__( 'Einsendung #%d', 'wp-dsgvo-form' ),
					(int) $submission_id
				);
				?>
			</h1>

			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php settings_errors( 'dsgvo_submission_messages' ); ?>

			<?php if ( $submission->is_restricted ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Gesperrt (Art. 18 DSGVO)', 'wp-dsgvo-form' ); ?></strong>
						&mdash;
						<?php esc_html_e( 'Diese Einsendung ist gesperrt (Art. 18 DSGVO).', 'wp-dsgvo-form' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">

					<!-- Main content: decrypted fields -->
					<div id="post-body-content">
						<?php $this->render_field_data( $decrypted, $fields ); ?>
					</div>

					<!-- Sidebar: metadata + actions -->
					<div id="postbox-container-1" class="postbox-container">
						<?php $this->render_metadata_box( $submission, $form ); ?>
						<?php $this->render_actions_box( $submission ); ?>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Decrypt submission data with error handling.
	 *
	 * @param Submission $submission The submission.
	 * @param Form       $form      The parent form.
	 * @return array<string, mixed>|null Decrypted data or null on failure.
	 */
	private function decrypt_submission( Submission $submission, Form $form ): ?array {
		if ( ! defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) || '' === DSGVO_FORM_ENCRYPTION_KEY ) {
			return null;
		}

		try {
			return $submission->decrypt_data( $this->encryption, $form );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Render the decrypted field data table.
	 *
	 * @param array<string, mixed>|null $data   Decrypted data.
	 * @param Field[]                   $fields Form field definitions.
	 * @return void
	 */
	private function render_field_data( ?array $data, array $fields ): void {
		?>
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Formulardaten', 'wp-dsgvo-form' ); ?></h2>
			<div class="inside">
				<?php if ( null === $data ) : ?>
					<div class="notice notice-error inline">
						<p>
							<?php esc_html_e( 'Entschluesselung fehlgeschlagen. Pruefen Sie den Encryption Key in wp-config.php.', 'wp-dsgvo-form' ); ?>
						</p>
					</div>
				<?php else : ?>
					<table class="widefat striped">
						<tbody>
							<?php foreach ( $fields as $field ) : ?>
								<?php
								if ( 'static' === $field->field_type ) {
									continue;
								}
								?>
								<tr>
									<th scope="row" style="width:30%;">
										<?php echo esc_html( $field->label ); ?>
									</th>
									<td>
										<?php
										$value = $data[ $field->name ] ?? '';

										if ( 'file' === $field->field_type ) {
											esc_html_e( '[Datei — Download ueber Dateiliste]', 'wp-dsgvo-form' );
										} elseif ( is_array( $value ) ) {
											echo esc_html( implode( ', ', $value ) );
										} elseif ( 'checkbox' === $field->field_type ) {
											echo $value
												? esc_html__( 'Ja', 'wp-dsgvo-form' )
												: esc_html__( 'Nein', 'wp-dsgvo-form' );
										} else {
											echo nl2br( esc_html( (string) $value ) );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the metadata sidebar box.
	 *
	 * @param Submission $submission The submission.
	 * @param Form       $form      The parent form.
	 * @return void
	 */
	private function render_metadata_box( Submission $submission, Form $form ): void {
		?>
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Metadaten', 'wp-dsgvo-form' ); ?></h2>
			<div class="inside">
				<table class="widefat">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Formular', 'wp-dsgvo-form' ); ?></th>
							<td><?php echo esc_html( $form->title ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Eingegangen', 'wp-dsgvo-form' ); ?></th>
							<td>
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( $submission->submitted_at )
									)
								);
								?>
							</td>
						</tr>
						<?php if ( $submission->expires_at ) : ?>
							<tr>
								<th><?php esc_html_e( 'Ablaufdatum', 'wp-dsgvo-form' ); ?></th>
								<td>
									<?php
									echo esc_html(
										wp_date(
											get_option( 'date_format' ),
											strtotime( $submission->expires_at )
										)
									);
									?>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( $submission->consent_timestamp ) : ?>
							<tr>
								<th><?php esc_html_e( 'Einwilligung', 'wp-dsgvo-form' ); ?></th>
								<td>
									<?php
									echo esc_html(
										wp_date(
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											strtotime( $submission->consent_timestamp )
										)
									);
									?>
									<?php if ( $submission->consent_text_version ) : ?>
										<br>
										<?php
										printf(
											/* translators: %d: consent version number */
											esc_html__( 'Version %d', 'wp-dsgvo-form' ),
											(int) $submission->consent_text_version
										);
										?>
									<?php endif; ?>
									<?php if ( $submission->consent_locale ) : ?>
										<br>
										<?php echo esc_html( $submission->consent_locale ); ?>
									<?php endif; ?>
								</td>
							</tr>
							<?php
							// Art. 7 DSGVO: Display exact consent text from versioned record.
							$consent_version_record = null;
							if ( $submission->consent_version_id ) {
								$consent_version_record = ConsentVersion::find( $submission->consent_version_id );
							}
							?>
							<?php if ( null !== $consent_version_record ) : ?>
								<tr>
									<th><?php esc_html_e( 'Einwilligungstext', 'wp-dsgvo-form' ); ?></th>
									<td>
										<details>
											<summary><?php esc_html_e( 'Text anzeigen', 'wp-dsgvo-form' ); ?></summary>
											<div style="margin-top:0.5rem;padding:0.75rem;background:#f9f9f9;border:1px solid #eee;border-radius:3px;">
												<?php echo wp_kses_post( $consent_version_record->consent_text ); ?>
											</div>
										</details>
										<?php if ( $consent_version_record->privacy_policy_url ) : ?>
											<div style="margin-top:0.5rem;">
												<a href="<?php echo esc_url( $consent_version_record->privacy_policy_url ); ?>" target="_blank" rel="noopener noreferrer">
													<?php esc_html_e( 'Datenschutzerklaerung', 'wp-dsgvo-form' ); ?>
												</a>
											</div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'Rechtsgrundlage', 'wp-dsgvo-form' ); ?></th>
							<td><?php echo esc_html( $form->legal_basis ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the DSGVO actions sidebar box.
	 *
	 * @param Submission $submission The submission.
	 * @return void
	 */
	private function render_actions_box( Submission $submission ): void {
		// SEC-FINDING-08: Restrict is available to all viewers; unrestrict requires elevated privilege.
		$can_unrestrict       = current_user_can( 'dsgvo_form_view_all_submissions' );
		$show_restrict_button = ! $submission->is_restricted || $can_unrestrict;
		?>
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'DSGVO-Aktionen', 'wp-dsgvo-form' ); ?></h2>
			<div class="inside">
				<?php if ( $show_restrict_button ) : ?>
					<?php
					$restrict_url = wp_nonce_url(
						admin_url(
							sprintf(
								'admin.php?page=%s-submissions&action=view&submission_id=%d&do=%s',
								AdminMenu::MENU_SLUG,
								$submission->id,
								$submission->is_restricted ? 'unrestrict' : 'restrict'
							)
						),
						'dsgvo_submission_action_' . $submission->id
					);
					?>
					<p>
						<a href="<?php echo esc_url( $restrict_url ); ?>" class="button">
							<?php
							echo $submission->is_restricted
								? esc_html__( 'Sperre aufheben', 'wp-dsgvo-form' )
								: esc_html__( 'Sperren (Art. 18)', 'wp-dsgvo-form' );
							?>
						</a>
					</p>
				<?php endif; ?>

				<?php if ( current_user_can( 'dsgvo_form_delete_submissions' ) ) : ?>
					<?php
					$delete_url = wp_nonce_url(
						admin_url(
							sprintf(
								'admin.php?page=%s-submissions&action=delete&submission_id=%d',
								AdminMenu::MENU_SLUG,
								$submission->id
							)
						),
						'dsgvo_submission_delete_' . $submission->id
					);
					?>
					<p>
						<a href="<?php echo esc_url( $delete_url ); ?>"
							class="button button-link-delete"
							onclick="return confirm('<?php echo esc_js( __( 'Einsendung wirklich loeschen?', 'wp-dsgvo-form' ) ); ?>');">
							<?php esc_html_e( 'Einsendung loeschen (Art. 17)', 'wp-dsgvo-form' ); ?>
						</a>
					</p>
				<?php endif; ?>

				<?php if ( current_user_can( 'dsgvo_form_export' ) && ! $submission->is_restricted ) : ?>
					<?php
					$export_url     = wp_nonce_url(
						admin_url(
							sprintf(
								'admin.php?page=%s-submissions&action=view&submission_id=%d&do=export&format=json',
								AdminMenu::MENU_SLUG,
								$submission->id
							)
						),
						'dsgvo_submission_action_' . $submission->id
					);
					$export_csv_url = wp_nonce_url(
						admin_url(
							sprintf(
								'admin.php?page=%s-submissions&action=view&submission_id=%d&do=export&format=csv',
								AdminMenu::MENU_SLUG,
								$submission->id
							)
						),
						'dsgvo_submission_action_' . $submission->id
					);
					?>
					<p>
						<a href="<?php echo esc_url( $export_url ); ?>" class="button">
							<?php esc_html_e( 'Export JSON (Art. 20)', 'wp-dsgvo-form' ); ?>
						</a>
						<a href="<?php echo esc_url( $export_csv_url ); ?>" class="button">
							<?php esc_html_e( 'Export CSV (Art. 20)', 'wp-dsgvo-form' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle restriction and other actions on a submission.
	 *
	 * @param int $submission_id The submission ID.
	 * @return void
	 */
	private function handle_actions( int $submission_id ): void {
		$action = sanitize_text_field( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'dsgvo_submission_action_' . $submission_id );

		if ( 'restrict' === $action ) {
			Submission::set_restricted( $submission_id, true );

			// SEC-AUDIT-01: Log restrict action (Art. 18 DSGVO).
			$submission = Submission::find( $submission_id );
			$this->audit_logger->log( get_current_user_id(), 'restrict', $submission_id, $submission ? $submission->form_id : null, 'restricted' );

			add_settings_error(
				'dsgvo_submission_messages',
				'submission_restricted',
				__( 'Gesperrt (Art. 18 DSGVO).', 'wp-dsgvo-form' ),
				'success'
			);
		} elseif ( 'unrestrict' === $action ) {
			// SEC-FINDING-08: Only privileged users may lift restriction.
			if ( ! current_user_can( 'dsgvo_form_view_all_submissions' ) ) {
				wp_die( esc_html__( 'Sie haben keine Berechtigung, die Sperre aufzuheben.', 'wp-dsgvo-form' ), 403 );
			}

			Submission::set_restricted( $submission_id, false );

			// SEC-AUDIT-01: Log unrestrict action (Art. 18 DSGVO).
			$submission = Submission::find( $submission_id );
			$this->audit_logger->log( get_current_user_id(), 'restrict', $submission_id, $submission ? $submission->form_id : null, 'unrestricted' );

			add_settings_error(
				'dsgvo_submission_messages',
				'submission_unrestricted',
				__( 'Sperre aufgehoben.', 'wp-dsgvo-form' ),
				'success'
			);
		}
	}

	/**
	 * Handles the Art. 20 DSGVO data export (file download).
	 *
	 * Called from AdminMenu load-{page} hook BEFORE any HTML output,
	 * so HTTP headers can be sent for the file download.
	 *
	 * Exports only decrypted field data — no internal metadata (IDs, encryption keys, etc.).
	 * Audit-logged per SEC-AUDIT-01.
	 *
	 * @return void Sends file download and exits.
	 *
	 * @privacy-relevant Art. 20 DSGVO — Recht auf Datenuebertragbarkeit
	 */
	public function handle_export(): void {
		$submission_id = absint( $_GET['submission_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 === $submission_id ) {
			wp_die( esc_html__( 'Keine Einsendung angegeben.', 'wp-dsgvo-form' ), 400 );
		}

		// Nonce verification.
		check_admin_referer( 'dsgvo_submission_action_' . $submission_id );

		// Capability check.
		if ( ! current_user_can( 'dsgvo_form_export' ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung fuer den Export.', 'wp-dsgvo-form' ), 403 );
		}

		$submission = Submission::find( $submission_id );

		if ( null === $submission ) {
			wp_die( esc_html__( 'Einsendung nicht gefunden.', 'wp-dsgvo-form' ), 404 );
		}

		// IDOR protection — verify user can view THIS submission.
		$access_control = new AccessControl();
		$current_user   = get_current_user_id();

		if ( ! $access_control->can_view_submission( $current_user, $submission_id ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Einsendung anzuzeigen.', 'wp-dsgvo-form' ), 403 );
		}

		// Art. 18 DSGVO: Export is processing — restricted submissions must not be exported.
		if ( $submission->is_restricted ) {
			wp_die( esc_html__( 'Gesperrte Einsendungen (Art. 18 DSGVO) koennen nicht exportiert werden.', 'wp-dsgvo-form' ), 403 );
		}

		$form = Form::find( $submission->form_id );

		if ( null === $form ) {
			wp_die( esc_html__( 'Zugehoeriges Formular nicht gefunden.', 'wp-dsgvo-form' ), 404 );
		}

		// Decrypt submission data.
		$decrypted = $this->decrypt_submission( $submission, $form );

		if ( null === $decrypted ) {
			wp_die( esc_html__( 'Entschluesselung fehlgeschlagen. Export nicht moeglich.', 'wp-dsgvo-form' ), 500 );
		}

		// Build export data: only field labels + values, no internal metadata.
		$fields      = Field::find_by_form_id( $form->id );
		$export_data = $this->build_export_data( $decrypted, $fields, $submission, $form );

		// SEC-AUDIT-01: Log export action.
		$this->audit_logger->log( $current_user, 'export', $submission_id, $submission->form_id );

		// Determine format.
		$format = sanitize_text_field( wp_unslash( $_GET['format'] ?? 'json' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'csv' === $format ) {
			$this->send_csv_download( $export_data, $submission_id );
		} else {
			$this->send_json_download( $export_data, $submission_id );
		}
	}

	/**
	 * Builds the export data array from decrypted submission data.
	 *
	 * Only includes user-facing field data — no IDs, encryption params,
	 * or internal metadata. Static fields are excluded.
	 *
	 * @param array<string, mixed> $decrypted  Decrypted field values.
	 * @param Field[]              $fields     Form field definitions.
	 * @param Submission           $submission The submission.
	 * @param Form                 $form       The parent form.
	 * @return array<string, mixed> Export-ready data.
	 */
	private function build_export_data( array $decrypted, array $fields, Submission $submission, Form $form ): array {
		$field_data = array();

		foreach ( $fields as $field ) {
			if ( 'static' === $field->field_type ) {
				continue;
			}

			if ( 'file' === $field->field_type ) {
				continue;
			}

			$value = $decrypted[ $field->name ] ?? '';

			$field_data[ $field->label ] = $value;
		}

		return array(
			'form_title'   => $form->title,
			'submitted_at' => $submission->submitted_at,
			'fields'       => $field_data,
		);
	}

	/**
	 * Sends export data as a JSON file download.
	 *
	 * @param array<string, mixed> $data          Export data.
	 * @param int                  $submission_id Submission ID (for filename).
	 * @return void Exits after sending.
	 */
	private function send_json_download( array $data, int $submission_id ): void {
		$filename = sprintf( 'dsgvo-export-%d-%s.json', $submission_id, gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding handles escaping.
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Sends export data as a CSV file download.
	 *
	 * @param array<string, mixed> $data          Export data.
	 * @param int                  $submission_id Submission ID (for filename).
	 * @return void Exits after sending.
	 */
	private function send_csv_download( array $data, int $submission_id ): void {
		$filename = sprintf( 'dsgvo-export-%d-%s.csv', $submission_id, gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8 detection.
		echo "\xEF\xBB\xBF";

		// Header row (static labels — no user data, safe from injection).
		fputcsv( $output, array( 'Feld', 'Wert' ) );

		// Metadata rows.
		fputcsv( $output, array( 'Formular', self::sanitize_csv_value( $data['form_title'] ) ) );
		fputcsv( $output, array( 'Eingegangen', self::sanitize_csv_value( $data['submitted_at'] ) ) );

		// Field data rows.
		foreach ( $data['fields'] as $label => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			fputcsv( $output, array( self::sanitize_csv_value( $label ), self::sanitize_csv_value( (string) $value ) ) );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, not filesystem
		exit;
	}

	/**
	 * Sanitizes a value for CSV export to prevent formula injection.
	 *
	 * Cells starting with =, +, -, @, tab, or carriage return can trigger
	 * formula execution in spreadsheet applications (Excel, LibreOffice Calc).
	 * Prefixing with a single quote neutralizes this without altering display.
	 *
	 * @param string $value The cell value to sanitize.
	 * @return string Sanitized value safe for CSV.
	 */
	private static function sanitize_csv_value( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
