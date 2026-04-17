<?php
declare(strict_types=1);

namespace WpDsgvoForm\Block;

defined('ABSPATH') || exit;

use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Field;

/**
 * Gutenberg Block registration and server-side rendering.
 *
 * Registers the dsgvo-form/form block, provides the render callback
 * for dynamic server-side rendering, and enqueues the CAPTCHA script
 * only on pages that contain the block.
 *
 * Architecture reference: ARCHITECTURE.md §6
 */
class FormBlock {

	/**
	 * Text domain for i18n.
	 */
	private const TEXT_DOMAIN = 'wp-dsgvo-form';

	/**
	 * Default CAPTCHA service base URL (fallback if option not configured).
	 */
	private const DEFAULT_CAPTCHA_URL = 'https://captcha.repaircafe-bruchsal.de';

	/**
	 * Returns the configured CAPTCHA base URL.
	 *
	 * Reads from wp_options (configurable via SettingsPage).
	 * Falls back to DEFAULT_CAPTCHA_URL if not set.
	 *
	 * @return string CAPTCHA base URL (always without trailing slash).
	 */
	private function get_captcha_url(): string {
		$url = get_option( 'wpdsgvo_captcha_base_url', self::DEFAULT_CAPTCHA_URL );

		if ( empty( $url ) || ! is_string( $url ) ) {
			$url = self::DEFAULT_CAPTCHA_URL;
		}

		return esc_url( rtrim( $url, '/' ) );
	}

	/**
	 * Registers the block type and shortcode with WordPress.
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			plugin_dir_path( __DIR__ ) . '../build/block/block.json',
			[
				'render_callback' => [ $this, 'render' ],
			]
		);

		// Shortcode: [dsgvo_form id="123"]
		add_shortcode( 'dsgvo_form', [ $this, 'shortcode_handler' ] );

		// Provide forms list for the editor REST API.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Localize editor script with admin data.
		add_action( 'enqueue_block_editor_assets', [ $this, 'localize_editor_script' ] );
	}

	/**
	 * Handles the [dsgvo_form] shortcode.
	 *
	 * Usage: [dsgvo_form id="123"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered form HTML.
	 */
	public function shortcode_handler( $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'dsgvo_form' );

		return $this->render( [ 'formId' => (int) $atts['id'] ] );
	}

	/**
	 * Registers REST API routes for the block editor.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'dsgvo-form/v1',
			'/forms',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_forms' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Returns active forms for the block editor dropdown.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_forms(): \WP_REST_Response {
		$forms = Form::find_all( true );

		$data = array_map(
			static function ( Form $form ): array {
				return [
					'id'    => $form->id,
					'title' => $form->title,
					'slug'  => $form->slug,
				];
			},
			$forms
		);

		return new \WP_REST_Response( array_values( $data ), 200 );
	}

	/**
	 * Localizes the editor script with admin URL data.
	 */
	public function localize_editor_script(): void {
		wp_add_inline_script(
			'dsgvo-form-form-editor-script',
			'window.dsgvoFormAdmin = ' . wp_json_encode( [
				'adminUrl' => admin_url(),
			] ) . ';',
			'before'
		);
	}

	/**
	 * Renders the block on the frontend (server-side rendering).
	 *
	 * Loads form configuration and fields from the database,
	 * generates the HTML form markup including CAPTCHA widget
	 * and consent checkbox.
	 *
	 * @param array  $attributes Block attributes (formId).
	 * @param string $content    Inner block content (empty for dynamic blocks).
	 * @return string Rendered HTML or empty string.
	 */
	public function render( array $attributes, string $content = '' ): string {
		$form_id = (int) ( $attributes['formId'] ?? 0 );

		if ( $form_id < 1 ) {
			return $this->admin_notice( __( 'Kein Formular ausgewaehlt.', self::TEXT_DOMAIN ) );
		}

		$form = Form::find( $form_id );

		if ( $form === null ) {
			return $this->admin_notice(
				sprintf(
					/* translators: %d: form ID */
					__( 'Formular #%d nicht gefunden.', self::TEXT_DOMAIN ),
					$form_id
				)
			);
		}

		if ( ! $form->is_active ) {
			return $this->admin_notice(
				sprintf(
					/* translators: %s: form title */
					__( 'Formular "%s" ist deaktiviert.', self::TEXT_DOMAIN ),
					$form->title
				)
			);
		}

		$fields = Field::find_by_form_id( $form_id );

		if ( empty( $fields ) ) {
			return $this->admin_notice(
				sprintf(
					/* translators: %s: form title */
					__( 'Formular "%s" hat keine Felder. Bitte Felder im Admin-Bereich hinzufuegen.', self::TEXT_DOMAIN ),
					$form->title
				)
			);
		}

		// Determine locale for consent text (DPO-FINDING-13).
		$locale = $this->get_current_locale();

		// DPO MUSS-3: Fail-Closed — do not render form if no ConsentVersion for current locale.
		$consent_version = null;
		if ( $form->legal_basis === 'consent' ) {
			$consent_version = ConsentVersion::get_current_version( $form_id, $locale );

			if ( $consent_version === null ) {
				return $this->admin_notice(
					sprintf(
						/* translators: 1: form title, 2: locale string */
						__( 'Formular "%1$s" kann nicht angezeigt werden: Kein Einwilligungstext fuer Sprache "%2$s" vorhanden. Bitte unter DSGVO Formulare → Einwilligungstexte anlegen.', self::TEXT_DOMAIN ),
						$form->title,
						$locale
					)
				);
			}
		}

		// Enqueue frontend form handler and styles on pages with this block.
		$this->enqueue_frontend_script();
		$this->enqueue_frontend_styles();

		// Enqueue CAPTCHA script only when enabled for this form.
		if ( $form->captcha_enabled ) {
			$this->enqueue_captcha_assets();
		}

		return $this->build_form_html( $form, $fields, $locale, $consent_version );
	}

	/**
	 * Builds the complete form HTML markup.
	 *
	 * @param Form                $form            The form configuration.
	 * @param Field[]             $fields          The form fields.
	 * @param string              $locale          Current locale (e.g. de_DE).
	 * @param ConsentVersion|null $consent_version The current consent version (null for contract basis).
	 * @return string Complete form HTML.
	 */
	private function build_form_html( Form $form, array $fields, string $locale, ?ConsentVersion $consent_version ): string {
		$form_id = $form->id;

		$html  = '<div class="wp-block-dsgvo-form-form">';
		$html .= '<form id="dsgvo-' . esc_attr( (string) $form_id ) . '" class="dsgvo-form" data-form-id="' . esc_attr( (string) $form_id ) . '" '
			. 'data-locale="' . esc_attr( $locale ) . '" method="post" novalidate>';

		// CSRF protection.
		$html .= wp_nonce_field( 'dsgvo_form_submit_' . $form_id, '_dsgvo_nonce', true, false );
		$html .= '<input type="hidden" name="dsgvo_form_id" value="' . esc_attr( (string) $form_id ) . '">';

		// Honeypot field — hidden via CSS, bots auto-fill it (SEC-BOT-01).
		$html .= '<div class="dsgvo-form__hp" aria-hidden="true" style="position:absolute;left:-9999px;">';
		$html .= '<label for="dsgvo-hp-' . esc_attr( (string) $form_id ) . '">'
			. esc_html__( 'Website', self::TEXT_DOMAIN ) . '</label>';
		$html .= '<input type="text" id="dsgvo-hp-' . esc_attr( (string) $form_id ) . '"'
			. ' name="website_url" value="" tabindex="-1" autocomplete="off">';
		$html .= '</div>';

		// Render each field.
		foreach ( $fields as $field ) {
			$html .= $this->render_field( $field );
		}

		// Consent checkbox (if legal basis is consent).
		if ( $form->legal_basis === 'consent' && $consent_version !== null ) {
			$html .= $this->render_consent_checkbox( $form, $consent_version );
		}

		// CAPTCHA widget (only when enabled for this form).
		if ( $form->captcha_enabled ) {
			$captcha_url = $this->get_captcha_url();
			$html .= '<div class="dsgvo-form__captcha">';
			$html .= '<captcha-widget '
				. 'form-id="dsgvo-' . esc_attr( (string) $form_id ) . '" '
				. 'server-url="' . esc_url( $captcha_url ) . '" '
				. 'lang="' . esc_attr( substr( $locale, 0, 2 ) ) . '" '
				. 'theme="auto">'
				. '</captcha-widget>';
			$html .= '</div>';
		}

		// Submit button.
		$html .= '<div class="dsgvo-form__submit">';
		$html .= '<button type="submit" class="dsgvo-form__button">';
		$html .= esc_html__( 'Absenden', self::TEXT_DOMAIN );
		$html .= '</button>';
		$html .= '</div>';

		// Status message area.
		$html .= '<div class="dsgvo-form__status" role="alert" aria-live="polite"></div>';

		$html .= '</form>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders a single form field.
	 *
	 * @param Field $field The field definition.
	 * @return string Field HTML.
	 */
	private function render_field( Field $field ): string {
		// Static content fields render directly without input.
		if ( $field->field_type === 'static' ) {
			return '<div class="dsgvo-form__static ' . esc_attr( $field->css_class ) . '">'
				. wp_kses_post( $field->static_content )
				. '</div>';
		}

		$required_attr = $field->is_required ? ' required aria-required="true"' : '';
		$required_mark = $field->is_required
			? ' <span class="dsgvo-form__required" aria-hidden="true">*</span>'
			: '';

		$field_id = 'dsgvo-field-' . esc_attr( (string) $field->id );

		$html  = '<div class="dsgvo-form__field dsgvo-form__field--' . esc_attr( $field->field_type )
			. ' ' . esc_attr( $field->css_class ) . '">';
		$html .= '<label for="' . $field_id . '" id="' . $field_id . '-label" class="dsgvo-form__label">'
			. esc_html( $field->label ) . $required_mark . '</label>';

		switch ( $field->field_type ) {
			case 'textarea':
				$html .= '<textarea id="' . $field_id . '" name="' . esc_attr( $field->name ) . '"'
					. ' class="dsgvo-form__input dsgvo-form__textarea"'
					. ' placeholder="' . esc_attr( $field->placeholder ) . '"'
					. $required_attr . ' rows="5"></textarea>';
				break;

			case 'select':
				$html .= '<select id="' . $field_id . '" name="' . esc_attr( $field->name ) . '"'
					. ' class="dsgvo-form__input dsgvo-form__select"' . $required_attr . '>';
				$html .= '<option value="">' . esc_html__( 'Bitte waehlen', self::TEXT_DOMAIN ) . '</option>';
				foreach ( $field->get_options() as $option ) {
					$html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
				}
				$html .= '</select>';
				break;

			case 'radio':
				$html .= '<div class="dsgvo-form__radio-group" role="radiogroup"'
					. ' aria-labelledby="' . $field_id . '-label">';
				foreach ( $field->get_options() as $index => $option ) {
					$option_id = $field_id . '-' . $index;
					$html     .= '<label class="dsgvo-form__radio-label">'
						. '<input type="radio" id="' . $option_id . '" name="' . esc_attr( $field->name ) . '"'
						. ' value="' . esc_attr( $option ) . '"' . $required_attr . '>'
						. ' ' . esc_html( $option )
						. '</label>';
				}
				$html .= '</div>';
				break;

			case 'checkbox':
				$options = $field->get_options();
				if ( ! empty( $options ) ) {
					// Multi-option checkboxes — render each option individually.
					$html .= '<div class="dsgvo-form__checkbox-group">';
					foreach ( $options as $index => $option ) {
						$option_id = $field_id . '-' . $index;
						$html     .= '<label class="dsgvo-form__checkbox-label">'
							. '<input type="checkbox" id="' . $option_id . '" name="' . esc_attr( $field->name ) . '[]"'
							. ' value="' . esc_attr( $option ) . '" class="dsgvo-form__checkbox"' . $required_attr . '>'
							. ' ' . esc_html( $option )
							. '</label>';
					}
					$html .= '</div>';
				} else {
					// Single boolean checkbox (no options defined).
					$html .= '<input type="checkbox" id="' . $field_id . '" name="' . esc_attr( $field->name ) . '"'
						. ' value="1" class="dsgvo-form__input dsgvo-form__checkbox"' . $required_attr . '>';
				}
				break;

			case 'file':
				$config = $field->get_file_config();
				$accept = '';
				if ( ! empty( $config['allowed_types'] ) ) {
					$accept_values = array_map(
						static fn( string $t ): string => '.' . ltrim( $t, '.' ),
						(array) $config['allowed_types']
					);
					$accept = ' accept="' . esc_attr( implode( ',', $accept_values ) ) . '"';
				}
				$html .= '<input type="file" id="' . $field_id . '" name="' . esc_attr( $field->name ) . '"'
					. ' class="dsgvo-form__input dsgvo-form__file"' . $accept . $required_attr . '>';
				break;

			default:
				// text, email, tel, date.
				$type  = in_array( $field->field_type, [ 'text', 'email', 'tel', 'date' ], true )
					? $field->field_type
					: 'text';
				$html .= '<input type="' . esc_attr( $type ) . '" id="' . $field_id . '"'
					. ' name="' . esc_attr( $field->name ) . '"'
					. ' class="dsgvo-form__input"'
					. ' placeholder="' . esc_attr( $field->placeholder ) . '"'
					. $required_attr . '>';
				break;
		}

		// Validation error placeholder.
		$html .= '<div class="dsgvo-form__error" role="alert"></div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders the DSGVO consent checkbox.
	 *
	 * Uses the versioned consent text from dsgvo_consent_versions table
	 * (Art. 7 Abs. 1 DSGVO — exact consent wording traceable per submission).
	 *
	 * @param Form           $form            The form configuration.
	 * @param ConsentVersion $consent_version The current consent version for this locale.
	 * @return string Consent checkbox HTML.
	 *
	 * @privacy-relevant Art. 7 DSGVO — Einwilligungs-Checkbox mit versioniertem Text
	 */
	private function render_consent_checkbox( Form $form, ConsentVersion $consent_version ): string {
		$field_id = 'dsgvo-consent-' . $form->id;

		$html  = '<div class="dsgvo-form__field dsgvo-form__field--consent">';
		$html .= '<label for="' . $field_id . '" class="dsgvo-form__consent-label">';
		$html .= '<input type="checkbox" id="' . $field_id . '" name="dsgvo_consent"'
			. ' value="1" required aria-required="true" class="dsgvo-form__checkbox">';
		$html .= ' <span class="dsgvo-form__consent-text">' . wp_kses_post( $consent_version->consent_text ) . '</span>';
		$html .= '</label>';
		$html .= '<input type="hidden" name="dsgvo_consent_version" value="' . esc_attr( (string) $consent_version->id ) . '">';
		$html .= '<div class="dsgvo-form__error" role="alert"></div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Enqueues the CAPTCHA Web Component script (defer, in footer).
	 *
	 * Uses the local bundled captcha.min.js by default (when the CAPTCHA
	 * server URL matches the built-in default). Falls back to the external
	 * URL when the admin has configured a custom CAPTCHA server.
	 *
	 * SRI integrity: For the local file, uses the build-generated constant
	 * WPDSGVO_CAPTCHA_SRI. For external URLs, uses the admin-configured
	 * wpdsgvo_captcha_sri_hash option.
	 */
	private function enqueue_captcha_assets(): void {
		if ( wp_script_is( 'dsgvo-captcha', 'enqueued' ) ) {
			return;
		}

		$captcha_base_url = $this->get_captcha_url();
		$use_local        = ( $captcha_base_url === self::DEFAULT_CAPTCHA_URL );

		if ( $use_local ) {
			$script_url = WPDSGVO_PLUGIN_URL . 'public/js/captcha.min.js';
		} else {
			$script_url = $captcha_base_url . '/captcha.js';
		}

		wp_enqueue_script(
			'dsgvo-captcha',
			esc_url_raw( $script_url ),
			[],
			$use_local ? WPDSGVO_VERSION : null,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// SRI integrity attribute (SEC-SRI-01).
		// Local file: build-generated constant. External: admin-configured option.
		$sri_hash = '';

		if ( $use_local && defined( 'WPDSGVO_CAPTCHA_SRI' ) && WPDSGVO_CAPTCHA_SRI !== '' ) {
			$sri_hash = WPDSGVO_CAPTCHA_SRI;
		} elseif ( ! $use_local ) {
			$sri_hash = get_option( 'wpdsgvo_captcha_sri_hash', '' );
		}

		if ( is_string( $sri_hash ) && $sri_hash !== '' ) {
			add_filter( 'script_loader_tag', function ( string $tag, string $handle ) use ( $sri_hash ): string {
				if ( 'dsgvo-captcha' !== $handle ) {
					return $tag;
				}

				$tag = str_replace( ' src=', ' integrity="' . esc_attr( $sri_hash ) . '" crossorigin="anonymous" src=', $tag );

				return $tag;
			}, 10, 2 );
		}
	}

	/**
	 * Enqueues the frontend CSS stylesheet.
	 *
	 * Only loaded on pages that contain a DSGVO form block.
	 */
	private function enqueue_frontend_styles(): void {
		if ( wp_style_is( 'dsgvo-form-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'dsgvo-form-frontend',
			WPDSGVO_PLUGIN_URL . 'public/css/dsgvo-form.css',
			[],
			WPDSGVO_VERSION
		);
	}

	/**
	 * Enqueues the frontend form handler script with i18n strings.
	 *
	 * Loaded in footer with defer strategy. Provides the REST URL
	 * and translatable validation messages via wp_localize_script().
	 */
	private function enqueue_frontend_script(): void {
		if ( wp_script_is( 'dsgvo-form-handler', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'dsgvo-form-handler',
			WPDSGVO_PLUGIN_URL . 'build/frontend/form-handler.js',
			[],
			WPDSGVO_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_localize_script(
			'dsgvo-form-handler',
			'dsgvoFormHandler',
			[
				'restUrl' => esc_url_raw( rest_url( 'dsgvo-form/v1/submit' ) ),
				'i18n'    => [
					'required'         => __( 'Dieses Feld ist erforderlich.', self::TEXT_DOMAIN ),
					'emailInvalid'     => __( 'Bitte geben Sie eine gueltige E-Mail-Adresse ein.', self::TEXT_DOMAIN ),
					'telInvalid'       => __( 'Bitte geben Sie eine gueltige Telefonnummer ein.', self::TEXT_DOMAIN ),
					'dateInvalid'      => __( 'Bitte geben Sie ein gueltiges Datum ein.', self::TEXT_DOMAIN ),
					'consentRequired'  => __( 'Sie muessen der Datenverarbeitung zustimmen.', self::TEXT_DOMAIN ),
					'captchaRequired'  => __( 'Bitte loesen Sie das CAPTCHA.', self::TEXT_DOMAIN ),
					'fileTooLarge'     => __( 'Die Datei ist zu gross.', self::TEXT_DOMAIN ),
					'fileTypeNotAllowed' => __( 'Dieser Dateityp ist nicht erlaubt.', self::TEXT_DOMAIN ),
					'submitting'       => __( 'Wird gesendet...', self::TEXT_DOMAIN ),
					'networkError'     => __( 'Netzwerkfehler. Bitte pruefen Sie Ihre Verbindung.', self::TEXT_DOMAIN ),
					'genericError'     => __( 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es spaeter erneut.', self::TEXT_DOMAIN ),
				],
			]
		);
	}

	/**
	 * Returns the current locale (language detection priority).
	 *
	 * Priority: WP Locale of current page → site default.
	 *
	 * @return string Locale string (e.g. de_DE).
	 */
	private function get_current_locale(): string {
		return determine_locale();
	}

	/**
	 * Returns an admin-only notice explaining why a form is not rendered.
	 *
	 * Visible only to logged-in users with dsgvo_form_manage capability.
	 * Regular visitors see nothing (DSGVO fail-closed compliant).
	 *
	 * @param string $message The notice message.
	 * @return string HTML notice or empty string.
	 */
	private function admin_notice( string $message ): string {
		if ( ! $this->is_form_admin() ) {
			return '';
		}

		return '<div class="wp-block-dsgvo-form-form dsgvo-form-admin-notice" style="'
			. 'padding:1rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#856404;margin:1rem 0;'
			. '">'
			. '<strong>' . esc_html__( 'DSGVO Formular:', self::TEXT_DOMAIN ) . '</strong> '
			. esc_html( $message )
			. '</div>';
	}

	/**
	 * Checks if the current user has form management capabilities.
	 *
	 * Returns false gracefully in test environments where WordPress
	 * capability functions may not be fully available.
	 *
	 * @return bool True if the current user can manage DSGVO forms.
	 */
	private function is_form_admin(): bool {
		try {
			return function_exists( 'current_user_can' ) && current_user_can( 'dsgvo_form_manage' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
