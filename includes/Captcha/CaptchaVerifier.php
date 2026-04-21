<?php
/**
 * CAPTCHA token verification against external service.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Captcha;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Models\Form;

/**
 * CAPTCHA token verification against the external CAPTCHA service.
 *
 * Implements fail-closed behaviour: any error (network, timeout, invalid
 * response) results in a failed verification.
 *
 * Security requirements: SEC-CAP-01 through SEC-CAP-08.
 *
 * @security-critical Fail-closed on timeout/error (SEC-CAP-04)
 */
class CaptchaVerifier {

	/**
	 * Request timeout in seconds (SEC-CAP-04).
	 */
	private const TIMEOUT = 5;

	/**
	 * The CAPTCHA server base URL (from WPDSGVO_CAPTCHA_URL constant).
	 */
	private string $base_url;

	/**
	 * The server-to-server validation URL (derived from base URL, SEC-CAP-05).
	 */
	private string $validate_url;

	public function __construct() {
		$this->base_url     = WPDSGVO_CAPTCHA_URL;
		$this->validate_url = WPDSGVO_CAPTCHA_URL . '/api/validate';
	}

	/**
	 * Validates a CAPTCHA verification token via the server-to-server
	 * /api/validate endpoint.
	 *
	 * SEC-CAP-01: Server-side validation via POST to /api/validate.
	 * SEC-CAP-04: Fail-closed — timeout, error, or missing API key returns false.
	 * SEC-CAP-06: HTTPS enforced, certificate validated.
	 * SEC-CAP-08: Only the verification token is sent, no user data.
	 *
	 * @param string $token The verification token from the captcha-widget hidden input.
	 * @return bool True if the token is valid, false otherwise.
	 */
	public function verify( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		// SEC-CAP-04: Fail-closed — no API key configured means no verification possible.
		$api_key = get_option( 'wpdsgvo_captcha_secret', '' );

		if ( ! is_string( $api_key ) || '' === $api_key ) {
			return false;
		}

		// SEC-CAP-08: Only send the verification token, no IP or user-agent.
		$response = wp_remote_post(
			$this->validate_url,
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'      => wp_json_encode( array( 'verification_token' => $token ) ),
				'timeout'   => self::TIMEOUT,
				'sslverify' => true, // SEC-CAP-06: Validate certificate.
			)
		);

		// SEC-CAP-04: Fail-closed on WP_Error (network error, timeout).
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return false;
		}

		return ( $body['valid'] ?? false ) === true;
	}

	/**
	 * Checks whether CAPTCHA is enabled for a specific form.
	 *
	 * SEC-CAP-07: CAPTCHA is per-form toggleable via the captcha_enabled
	 * column in the forms table (secure default = enabled).
	 *
	 * @param int $form_id The form ID.
	 * @return bool True if CAPTCHA is enabled for this form.
	 */
	public function is_enabled_for_form( int $form_id ): bool {
		$global_mode = get_option( 'dsgvo_form_captcha_mode', 'always' );

		if ( 'off' === $global_mode ) {
			return false;
		}

		// SEC-CAP-07: Read per-form CAPTCHA setting from the Form model.
		$form = Form::find( $form_id );

		if ( null === $form ) {
			return true; // Secure default: enable CAPTCHA for unknown forms.
		}

		return $form->captcha_enabled;
	}

	/**
	 * Returns the CAPTCHA script URL for frontend embedding.
	 *
	 * @return string The URL to the CAPTCHA JavaScript.
	 */
	public function get_script_url(): string {
		return $this->base_url . '/captcha.js';
	}
}
