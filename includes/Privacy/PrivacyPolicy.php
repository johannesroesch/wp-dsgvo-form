<?php
/**
 * Privacy policy suggested content for WordPress Privacy Page.
 *
 * Registers a suggested privacy policy text via wp_add_privacy_policy_content()
 * (WordPress 4.9.6+). The text covers Art. 13 DSGVO mandatory disclosure
 * requirements specific to this plugin's data processing.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 13 DSGVO — Information obligations
 */

declare(strict_types=1);

namespace WpDsgvoForm\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Provides suggested privacy policy content for the WordPress Privacy Page.
 *
 * LEGAL-F02: Integrates with WordPress core privacy tools (since 4.9.6)
 * to suggest Art. 13 DSGVO-compliant text covering:
 * - Data collection scope
 * - Encryption (AES-256)
 * - Retention periods
 * - Legal basis
 * - Data subject rights
 * - CAPTCHA processing
 */
class PrivacyPolicy {

	/**
	 * Plugin display name for the privacy policy section.
	 */
	private const PLUGIN_NAME = 'WP DSGVO Form';

	/**
	 * Registers the privacy policy content hook.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Adds the suggested privacy policy content to the WordPress Privacy Page.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		$content = $this->get_suggested_content();

		/**
		 * Filters the suggested privacy policy content.
		 *
		 * @param string $content The suggested HTML content for the privacy policy.
		 */
		$content = apply_filters( 'wpdsgvo_privacy_policy_content', $content );

		wp_add_privacy_policy_content( self::PLUGIN_NAME, $content );
	}

	/**
	 * Returns the suggested privacy policy content.
	 *
	 * Covers Art. 13 DSGVO mandatory information requirements:
	 * 1. Data collection and scope
	 * 2. Encryption and security measures
	 * 3. Retention periods
	 * 4. Legal basis for processing
	 * 5. Data subject rights
	 * 6. CAPTCHA processing
	 *
	 * @return string HTML-formatted privacy policy suggestion.
	 */
	private function get_suggested_content(): string {
		$content = '';

		$content .= '<h2>' . __( 'Kontaktformulare (WP DSGVO Form)', 'wp-dsgvo-form' ) . '</h2>';

		$content .= '<h3>' . __( 'Welche Daten wir erheben', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Wenn Sie ein Kontaktformular auf dieser Website absenden, erheben wir die von Ihnen eingegebenen Daten (z.B. Name, E-Mail-Adresse, Nachricht) sowie technische Metadaten (Zeitpunkt der Einsendung).', 'wp-dsgvo-form' ) . '</p>';

		$content .= '<h3>' . __( 'Wie wir Ihre Daten schuetzen', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Alle ueber Kontaktformulare uebermittelten Daten werden unmittelbar nach dem Empfang mit AES-256-Verschluesselung (Authenticated Encryption) verschluesselt gespeichert. Die Verschluesselung erfolgt serverseitig, bevor die Daten in die Datenbank geschrieben werden. Hochgeladene Dateien werden ebenfalls verschluesselt abgelegt.', 'wp-dsgvo-form' ) . '</p>';

		$content .= '<h3>' . __( 'Speicherdauer', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Die Speicherdauer Ihrer Formulardaten richtet sich nach der fuer das jeweilige Formular konfigurierten Aufbewahrungsfrist. Nach Ablauf dieser Frist werden Ihre Daten automatisch und unwiderruflich geloescht. Die konkrete Speicherdauer wird Ihnen im jeweiligen Formular mitgeteilt.', 'wp-dsgvo-form' ) . '</p>';

		$content .= '<h3>' . __( 'Empfaenger Ihrer Daten', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Zugriff auf Ihre Formulardaten haben ausschliesslich die dem jeweiligen Formular zugeordneten Empfaenger. Die konkreten Empfaenger werden Ihnen im jeweiligen Formular mitgeteilt. Der Hosting-Anbieter hat als Auftragsverarbeiter gegebenenfalls technischen Zugang zu den verschluesselten Daten.', 'wp-dsgvo-form' ) . '</p>';

		$content .= '<h3>' . __( 'Rechtsgrundlage der Verarbeitung', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Die Verarbeitung Ihrer Formulardaten erfolgt je nach Formular auf Grundlage von:', 'wp-dsgvo-form' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'Art. 6 Abs. 1 lit. a DSGVO (Einwilligung) — wenn das Formular eine ausdrueckliche Einwilligung erfordert', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Art. 6 Abs. 1 lit. b DSGVO (Vertragsdurchfuehrung) — wenn die Datenverarbeitung zur Erfuellung eines Vertrags erforderlich ist', 'wp-dsgvo-form' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h3>' . __( 'Ihre Rechte', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Sie haben jederzeit das Recht auf:', 'wp-dsgvo-form' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( 'Auskunft ueber Ihre gespeicherten Daten (Art. 15 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Berichtigung unrichtiger Daten (Art. 16 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Loeschung Ihrer Daten (Art. 17 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Einschraenkung der Verarbeitung (Art. 18 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Datenuebertragbarkeit (Art. 20 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '<li>' . __( 'Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)', 'wp-dsgvo-form' ) . '</li>';
		$content .= '</ul>';
		$content .= '<p>' . __( 'Wenn Sie eine Einwilligung erteilt haben, koennen Sie diese jederzeit mit Wirkung fuer die Zukunft widerrufen (Art. 7 Abs. 3 DSGVO).', 'wp-dsgvo-form' ) . '</p>';
		$content .= '<p>' . __( 'Sie haben zudem das Recht, Beschwerde bei einer Datenschutz-Aufsichtsbehoerde einzulegen (Art. 77 DSGVO).', 'wp-dsgvo-form' ) . '</p>';

		$content .= '<h3>' . __( 'CAPTCHA-Verifizierung', 'wp-dsgvo-form' ) . '</h3>';
		$content .= '<p>' . __( 'Zum Schutz vor automatisierten Einsendungen (Spam) wird beim Absenden des Formulars ein CAPTCHA-Verfahren eingesetzt. Dabei wird Ihre IP-Adresse an den CAPTCHA-Dienstleister uebertragen. Die Verarbeitung erfolgt auf Grundlage unseres berechtigten Interesses an der Abwehr von Spam und Missbrauch (Art. 6 Abs. 1 lit. f DSGVO). Der CAPTCHA-Dienst speichert Ihre IP-Adresse nicht dauerhaft.', 'wp-dsgvo-form' ) . '</p>';

		return $content;
	}
}
