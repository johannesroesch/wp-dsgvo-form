<?php
/**
 * Data subject search page (Art. 15/17 DSGVO).
 *
 * Allows administrators to search for all submissions belonging to
 * a data subject by email address. Uses the HMAC-SHA256 email_lookup_hash
 * (blind index) to find submissions without storing plaintext emails.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;

/**
 * Renders the data subject search admin page.
 *
 * Hidden admin page (no menu entry) — accessible via link in the
 * submission list page header. Restricted to administrators with
 * dsgvo_form_manage capability.
 *
 * LEGAL-RIGHTS-02: Every search is audit-logged to ensure accountability.
 */
class DataSubjectSearchPage {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'dsgvo-form-subject-search';

	/**
	 * Nonce action for the search form.
	 */
	private const NONCE_ACTION = 'wpdsgvo_subject_search';

	private EncryptionService $encryption;
	private AuditLogger $audit_logger;

	public function __construct( EncryptionService $encryption, AuditLogger $audit_logger ) {
		$this->encryption   = $encryption;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Renders the data subject search page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die(
				esc_html__( 'Sie haben keine Berechtigung fuer diese Seite.', 'wp-dsgvo-form' ),
				esc_html__( 'Zugriff verweigert', 'wp-dsgvo-form' ),
				[ 'response' => 403 ]
			);
		}

		$email       = '';
		$submissions = null;
		$searched    = false;

		if (
			isset( $_POST['_wpdsgvo_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpdsgvo_nonce'] ) ),
				self::NONCE_ACTION
			)
		) {
			$email = isset( $_POST['subject_email'] )
				? sanitize_email( wp_unslash( $_POST['subject_email'] ) )
				: '';

			if ( $email !== '' && is_email( $email ) ) {
				$searched    = true;
				$lookup_hash = $this->encryption->calculate_email_lookup_hash( $email );
				$submissions = Submission::find_by_email_lookup_hash( $lookup_hash );

				// SEC-AUDIT-01: Log every data subject search for accountability.
				$this->audit_logger->log(
					get_current_user_id(),
					'view',
					null,
					null,
					sprintf(
						'Data subject search (Art. 15/17 DSGVO): %d results, hash=%s',
						count( $submissions ),
						substr( $lookup_hash, 0, 12 ) . '...'
					)
				);
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Betroffenen-Suche (Art. 15/17 DSGVO)', 'wp-dsgvo-form' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Suchen Sie nach allen Einsendungen eines Betroffenen anhand seiner E-Mail-Adresse. Jede Suche wird im Audit-Log protokolliert.', 'wp-dsgvo-form' ); ?>
			</p>

			<form method="post" style="margin-top:1.5rem;">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wpdsgvo_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="subject_email">
								<?php esc_html_e( 'E-Mail-Adresse', 'wp-dsgvo-form' ); ?>
							</label>
						</th>
						<td>
							<input type="email"
								name="subject_email"
								id="subject_email"
								value="<?php echo esc_attr( $email ); ?>"
								class="regular-text"
								required>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Suchen', 'wp-dsgvo-form' ), 'primary', 'submit', true ); ?>
			</form>

			<?php if ( $searched ) : ?>
				<hr>

				<?php if ( empty( $submissions ) ) : ?>
					<div class="notice notice-info inline" style="margin-top:1rem;">
						<p>
							<?php esc_html_e( 'Keine Einsendungen fuer diese E-Mail-Adresse gefunden.', 'wp-dsgvo-form' ); ?>
						</p>
					</div>
				<?php else : ?>
					<h2>
						<?php
						printf(
							/* translators: %d: number of submissions */
							esc_html( _n( '%d Einsendung gefunden', '%d Einsendungen gefunden', count( $submissions ), 'wp-dsgvo-form' ) ),
							count( $submissions )
						);
						?>
					</h2>

					<table class="widefat striped" style="margin-top:1rem;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Formular', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Eingereicht am', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Aktionen', 'wp-dsgvo-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $submissions as $submission ) : ?>
								<?php $form = Form::find( $submission->form_id ); ?>
								<tr>
									<td><?php echo esc_html( (string) $submission->id ); ?></td>
									<td><?php echo esc_html( $form ? $form->title : __( '(Formular geloescht)', 'wp-dsgvo-form' ) ); ?></td>
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
									<td>
										<?php if ( $submission->is_restricted ) : ?>
											<span style="color:#dc3232;">
												<?php esc_html_e( 'Gesperrt (Art. 18)', 'wp-dsgvo-form' ); ?>
											</span>
										<?php elseif ( $submission->is_read ) : ?>
											<?php esc_html_e( 'Gelesen', 'wp-dsgvo-form' ); ?>
										<?php else : ?>
											<strong><?php esc_html_e( 'Ungelesen', 'wp-dsgvo-form' ); ?></strong>
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( admin_url( sprintf( 'admin.php?page=%s-submissions&action=view&submission_id=%d', AdminMenu::MENU_SLUG, $submission->id ) ) ); ?>">
											<?php esc_html_e( 'Anzeigen', 'wp-dsgvo-form' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description" style="margin-top:1rem;">
						<?php esc_html_e( 'Hinweis: Nutzen Sie die WordPress-Werkzeuge unter Werkzeuge → Personenbezogene Daten exportieren/loeschen fuer formelle Art. 15/17 Anfragen.', 'wp-dsgvo-form' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns the URL to the data subject search page.
	 *
	 * @return string Admin URL.
	 */
	public static function get_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}
}
