<?php
/**
 * Submission list page.
 *
 * Renders the WP_List_Table overview of form submissions
 * with filtering by form, read status, and pagination.
 * Handles single-submission delete action.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the submission list admin page.
 */
class SubmissionListPage {

	/**
	 * Cascading submission deleter (SEC-FILE-09).
	 *
	 * @var SubmissionDeleter
	 */
	private SubmissionDeleter $deleter;

	/**
	 * Audit logger for DSGVO accountability (SEC-AUDIT-01).
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Constructor.
	 *
	 * @param SubmissionDeleter $deleter      Cascading deletion service.
	 * @param AuditLogger       $audit_logger Audit logging service.
	 */
	public function __construct( SubmissionDeleter $deleter, AuditLogger $audit_logger ) {
		$this->deleter      = $deleter;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Render the submission list page.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->handle_single_delete();

		$table = new SubmissionListTable( $this->deleter, $this->audit_logger );
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Einsendungen', 'wp-dsgvo-form' ); ?>
			</h1>

			<?php if ( current_user_can( 'dsgvo_form_manage' ) ) : ?>
				<a href="<?php echo esc_url( DataSubjectSearchPage::get_url() ); ?>" class="page-title-action">
					<?php esc_html_e( 'Betroffenen-Suche', 'wp-dsgvo-form' ); ?>
				</a>
			<?php endif; ?>

			<hr class="wp-header-end">

			<?php settings_errors( 'dsgvo_submission_messages' ); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::MENU_SLUG . '-submissions' ); ?>">
				<?php
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle single submission deletion via row action.
	 *
	 * @return void
	 */
	private function handle_single_delete(): void {
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'dsgvo_form_delete_submissions' ) ) {
			wp_die( esc_html__( 'Sie haben nicht die Berechtigung, Einsendungen zu loeschen.', 'wp-dsgvo-form' ) );
		}

		$submission_id = absint( $_GET['submission_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $submission_id < 1 ) {
			return;
		}

		check_admin_referer( 'dsgvo_submission_delete_' . $submission_id );

		// SEC-AUDIT-01: Log deletion BEFORE executing (data is gone after).
		$submission = Submission::find( $submission_id );

		if ( $submission === null ) {
			return;
		}

		// LEGAL-F01 / SEC-DSGVO-13: Restricted submissions cannot be deleted (Art. 18 DSGVO).
		if ( $submission->is_restricted ) {
			add_settings_error(
				'dsgvo_submission_messages',
				'submission_locked',
				sprintf(
					/* translators: %d: submission ID */
					__( 'Einsendung #%d ist gesperrt (Art. 18 DSGVO) und kann nicht geloescht werden.', 'wp-dsgvo-form' ),
					$submission_id
				),
				'error'
			);
			return;
		}

		$this->audit_logger->log( get_current_user_id(), 'delete', $submission_id, $submission->form_id );

		// SEC-FILE-09: Cascading deletion (files → DB) via SubmissionDeleter.
		$this->deleter->delete( $submission_id );

		add_settings_error(
			'dsgvo_submission_messages',
			'submission_deleted',
			sprintf(
				/* translators: %d: submission ID */
				__( 'Einsendung #%d wurde geloescht.', 'wp-dsgvo-form' ),
				$submission_id
			),
			'success'
		);
	}
}
