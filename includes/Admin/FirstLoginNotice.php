<?php
/**
 * First-login privacy notice for plugin role users.
 *
 * Art. 13 DSGVO requires informing data subjects about data processing.
 * Recipients (Reader/Supervisor) are data subjects because their access
 * is audit-logged. This class ensures acknowledgment before first use.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 13 DSGVO — Information obligations (recipients)
 * @privacy-relevant Art. 29, 32 DSGVO — Confidentiality obligation
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Recipient\RecipientPage;

/**
 * Displays a mandatory privacy notice on first login for plugin roles.
 *
 * UX-REC-02 (MUSS): Recipients must acknowledge the privacy notice
 * before they can access any submissions. The acknowledgment is
 * version-tracked in user_meta — when NOTICE_VERSION is bumped,
 * all users must re-acknowledge.
 *
 * Two enforcement paths:
 * - Admin area: current_screen hook (priority 5) redirects to hidden admin page
 * - Frontend (/dsgvo-empfaenger/): RecipientPage guard renders notice inline
 */
class FirstLoginNotice {

	/**
	 * User meta key storing the acknowledged notice version.
	 */
	public const META_KEY = 'wpdsgvo_privacy_notice_ack';

	/**
	 * Current notice version. Bump to force re-acknowledgment.
	 */
	private const NOTICE_VERSION = 1;

	/**
	 * Hidden admin page slug.
	 */
	private const PAGE_SLUG = 'dsgvo-form-acknowledge';

	/**
	 * Nonce action for the acknowledgment form.
	 */
	private const NONCE_ACTION = 'wpdsgvo_acknowledge_notice';

	/** @todo REFACTOR-01: Wird genutzt sobald needs_acknowledgment() auf Instanzmethode umgestellt wird. */
	private AccessControl $access_control;
	private AuditLogger $audit_logger;

	public function __construct( AccessControl $access_control, AuditLogger $audit_logger ) {
		$this->access_control = $access_control;
		$this->audit_logger   = $audit_logger;
	}

	/**
	 * Registers hooks for the first-login notice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'current_screen', [ $this, 'maybe_redirect_to_notice' ], 5 );
		add_action( 'admin_post_' . self::NONCE_ACTION, [ $this, 'handle_acknowledgment' ] );
	}

	/**
	 * Registers a hidden admin page for the acknowledgment form.
	 *
	 * No parent slug = page is hidden from the admin menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			null,
			__( 'Datenschutzhinweis', 'wp-dsgvo-form' ),
			'',
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_acknowledge_page' ]
		);
	}

	/**
	 * Redirects users with plugin access to the acknowledge page if needed.
	 *
	 * Runs at priority 5 on current_screen — BEFORE LoginRedirect's
	 * block_unauthorized_access (default priority 10).
	 *
	 * @param \WP_Screen $screen The current admin screen.
	 * @return void
	 */
	public function maybe_redirect_to_notice( \WP_Screen $screen ): void {
		$user_id = get_current_user_id();

		if ( ! self::needs_acknowledgment( $user_id ) ) {
			return;
		}

		// Don't redirect if already on the acknowledge page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page === self::PAGE_SLUG ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Renders the admin acknowledge page.
	 *
	 * @return void
	 */
	public function render_acknowledge_page(): void {
		$notice_text = $this->get_notice_text();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Datenschutzhinweis', 'wp-dsgvo-form' ); ?></h1>

			<div class="card" style="max-width:800px;padding:1.5rem;margin-top:1rem;">
				<?php echo wp_kses_post( $notice_text ); ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wpdsgvo_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=dsgvo-form-submissions' ) ); ?>">

				<p>
					<label>
						<input type="checkbox" name="acknowledge" value="1" required>
						<?php esc_html_e( 'Ich habe den Datenschutzhinweis gelesen und akzeptiere die Vertraulichkeitspflicht.', 'wp-dsgvo-form' ); ?>
					</label>
				</p>

				<?php submit_button( __( 'Bestaetigen', 'wp-dsgvo-form' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the acknowledgment notice as HTML for the recipient frontend.
	 *
	 * Used by RecipientPage as a guard — rendered instead of the normal content.
	 *
	 * @return string HTML with notice text and confirmation form.
	 */
	public function render_notice_frontend(): string {
		$notice_text = $this->get_notice_text();

		$html  = '<div class="dsgvo-recipient__notice">';
		$html .= '<h2>' . esc_html__( 'Datenschutzhinweis', 'wp-dsgvo-form' ) . '</h2>';
		$html .= '<div class="dsgvo-recipient__notice-text">' . wp_kses_post( $notice_text ) . '</div>';

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= wp_nonce_field( self::NONCE_ACTION, '_wpdsgvo_nonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_ACTION ) . '">';
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( RecipientPage::get_base_url() ) . '">';

		$html .= '<p style="margin-top:1.5rem;">';
		$html .= '<label>';
		$html .= '<input type="checkbox" name="acknowledge" value="1" required> ';
		$html .= esc_html__( 'Ich habe den Datenschutzhinweis gelesen und akzeptiere die Vertraulichkeitspflicht.', 'wp-dsgvo-form' );
		$html .= '</label>';
		$html .= '</p>';

		$html .= '<p style="margin-top:1rem;">';
		$html .= '<button type="submit" class="dsgvo-form__button">';
		$html .= esc_html__( 'Bestaetigen', 'wp-dsgvo-form' );
		$html .= '</button>';
		$html .= '</p>';

		$html .= '</form>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Handles the acknowledgment form POST.
	 *
	 * Verifies nonce, saves acknowledgment version to user_meta,
	 * logs the action to the audit log, and redirects.
	 *
	 * @return void
	 */
	public function handle_acknowledgment(): void {
		if (
			! isset( $_POST['_wpdsgvo_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpdsgvo_nonce'] ) ),
				self::NONCE_ACTION
			)
		) {
			wp_die(
				esc_html__( 'Sicherheitspruefung fehlgeschlagen.', 'wp-dsgvo-form' ),
				esc_html__( 'Fehler', 'wp-dsgvo-form' ),
				[ 'response' => 403 ]
			);
		}

		$user_id = get_current_user_id();

		if ( $user_id === 0 ) {
			wp_die(
				esc_html__( 'Sie muessen angemeldet sein.', 'wp-dsgvo-form' ),
				esc_html__( 'Fehler', 'wp-dsgvo-form' ),
				[ 'response' => 403 ]
			);
		}

		// Checkbox must be checked.
		if ( empty( $_POST['acknowledge'] ) ) {
			wp_die(
				esc_html__( 'Bitte bestaetigen Sie den Datenschutzhinweis.', 'wp-dsgvo-form' ),
				esc_html__( 'Fehler', 'wp-dsgvo-form' ),
				[ 'response' => 400 ]
			);
		}

		// Save acknowledgment version.
		update_user_meta( $user_id, self::META_KEY, self::NOTICE_VERSION );

		// SEC-AUDIT-01: Log the acknowledgment.
		$this->audit_logger->log(
			$user_id,
			'privacy_notice_acknowledged',
			null,
			null,
			'Version ' . self::NOTICE_VERSION
		);

		// Redirect to origin (admin or frontend recipient area).
		$redirect_to = isset( $_POST['redirect_to'] )
			? sanitize_url( wp_unslash( $_POST['redirect_to'] ) )
			: admin_url( 'admin.php?page=dsgvo-form-submissions' );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Checks whether a user needs to acknowledge the privacy notice.
	 *
	 * Phase 3 dual-check: Captures users with the dsgvo_form_recipient
	 * capability OR legacy plugin roles. This ensures ALL users with
	 * submission access are covered — including editors who receive
	 * plugin capabilities directly.
	 *
	 * LEGAL-MUSS: Every user with submission access MUST acknowledge.
	 * Admins with dsgvo_form_manage capability are exempt.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user must acknowledge before proceeding.
	 */
	public static function needs_acknowledgment( int $user_id ): bool {
		if ( $user_id === 0 ) {
			return false;
		}

		// Admins are exempt.
		if ( user_can( $user_id, 'dsgvo_form_manage' ) ) {
			return false;
		}

		// Phase 3 dual-check: capability-based (new) OR role-based (legacy).
		// LEGAL-MUSS: Must capture ALL users with submissions access.
		$has_capability = user_can( $user_id, AccessControl::RECIPIENT_CAPABILITY )
			|| user_can( $user_id, 'dsgvo_form_view_submissions' )
			|| user_can( $user_id, 'dsgvo_form_view_all_submissions' );

		$user = get_userdata( $user_id );

		$has_legacy_role = $user
			&& ! empty( array_intersect( AccessControl::PLUGIN_ROLES, $user->roles ) );

		if ( ! $has_capability && ! $has_legacy_role ) {
			return false;
		}

		// Check acknowledged version against current version.
		$ack_version = (int) get_user_meta( $user_id, self::META_KEY, true );

		return $ack_version < self::NOTICE_VERSION;
	}

	/**
	 * Returns the privacy notice text (filterable).
	 *
	 * Covers Art. 13 DSGVO (information for recipients as data subjects)
	 * and Art. 29/32 DSGVO (confidentiality obligation).
	 *
	 * @return string HTML-formatted notice text.
	 */
	private function get_notice_text(): string {
		$text  = '<h3>' . esc_html__( 'Informationspflicht gemaess Art. 13 DSGVO', 'wp-dsgvo-form' ) . '</h3>';
		$text .= '<p>' . esc_html__( 'Als Empfaenger von Formulareinsendungen verarbeiten Sie personenbezogene Daten, die ueber dieses Plugin uebermittelt wurden. Gemaess Art. 13 DSGVO informieren wir Sie ueber die Verarbeitung Ihrer eigenen Daten:', 'wp-dsgvo-form' ) . '</p>';

		$text .= '<h4>' . esc_html__( 'Verarbeitete Daten', 'wp-dsgvo-form' ) . '</h4>';
		$text .= '<ul>';
		$text .= '<li>' . esc_html__( 'Ihre Zugriffe auf Einsendungen werden protokolliert (Zeitpunkt, IP-Adresse, ausgefuehrte Aktion).', 'wp-dsgvo-form' ) . '</li>';
		$text .= '<li>' . esc_html__( 'Audit-Log-Eintraege werden 1 Jahr aufbewahrt. IP-Adressen werden nach 90 Tagen anonymisiert.', 'wp-dsgvo-form' ) . '</li>';
		$text .= '</ul>';

		$text .= '<p>' . esc_html__( 'Zugriff auf das Audit-Log haben ausschliesslich Administratoren mit der Berechtigung zur Plugin-Verwaltung.', 'wp-dsgvo-form' ) . '</p>';

		$text .= '<p>' . esc_html__( 'Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an der Nachvollziehbarkeit und Sicherheit der Datenverarbeitung) in Verbindung mit Art. 5 Abs. 2 DSGVO (Rechenschaftspflicht).', 'wp-dsgvo-form' ) . '</p>';

		$text .= '<h4>' . esc_html__( 'Vertraulichkeitspflicht (Art. 29, 32 DSGVO)', 'wp-dsgvo-form' ) . '</h4>';
		$text .= '<ul>';
		$text .= '<li>' . esc_html__( 'Sie duerfen die Ihnen zugaenglichen Einsendungen nur fuer den vorgesehenen Zweck verwenden.', 'wp-dsgvo-form' ) . '</li>';
		$text .= '<li>' . esc_html__( 'Eine Weitergabe an unbefugte Dritte ist untersagt.', 'wp-dsgvo-form' ) . '</li>';
		$text .= '<li>' . esc_html__( 'Bei Verdacht auf eine Datenschutzverletzung informieren Sie umgehend den Verantwortlichen.', 'wp-dsgvo-form' ) . '</li>';
		$text .= '</ul>';

		$text .= '<h4>' . esc_html__( 'Ihre Rechte', 'wp-dsgvo-form' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'Sie haben das Recht auf Auskunft, Berichtigung und Loeschung Ihrer im Audit-Log gespeicherten Daten. Wenden Sie sich hierzu an den Website-Administrator.', 'wp-dsgvo-form' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Sie haben zudem das Recht, Beschwerde bei einer Datenschutz-Aufsichtsbehoerde einzulegen (Art. 77 DSGVO).', 'wp-dsgvo-form' ) . '</p>';

		// SOLL-ARCH-04/FLN-03: Dynamic defaults for responsible entity.
		$site_name   = get_option( 'wpdsgvo_controller_name', '' );
		$admin_email = get_option( 'wpdsgvo_controller_email', '' );

		// Fallback to WordPress defaults if not configured.
		if ( '' === $site_name ) {
			$site_name = get_option( 'blogname' );
		}
		if ( '' === $admin_email ) {
			$admin_email = get_option( 'admin_email' );
		}
		$text       .= '<p>' . sprintf(
			/* translators: 1: site name, 2: admin email */
			esc_html__( 'Verantwortlicher: %1$s (Kontakt: %2$s)', 'wp-dsgvo-form' ),
			esc_html( $site_name ),
			esc_html( $admin_email )
		) . '</p>';

		/**
		 * Filters the first-login privacy notice text.
		 *
		 * @param string $text The HTML-formatted notice text.
		 */
		return apply_filters( 'wpdsgvo_first_login_notice_text', $text );
	}
}
