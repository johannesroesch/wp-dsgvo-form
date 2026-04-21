<?php
/**
 * Capability manager for audited capability grant/revoke operations.
 *
 * Wraps WordPress user_can/add_cap/remove_cap with enforced audit logging.
 * Every capability change is recorded for DSGVO accountability (Art. 5 Abs. 2).
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Auth;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Audit\AuditLogger;

/**
 * Manages plugin capability grants and revocations with audit trail.
 *
 * DPO-SOLL-F06: Every capability change must include a context explaining
 * why the change was made (manual, migration, auto_grant, auto_revoke).
 *
 * Security requirements: SEC-AUTH-DSGVO-03.
 */
class CapabilityManager {

	/**
	 * Allowed context values for capability changes (DPO-SOLL-F06).
	 *
	 * @var string[]
	 */
	private const ALLOWED_CONTEXTS = [
		'manual',
		'migration',
		'auto_grant',
		'auto_revoke',
	];

	/**
	 * Audit logger instance.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * @param AuditLogger $audit_logger Shared audit logger.
	 */
	public function __construct( AuditLogger $audit_logger ) {
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Grants a capability to a user with audit logging.
	 *
	 * DPO-SOLL-F06: Context is mandatory to document the reason for the change.
	 *
	 * @param int    $user_id    The user receiving the capability.
	 * @param string $capability The capability slug to grant.
	 * @param int    $granted_by The user ID performing the grant.
	 * @param string $context    Why the capability was granted (manual|migration|auto_grant|auto_revoke).
	 * @return void
	 * @throws \InvalidArgumentException If context is invalid.
	 * @throws \RuntimeException If user does not exist.
	 */
	public function grant( int $user_id, string $capability, int $granted_by, string $context = 'manual' ): void {
		$this->validate_context( $context );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			throw new \RuntimeException( sprintf( 'User #%d does not exist.', $user_id ) );
		}

		$user->add_cap( $capability );

		$this->audit_logger->log(
			$granted_by,
			'capability_granted',
			null,
			null,
			sprintf(
				'Capability "%s" granted to user #%d (%s) — context: %s',
				$capability,
				$user_id,
				$user->user_login,
				$context
			)
		);
	}

	/**
	 * Revokes a capability from a user with audit logging.
	 *
	 * DPO-SOLL-F06: Context is mandatory to document the reason for the change.
	 *
	 * @param int    $user_id    The user losing the capability.
	 * @param string $capability The capability slug to revoke.
	 * @param int    $revoked_by The user ID performing the revocation.
	 * @param string $context    Why the capability was revoked (manual|migration|auto_grant|auto_revoke).
	 * @return void
	 * @throws \InvalidArgumentException If context is invalid.
	 * @throws \RuntimeException If user does not exist.
	 */
	public function revoke( int $user_id, string $capability, int $revoked_by, string $context = 'manual' ): void {
		$this->validate_context( $context );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			throw new \RuntimeException( sprintf( 'User #%d does not exist.', $user_id ) );
		}

		$user->remove_cap( $capability );

		$this->audit_logger->log(
			$revoked_by,
			'capability_revoked',
			null,
			null,
			sprintf(
				'Capability "%s" revoked from user #%d (%s) — context: %s',
				$capability,
				$user_id,
				$user->user_login,
				$context
			)
		);
	}

	/**
	 * Validates that the context is one of the allowed values.
	 *
	 * @param string $context The context to validate.
	 * @throws \InvalidArgumentException If context is not allowed.
	 */
	private function validate_context( string $context ): void {
		if ( ! in_array( $context, self::ALLOWED_CONTEXTS, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid context "%s". Allowed: %s',
					esc_html( $context ),
					implode( ', ', self::ALLOWED_CONTEXTS )
				)
			);
		}
	}
}
