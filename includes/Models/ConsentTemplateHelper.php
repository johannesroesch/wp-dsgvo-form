<?php
/**
 * Consent template helper.
 *
 * Provides draft consent text templates per locale for initial
 * consent version creation. Templates are marked as DRAFT until
 * legal review and approval by the legal expert.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 7 DSGVO — Einwilligungstext-Vorlagen
 */

declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Provides locale-specific consent text templates.
 *
 * Templates are draft placeholders — they MUST be reviewed and approved
 * by the legal expert before use in production forms.
 *
 * LEGAL-I18N-04: Single source of truth for supported locales is
 * ConsentVersion::SUPPORTED_LOCALES. This class delegates to it.
 */
class ConsentTemplateHelper {

	/**
	 * Draft consent text templates per locale.
	 *
	 * IMPORTANT: All templates are marked with <!-- DRAFT --> and must
	 * receive legal approval before being used in production.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATES = [
		'de_DE' => '<!-- DRAFT -->Ich willige ein, dass meine Angaben durch {controller_name} zur Bearbeitung meiner Anfrage erhoben und verarbeitet werden. Die Daten werden nach abgeschlossener Bearbeitung geloescht. Hinweis: Sie koennen Ihre Einwilligung jederzeit fuer die Zukunft per E-Mail an {controller_email} widerrufen. Detaillierte Informationen zum Umgang mit Nutzerdaten finden Sie in unserer Datenschutzerklaerung.',

		'en_US' => '<!-- DRAFT -->I consent to my data being collected and processed by {controller_name} for the purpose of handling my inquiry. The data will be deleted after processing is complete. Note: You may revoke your consent at any time by sending an email to {controller_email}. Detailed information on data handling can be found in our privacy policy.',

		'fr_FR' => '<!-- DRAFT -->Je consens a ce que mes donnees soient collectees et traitees par {controller_name} dans le but de traiter ma demande. Les donnees seront supprimees une fois le traitement termine. Remarque: Vous pouvez revoquer votre consentement a tout moment par e-mail a {controller_email}. Des informations detaillees sur le traitement des donnees figurent dans notre politique de confidentialite.',

		'es_ES' => '<!-- DRAFT -->Doy mi consentimiento para que mis datos sean recopilados y procesados por {controller_name} con el fin de gestionar mi consulta. Los datos seran eliminados una vez finalizado el procesamiento. Nota: Puede revocar su consentimiento en cualquier momento enviando un correo electronico a {controller_email}. Encontrara informacion detallada sobre el tratamiento de datos en nuestra politica de privacidad.',

		'it_IT' => '<!-- DRAFT -->Acconsento alla raccolta e al trattamento dei miei dati da parte di {controller_name} al fine di gestire la mia richiesta. I dati saranno cancellati al termine dell\'elaborazione. Nota: e possibile revocare il consenso in qualsiasi momento tramite e-mail a {controller_email}. Informazioni dettagliate sul trattamento dei dati sono disponibili nella nostra informativa sulla privacy.',

		'sv_SE' => '<!-- DRAFT -->Jag samtycker till att mina uppgifter samlas in och behandlas av {controller_name} for att hantera min forfragan. Uppgifterna raderas efter avslutad behandling. Observera: Du kan nar som helst aterkalla ditt samtycke via e-post till {controller_email}. Detaljerad information om datahantering finns i var integritetspolicy.',
	];

	/**
	 * Returns the draft consent text template for the given locale.
	 *
	 * The returned text is a starting point and MUST be reviewed
	 * by the legal expert before production use.
	 *
	 * Filterable via 'wpdsgvo_consent_template' for site-specific customization.
	 *
	 * @param string $locale Locale code (e.g. de_DE, en_US).
	 * @return string The consent text template, or empty string if locale is unsupported.
	 */
	public static function get_template( string $locale ): string {
		$template = self::TEMPLATES[ $locale ] ?? '';

		/**
		 * Filters the consent text template for a locale.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template The consent text template (may contain <!-- DRAFT -->).
		 * @param string $locale   The locale code (e.g. de_DE).
		 */
		return apply_filters( 'wpdsgvo_consent_template', $template, $locale );
	}

	/**
	 * Returns all available locales with their display labels.
	 *
	 * Delegates to ConsentVersion::SUPPORTED_LOCALES as single source of truth.
	 *
	 * @return array<string, string> Locale code => display label.
	 */
	public static function get_available_locales(): array {
		return apply_filters( 'wpdsgvo_supported_locales', ConsentVersion::SUPPORTED_LOCALES );
	}
}
