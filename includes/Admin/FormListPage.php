<?php
/**
 * Form list page.
 *
 * Renders the WP_List_Table overview of all DSGVO forms
 * in the admin backend. Handles single-form delete action.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Models\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the form list admin page.
 */
class FormListPage {

	/**
	 * Render the form list page.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->handle_single_delete();

		$table = new FormListTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Formulare', 'wp-dsgvo-form' ); ?>
			</h1>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&action=edit' ) ); ?>"
				class="page-title-action">
				<?php esc_html_e( 'Neues Formular', 'wp-dsgvo-form' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php settings_errors( 'dsgvo_form_messages' ); ?>

			<form method="post">
				<?php
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle single form deletion via row action.
	 *
	 * @return void
	 */
	private function handle_single_delete(): void {
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$form_id = absint( $_GET['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $form_id < 1 ) {
			return;
		}

		check_admin_referer( 'dsgvo_form_delete_' . $form_id );

		// M2: Explicit capability check (Defense in Depth).
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung zum Loeschen von Formularen.', 'wp-dsgvo-form' ), 403 );
		}

		$form = Form::find( $form_id );

		if ( null === $form ) {
			add_settings_error(
				'dsgvo_form_messages',
				'form_not_found',
				__( 'Formular nicht gefunden.', 'wp-dsgvo-form' ),
				'error'
			);
			return;
		}

		Form::delete( $form_id );

		add_settings_error(
			'dsgvo_form_messages',
			'form_deleted',
			sprintf(
				/* translators: %s: form title */
				__( 'Formular "%s" wurde geloescht.', 'wp-dsgvo-form' ),
				esc_html( $form->title )
			),
			'success'
		);
	}
}
