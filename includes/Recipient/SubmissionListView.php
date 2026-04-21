<?php
/**
 * Recipient-facing submission list view.
 *
 * Displays submissions accessible to the current recipient user
 * with form filter, status filter, and pagination.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Recipient;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Recipient;
use WpDsgvoForm\Models\Submission;

/**
 * Renders the submission list for the recipient area.
 *
 * Access tiers (SEC-AUTH-DSGVO-03):
 * - Reader: Only submissions for assigned forms (via recipient table).
 * - Supervisor: All submissions, every list access is audit-logged.
 *
 * UX-Concept §2.2: List with form filter, status filter, pagination (20/page).
 */
class SubmissionListView {

	private const PER_PAGE = 20;

	private AccessControl $access_control;
	private AuditLogger $audit_logger;

	public function __construct( AccessControl $access_control, AuditLogger $audit_logger ) {
		$this->access_control = $access_control;
		$this->audit_logger   = $audit_logger;
	}

	/**
	 * Renders the full submission list view.
	 *
	 * @param int $user_id Current user ID.
	 */
	public function render( int $user_id ): void {
		$is_supervisor = $this->access_control->is_supervisor( $user_id );

		// SEC-AUDIT-01: Log supervisor list access.
		if ( $is_supervisor ) {
			$this->audit_logger->log( $user_id, 'view', null, null, 'recipient_list_view' );
		}

		$accessible_forms = $this->get_accessible_forms( $user_id, $is_supervisor );

		// Filter parameters from GET.
		$filter_form_id = absint( $_GET['form_id'] ?? 0 ); // nosemgrep: echoed-request — absint() + selected()/esc_url() escapen den Wert // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_status  = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ); // nosemgrep: echoed-request — sanitize_text_field() + selected()/esc_url() escapen den Wert // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Verify form access for filter.
		if ( $filter_form_id > 0 && ! $this->access_control->can_view_form( $user_id, $filter_form_id ) ) {
			$filter_form_id = 0;
		}

		// Determine status filter.
		$is_read = null;
		// DPO-EMPFEHLUNG: Privacy-by-Default — readers don't see restricted submissions.
		// Supervisors/admins see restricted submissions for oversight.
		$include_restricted = $is_supervisor || $this->access_control->is_admin( $user_id );
		if ( 'new' === $filter_status ) {
			$is_read = false;
		} elseif ( 'read' === $filter_status ) {
			$is_read = true;
		}

		// Build submissions list.
		$submissions = array();
		$total_count = 0;

		if ( $filter_form_id > 0 ) {
			// Single form filter.
			$submissions = Submission::find_by_form_id( $filter_form_id, $current_page, self::PER_PAGE, $is_read, $include_restricted );
			$total_count = Submission::count_by_form_id( $filter_form_id, $is_read, $include_restricted );
		} else {
			// All accessible forms — single SQL query with proper pagination.
			$form_ids    = array_map( static fn( $form ) => $form->id, $accessible_forms );
			$submissions = Submission::find_by_form_ids( $form_ids, $current_page, self::PER_PAGE, $is_read, $include_restricted );
			$total_count = Submission::count_by_form_ids( $form_ids, $is_read, $include_restricted );
		}

		$total_pages = (int) ceil( $total_count / self::PER_PAGE );

		// Form lookup for display.
		$form_map = array();
		foreach ( $accessible_forms as $form ) {
			$form_map[ $form->id ] = $form->title;
		}

		$this->render_filters( $accessible_forms, $filter_form_id, $filter_status );
		$this->render_table( $submissions, $form_map );
		$this->render_pagination( $current_page, $total_pages, $total_count );
	}

	/**
	 * Returns forms accessible to the current user.
	 *
	 * @param int  $user_id      Current user ID.
	 * @param bool $is_supervisor Whether the user is a supervisor.
	 * @return Form[] Accessible forms.
	 */
	private function get_accessible_forms( int $user_id, bool $is_supervisor ): array {
		if ( $is_supervisor || $this->access_control->is_admin( $user_id ) ) {
			return Form::find_all();
		}

		return $this->get_assigned_forms( $user_id );
	}

	/**
	 * Returns forms assigned to a reader via the recipients table.
	 *
	 * @param int $user_id The reader's user ID.
	 * @return Form[] Assigned forms.
	 */
	private function get_assigned_forms( int $user_id ): array {
		global $wpdb;

		$table = Recipient::get_table_name();

		// SEC-SQL-01: Prepared statement.
		$form_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT form_id FROM `{$table}` WHERE user_id = %d",
				$user_id
			)
		);

		if ( empty( $form_ids ) ) {
			return array();
		}

		$forms = array();
		foreach ( $form_ids as $form_id ) {
			$form = Form::find( (int) $form_id );
			if ( null !== $form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Renders the filter bar (form dropdown + status filter).
	 *
	 * @param Form[] $forms           Available forms.
	 * @param int    $active_form_id  Currently selected form filter.
	 * @param string $active_status   Currently selected status filter.
	 */
	private function render_filters( array $forms, int $active_form_id, string $active_status ): void {
		$base_url = RecipientPage::get_base_url();
		?>
		<div class="dsgvo-recipient__filters" style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
			<form method="get" action="<?php echo esc_url( $base_url ); ?>" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
				<?php if ( count( $forms ) > 1 ) : ?>
					<select name="form_id" style="padding:0.4rem 0.6rem;border:1px solid #ccc;border-radius:4px;">
						<option value="0"><?php esc_html_e( 'Alle Formulare', 'wp-dsgvo-form' ); ?></option>
						<?php foreach ( $forms as $form ) : ?>
							<option value="<?php echo esc_attr( (string) $form->id ); ?>"
								<?php selected( $active_form_id, $form->id ); ?>>
								<?php echo esc_html( $form->title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<select name="status" style="padding:0.4rem 0.6rem;border:1px solid #ccc;border-radius:4px;">
					<option value=""><?php esc_html_e( 'Alle Status', 'wp-dsgvo-form' ); ?></option>
					<option value="new" <?php selected( $active_status, 'new' ); ?>>
						<?php esc_html_e( 'Neu', 'wp-dsgvo-form' ); ?>
					</option>
					<option value="read" <?php selected( $active_status, 'read' ); ?>>
						<?php esc_html_e( 'Gelesen', 'wp-dsgvo-form' ); ?>
					</option>
				</select>

				<button type="submit" style="padding:0.4rem 1rem;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;">
					<?php esc_html_e( 'Filtern', 'wp-dsgvo-form' ); ?>
				</button>

				<?php if ( $active_form_id > 0 || '' !== $active_status ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" style="padding:0.4rem 0.6rem;color:#666;text-decoration:none;">
						<?php esc_html_e( 'Filter zuruecksetzen', 'wp-dsgvo-form' ); ?>
					</a>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the submissions table.
	 *
	 * @param Submission[] $submissions Submissions to display.
	 * @param array<int, string> $form_map  Form ID → title map.
	 */
	private function render_table( array $submissions, array $form_map ): void {
		if ( empty( $submissions ) ) {
			?>
			<div style="padding:2rem;text-align:center;color:#666;background:#f9f9f9;border-radius:4px;">
				<?php esc_html_e( 'Keine Einsendungen gefunden.', 'wp-dsgvo-form' ); ?>
			</div>
			<?php
			return;
		}

		?>
		<table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #ddd;">
			<thead>
				<tr style="background:#f5f5f5;border-bottom:2px solid #ddd;">
					<th style="padding:0.75rem 1rem;text-align:left;font-weight:600;">
						<?php esc_html_e( '#', 'wp-dsgvo-form' ); ?>
					</th>
					<th style="padding:0.75rem 1rem;text-align:left;font-weight:600;">
						<?php esc_html_e( 'Formular', 'wp-dsgvo-form' ); ?>
					</th>
					<th style="padding:0.75rem 1rem;text-align:left;font-weight:600;">
						<?php esc_html_e( 'Eingegangen', 'wp-dsgvo-form' ); ?>
					</th>
					<th style="padding:0.75rem 1rem;text-align:left;font-weight:600;">
						<?php esc_html_e( 'Status', 'wp-dsgvo-form' ); ?>
					</th>
					<th style="padding:0.75rem 1rem;text-align:left;font-weight:600;">
						<?php esc_html_e( 'Aktion', 'wp-dsgvo-form' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $submissions as $submission ) : ?>
					<?php
					$row_style = 'padding:0.75rem 1rem;border-bottom:1px solid #eee;';
					if ( ! $submission->is_read ) {
						$row_style .= 'font-weight:600;';
					}
					?>
					<tr>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<?php echo esc_html( (string) $submission->id ); ?>
						</td>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<?php echo esc_html( $form_map[ $submission->form_id ] ?? '—' ); ?>
						</td>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $submission->submitted_at )
								)
							);
							?>
						</td>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<?php echo esc_html( $this->get_status_label( $submission ) ); ?>
						</td>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<a href="<?php echo esc_url( RecipientPage::get_view_url( $submission->id ) ); ?>"
								style="color:#2271b1;text-decoration:none;">
								<?php esc_html_e( 'Anzeigen', 'wp-dsgvo-form' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns a human-readable status label for a submission.
	 *
	 * @param Submission $submission The submission.
	 * @return string Status label.
	 */
	private function get_status_label( Submission $submission ): string {
		if ( $submission->is_restricted ) {
			return __( 'Gesperrt (Art. 18)', 'wp-dsgvo-form' );
		}

		if ( ! $submission->is_read ) {
			return __( 'Neu', 'wp-dsgvo-form' );
		}

		return __( 'Gelesen', 'wp-dsgvo-form' );
	}

	/**
	 * Renders pagination controls.
	 *
	 * @param int $current_page Current page number.
	 * @param int $total_pages  Total number of pages.
	 * @param int $total_count  Total number of submissions.
	 */
	private function render_pagination( int $current_page, int $total_pages, int $total_count ): void {
		if ( $total_pages <= 1 ) {
			?>
			<div style="margin-top:1rem;color:#666;font-size:0.9rem;">
				<?php
				printf(
					/* translators: %d: number of submissions */
					esc_html( _n( '%d Einsendung', '%d Einsendungen', $total_count, 'wp-dsgvo-form' ) ),
					(int) $total_count
				);
				?>
			</div>
			<?php
			return;
		}

		$base_url = RecipientPage::get_base_url();

		// Preserve current filter parameters.
		$query_args = array();
		if ( ! empty( $_GET['form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['form_id'] = absint( $_GET['form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		?>
		<div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;padding:0.75rem 0;">
				<span style="color:#666;font-size:0.9rem;">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages, 3: total submissions */
					esc_html__( 'Seite %1$d von %2$d (%3$d Einsendungen)', 'wp-dsgvo-form' ),
					(int) $current_page,
					(int) $total_pages,
					(int) $total_count
				);
				?>
			</span>
			<div style="display:flex;gap:0.5rem;">
				<?php if ( $current_page > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array_merge( $query_args, array( 'paged' => $current_page - 1 ) ), $base_url ) ); ?>"
						style="padding:0.4rem 0.8rem;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:#333;">
						&laquo; <?php esc_html_e( 'Zurueck', 'wp-dsgvo-form' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $current_page < $total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array_merge( $query_args, array( 'paged' => $current_page + 1 ) ), $base_url ) ); ?>"
						style="padding:0.4rem 0.8rem;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:#333;">
						<?php esc_html_e( 'Weiter', 'wp-dsgvo-form' ); ?> &raquo;
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
