<?php
/**
 * CAPTCHA token verification against external service.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Captcha;

defined('ABSPATH') || exit;

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
	 * Default verification endpoint.
	 */
	private const DEFAULT_VERIFY_URL = 'https://captcha.repaircafe-bruchsal.de/api/verify';

	/**
	 * Request timeout in seconds (SEC-CAP-04).
	 */
	private const TIMEOUT = 5;

	/**
	 * The verification URL (configurable via admin settings, SEC-CAP-05).
	 */
	private string $verify_url;

	/**
	 * @param string $verify_url Optional custom CAPTCHA verification URL.
	 */
	public function __construct( string $verify_url = '' ) {
		if ( $verify_url === '' ) {
			$verify_url = get_option( 'wpdsgvo_captcha_verify_url', self::DEFAULT_VERIFY_URL );
		}

		// SEC-CAP-06: Enforce HTTPS.
		if ( strpos( $verify_url, 'https://' ) !== 0 ) {
			$verify_url = self::DEFAULT_VERIFY_URL;
		}

		$this->verify_url = $verify_url;
	}

	/**
	 * Verifies a CAPTCHA token against the external service.
	 *
	 * SEC-CAP-01: Server-side verification via POST.
	 * SEC-CAP-04: Fail-closed — timeout or error returns false.
	 * SEC-CAP-06: HTTPS enforced, certificate validated.
	 * SEC-CAP-08: Only the token is sent, no user data.
	 *
	 * @param string $token The CAPTCHA response token from the frontend.
	 * @return bool True if the token is valid, false otherwise.
	 */
	public function verify( string $token ): bool {
		if ( $token === '' ) {
			return false;
		}

		// SEC-CAP-08: Only send the token, no IP or user-agent.
		$response = wp_remote_post( $this->verify_url, [
			'body'      => [ 'token' => $token ],
			'timeout'   => self::TIMEOUT,
			'sslverify' => true, // SEC-CAP-06: Validate certificate.
		] );

		// SEC-CAP-04: Fail-closed on WP_Error (network error, timeout).
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
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

		if ( $global_mode === 'off' ) {
			return false;
		}

		// SEC-CAP-07: Read per-form CAPTCHA setting from the Form model.
		$form = Form::find( $form_id );

		if ( $form === null ) {
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
		$base = rtrim( dirname( $this->verify_url, 2 ), '/' );

		return $base . '/captcha.js';
	}
}
