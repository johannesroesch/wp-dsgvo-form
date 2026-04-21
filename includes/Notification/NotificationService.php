<?php
/**
 * E-Mail notification service.
 *
 * Sends notification emails to form recipients when new submissions arrive.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Notification;

defined( 'ABSPATH' ) || exit;

/**
 * E-Mail notification service for form submissions.
 *
 * Sends notification emails to form recipients when new submissions arrive.
 * Per SEC-MAIL-03: Emails contain NO decrypted form data — only a
 * notification with a link to the authenticated login area.
 *
 * Security requirements: SEC-MAIL-01 through SEC-MAIL-05.
 */
class NotificationService {


	/**
	 * Sends notification emails to all active recipients of a form.
	 *
	 * @param int    $form_id       The form ID.
	 * @param int    $submission_id The new submission ID.
	 * @param string $form_title    The form title (for email subject).
	 * @return int Number of emails successfully sent.
	 */
	public function notify( int $form_id, int $submission_id, string $form_title ): int {
		$recipients = $this->get_recipients( $form_id );

		if ( empty( $recipients ) ) {
			return 0;
		}

		$subject = $this->build_subject( $form_title );
		$body    = $this->build_body( $form_title );
		$headers = $this->build_headers();
		$sent    = 0;

		foreach ( $recipients as $recipient ) {
			$user = get_userdata( $recipient->user_id );

			if ( ! $user || empty( $user->user_email ) ) {
				continue;
			}

			$email = sanitize_email( $user->user_email );

			if ( empty( $email ) ) {
				continue;
			}

			// SEC-MAIL-01: Only wp_mail(), never mail() directly.
			$result = wp_mail( $email, $subject, $body, $headers );

			if ( $result ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Sends a notification to a single recipient.
	 *
	 * @param int    $user_id       The WordPress user ID.
	 * @param int    $submission_id The submission ID.
	 * @param string $form_title    The form title.
	 * @return bool True if the email was sent successfully.
	 */
	public function notify_single( int $user_id, int $submission_id, string $form_title ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$email = sanitize_email( $user->user_email );

		if ( empty( $email ) ) {
			return false;
		}

		$subject = $this->build_subject( $form_title );
		$body    = $this->build_body( $form_title );
		$headers = $this->build_headers();

		return wp_mail( $email, $subject, $body, $headers );
	}

	/**
	 * Builds the email subject line.
	 *
	 * SEC-MAIL-02: Subject sanitized to prevent header injection.
	 *
	 * @param string $form_title The form title.
	 * @return string Sanitized subject line.
	 */
	private function build_subject( string $form_title ): string {
		$sanitized_title = sanitize_text_field( $form_title );

		// SEC-MAIL-02: Remove line breaks that could cause header injection.
		$sanitized_title = str_replace( array( "\r", "\n" ), '', $sanitized_title );

		return sprintf(
			/* translators: %s: form title */
			__( 'Neue Einsendung: %s', 'wp-dsgvo-form' ),
			$sanitized_title
		);
	}

	/**
	 * Builds the email body.
	 *
	 * SEC-MAIL-03: Contains NO form data — only a notification
	 * with a link to the authenticated submissions page.
	 *
	 * @param string $form_title The form title.
	 * @return string HTML email body.
	 */
	private function build_body( string $form_title ): string {
		$login_url = $this->get_submissions_url();

		$sanitized_title = esc_html( sanitize_text_field( $form_title ) );

		$body  = '<!DOCTYPE html><html><body>';
		$body .= '<p>' . sprintf(
			/* translators: %s: form title */
			esc_html__( 'Eine neue Einsendung ist fuer das Formular "%s" eingegangen.', 'wp-dsgvo-form' ),
			$sanitized_title
		) . '</p>';
		$body .= '<p>' . esc_html__(
			'Bitte melden Sie sich an, um die Einsendung einzusehen:',
			'wp-dsgvo-form'
		) . '</p>';
		$body .= '<p><a href="' . esc_url( $login_url ) . '">'
			. esc_html__( 'Zum Login-Bereich', 'wp-dsgvo-form' )
			. '</a></p>';
		$body .= '<hr>';
		$body .= '<p><small>' . esc_html__(
			'Diese E-Mail enthaelt aus Datenschutzgruenden keine Formulardaten. Bitte melden Sie sich im geschuetzten Bereich an, um die Einsendung einzusehen.',
			'wp-dsgvo-form'
		) . '</small></p>';
		$body .= '</body></html>';

		return $body;
	}

	/**
	 * Builds the email headers.
	 *
	 * @return string[] Email headers array.
	 */
	private function build_headers(): array {
		return array(
			'Content-Type: text/html; charset=UTF-8',
		);
	}

	/**
	 * Gets all active email recipients for a form.
	 *
	 * SEC-MAIL-04: Recipients validated against WordPress user system.
	 *
	 * @param int $form_id The form ID.
	 * @return object[] Array of recipient records with user_id property.
	 */
	private function get_recipients( int $form_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'dsgvo_form_recipients';

		// SEC-SQL-01: All queries via $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id FROM `{$table}` WHERE form_id = %d AND notify_email = 1",
				$form_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Returns the URL to the submissions page in the admin area.
	 *
	 * @return string Admin URL for submissions page.
	 */
	private function get_submissions_url(): string {
		return admin_url( 'admin.php?page=dsgvo-form-submissions' );
	}
}
