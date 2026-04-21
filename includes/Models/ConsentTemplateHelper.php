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
	private const TEMPLATES = array(
		'de_DE' => '<!-- DRAFT -->Ich willige ein, dass meine Angaben durch {controller_name} zur Bearbeitung meiner Anfrage erhoben und verarbeitet werden. Die Daten werden nach Ablauf von {retention_days} Tagen geloescht. Hinweis: Sie koennen Ihre Einwilligung jederzeit fuer die Zukunft per E-Mail an {controller_email} widerrufen. Detaillierte Informationen zum Umgang mit Nutzerdaten finden Sie in unserer <a href="{privacy_policy_url}">Datenschutzerklaerung</a>.',

		'en_US' => '<!-- DRAFT -->I consent to my data being collected and processed by {controller_name} for the purpose of handling my inquiry. The data will be deleted after {retention_days} days. Note: You may revoke your consent at any time by sending an email to {controller_email}. Detailed information on data handling can be found in our <a href="{privacy_policy_url}">privacy policy</a>.',

		'fr_FR' => '<!-- DRAFT -->Je consens a ce que mes donnees soient collectees et traitees par {controller_name} dans le but de traiter ma demande. Les donnees seront supprimees apres {retention_days} jours. Remarque: Vous pouvez revoquer votre consentement a tout moment par e-mail a {controller_email}. Des informations detaillees sur le traitement des donnees figurent dans notre <a href="{privacy_policy_url}">politique de confidentialite</a>.',

		'es_ES' => '<!-- DRAFT -->Doy mi consentimiento para que mis datos sean recopilados y procesados por {controller_name} con el fin de gestionar mi consulta. Los datos seran eliminados despues de {retention_days} dias. Nota: Puede revocar su consentimiento en cualquier momento enviando un correo electronico a {controller_email}. Encontrara informacion detallada sobre el tratamiento de datos en nuestra <a href="{privacy_policy_url}">politica de privacidad</a>.',

		'it_IT' => '<!-- DRAFT -->Acconsento alla raccolta e al trattamento dei miei dati da parte di {controller_name} al fine di gestire la mia richiesta. I dati saranno cancellati dopo {retention_days} giorni. Nota: e possibile revocare il consenso in qualsiasi momento tramite e-mail a {controller_email}. Informazioni dettagliate sul trattamento dei dati sono disponibili nella nostra <a href="{privacy_policy_url}">informativa sulla privacy</a>.',

		'nl_NL' => '<!-- DRAFT -->Ik geef toestemming voor het verzamelen en verwerken van mijn gegevens door {controller_name} voor de afhandeling van mijn verzoek. De gegevens worden na {retention_days} dagen automatisch verwijderd. Opmerking: U kunt uw toestemming op elk moment intrekken door een e-mail te sturen naar {controller_email}. Gedetailleerde informatie over de omgang met persoonsgegevens vindt u in ons <a href="{privacy_policy_url}">privacybeleid</a>.',

		'pl_PL' => '<!-- DRAFT -->Wyrażam zgodę na zbieranie i przetwarzanie moich danych przez {controller_name} w celu obsługi mojego zapytania. Dane zostaną automatycznie usunięte po {retention_days} dniach. Uwaga: Zgodę można wycofać w dowolnym momencie, wysyłając wiadomość e-mail na adres {controller_email}. Szczegółowe informacje dotyczące przetwarzania danych osobowych znajdują się w naszej <a href="{privacy_policy_url}">polityce prywatności</a>.',

		'sv_SE' => '<!-- DRAFT -->Jag samtycker till att mina uppgifter samlas in och behandlas av {controller_name} for att hantera min forfragan. Uppgifterna raderas efter {retention_days} dagar. Observera: Du kan nar som helst aterkalla ditt samtycke via e-post till {controller_email}. Detaljerad information om datahantering finns i var <a href="{privacy_policy_url}">integritetspolicy</a>.',
	);

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

	/**
	 * Resolves placeholders in a consent text template.
	 *
	 * DPO-SOLL-F04: {retention_days} replaced with form's retention period.
	 * DPO-SOLL-F05: {controller_email} replaced with configured controller email.
	 *
	 * @param string $template           Template text with placeholders.
	 * @param Form   $form               Form for context-specific values (retention_days).
	 * @param string $privacy_policy_url Optional privacy policy URL to resolve {privacy_policy_url}.
	 * @return string Template with placeholders replaced.
	 */
	public static function resolve_placeholders( string $template, Form $form, string $privacy_policy_url = '' ): string {
		$replacements = array(
			'{controller_name}'  => get_option( 'wpdsgvo_controller_name', '' ),
			'{controller_email}' => get_option( 'wpdsgvo_controller_email', '' ),
			'{retention_days}'   => (string) $form->retention_days,
		);

		if ( '' !== $privacy_policy_url ) {
			$replacements['{privacy_policy_url}'] = $privacy_policy_url;
		}

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$template
		);
	}

	/**
	 * Returns the resolved consent template for a locale and form.
	 *
	 * Convenience method: fetches template and resolves all placeholders.
	 * UX-TMPL-01: Used by ConsentManagementPage for "Vorlage laden" feature.
	 *
	 * @param string $locale             Locale code (e.g. de_DE).
	 * @param Form   $form               Form for context-specific values.
	 * @param string $privacy_policy_url Optional privacy policy URL.
	 * @return string Resolved template, or empty string if locale unsupported.
	 */
	public static function get_resolved_template( string $locale, Form $form, string $privacy_policy_url = '' ): string {
		$template = self::get_template( $locale );

		if ( '' === $template ) {
			return '';
		}

		return self::resolve_placeholders( $template, $form, $privacy_policy_url );
	}
}
