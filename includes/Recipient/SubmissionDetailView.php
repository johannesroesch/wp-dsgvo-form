<?php
/**
 * Recipient-facing submission detail view.
 *
 * Displays a single decrypted submission with field data,
 * consent metadata, and DSGVO action buttons.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Recipient;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;

/**
 * Renders a single submission detail for the recipient area.
 *
 * Features:
 * - SEC-AUTH-14: IDOR protection via AccessControl::can_view_submission().
 * - SEC-AUDIT-01: Every view is audit-logged.
 * - Art. 18 DSGVO: Restrict/unrestrict processing.
 * - Decryption via EncryptionService + KeyManager.
 *
 * UX-Concept §2.3: Detail view with field data, metadata sidebar, DSGVO actions.
 */
class SubmissionDetailView {

	private AccessControl $access_control;
	private bool $action_performed = false;
	private string $action_type    = '';

	public function __construct( AccessControl $access_control ) {
		$this->access_control = $access_control;
	}

	/**
	 * Renders the submission detail view.
	 *
	 * @param int $user_id       Current user ID.
	 * @param int $submission_id Submission to display.
	 */
	public function render( int $user_id, int $submission_id ): void {
		if ( 0 === $submission_id ) {
			$this->render_error( __( 'Keine Einsendung angegeben.', 'wp-dsgvo-form' ) );
			return;
		}

		// SEC-AUTH-14: IDOR protection.
		if ( ! $this->access_control->can_view_submission( $user_id, $submission_id ) ) {
			$this->render_error( __( 'Sie haben keine Berechtigung, diese Einsendung anzuzeigen.', 'wp-dsgvo-form' ) );
			return;
		}

		$is_privileged = $this->access_control->is_supervisor( $user_id ) || $this->access_control->is_admin( $user_id );

		// Handle restrict/unrestrict actions (after IDOR check).
		$this->handle_actions( $submission_id, $is_privileged );

		$submission = Submission::find( $submission_id );

		if ( $submission === null ) {
			$this->render_error( __( 'Einsendung nicht gefunden.', 'wp-dsgvo-form' ) );
			return;
		}

		// SEC-AUDIT-01: Log every submission view.
		$audit_logger = new AuditLogger();
		$audit_logger->log( $user_id, 'view', $submission_id, $submission->form_id );

		// Mark as read on first view.
		if ( ! $submission->is_read ) {
			Submission::mark_as_read( $submission_id );
			$submission->is_read = true;
		}

		$form = Form::find( $submission->form_id );

		if ( $form === null ) {
			$this->render_error( __( 'Zugehoeriges Formular nicht gefunden.', 'wp-dsgvo-form' ) );
			return;
		}

		$this->render_navigation();
		$this->render_status_notices( $submission );

		// DPO-BEDINGUNG-1 (Art. 18 DSGVO): Restricted submissions must NOT be
		// decrypted for readers. Decryption = processing. Restricted = storage only.
		// Supervisors/admins may still decrypt for oversight purposes.
		if ( $submission->is_restricted && ! $is_privileged ) {
			$this->render_restricted_notice( $submission_id );
			$this->render_metadata( $submission, $form );
			return;
		}

		$fields    = Field::find_by_form_id( $form->id );
		$decrypted = $this->decrypt_submission( $submission, $form );

		$this->render_field_data( $decrypted, $fields, $submission_id );
		$this->render_metadata( $submission, $form );
		$this->render_dsgvo_actions( $submission, $is_privileged );
	}

	/**
	 * Handles restrict/unrestrict actions via GET with nonce verification.
	 *
	 * @param int  $submission_id The submission ID.
	 * @param bool $is_privileged Whether user is supervisor/admin.
	 */
	private function handle_actions( int $submission_id, bool $is_privileged ): void {
		$action = sanitize_text_field( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $action ) {
			return;
		}

		// Nonce verification for state-changing actions.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dsgvo_recipient_action_' . $submission_id ) ) {
			return;
		}

		if ( 'restrict' === $action ) {
			Submission::set_restricted( $submission_id, true );

			// SEC-AUDIT-01: Log restrict action (Art. 18 DSGVO).
			$audit_logger = new AuditLogger();
			$submission   = Submission::find( $submission_id );
			$audit_logger->log( get_current_user_id(), 'restrict', $submission_id, $submission ? $submission->form_id : null, 'restricted' );

			$this->action_performed = true;
			$this->action_type      = 'restrict';
		} elseif ( 'unrestrict' === $action && $is_privileged ) {
			// DPO-BEDINGUNG-2: Only supervisor/admin may lift restriction.
			Submission::set_restricted( $submission_id, false );

			// SEC-AUDIT-01: Log unrestrict action (Art. 18 DSGVO).
			$audit_logger = new AuditLogger();
			$submission   = Submission::find( $submission_id );
			$audit_logger->log( get_current_user_id(), 'restrict', $submission_id, $submission ? $submission->form_id : null, 'unrestricted' );

			$this->action_performed = true;
			$this->action_type      = 'unrestrict';
		}
	}

	/**
	 * Decrypts submission data with error handling.
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
			$key_manager = new KeyManager();
			$encryption  = new EncryptionService( $key_manager );

			return $submission->decrypt_data( $encryption, $form );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Renders the back navigation link.
	 */
	private function render_navigation(): void {
		?>
		<div style="margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( RecipientPage::get_base_url() ); ?>"
				style="color:#2271b1;text-decoration:none;">
				&laquo; <?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Renders status notices (restriction warning, action success).
	 *
	 * @param Submission $submission The submission.
	 */
	private function render_status_notices( Submission $submission ): void {
		if ( $this->action_performed && 'restrict' === $this->action_type ) {
			?>
			<div style="padding:0.75rem 1rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;margin-bottom:1rem;">
				<?php esc_html_e( 'Gesperrt (Art. 18 DSGVO).', 'wp-dsgvo-form' ); ?>
			</div>
			<?php
		} elseif ( $this->action_performed && 'unrestrict' === $this->action_type ) {
			?>
			<div style="padding:0.75rem 1rem;background:#d4edda;border:1px solid #28a745;border-radius:4px;margin-bottom:1rem;">
				<?php esc_html_e( 'Sperre aufgehoben.', 'wp-dsgvo-form' ); ?>
			</div>
			<?php
		}

		if ( $submission->is_restricted ) {
			?>
			<div style="padding:0.75rem 1rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;margin-bottom:1rem;">
				<strong><?php esc_html_e( 'Gesperrt (Art. 18 DSGVO)', 'wp-dsgvo-form' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'Diese Einsendung ist gesperrt (Art. 18 DSGVO).', 'wp-dsgvo-form' ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Renders the decrypted field data.
	 *
	 * @param array<string, mixed>|null $data          Decrypted data.
	 * @param Field[]                   $fields        Form field definitions.
	 * @param int                       $submission_id Submission ID for the heading.
	 */
	private function render_field_data( ?array $data, array $fields, int $submission_id ): void {
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:4px;margin-bottom:1.5rem;">
			<div style="padding:1rem;border-bottom:1px solid #ddd;font-weight:600;font-size:1.1rem;">
				<?php
				printf(
					/* translators: %d: submission ID */
					esc_html__( 'Einsendung #%d', 'wp-dsgvo-form' ),
					(int) $submission_id
				);
				?>
			</div>
			<div style="padding:1rem;">
				<?php if ( $data === null ) : ?>
					<div style="padding:0.75rem 1rem;background:#f8d7da;border:1px solid #dc3545;border-radius:4px;color:#721c24;">
						<?php esc_html_e( 'Entschluesselung fehlgeschlagen. Pruefen Sie den Encryption Key.', 'wp-dsgvo-form' ); ?>
					</div>
				<?php else : ?>
					<table style="width:100%;border-collapse:collapse;">
						<tbody>
							<?php foreach ( $fields as $field ) : ?>
								<?php if ( 'static' === $field->field_type ) {
									continue;
								} ?>
								<tr style="border-bottom:1px solid #eee;">
									<th style="padding:0.75rem 1rem;text-align:left;width:30%;color:#555;font-weight:600;vertical-align:top;">
										<?php echo esc_html( $field->label ); ?>
									</th>
									<td style="padding:0.75rem 1rem;">
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
	 * Renders the metadata section (form, timestamps, consent info).
	 *
	 * @param Submission $submission The submission.
	 * @param Form       $form      The parent form.
	 */
	private function render_metadata( Submission $submission, Form $form ): void {
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:4px;margin-bottom:1.5rem;">
			<div style="padding:1rem;border-bottom:1px solid #ddd;font-weight:600;">
				<?php esc_html_e( 'Metadaten', 'wp-dsgvo-form' ); ?>
			</div>
			<div style="padding:1rem;">
				<table style="width:100%;border-collapse:collapse;">
					<tbody>
						<tr style="border-bottom:1px solid #eee;">
							<th style="padding:0.5rem 1rem;text-align:left;width:30%;color:#555;">
								<?php esc_html_e( 'Formular', 'wp-dsgvo-form' ); ?>
							</th>
							<td style="padding:0.5rem 1rem;">
								<?php echo esc_html( $form->title ); ?>
							</td>
						</tr>
						<tr style="border-bottom:1px solid #eee;">
							<th style="padding:0.5rem 1rem;text-align:left;color:#555;">
								<?php esc_html_e( 'Eingegangen', 'wp-dsgvo-form' ); ?>
							</th>
							<td style="padding:0.5rem 1rem;">
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
							<tr style="border-bottom:1px solid #eee;">
								<th style="padding:0.5rem 1rem;text-align:left;color:#555;">
									<?php esc_html_e( 'Ablaufdatum', 'wp-dsgvo-form' ); ?>
								</th>
								<td style="padding:0.5rem 1rem;">
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
							<tr style="border-bottom:1px solid #eee;">
								<th style="padding:0.5rem 1rem;text-align:left;color:#555;">
									<?php esc_html_e( 'Einwilligung', 'wp-dsgvo-form' ); ?>
								</th>
								<td style="padding:0.5rem 1rem;">
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
							<?php if ( $consent_version_record !== null ) : ?>
								<tr style="border-bottom:1px solid #eee;">
									<th style="padding:0.5rem 1rem;text-align:left;color:#555;vertical-align:top;">
										<?php esc_html_e( 'Einwilligungstext', 'wp-dsgvo-form' ); ?>
									</th>
									<td style="padding:0.5rem 1rem;">
										<details>
											<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Text anzeigen', 'wp-dsgvo-form' ); ?></summary>
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
							<th style="padding:0.5rem 1rem;text-align:left;color:#555;">
								<?php esc_html_e( 'Rechtsgrundlage', 'wp-dsgvo-form' ); ?>
							</th>
							<td style="padding:0.5rem 1rem;">
								<?php echo esc_html( $form->legal_basis ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders DSGVO action buttons.
	 *
	 * - Restrict (Art. 18): Available to all recipients (data subjects can request restriction).
	 * - Unrestrict: Only supervisor/admin (DPO-BEDINGUNG-2: lifting restriction = controller decision).
	 * - Delete: Reserved for admins with dsgvo_form_delete_submissions capability.
	 *
	 * @param Submission $submission    The submission.
	 * @param bool       $is_privileged Whether user is supervisor/admin.
	 */
	private function render_dsgvo_actions( Submission $submission, bool $is_privileged ): void {
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:4px;">
			<div style="padding:1rem;border-bottom:1px solid #ddd;font-weight:600;">
				<?php esc_html_e( 'DSGVO-Aktionen', 'wp-dsgvo-form' ); ?>
			</div>
			<div style="padding:1rem;">
				<?php if ( $submission->is_restricted && $is_privileged ) : ?>
					<?php
					$unrestrict_url = wp_nonce_url(
						RecipientPage::get_view_url( $submission->id ) . '?do=unrestrict',
						'dsgvo_recipient_action_' . $submission->id
					);
					?>
					<a href="<?php echo esc_url( $unrestrict_url ); ?>"
						style="display:inline-block;padding:0.5rem 1rem;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:#333;">
						<?php esc_html_e( 'Sperre aufheben', 'wp-dsgvo-form' ); ?>
					</a>
				<?php elseif ( ! $submission->is_restricted ) : ?>
					<?php
					$restrict_url = wp_nonce_url(
						RecipientPage::get_view_url( $submission->id ) . '?do=restrict',
						'dsgvo_recipient_action_' . $submission->id
					);
					?>
					<a href="<?php echo esc_url( $restrict_url ); ?>"
						style="display:inline-block;padding:0.5rem 1rem;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:#333;">
						<?php esc_html_e( 'Sperren (Art. 18 DSGVO)', 'wp-dsgvo-form' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a notice for readers viewing restricted submissions.
	 *
	 * DPO-BEDINGUNG-1: Restricted submissions must not be decrypted for readers.
	 * Only metadata (form, date, status) is shown — no field data.
	 *
	 * @param int $submission_id The submission ID.
	 */
	private function render_restricted_notice( int $submission_id ): void {
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:4px;margin-bottom:1.5rem;">
			<div style="padding:1rem;border-bottom:1px solid #ddd;font-weight:600;font-size:1.1rem;">
				<?php
				printf(
					/* translators: %d: submission ID */
					esc_html__( 'Einsendung #%d', 'wp-dsgvo-form' ),
					(int) $submission_id
				);
				?>
			</div>
			<div style="padding:2rem;text-align:center;color:#666;">
				<p style="font-size:1.1rem;margin-bottom:0.5rem;">
					<strong><?php esc_html_e( 'Gesperrt (Art. 18 DSGVO)', 'wp-dsgvo-form' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Diese Einsendung ist gesperrt (Art. 18 DSGVO). Die Formulardaten koennen nicht angezeigt werden.', 'wp-dsgvo-form' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders an error message.
	 *
	 * @param string $message The error message.
	 */
	private function render_error( string $message ): void {
		?>
		<div style="padding:1rem;background:#f8d7da;border:1px solid #dc3545;border-radius:4px;color:#721c24;">
			<?php echo esc_html( $message ); ?>
		</div>
		<div style="margin-top:1rem;">
			<a href="<?php echo esc_url( RecipientPage::get_base_url() ); ?>"
				style="color:#2271b1;text-decoration:none;">
				&laquo; <?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
			</a>
		</div>
		<?php
	}
}
