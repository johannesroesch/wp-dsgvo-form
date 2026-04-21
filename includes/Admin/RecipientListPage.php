<?php
/**
 * Recipient list page.
 *
 * Manages the assignment of WordPress users to forms
 * as notification recipients. Provides form selector,
 * recipient list with access-level badges, add/remove actions,
 * auto capability grant/revoke, and email notification toggle.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Auth\CapabilityManager;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Recipient;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the recipient management admin page.
 */
class RecipientListPage {

	/**
	 * User meta key to track migration-notice dismissal.
	 */
	private const MIGRATION_NOTICE_DISMISS_KEY = 'wpdsgvo_cap_migration_notice_dismissed';

	/**
	 * Currently selected form ID.
	 *
	 * @var int
	 */
	private int $selected_form_id = 0;

	/**
	 * Capability manager for audited grant/revoke.
	 */
	private CapabilityManager $cap_manager;

	/**
	 * Audit logger for DPO-SOLL-F07 entries.
	 */
	private AuditLogger $audit_logger;

	/**
	 * @param CapabilityManager $cap_manager Shared CapabilityManager.
	 * @param AuditLogger       $audit_logger Shared AuditLogger.
	 */
	public function __construct( CapabilityManager $cap_manager, AuditLogger $audit_logger ) {
		$this->cap_manager  = $cap_manager;
		$this->audit_logger = $audit_logger;
	}

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

			<?php $this->maybe_render_migration_notice(); ?>

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
	 * Renders a dismissible migration notice for the capability system change.
	 *
	 * Pattern: DsfaNotice — per-user dismissal via user_meta + AJAX.
	 *
	 * @return void
	 */
	private function maybe_render_migration_notice(): void {
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::MIGRATION_NOTICE_DISMISS_KEY, true ) ) {
			return;
		}

		// Only show on the recipients page.
		$screen = get_current_screen();
		if ( $screen === null || strpos( $screen->id, 'dsgvo-form-recipients' ) === false ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible" id="wpdsgvo-cap-migration-notice">
			<p>
				<strong><?php esc_html_e( 'Neue Zugriffsebenen:', 'wp-dsgvo-form' ); ?></strong>
				<?php esc_html_e( 'Empfaenger werden jetzt mit einer expliziten Zugriffsebene (Leser/Supervisor) verwaltet. Bestehende Zuweisungen wurden automatisch migriert. Die Capabilities werden beim Hinzufuegen/Entfernen von Empfaengern automatisch gesetzt.', 'wp-dsgvo-form' ); ?>
			</p>
		</div>
		<script>
		jQuery(document).on('click', '#wpdsgvo-cap-migration-notice .notice-dismiss', function() {
			jQuery.post(ajaxurl, {action: 'wpdsgvo_dismiss_cap_migration_notice', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'wpdsgvo_dismiss_cap_migration' ) ); ?>'});
		});
		</script>
		<?php
	}

	/**
	 * Handles AJAX dismiss for the migration notice.
	 *
	 * Must be registered externally via add_action('wp_ajax_...').
	 *
	 * @return void
	 */
	public static function handle_dismiss_migration_notice(): void {
		check_ajax_referer( 'wpdsgvo_dismiss_cap_migration', '_wpnonce' );

		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		update_user_meta( get_current_user_id(), self::MIGRATION_NOTICE_DISMISS_KEY, 1 );
		wp_die();
	}

	/**
	 * Render the form selector dropdown.
	 *
	 * @param Form[] $forms All available forms.
	 * @return void
	 */
	private function render_form_selector( array $forms ): void {
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
								<th><?php esc_html_e( 'Zugriffsebene', 'wp-dsgvo-form' ); ?></th>
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
										<?php echo wp_kses_post( self::render_access_level_badge( $recipient->access_level ) ); ?>
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
	 * Returns an HTML badge for the given access level.
	 *
	 * @param string $access_level The access level (reader|supervisor).
	 * @return string HTML span element with colored badge.
	 */
	private static function render_access_level_badge( string $access_level ): string {
		if ( Recipient::ACCESS_LEVEL_SUPERVISOR === $access_level ) {
			return sprintf(
				'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:#e8f0fe;color:#1a56db;">%s</span>',
				esc_html__( 'Erweitert', 'wp-dsgvo-form' )
			);
		}

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:#fef3e2;color:#b45309;">%s</span>',
			esc_html__( 'Eingeschraenkt', 'wp-dsgvo-form' )
		);
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
							<label for="access_level"><?php esc_html_e( 'Zugriffsebene:', 'wp-dsgvo-form' ); ?></label>
							<select name="access_level" id="access_level" class="widefat">
								<option value="<?php echo esc_attr( Recipient::ACCESS_LEVEL_READER ); ?>">
									<?php esc_html_e( 'Leser (nur dieses Formular)', 'wp-dsgvo-form' ); ?>
								</option>
								<option value="<?php echo esc_attr( Recipient::ACCESS_LEVEL_SUPERVISOR ); ?>">
									<?php esc_html_e( 'Supervisor (alle Formulare)', 'wp-dsgvo-form' ); ?>
								</option>
							</select>
							<span class="description">
								<?php esc_html_e( 'Supervisoren sehen alle Einsendungen. Jeder Zugriff wird protokolliert.', 'wp-dsgvo-form' ); ?>
							</span>
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

		// PERF: Limit to 200 users to avoid memory issues on large sites.
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 200,
			)
		);

		return array_filter(
			$users,
			function ( \WP_User $user ) use ( $assigned_user_ids ): bool {
				if ( in_array( $user->ID, $assigned_user_ids, true ) ) {
					return false;
				}

				// Include any logged-in WordPress user (read is the base capability).
				// This ensures users whose plugin caps were revoked after removal
				// can be re-assigned as recipients.
				return $user->has_cap( 'read' );
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
	 * Grants capabilities automatically via CapabilityManager (DPO-SOLL-F06).
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

		$access_level = sanitize_text_field( wp_unslash( $_POST['access_level'] ?? Recipient::ACCESS_LEVEL_READER ) );

		if ( ! in_array( $access_level, [ Recipient::ACCESS_LEVEL_READER, Recipient::ACCESS_LEVEL_SUPERVISOR ], true ) ) {
			$access_level = Recipient::ACCESS_LEVEL_READER;
		}

		$role_justification = sanitize_textarea_field( wp_unslash( $_POST['role_justification'] ?? '' ) );

		// SEC-AUTH-DSGVO-01: Supervisors require justification (Art. 5 Abs. 1 lit. b).
		if ( Recipient::ACCESS_LEVEL_SUPERVISOR === $access_level && '' === $role_justification ) {
			add_settings_error(
				'dsgvo_recipient_messages',
				'justification_required',
				__( 'Fuer Supervisoren ist die Angabe des Zuweisungszwecks Pflicht (Art. 5 Abs. 1 lit. b DSGVO).', 'wp-dsgvo-form' ),
				'error'
			);
			return;
		}

		$recipient                     = new Recipient();
		$recipient->form_id            = $form_id;
		$recipient->user_id            = $user_id;
		$recipient->notify_email       = isset( $_POST['notify_email'] );
		$recipient->access_level       = $access_level;
		$recipient->role_justification = $role_justification;

		try {
			$recipient->save();

			// Auto-grant capabilities via CapabilityManager (DPO-SOLL-F06).
			$this->grant_capabilities_for_level( $user_id, $access_level );

			$user = get_userdata( $user_id );
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
	 * On removal: Checks DPO-MUSS-F16 — only revokes capabilities when
	 * the user has no remaining form assignments.
	 *
	 * @return void
	 */
	private function handle_recipient_action(): void {
		$action = sanitize_text_field( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
			$this->handle_remove_recipient( $recipient_id );
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

	/**
	 * Removes a recipient and conditionally revokes capabilities.
	 *
	 * DPO-MUSS-F16: Only revoke when user has 0 remaining assignments.
	 * DPO-SOLL-F07: Audit-log entry for auto-revoke.
	 *
	 * @param int $recipient_id The recipient record ID.
	 */
	private function handle_remove_recipient( int $recipient_id ): void {
		$recipient = Recipient::find( $recipient_id );

		if ( $recipient === null ) {
			return;
		}

		$user_id      = $recipient->user_id;
		$access_level = $recipient->access_level;

		Recipient::delete( $recipient_id );

		// DPO-MUSS-F16: Only revoke when no remaining assignments.
		$remaining = Recipient::count_by_user_id( $user_id );
		$admin_id  = get_current_user_id();

		if ( 0 === $remaining ) {
			$this->revoke_all_plugin_capabilities( $user_id, $admin_id );
		} elseif ( Recipient::ACCESS_LEVEL_SUPERVISOR === $access_level ) {
			// If removed recipient was supervisor, check if any remaining assignment
			// still requires supervisor. If not, downgrade to reader capabilities.
			$this->maybe_downgrade_supervisor( $user_id, $admin_id );
		}

		add_settings_error(
			'dsgvo_recipient_messages',
			'recipient_removed',
			__( 'Empfaenger wurde entfernt.', 'wp-dsgvo-form' ),
			'success'
		);
	}

	/**
	 * Grants capabilities matching the given access level.
	 *
	 * @param int    $user_id      The user receiving capabilities.
	 * @param string $access_level reader or supervisor.
	 */
	private function grant_capabilities_for_level( int $user_id, string $access_level ): void {
		$admin_id = get_current_user_id();

		// Base capabilities for all recipients.
		$this->cap_manager->grant( $user_id, 'dsgvo_form_view_submissions', $admin_id, 'auto_grant' );
		$this->cap_manager->grant( $user_id, AccessControl::RECIPIENT_CAPABILITY, $admin_id, 'auto_grant' );

		// Supervisor: additional capabilities.
		if ( Recipient::ACCESS_LEVEL_SUPERVISOR === $access_level ) {
			$this->cap_manager->grant( $user_id, 'dsgvo_form_view_all_submissions', $admin_id, 'auto_grant' );
			$this->cap_manager->grant( $user_id, 'dsgvo_form_export', $admin_id, 'auto_grant' );
		}
	}

	/**
	 * Revokes all plugin capabilities when user has no remaining assignments.
	 *
	 * DPO-SOLL-F07: Logged via CapabilityManager with auto_revoke context.
	 *
	 * @param int $user_id  The user losing capabilities.
	 * @param int $admin_id The admin performing the action.
	 */
	private function revoke_all_plugin_capabilities( int $user_id, int $admin_id ): void {
		$caps_to_revoke = [
			'dsgvo_form_view_submissions',
			'dsgvo_form_view_all_submissions',
			'dsgvo_form_export',
			AccessControl::RECIPIENT_CAPABILITY,
		];

		foreach ( $caps_to_revoke as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				$this->cap_manager->revoke( $user_id, $cap, $admin_id, 'auto_revoke' );
			}
		}
	}

	/**
	 * Downgrades a user from supervisor to reader if no supervisor assignments remain.
	 *
	 * @param int $user_id  The user to check.
	 * @param int $admin_id The admin performing the action.
	 */
	private function maybe_downgrade_supervisor( int $user_id, int $admin_id ): void {
		$remaining_recipients = Recipient::find_by_user_id( $user_id );

		$has_supervisor_assignment = false;
		foreach ( $remaining_recipients as $r ) {
			if ( Recipient::ACCESS_LEVEL_SUPERVISOR === $r->access_level ) {
				$has_supervisor_assignment = true;
				break;
			}
		}

		if ( ! $has_supervisor_assignment ) {
			// Remove supervisor capabilities, keep reader capabilities.
			if ( user_can( $user_id, 'dsgvo_form_view_all_submissions' ) ) {
				$this->cap_manager->revoke( $user_id, 'dsgvo_form_view_all_submissions', $admin_id, 'auto_revoke' );
			}
			if ( user_can( $user_id, 'dsgvo_form_export' ) ) {
				$this->cap_manager->revoke( $user_id, 'dsgvo_form_export', $admin_id, 'auto_revoke' );
			}
		}
	}
}
