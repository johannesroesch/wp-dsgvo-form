<?php
/**
 * Lightweight service container.
 *
 * Provides lazy-initialized shared instances of core services.
 * Replaces duplicate lazy-getter patterns in AdminMenu and RecipientPage.
 *
 * KANN-ARCH-01: Centralizes service creation to avoid duplicate instantiation.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\CapabilityManager;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;

/**
 * Lazy-initialized service container for shared plugin services.
 */
class ServiceContainer {

	private ?EncryptionService $encryption       = null;
	private ?AuditLogger $audit_logger           = null;
	private ?CapabilityManager $capability_manager = null;

	/**
	 * Returns the shared EncryptionService instance.
	 *
	 * @return EncryptionService
	 */
	public function encryption(): EncryptionService {
		if ( $this->encryption === null ) {
			$this->encryption = new EncryptionService( new KeyManager() );
		}

		return $this->encryption;
	}

	/**
	 * Returns the shared AuditLogger instance.
	 *
	 * @return AuditLogger
	 */
	public function audit_logger(): AuditLogger {
		if ( $this->audit_logger === null ) {
			$this->audit_logger = new AuditLogger();
		}

		return $this->audit_logger;
	}

	/**
	 * Returns the shared CapabilityManager instance.
	 *
	 * DPO-SOLL-F06: All capability changes must be audit-logged.
	 *
	 * @return CapabilityManager
	 */
	public function capability_manager(): CapabilityManager {
		if ( $this->capability_manager === null ) {
			$this->capability_manager = new CapabilityManager( $this->audit_logger() );
		}

		return $this->capability_manager;
	}
}
