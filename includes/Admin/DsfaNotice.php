<?php
/**
 * DSFA (Data Protection Impact Assessment) notice.
 *
 * Displays a dismissible admin notice when the plugin exceeds
 * thresholds that suggest a DSFA may be required per Art. 35 DSGVO.
 *
 * @package WpDsgvoForm
 * @privacy-relevant Art. 35 DSGVO — Datenschutz-Folgenabschaetzung
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;

/**
 * Checks form/submission volume and shows a DSFA recommendation.
 *
 * LEGAL-F04: Triggers when Form count > 5 OR total submissions > 1000.
 */
class DsfaNotice {

	/**
	 * Maximum number of forms before DSFA hint.
	 */
	private const FORM_THRESHOLD = 5;

	/**
	 * Maximum number of submissions before DSFA hint.
	 */
	private const SUBMISSION_THRESHOLD = 1000;

	/**
	 * User meta key to track dismissal.
	 */
	private const DISMISS_META_KEY = 'wpdsgvo_dsfa_notice_dismissed';

	/**
	 * Registers the admin notice hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'maybe_show_notice' ] );
		add_action( 'wp_ajax_wpdsgvo_dismiss_dsfa_notice', [ $this, 'handle_dismiss' ] );
	}

	/**
	 * Conditionally displays the DSFA notice.
	 *
	 * @return void
	 */
	public function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if already dismissed.
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true ) ) {
			return;
		}

		// Only show on plugin pages.
		$screen = get_current_screen();
		if ( $screen === null || strpos( $screen->id, 'dsgvo-form' ) === false ) {
			return;
		}

		if ( ! $this->thresholds_exceeded() ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible" id="wpdsgvo-dsfa-notice">
			<p>
				<strong><?php esc_html_e( 'DSFA-Empfehlung (Art. 35 DSGVO):', 'wp-dsgvo-form' ); ?></strong>
				<?php esc_html_e( 'Der Umfang der Datenverarbeitung ueber dieses Plugin (Anzahl Formulare oder Einsendungen) deutet darauf hin, dass eine Datenschutz-Folgenabschaetzung (DSFA) nach Art. 35 DSGVO empfohlen wird. Bitte wenden Sie sich an Ihren Datenschutzbeauftragten.', 'wp-dsgvo-form' ); ?>
			</p>
		</div>
		<script>
		jQuery(document).on('click', '#wpdsgvo-dsfa-notice .notice-dismiss', function() {
			jQuery.post(ajaxurl, {action: 'wpdsgvo_dismiss_dsfa_notice', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'wpdsgvo_dismiss_dsfa' ) ); ?>'});
		});
		</script>
		<?php
	}

	/**
	 * Handles AJAX dismiss request.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( 'wpdsgvo_dismiss_dsfa', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, 1 );
		wp_die();
	}

	/**
	 * Checks whether form or submission thresholds are exceeded.
	 *
	 * @return bool True if DSFA notice should be shown.
	 */
	private function thresholds_exceeded(): bool {
		global $wpdb;

		$form_table       = Form::get_table_name();
		$submission_table = Submission::get_table_name();

		// SEC-SOLL-01: Defense-in-depth — use prepare() even without user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$form_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$form_table}` WHERE 1 = %d", 1 )
		);

		if ( $form_count > self::FORM_THRESHOLD ) {
			return true;
		}

		// SEC-SOLL-01: Defense-in-depth — use prepare() even without user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$submission_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$submission_table}` WHERE 1 = %d", 1 )
		);

		return $submission_count > self::SUBMISSION_THRESHOLD;
	}
}
