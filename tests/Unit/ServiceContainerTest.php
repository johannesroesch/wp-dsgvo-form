<?php
/**
 * Unit tests for ServiceContainer.
 *
 * KANN-ARCH-01: Centralized lazy service container.
 *
 * @package WpDsgvoForm\Tests\Unit
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit;

use WpDsgvoForm\ServiceContainer;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\CapabilityManager;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for ServiceContainer — lazy initialization, singleton pattern.
 */
class ServiceContainerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( '' );
	}

	// ------------------------------------------------------------------
	// audit_logger — lazy initialization
	// ------------------------------------------------------------------

	/**
	 * @test
	 * KANN-ARCH-01: audit_logger() returns AuditLogger instance.
	 */
	public function test_audit_logger_returns_instance(): void {
		$container = new ServiceContainer();
		$logger    = $container->audit_logger();

		$this->assertInstanceOf( AuditLogger::class, $logger );
	}

	/**
	 * @test
	 * KANN-ARCH-01: audit_logger() returns same instance (lazy singleton).
	 */
	public function test_audit_logger_returns_same_instance(): void {
		$container = new ServiceContainer();

		$logger1 = $container->audit_logger();
		$logger2 = $container->audit_logger();

		$this->assertSame( $logger1, $logger2 );
	}

	// ------------------------------------------------------------------
	// encryption — lazy initialization (requires KEK)
	// ------------------------------------------------------------------

	/**
	 * @test
	 * KANN-ARCH-01: encryption() returns EncryptionService instance.
	 */
	public function test_encryption_returns_instance(): void {
		$container  = new ServiceContainer();
		$encryption = $container->encryption();

		$this->assertInstanceOf( EncryptionService::class, $encryption );
	}

	/**
	 * @test
	 * KANN-ARCH-01: encryption() returns same instance (lazy singleton).
	 */
	public function test_encryption_returns_same_instance(): void {
		$container = new ServiceContainer();

		$enc1 = $container->encryption();
		$enc2 = $container->encryption();

		$this->assertSame( $enc1, $enc2 );
	}

	// ------------------------------------------------------------------
	// Independence — different services are independent
	// ------------------------------------------------------------------

	/**
	 * @test
	 * KANN-ARCH-01: audit_logger and encryption are independent instances.
	 */
	public function test_services_are_independent(): void {
		$container = new ServiceContainer();

		$logger     = $container->audit_logger();
		$encryption = $container->encryption();

		$this->assertNotSame( $logger, $encryption );
	}

	/**
	 * @test
	 * KANN-ARCH-01: New container creates new instances.
	 */
	public function test_new_container_creates_new_instances(): void {
		$container1 = new ServiceContainer();
		$container2 = new ServiceContainer();

		$this->assertNotSame(
			$container1->audit_logger(),
			$container2->audit_logger()
		);
	}

	// ------------------------------------------------------------------
	// capability_manager — lazy initialization
	// ------------------------------------------------------------------

	/**
	 * @test
	 * DPO-SOLL-F06: capability_manager() returns CapabilityManager instance.
	 */
	public function test_capability_manager_returns_instance(): void {
		$container = new ServiceContainer();
		$manager   = $container->capability_manager();

		$this->assertInstanceOf( CapabilityManager::class, $manager );
	}

	/**
	 * @test
	 * DPO-SOLL-F06: capability_manager() returns same instance (lazy singleton).
	 */
	public function test_capability_manager_returns_same_instance(): void {
		$container = new ServiceContainer();

		$mgr1 = $container->capability_manager();
		$mgr2 = $container->capability_manager();

		$this->assertSame( $mgr1, $mgr2 );
	}

	/**
	 * @test
	 * capability_manager is independent from other services.
	 */
	public function test_capability_manager_is_independent_service(): void {
		$container = new ServiceContainer();

		$manager    = $container->capability_manager();
		$logger     = $container->audit_logger();
		$encryption = $container->encryption();

		$this->assertNotSame( $manager, $logger );
		$this->assertNotSame( $manager, $encryption );
	}
}
