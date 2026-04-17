<?php
/**
 * Form list table.
 *
 * Extends WP_List_Table to display all DSGVO forms
 * in the admin backend with sorting, pagination,
 * row actions, and bulk delete.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Submission;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table subclass for the form overview.
 */
class FormListTable extends \WP_List_Table {

	/**
	 * Preloaded field counts per form (N+1 optimization).
	 *
	 * @var array<int, int>
	 */
	private array $field_counts = [];

	/**
	 * Preloaded total submission counts per form (N+1 optimization).
	 *
	 * @var array<int, int>
	 */
	private array $submission_total_counts = [];

	/**
	 * Preloaded unread submission counts per form (N+1 optimization).
	 *
	 * @var array<int, int>
	 */
	private array $submission_unread_counts = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'dsgvo-form',
				'plural'   => 'dsgvo-forms',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'             => '<input type="checkbox" />',
			'title'          => __( 'Titel', 'wp-dsgvo-form' ),
			'slug'           => __( 'Slug', 'wp-dsgvo-form' ),
			'is_active'      => __( 'Status', 'wp-dsgvo-form' ),
			'fields_count'   => __( 'Felder', 'wp-dsgvo-form' ),
			'submissions'    => __( 'Einsendungen', 'wp-dsgvo-form' ),
			'retention_days' => __( 'Aufbewahrung', 'wp-dsgvo-form' ),
			'created_at'     => __( 'Erstellt', 'wp-dsgvo-form' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'title'      => array( 'title', false ),
			'is_active'  => array( 'is_active', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Loeschen', 'wp-dsgvo-form' ),
		);
	}

	/**
	 * Checkbox column for bulk actions.
	 *
	 * @param Form $item The form object.
	 * @return string Checkbox HTML.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="form_ids[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * Title column with row actions.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_title( $item ): string {
		$edit_url = admin_url(
			sprintf(
				'admin.php?page=%s&action=edit&form_id=%d',
				AdminMenu::MENU_SLUG,
				$item->id
			)
		);

		$consent_url = admin_url(
			sprintf(
				'admin.php?page=%s&action=consent&form_id=%d',
				AdminMenu::MENU_SLUG,
				$item->id
			)
		);

		$delete_url = wp_nonce_url(
			admin_url(
				sprintf(
					'admin.php?page=%s&action=delete&form_id=%d',
					AdminMenu::MENU_SLUG,
					$item->id
				)
			),
			'dsgvo_form_delete_' . $item->id
		);

		$title = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->title )
		);

		$actions = array(
			'edit'    => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Bearbeiten', 'wp-dsgvo-form' )
			),
			'consent' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $consent_url ),
				esc_html__( 'Einwilligungstexte', 'wp-dsgvo-form' )
			),
			'delete'  => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Formular wirklich loeschen? Alle Einsendungen werden unwiderruflich geloescht.', 'wp-dsgvo-form' ) ),
				esc_html__( 'Loeschen', 'wp-dsgvo-form' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Status column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_is_active( $item ): string {
		if ( $item->is_active ) {
			return '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="'
				. esc_attr__( 'Aktiv', 'wp-dsgvo-form' ) . '"></span> '
				. esc_html__( 'Aktiv', 'wp-dsgvo-form' );
		}

		return '<span class="dashicons dashicons-marker" style="color:#dc3232;" title="'
			. esc_attr__( 'Inaktiv', 'wp-dsgvo-form' ) . '"></span> '
			. esc_html__( 'Inaktiv', 'wp-dsgvo-form' );
	}

	/**
	 * Slug column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_slug( $item ): string {
		return '<code>' . esc_html( $item->slug ) . '</code>';
	}

	/**
	 * Fields count column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_fields_count( $item ): string {
		return (string) ( $this->field_counts[ $item->id ] ?? 0 );
	}

	/**
	 * Submissions count column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_submissions( $item ): string {
		$total  = $this->submission_total_counts[ $item->id ] ?? 0;
		$unread = $this->submission_unread_counts[ $item->id ] ?? 0;

		$link = admin_url(
			sprintf(
				'admin.php?page=%s-submissions&form_id=%d',
				AdminMenu::MENU_SLUG,
				$item->id
			)
		);

		$output = sprintf(
			'<a href="%s">%d</a>',
			esc_url( $link ),
			$total
		);

		if ( $unread > 0 ) {
			$output .= sprintf(
				' <span class="count">(<strong>%d</strong> %s)</span>',
				$unread,
				esc_html__( 'ungelesen', 'wp-dsgvo-form' )
			);
		}

		return $output;
	}

	/**
	 * Retention days column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_retention_days( $item ): string {
		return sprintf(
			/* translators: %d: number of days */
			esc_html( _n( '%d Tag', '%d Tage', $item->retention_days, 'wp-dsgvo-form' ) ),
			$item->retention_days
		);
	}

	/**
	 * Created at column.
	 *
	 * @param Form $item The form object.
	 * @return string Column HTML.
	 */
	protected function column_created_at( $item ): string {
		if ( empty( $item->created_at ) ) {
			return '&mdash;';
		}

		return esc_html(
			wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $item->created_at )
			)
		);
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

		$forms = Form::find_all();

		// Client-side sorting (Form::find_all returns created_at DESC by default).
		$orderby = sanitize_text_field( $_GET['orderby'] ?? 'created_at' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = sanitize_text_field( $_GET['order'] ?? 'desc' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		usort(
			$forms,
			function ( Form $a, Form $b ) use ( $orderby, $order ): int {
				$result = match ( $orderby ) {
					'title'     => strcasecmp( $a->title, $b->title ),
					'is_active' => (int) $b->is_active - (int) $a->is_active,
					default     => strcmp( $b->created_at, $a->created_at ),
				};

				return 'asc' === $order ? $result : -$result;
			}
		);

		// Pagination.
		$per_page    = 20;
		$total_items = count( $forms );
		$current_page = $this->get_pagenum();

		$this->items = array_slice(
			$forms,
			( $current_page - 1 ) * $per_page,
			$per_page
		);

		// N+1 optimization: batch-load counts for visible forms.
		$this->preload_counts();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Preloads field and submission counts for visible forms.
	 *
	 * Replaces per-row queries in column_fields_count() and column_submissions()
	 * with three batch queries using GROUP BY (N+1 → 3 queries).
	 *
	 * @return void
	 */
	private function preload_counts(): void {
		if ( empty( $this->items ) ) {
			return;
		}

		global $wpdb;

		$form_ids     = array_map( fn( $form ) => $form->id, $this->items );
		$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
		$fields_table = Field::get_table_name();
		$subs_table   = Submission::get_table_name();

		// Batch: field counts per form.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$field_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS cnt FROM `{$fields_table}` WHERE form_id IN ({$placeholders}) GROUP BY form_id",
				...$form_ids
			)
		);

		foreach ( $field_rows as $row ) {
			$this->field_counts[ (int) $row->form_id ] = (int) $row->cnt;
		}

		// Batch: total submission counts per form.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS cnt FROM `{$subs_table}` WHERE form_id IN ({$placeholders}) GROUP BY form_id",
				...$form_ids
			)
		);

		foreach ( $total_rows as $row ) {
			$this->submission_total_counts[ (int) $row->form_id ] = (int) $row->cnt;
		}

		// Batch: unread submission counts per form.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unread_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS cnt FROM `{$subs_table}` WHERE form_id IN ({$placeholders}) AND is_read = 0 GROUP BY form_id",
				...$form_ids
			)
		);

		foreach ( $unread_rows as $row ) {
			$this->submission_unread_counts[ (int) $row->form_id ] = (int) $row->cnt;
		}
	}

	/**
	 * Process bulk actions (delete).
	 *
	 * @return void
	 */
	private function process_bulk_action(): void {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-dsgvo-forms' );

		// M2: Explicit capability check (Defense in Depth).
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			return;
		}

		$form_ids = array_map( 'absint', $_POST['form_ids'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( $form_ids as $form_id ) {
			if ( $form_id > 0 ) {
				Form::delete( $form_id );
			}
		}
	}

	/**
	 * Message for empty table.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'Keine Formulare vorhanden.', 'wp-dsgvo-form' );
	}
}
