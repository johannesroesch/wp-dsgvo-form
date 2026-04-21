<?php
/**
 * Submission list table.
 *
 * Extends WP_List_Table to display form submissions
 * with filtering by form, read status, and pagination.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table subclass for the submission overview.
 */
class SubmissionListTable extends \WP_List_Table {

	/**
	 * Items per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Currently selected form ID filter.
	 *
	 * @var int
	 */
	private int $filter_form_id = 0;

	/**
	 * Currently selected read status filter.
	 *
	 * @var bool|null
	 */
	private ?bool $filter_is_read = null;

	/**
	 * Access control service (SEC-AUTH-DSGVO-03).
	 *
	 * @var AccessControl
	 */
	private AccessControl $access_control;

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
	 * Cached accessible forms to avoid repeated loading.
	 *
	 * @var Form[]|null
	 */
	private ?array $cached_forms = null;

	/**
	 * Form ID to title map, built from cached forms.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_map = array();

	/**
	 * Constructor.
	 *
	 * @param SubmissionDeleter $deleter      Cascading deletion service.
	 * @param AuditLogger       $audit_logger Audit logging service.
	 */
	public function __construct( SubmissionDeleter $deleter, AuditLogger $audit_logger ) {
		parent::__construct(
			array(
				'singular' => 'dsgvo-submission',
				'plural'   => 'dsgvo-submissions',
				'ajax'     => false,
			)
		);

		$this->access_control = new AccessControl();
		$this->deleter        = $deleter;
		$this->audit_logger   = $audit_logger;
		$this->filter_form_id = absint( $_GET['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $_GET['is_read'] ) && '' !== $_GET['is_read'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->filter_is_read = (bool) absint( $_GET['is_read'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Define table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'ID', 'wp-dsgvo-form' ),
			'form_title'   => __( 'Formular', 'wp-dsgvo-form' ),
			'is_read'      => __( 'Status', 'wp-dsgvo-form' ),
			'submitted_at' => __( 'Eingegangen', 'wp-dsgvo-form' ),
			'expires_at'   => __( 'Ablaufdatum', 'wp-dsgvo-form' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'id'           => array( 'id', false ),
			'submitted_at' => array( 'submitted_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		$actions = array(
			'mark_read' => __( 'Als gelesen markieren', 'wp-dsgvo-form' ),
		);

		if ( current_user_can( 'dsgvo_form_delete_submissions' ) ) {
			$actions['delete'] = __( 'Loeschen', 'wp-dsgvo-form' );
		}

		return $actions;
	}

	/**
	 * Checkbox column.
	 *
	 * @param Submission $item The submission object.
	 * @return string Checkbox HTML.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="submission_ids[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * ID column with link to detail view.
	 *
	 * @param Submission $item The submission object.
	 * @return string Column HTML.
	 */
	protected function column_id( $item ): string {
		$view_url = admin_url(
			sprintf(
				'admin.php?page=%s-submissions&action=view&submission_id=%d',
				AdminMenu::MENU_SLUG,
				$item->id
			)
		);

		$title = sprintf(
			'<strong><a href="%s">#%d</a></strong>',
			esc_url( $view_url ),
			$item->id
		);

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $view_url ),
				esc_html__( 'Anzeigen', 'wp-dsgvo-form' )
			),
		);

		if ( current_user_can( 'dsgvo_form_delete_submissions' ) ) {
			$delete_url = wp_nonce_url(
				admin_url(
					sprintf(
						'admin.php?page=%s-submissions&action=delete&submission_id=%d',
						AdminMenu::MENU_SLUG,
						$item->id
					)
				),
				'dsgvo_submission_delete_' . $item->id
			);

			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Einsendung wirklich loeschen? Diese Aktion kann nicht rueckgaengig gemacht werden.', 'wp-dsgvo-form' ) ),
				esc_html__( 'Loeschen', 'wp-dsgvo-form' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Form title column.
	 *
	 * @param Submission $item The submission object.
	 * @return string Column HTML.
	 */
	protected function column_form_title( $item ): string {
		$title = $this->form_title_map[ $item->form_id ] ?? null;

		if ( null === $title ) {
			return sprintf(
				'<em>%s (#%d)</em>',
				esc_html__( 'Unbekannt', 'wp-dsgvo-form' ),
				$item->form_id
			);
		}

		return esc_html( $title );
	}

	/**
	 * Read status column.
	 *
	 * @param Submission $item The submission object.
	 * @return string Column HTML.
	 */
	protected function column_is_read( $item ): string {
		if ( $item->is_restricted ) {
			return '<span class="dashicons dashicons-lock" style="color:#826eb4;" title="'
				. esc_attr__( 'Gesperrt (Art. 18)', 'wp-dsgvo-form' ) . '"></span> '
				. esc_html__( 'Gesperrt (Art. 18)', 'wp-dsgvo-form' );
		}

		if ( $item->is_read ) {
			return '<span class="dashicons dashicons-yes" style="color:#82878c;" title="'
				. esc_attr__( 'Gelesen', 'wp-dsgvo-form' ) . '"></span> '
				. esc_html__( 'Gelesen', 'wp-dsgvo-form' );
		}

		return '<span class="dashicons dashicons-email-alt" style="color:#0073aa;" title="'
			. esc_attr__( 'Ungelesen', 'wp-dsgvo-form' ) . '"></span> '
			. '<strong>' . esc_html__( 'Ungelesen', 'wp-dsgvo-form' ) . '</strong>';
	}

	/**
	 * Submitted at column.
	 *
	 * @param Submission $item The submission object.
	 * @return string Column HTML.
	 */
	protected function column_submitted_at( $item ): string {
		if ( empty( $item->submitted_at ) ) {
			return '&mdash;';
		}

		return esc_html(
			wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $item->submitted_at )
			)
		);
	}

	/**
	 * Expires at column.
	 *
	 * @param Submission $item The submission object.
	 * @return string Column HTML.
	 */
	protected function column_expires_at( $item ): string {
		if ( empty( $item->expires_at ) ) {
			return '<em>' . esc_html__( 'Kein Ablauf', 'wp-dsgvo-form' ) . '</em>';
		}

		$timestamp = strtotime( $item->expires_at );
		$formatted = wp_date(
			get_option( 'date_format' ),
			$timestamp
		);

		if ( $timestamp < time() ) {
			return '<span style="color:#dc3232;">' . esc_html( $formatted ) . ' ('
				. esc_html__( 'abgelaufen', 'wp-dsgvo-form' ) . ')</span>';
		}

		return esc_html( $formatted );
	}

	/**
	 * Render the filter dropdowns above the table.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$forms = $this->get_accessible_forms();
		?>
		<div class="alignleft actions">
			<select name="form_id" id="filter-by-form">
				<option value=""><?php esc_html_e( 'Alle Formulare', 'wp-dsgvo-form' ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( (string) $form->id ); ?>"
						<?php selected( $this->filter_form_id, $form->id ); ?>>
						<?php echo esc_html( $form->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="is_read" id="filter-by-read">
				<option value=""><?php esc_html_e( 'Alle Status', 'wp-dsgvo-form' ); ?></option>
				<option value="0" <?php selected( $this->filter_is_read, false ); ?>>
					<?php esc_html_e( 'Ungelesen', 'wp-dsgvo-form' ); ?>
				</option>
				<option value="1" <?php selected( $this->filter_is_read, true ); ?>>
					<?php esc_html_e( 'Gelesen', 'wp-dsgvo-form' ); ?>
				</option>
			</select>

			<?php submit_button( __( 'Filtern', 'wp-dsgvo-form' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->process_bulk_action();

		$current_page = $this->get_pagenum();
		$user_id      = get_current_user_id();

		if ( $this->filter_form_id > 0 ) {
			// SEC-AUTH-DSGVO-03: Verify access to filtered form.
			if ( ! $this->access_control->can_view_form( $user_id, $this->filter_form_id ) ) {
				$this->items = array();
				$this->set_pagination_args(
					array(
						'total_items' => 0,
						'per_page'    => self::PER_PAGE,
						'total_pages' => 0,
					)
				);
				return;
			}

			$total_items = Submission::count_by_form_id(
				$this->filter_form_id,
				$this->filter_is_read
			);

			$this->items = Submission::find_by_form_id(
				$this->filter_form_id,
				$current_page,
				self::PER_PAGE,
				$this->filter_is_read
			);
		} else {
			$this->items = $this->load_all_submissions( $current_page );
			$total_items = $this->count_all_submissions();
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total_items / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Load submissions across all forms (when no form filter is set).
	 *
	 * @param int $page Current page number.
	 * @return Submission[]
	 */
	private function load_all_submissions( int $page ): array {
		$forms       = $this->get_accessible_forms();
		$submissions = array();

		foreach ( $forms as $form ) {
			$form_subs = Submission::find_by_form_id(
				$form->id,
				1,
				self::PER_PAGE * 10,
				$this->filter_is_read
			);

			$submissions = array_merge( $submissions, $form_subs );
		}

		// Sort by submitted_at DESC.
		usort(
			$submissions,
			function ( Submission $a, Submission $b ): int {
				return strcmp( $b->submitted_at, $a->submitted_at );
			}
		);

		// Paginate.
		$offset = ( $page - 1 ) * self::PER_PAGE;

		return array_slice( $submissions, $offset, self::PER_PAGE );
	}

	/**
	 * Count all submissions across all forms.
	 *
	 * @return int
	 */
	private function count_all_submissions(): int {
		$forms = $this->get_accessible_forms();
		$total = 0;

		foreach ( $forms as $form ) {
			$total += Submission::count_by_form_id( $form->id, $this->filter_is_read );
		}

		return $total;
	}

	/**
	 * Process bulk actions.
	 *
	 * @return void
	 */
	private function process_bulk_action(): void {
		$action = $this->current_action();

		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-dsgvo-submissions' );

		$ids     = array_map( 'absint', $_POST['submission_ids'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_id = get_current_user_id();

		foreach ( $ids as $id ) {
			if ( $id < 1 ) {
				continue;
			}

			// SEC-AUTH-14: IDOR check per submission.
			if ( ! $this->access_control->can_view_submission( $user_id, $id ) ) {
				continue;
			}

			if ( 'mark_read' === $action ) {
				Submission::mark_as_read( $id );
			} elseif ( 'delete' === $action && current_user_can( 'dsgvo_form_delete_submissions' ) ) {
				// SEC-AUDIT-01: Log deletion BEFORE executing (data is gone after).
				$submission = Submission::find( $id );

				// LEGAL-F01 / SEC-DSGVO-13: Skip restricted submissions (Art. 18 DSGVO).
				if ( null !== $submission && ! $submission->is_restricted ) {
					$this->audit_logger->log( $user_id, 'delete', $id, $submission->form_id );

					// SEC-FILE-09: Cascading deletion (files → DB) via SubmissionDeleter.
					$this->deleter->delete( $id );
				}
			}
		}
	}

	/**
	 * Returns forms the current user is allowed to view (SEC-AUTH-DSGVO-03).
	 *
	 * @return Form[]
	 */
	private function get_accessible_forms(): array {
		if ( null !== $this->cached_forms ) {
			return $this->cached_forms;
		}

		$user_id = get_current_user_id();
		$forms   = Form::find_all();

		$this->cached_forms = array_filter(
			$forms,
			fn( Form $form ): bool => $this->access_control->can_view_form( $user_id, $form->id )
		);

		// Build title map for column_form_title() lookups.
		foreach ( $this->cached_forms as $form ) {
			$this->form_title_map[ $form->id ] = $form->title;
		}

		return $this->cached_forms;
	}

	/**
	 * Message for empty table.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'Keine Einsendungen vorhanden.', 'wp-dsgvo-form' );
	}
}
