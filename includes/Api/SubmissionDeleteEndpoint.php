<?php
/**
 * REST API endpoint for deleting submissions.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Api;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Submission;

/**
 * REST API endpoint for deleting submissions with cascading file cleanup.
 *
 * Handles DELETE /wp-json/dsgvo-form/v1/submissions/{id} with capability
 * checks, audit logging, and nonce verification.
 *
 * Uses SubmissionDeleter for Art. 17 DSGVO compliant cascading deletion
 * (physical files removed before DB record).
 *
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung
 * @security-critical SEC-AUTH-03 — Capability-based access control
 * @security-critical SEC-AUDIT-01 — Deletion audit logging
 */
class SubmissionDeleteEndpoint {

	private const NAMESPACE = 'dsgvo-form/v1';
	private const ROUTE     = '/submissions/(?P<id>[\d]+)';

	private SubmissionDeleter $deleter;
	private AccessControl $access_control;
	private AuditLogger $audit_logger;

	public function __construct( SubmissionDeleter $deleter, AccessControl $access_control, AuditLogger $audit_logger ) {
		$this->deleter        = $deleter;
		$this->access_control = $access_control;
		$this->audit_logger   = $audit_logger;
	}

	/**
	 * Registers the REST API route.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ): bool {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => static function ( $value ): int {
							return (int) $value;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission callback — uses AccessControl for capability check.
	 *
	 * SEC-AUTH-03: Capability-based via AccessControl service.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_permissions( \WP_REST_Request $request ) {
		if ( ! $this->access_control->can_delete_submission( get_current_user_id() ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'Sie haben keine Berechtigung, Einsendungen zu loeschen.', 'wp-dsgvo-form' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handles a DELETE request for a single submission.
	 *
	 * Verifies the submission exists, then delegates to SubmissionDeleter
	 * for cascading file + DB deletion.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_delete( \WP_REST_Request $request ) {
		$submission_id = (int) $request->get_param( 'id' );

		// Verify the submission exists.
		$submission = Submission::find( $submission_id );

		if ( null === $submission ) {
			return new \WP_Error(
				'not_found',
				__( 'Einsendung nicht gefunden.', 'wp-dsgvo-form' ),
				array( 'status' => 404 )
			);
		}

		// SEC-DSGVO-13: Locked submissions cannot be deleted without explicit unlock.
		if ( $submission->is_restricted ) {
			return new \WP_Error(
				'submission_locked',
				__( 'Diese Einsendung ist gesperrt (Art. 18 DSGVO) und kann nicht geloescht werden.', 'wp-dsgvo-form' ),
				array( 'status' => 409 )
			);
		}

		// SEC-AUDIT-01: Log deletion BEFORE executing (data is gone after).
		$this->audit_logger->log( get_current_user_id(), 'delete', $submission_id, $submission->form_id );

		// Cascading deletion: files first, then DB record.
		$deleted = $this->deleter->delete( $submission_id );

		if ( ! $deleted ) {
			return new \WP_Error(
				'delete_failed',
				__( 'Beim Loeschen ist ein Fehler aufgetreten.', 'wp-dsgvo-form' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $submission_id,
			),
			200
		);
	}
}
