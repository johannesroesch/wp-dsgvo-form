<?php
/**
 * Central access control service.
 *
 * Provides IDOR-safe capability and recipient-based access checks
 * for submissions, forms, and admin features.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Handles authorization checks for the DSGVO Form plugin.
 *
 * Three access tiers:
 * - Admin (`dsgvo_form_manage`): Full access, no audit logging.
 * - Supervisor (`dsgvo_form_view_all_submissions`): Full read, every access logged.
 * - Reader (`dsgvo_form_view_submissions`): Only assigned forms, via recipient table.
 *
 * Security requirements: SEC-AUTH-03, SEC-AUTH-10, SEC-AUTH-14, SEC-AUTH-DSGVO-03.
 */
class AccessControl {


	/**
	 * Plugin roles that receive restricted backend access.
	 *
	 * @var string[]
	 */
	public const PLUGIN_ROLES = array(
		'wp_dsgvo_form_reader',
		'wp_dsgvo_form_supervisor',
	);

	/**
	 * Capability for DSGVO-compliant recipient access.
	 *
	 * Replaces role-based check with capability-based check.
	 * Users with this capability are recognized as plugin users
	 * for login redirect, admin-bar restrictions, and access control.
	 */
	public const RECIPIENT_CAPABILITY = 'dsgvo_form_recipient';

	/**
	 * Checks whether a user can view a specific form's submissions.
	 *
	 * SEC-AUTH-DSGVO-03: Admin → full access, Supervisor → all forms (logged),
	 * Reader → only assigned forms via recipient table.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @param int $form_id The form ID.
	 * @return bool True if the user may access the form.
	 */
	public function can_view_form( int $user_id, int $form_id ): bool {
		// Admin: full access.
		if ( user_can( $user_id, 'dsgvo_form_manage' ) ) {
			return true;
		}

		// Supervisor: all forms (caller is responsible for audit logging).
		if ( $this->is_supervisor( $user_id ) ) {
			return true;
		}

		// Reader: only assigned forms.
		if ( ! user_can( $user_id, 'dsgvo_form_view_submissions' ) ) {
			return false;
		}

		return $this->is_recipient_of_form( $user_id, $form_id );
	}

	/**
	 * Checks whether a user can view a specific submission (IDOR protection).
	 *
	 * SEC-AUTH-14: Every access must verify the user is allowed to see
	 * THIS specific submission, not just submissions in general.
	 *
	 * @param int $user_id       The WordPress user ID.
	 * @param int $submission_id The submission ID.
	 * @return bool True if the user may access the submission.
	 */
	public function can_view_submission( int $user_id, int $submission_id ): bool {
		// Admin: full access.
		if ( user_can( $user_id, 'dsgvo_form_manage' ) ) {
			return true;
		}

		// Supervisor: all submissions (caller must log).
		if ( $this->is_supervisor( $user_id ) ) {
			return true;
		}

		// Reader: only submissions for assigned forms.
		if ( ! user_can( $user_id, 'dsgvo_form_view_submissions' ) ) {
			return false;
		}

		$form_id = $this->get_form_id_for_submission( $submission_id );

		if ( 0 === $form_id ) {
			return false;
		}

		return $this->is_recipient_of_form( $user_id, $form_id );
	}

	/**
	 * Checks whether a user can delete a submission.
	 *
	 * Only administrators with the explicit delete capability.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user may delete submissions.
	 */
	public function can_delete_submission( int $user_id ): bool {
		return user_can( $user_id, 'dsgvo_form_delete_submissions' );
	}

	/**
	 * Checks whether a user can export submissions.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user may export submissions.
	 */
	public function can_export( int $user_id ): bool {
		return user_can( $user_id, 'dsgvo_form_export' );
	}

	/**
	 * Checks whether a user has the Supervisor role.
	 *
	 * Supervisors can see all submissions across all forms,
	 * but every access MUST be audit-logged (SEC-AUDIT-01).
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user is a supervisor.
	 */
	public function is_supervisor( int $user_id ): bool {
		return user_can( $user_id, 'dsgvo_form_view_all_submissions' );
	}

	/**
	 * Checks whether a user has plugin access (capability or legacy role).
	 *
	 * Dual-check: Returns true if the user has the dsgvo_form_recipient
	 * capability OR one of the legacy plugin roles. This enables a gradual
	 * migration from role-based to capability-based access control.
	 *
	 * Used to determine if login redirect and admin-bar restrictions apply.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user has plugin access.
	 */
	public function has_plugin_access( int $user_id ): bool {
		// New capability-based check.
		if ( user_can( $user_id, self::RECIPIENT_CAPABILITY ) ) {
			return true;
		}

		// Legacy role-based check for backward compatibility.
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return ! empty( array_intersect( self::PLUGIN_ROLES, $user->roles ) );
	}

	/**
	 * Checks whether a user has one of the plugin's custom roles.
	 *
	 * @deprecated 1.2.0 Use has_plugin_access() instead.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user has a plugin role.
	 */
	public function has_plugin_role( int $user_id ): bool {
		_deprecated_function( __METHOD__, '1.2.0', 'AccessControl::has_plugin_access()' );
		return $this->has_plugin_access( $user_id );
	}

	/**
	 * Checks whether a user is an admin with plugin management rights.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return bool True if the user can manage forms.
	 */
	public function is_admin( int $user_id ): bool {
		return user_can( $user_id, 'dsgvo_form_manage' );
	}

	/**
	 * Checks whether a user is assigned as a recipient for a specific form.
	 *
	 * SEC-AUTH-10: Recipient assignment via `dsgvo_form_recipients` table.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @param int $form_id The form ID.
	 * @return bool True if the user is a recipient of the form.
	 */
	private function is_recipient_of_form( int $user_id, int $form_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'dsgvo_form_recipients';

		// SEC-SQL-01: Prepared statement.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d AND user_id = %d",
				$form_id,
				$user_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Returns the form ID for a given submission.
	 *
	 * @param int $submission_id The submission ID.
	 * @return int The form ID, or 0 if not found.
	 */
	private function get_form_id_for_submission( int $submission_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'dsgvo_submissions';

		// SEC-SQL-01: Prepared statement.
		$form_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT form_id FROM `{$table}` WHERE id = %d",
				$submission_id
			)
		);

		return null !== $form_id ? (int) $form_id : 0;
	}
}
