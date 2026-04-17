<?php
/**
 * Recipient list page.
 *
 * Manages the assignment of WordPress users to forms
 * as notification recipients. Provides form selector,
 * recipient list with user data, add/remove actions,
 * and email notification toggle.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Recipient;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the recipient management admin page.
 */
class RecipientListPage {

	/**
	 * Currently selected form ID.
	 *
	 * @var int
	 */
	private int $selected_form_id = 0;

	/**
	 * Render the recipient management page.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->selected_form_id = absint( $_GET['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->handle_actions();

		$forms = Form::find_all();

		// Auto-select first form if none selected.
		if ( 0 === $this->selected_form_id && ! empty( $forms ) ) {
			$this->selected_form_id = $forms[0]->id;
		}

		$recipients = array();
		if ( $this->selected_form_id > 0 ) {
			$recipients = Recipient::find_by_form_id( $this->selected_form_id );
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Empfaenger', 'wp-dsgvo-form' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php settings_errors( 'dsgvo_recipient_messages' ); ?>

			<?php if ( empty( $forms ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'Erstellen Sie zuerst ein Formular, bevor Sie Empfaenger zuweisen koennen.', 'wp-dsgvo-form' ); ?>
					</p>
				</div>
			<?php else : ?>
				<?php $this->render_form_selector( $forms ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">

						<!-- Main: recipient list -->
						<div id="post-body-content">
							<?php $this->render_recipient_table( $recipients ); ?>
						</div>

						<!-- Sidebar: add recipient -->
						<div id="postbox-container-1" class="postbox-container">
							<?php $this->render_add_recipient_box(); ?>
						</div>

					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the form selector dropdown.
	 *
	 * @param Form[] $forms All available forms.
	 * @return void
	 */
	private function render_form_selector( array $forms ): void {
		$base_url = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '-recipients' );
		?>
		<form method="get" style="margin: 1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::MENU_SLUG . '-recipients' ); ?>">
			<label for="form_id"><strong><?php esc_html_e( 'Formular:', 'wp-dsgvo-form' ); ?></strong></label>
			<select name="form_id" id="form_id" onchange="this.form.submit();">
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( (string) $form->id ); ?>"
						<?php selected( $this->selected_form_id, $form->id ); ?>>
						<?php echo esc_html( $form->title ); ?>
						<?php if ( ! $form->is_active ) : ?>
							(<?php esc_html_e( 'inaktiv', 'wp-dsgvo-form' ); ?>)
						<?php endif; ?>
					</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php
	}

	/**
	 * Render the recipient table for the selected form.
	 *
	 * @param Recipient[] $recipients Recipients for the selected form.
	 * @return void
	 */
	private function render_recipient_table( array $recipients ): void {
		?>
		<div class="postbox">
			<h2 class="hndle">
				<?php esc_html_e( 'Zugewiesene Empfaenger', 'wp-dsgvo-form' ); ?>
			</h2>
			<div class="inside">
				<?php if ( empty( $recipients ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Diesem Formular sind noch keine Empfaenger zugewiesen.', 'wp-dsgvo-form' ); ?>
					</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Benutzer', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'E-Mail', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'E-Mail-Benachrichtigung', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Zweck', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Zugewiesen am', 'wp-dsgvo-form' ); ?></th>
								<th><?php esc_html_e( 'Aktionen', 'wp-dsgvo-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recipients as $recipient ) : ?>
								<?php $user = get_userdata( $recipient->user_id ); ?>
								<tr>
									<td>
										<?php if ( $user ) : ?>
											<?php echo esc_html( $user->display_name ); ?>
											<br>
											<small><?php echo esc_html( $user->user_login ); ?></small>
										<?php else : ?>
											<em>
												<?php
												printf(
													/* translators: %d: WordPress user ID */
													esc_html__( 'Unbekannter Benutzer (ID %d)', 'wp-dsgvo-form' ),
													(int) $recipient->user_id
												);
												?>
											</em>
										<?php endif; ?>
									</td>
									<td>
										<?php
										echo $user
											? esc_html( $user->user_email )
											: '&mdash;';
										?>
									</td>
									<td>
										<?php if ( $recipient->notify_email ) : ?>
											<span class="dashicons dashicons-yes-alt" style="color:#46b450;"
												title="<?php esc_attr_e( 'Aktiv', 'wp-dsgvo-form' ); ?>"></span>
											<?php esc_html_e( 'Aktiv', 'wp-dsgvo-form' ); ?>
										<?php else : ?>
											<span class="dashicons dashicons-marker" style="color:#dc3232;"
												title="<?php esc_attr_e( 'Deaktiviert', 'wp-dsgvo-form' ); ?>"></span>
											<?php esc_html_e( 'Deaktiviert', 'wp-dsgvo-form' ); ?>
										<?php endif; ?>
									</td>
									<td>
										<?php
										if ( $recipient->role_justification !== '' ) {
											echo esc_html( $recipient->role_justification );
										} else {
											echo '<em style="color:#999;">' . esc_html__( 'Nicht angegeben', 'wp-dsgvo-form' ) . '</em>';
										}
										?>
									</td>
									<td>
										<?php
										if ( $recipient->created_at ) {
											echo esc_html(
												wp_date(
													get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
													strtotime( $recipient->created_at )
												)
											);
										} else {
											echo '&mdash;';
										}
										?>
									</td>
									<td>
										<?php
										$toggle_url = wp_nonce_url(
											admin_url(
												sprintf(
													'admin.php?page=%s-recipients&form_id=%d&do=%s&recipient_id=%d',
													AdminMenu::MENU_SLUG,
													$this->selected_form_id,
													$recipient->notify_email ? 'disable_notify' : 'enable_notify',
													$recipient->id
												)
											),
											'dsgvo_recipient_action_' . $recipient->id
										);

										$remove_url = wp_nonce_url(
											admin_url(
												sprintf(
													'admin.php?page=%s-recipients&form_id=%d&do=remove&recipient_id=%d',
													AdminMenu::MENU_SLUG,
													$this->selected_form_id,
													$recipient->id
												)
											),
											'dsgvo_recipient_action_' . $recipient->id
										);
										?>
										<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
											<?php
											echo $recipient->notify_email
												? esc_html__( 'Benachrichtigung deaktivieren', 'wp-dsgvo-form' )
												: esc_html__( 'Benachrichtigung aktivieren', 'wp-dsgvo-form' );
											?>
										</a>
										<a href="<?php echo esc_url( $remove_url ); ?>"
											class="button button-small button-link-delete"
											onclick="return confirm('<?php echo esc_js( __( 'Empfaenger wirklich entfernen?', 'wp-dsgvo-form' ) ); ?>');">
											<?php esc_html_e( 'Entfernen', 'wp-dsgvo-form' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the sidebar box for adding a new recipient.
	 *
	 * @return void
	 */
	private function render_add_recipient_box(): void {
		$users = $this->get_eligible_users();
		?>
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Empfaenger hinzufuegen', 'wp-dsgvo-form' ); ?></h2>
			<div class="inside">
				<?php if ( empty( $users ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Alle berechtigten Benutzer sind bereits als Empfaenger zugewiesen.', 'wp-dsgvo-form' ); ?>
					</p>
				<?php else : ?>
					<form method="post">
						<input type="hidden" name="dsgvo_action" value="add_recipient">
						<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $this->selected_form_id ); ?>">
						<?php wp_nonce_field( 'dsgvo_add_recipient_' . $this->selected_form_id ); ?>

						<p>
							<label for="user_id"><?php esc_html_e( 'Benutzer:', 'wp-dsgvo-form' ); ?></label>
							<select name="user_id" id="user_id" class="widefat" required>
								<option value=""><?php esc_html_e( '-- Benutzer waehlen --', 'wp-dsgvo-form' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>">
										<?php
										echo esc_html(
											sprintf(
												'%s (%s)',
												$user->display_name,
												$user->user_email
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label>
								<input type="checkbox" name="notify_email" value="1" checked>
								<?php esc_html_e( 'E-Mail-Benachrichtigung bei neuen Einsendungen', 'wp-dsgvo-form' ); ?>
							</label>
						</p>

						<p>
							<label for="role_justification">
								<?php esc_html_e( 'Zweck der Zuweisung (Art. 5 Abs. 1 lit. b):', 'wp-dsgvo-form' ); ?>
							</label>
							<textarea name="role_justification" id="role_justification"
								class="widefat" rows="3"
								placeholder="<?php esc_attr_e( 'z.B. Bearbeitung von Kontaktanfragen, Aufsichtsfunktion', 'wp-dsgvo-form' ); ?>"
							></textarea>
							<span class="description">
								<?php esc_html_e( 'Pflichtfeld fuer Supervisoren. Dokumentiert die Zweckbindung des Zugriffs.', 'wp-dsgvo-form' ); ?>
							</span>
						</p>

						<?php submit_button( __( 'Empfaenger hinzufuegen', 'wp-dsgvo-form' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get WordPress users eligible to be recipients (not yet assigned to this form).
	 *
	 * Returns users with dsgvo_form_view_submissions capability (recipients + supervisors + admins).
	 *
	 * @return \WP_User[]
	 */
	private function get_eligible_users(): array {
		if ( $this->selected_form_id < 1 ) {
			return array();
		}

		$existing_recipients = Recipient::find_by_form_id( $this->selected_form_id );
		$assigned_user_ids   = array_map(
			function ( Recipient $r ): int {
				return $r->user_id;
			},
			$existing_recipients
		);

		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		return array_filter(
			$users,
			function ( \WP_User $user ) use ( $assigned_user_ids ): bool {
				if ( in_array( $user->ID, $assigned_user_ids, true ) ) {
					return false;
				}

				return $user->has_cap( 'dsgvo_form_view_submissions' )
					|| $user->has_cap( 'manage_options' );
			}
		);
	}

	/**
	 * Handle add, remove, and toggle notification actions.
	 *
	 * @return void
	 */
	private function handle_actions(): void {
		$this->handle_add_recipient();
		$this->handle_recipient_action();
	}

	/**
	 * Handle the add-recipient POST action.
	 *
	 * @return void
	 */
	private function handle_add_recipient(): void {
		if ( ! isset( $_POST['dsgvo_action'] ) || 'add_recipient' !== $_POST['dsgvo_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Defense-in-Depth: explicit capability check (SEC-AUTH-03).
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-dsgvo-form' ), 403 );
		}

		$form_id = absint( $_POST['form_id'] ?? 0 );

		if ( $form_id < 1 ) {
			return;
		}

		check_admin_referer( 'dsgvo_add_recipient_' . $form_id );

		$user_id = absint( $_POST['user_id'] ?? 0 );

		if ( $user_id < 1 ) {
			add_settings_error(
				'dsgvo_recipient_messages',
				'no_user_selected',
				__( 'Bitte waehlen Sie einen Benutzer aus.', 'wp-dsgvo-form' ),
				'error'
			);
			return;
		}

		if ( Recipient::exists( $form_id, $user_id ) ) {
			add_settings_error(
				'dsgvo_recipient_messages',
				'already_assigned',
				__( 'Dieser Benutzer ist bereits als Empfaenger fuer dieses Formular zugewiesen.', 'wp-dsgvo-form' ),
				'error'
			);
			return;
		}

		$recipient               = new Recipient();
		$recipient->form_id      = $form_id;
		$recipient->user_id      = $user_id;
		$recipient->notify_email = isset( $_POST['notify_email'] );

		$role_justification = sanitize_textarea_field( $_POST['role_justification'] ?? '' );

		// SEC-AUTH-DSGVO-01: Supervisors require justification (Art. 5 Abs. 1 lit. b).
		$user = get_userdata( $user_id );

		if ( $user && $user->has_cap( 'dsgvo_form_view_all_submissions' ) && '' === $role_justification ) {
			add_settings_error(
				'dsgvo_recipient_messages',
				'justification_required',
				__( 'Fuer Supervisoren ist die Angabe des Zuweisungszwecks Pflicht (Art. 5 Abs. 1 lit. b DSGVO).', 'wp-dsgvo-form' ),
				'error'
			);
			return;
		}

		$recipient->role_justification = $role_justification;

		try {
			$recipient->save();

			add_settings_error(
				'dsgvo_recipient_messages',
				'recipient_added',
				sprintf(
					/* translators: %s: user display name */
					__( '%s wurde als Empfaenger hinzugefuegt.', 'wp-dsgvo-form' ),
					esc_html( $user ? $user->display_name : (string) $user_id )
				),
				'success'
			);
		} catch ( \RuntimeException $e ) {
			add_settings_error(
				'dsgvo_recipient_messages',
				'save_failed',
				__( 'Empfaenger konnte nicht gespeichert werden.', 'wp-dsgvo-form' ),
				'error'
			);
		}

		$this->selected_form_id = $form_id;
	}

	/**
	 * Handle remove and notification toggle actions via GET parameters.
	 *
	 * @return void
	 */
	private function handle_recipient_action(): void {
		$action = sanitize_text_field( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $action ) {
			return;
		}

		// Defense-in-Depth: explicit capability check (SEC-AUTH-03).
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-dsgvo-form' ), 403 );
		}

		$recipient_id = absint( $_GET['recipient_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $recipient_id < 1 ) {
			return;
		}

		check_admin_referer( 'dsgvo_recipient_action_' . $recipient_id );

		if ( 'remove' === $action ) {
			Recipient::delete( $recipient_id );
			add_settings_error(
				'dsgvo_recipient_messages',
				'recipient_removed',
				__( 'Empfaenger wurde entfernt.', 'wp-dsgvo-form' ),
				'success'
			);
		} elseif ( 'enable_notify' === $action || 'disable_notify' === $action ) {
			$recipient = Recipient::find( $recipient_id );

			if ( $recipient !== null ) {
				$recipient->notify_email = ( 'enable_notify' === $action );
				$recipient->save();

				add_settings_error(
					'dsgvo_recipient_messages',
					'notify_toggled',
					'enable_notify' === $action
						? __( 'E-Mail-Benachrichtigung aktiviert.', 'wp-dsgvo-form' )
						: __( 'E-Mail-Benachrichtigung deaktiviert.', 'wp-dsgvo-form' ),
					'success'
				);
			}
		}
	}
}
