<?php
/**
 * Admin Bar Notification — Unread-Badge in WP Admin Bar.
 *
 * Shows a notification badge with unread submission count
 * for users with view_submissions capability.
 *
 * Architecture: §7.6 Admin Bar Notification.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Models\Recipient;
use WpDsgvoForm\Models\Submission;

class AdminBarNotification {

	/**
	 * Cache TTL in seconds (2 minutes).
	 */
	private const CACHE_TTL = 120;

	/**
	 * Maximum count to display before showing "99+".
	 */
	private const COUNT_CAP = 99;

	private AccessControl $access_control;

	/**
	 * @param AccessControl $access_control Shared access control service.
	 */
	public function __construct( AccessControl $access_control ) {
		$this->access_control = $access_control;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * Hooks into admin_bar_menu (priority 80: after standard nodes,
	 * before LoginRedirect::restrict_admin_bar at 999).
	 * Styles on both admin_head and wp_head (admin bar appears on frontend too).
	 */
	public function register_hooks(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_notification_node' ), 80 );
		add_action( 'admin_head', array( $this, 'render_badge_styles' ) );
		add_action( 'wp_head', array( $this, 'render_badge_styles' ) );
	}

	/**
	 * Adds the unread-count node to the admin bar.
	 *
	 * Only visible for users with dsgvo_form_view_submissions capability.
	 * Node is omitted entirely when count is 0.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_notification_node( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! user_can( $user_id, 'dsgvo_form_view_submissions' ) ) {
			return;
		}

		$count = $this->get_unread_count( $user_id );

		if ( 0 === $count ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wpdsgvo-unread',
				'title' => $this->build_badge_html( $count ),
				'href'  => admin_url( 'admin.php?page=dsgvo-form-submissions' ),
				'meta'  => array(
					'class' => 'wpdsgvo-admin-bar-notification',
					'title' => sprintf(
					/* translators: %d: number of unread submissions */
						_n( '%d ungelesene Einsendung', '%d ungelesene Einsendungen', $count, 'wp-dsgvo-form' ),
						$count
					),
				),
			)
		);
	}

	/**
	 * Renders inline badge CSS.
	 *
	 * Inline because it's < 200 bytes — no separate stylesheet needed.
	 * Visually consistent with WordPress notification badges (plugin updates, comments).
	 */
	public function render_badge_styles(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! user_can( get_current_user_id(), 'dsgvo_form_view_submissions' ) ) {
			return;
		}

		?>
		<style>
		#wpadminbar .wpdsgvo-admin-bar-notification .ab-item {
			display: flex;
			align-items: center;
		}
		#wpadminbar .wpdsgvo-unread-count {
			background: #d63638;
			color: #fff;
			border-radius: 50%;
			font-size: 9px;
			font-weight: 600;
			min-width: 17px;
			height: 17px;
			line-height: 17px;
			text-align: center;
			display: inline-block;
			margin-left: 2px;
			padding: 0 4px;
			box-sizing: border-box;
		}
		</style>
		<?php
	}

	/**
	 * Returns the unread submission count for a user.
	 *
	 * Admin/Supervisor: global count (all forms).
	 * Reader: only assigned forms via Recipient table.
	 * Cached via WordPress Transients (2 min TTL, no active invalidation).
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return int Unread count.
	 */
	private function get_unread_count( int $user_id ): int {
		if ( $this->access_control->is_admin( $user_id ) || $this->access_control->is_supervisor( $user_id ) ) {
			return $this->get_global_unread_count();
		}

		return $this->get_reader_unread_count( $user_id );
	}

	/**
	 * Returns the global unread count for Admin/Supervisor roles.
	 *
	 * Uses a shared transient (same count for all admins/supervisors).
	 * Restricted submissions (Art. 18 DSGVO) are excluded.
	 *
	 * @return int Global unread count.
	 */
	private function get_global_unread_count(): int {
		$cache_key = 'wpdsgvo_unread_count';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Submission::get_table_name();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE is_read = %d AND is_restricted = %d",
				0,
				0
			)
		);

		set_transient( $cache_key, $count, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Returns the unread count for a Reader (only assigned forms).
	 *
	 * Uses a per-user transient because each Reader sees different forms.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return int Reader-scoped unread count.
	 */
	private function get_reader_unread_count( int $user_id ): int {
		$cache_key = 'wpdsgvo_unread_count_' . $user_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$form_ids = Recipient::get_form_ids_for_user( $user_id );

		if ( empty( $form_ids ) ) {
			set_transient( $cache_key, 0, self::CACHE_TTL );
			return 0;
		}

		$count = Submission::count_by_form_ids( $form_ids, false, false );
		set_transient( $cache_key, $count, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Builds the badge HTML for the admin bar node title.
	 *
	 * Uses dashicons-email-alt icon + red count bubble.
	 * Count > 99 displays as "99+" (UX-Limit).
	 * Includes screen-reader-text for WCAG 2.1 AA accessibility.
	 *
	 * @param int $count Unread submission count.
	 * @return string HTML for the admin bar node title.
	 */
	private function build_badge_html( int $count ): string {
		$display = $count > self::COUNT_CAP ? '99+' : (string) $count;

		$screen_reader_text = sprintf(
			/* translators: %d: number of unread submissions */
			_n( '%d ungelesene Einsendung', '%d ungelesene Einsendungen', $count, 'wp-dsgvo-form' ),
			$count
		);

		return '<span class="ab-icon dashicons dashicons-email-alt"></span>'
			. '<span class="wpdsgvo-unread-count">' . esc_html( $display ) . '</span>'
			. '<span class="screen-reader-text">' . esc_html( $screen_reader_text ) . '</span>';
	}
}
