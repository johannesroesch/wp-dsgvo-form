<?php
/**
 * Unit tests for CapabilityManager.
 *
 * Covers DPO-SOLL-F06: audited capability grant/revoke with context enforcement.
 *
 * @package WpDsgvoForm\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Auth;

use WpDsgvoForm\Auth\CapabilityManager;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for CapabilityManager: grant, revoke, context validation, audit logging.
 *
 * @security-relevant SEC-AUTH-DSGVO-03
 * @privacy-relevant  DPO-SOLL-F06 — every capability change must be audit-logged
 */
class CapabilityManagerTest extends TestCase {

	private AuditLogger|Mockery\MockInterface $audit_logger;

	protected function setUp(): void {
		parent::setUp();
		$this->audit_logger = Mockery::mock( AuditLogger::class );
	}

	/**
	 * Creates a mock WP_User object.
	 *
	 * @param int    $id         User ID.
	 * @param string $user_login Login name.
	 * @return Mockery\MockInterface
	 */
	private function mock_wp_user( int $id, string $user_login = 'testuser' ): Mockery\MockInterface {
		$user = Mockery::mock( 'WP_User' );
		$user->ID         = $id;
		$user->user_login = $user_login;

		return $user;
	}

	// ------------------------------------------------------------------
	// grant() — Happy path
	// ------------------------------------------------------------------

	/**
	 * @test
	 * DPO-SOLL-F06: grant() calls add_cap on the user.
	 */
	public function test_grant_calls_add_cap_on_user(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'add_cap' )
			->once()
			->with( 'dsgvo_form_view_submissions' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )->once();

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_view_submissions', 1, 'manual' );
	}

	/**
	 * @test
	 * DPO-SOLL-F06: grant() logs capability_granted with all details.
	 */
	public function test_grant_logs_capability_granted_event(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'add_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				1,
				'capability_granted',
				null,
				null,
				Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'dsgvo_form_view_submissions' )
						&& str_contains( $msg, '#42' )
						&& str_contains( $msg, 'reader1' )
						&& str_contains( $msg, 'manual' );
				} )
			);

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_view_submissions', 1, 'manual' );
	}

	/**
	 * @test
	 * All allowed contexts should be accepted for grant().
	 */
	public function test_grant_accepts_all_allowed_contexts(): void {
		$allowed = [ 'manual', 'migration', 'auto_grant', 'auto_revoke' ];

		foreach ( $allowed as $context ) {
			$user = $this->mock_wp_user( 10, 'user1' );
			$user->shouldReceive( 'add_cap' )->once();

			Functions\when( 'get_userdata' )->justReturn( $user );

			$logger = Mockery::mock( AuditLogger::class );
			$logger->shouldReceive( 'log' )
				->once()
				->with(
					1,
					'capability_granted',
					null,
					null,
					Mockery::on( function ( string $msg ) use ( $context ): bool {
						return str_contains( $msg, $context );
					} )
				);

			$manager = new CapabilityManager( $logger );
			$manager->grant( 10, 'dsgvo_form_recipient', 1, $context );
		}
	}

	/**
	 * @test
	 * Default context is 'manual'.
	 */
	public function test_grant_uses_manual_as_default_context(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'add_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				1,
				'capability_granted',
				null,
				null,
				Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'manual' );
				} )
			);

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_view_submissions', 1 );
	}

	// ------------------------------------------------------------------
	// grant() — Context validation
	// ------------------------------------------------------------------

	/**
	 * @test
	 * DPO-SOLL-F06: Invalid context throws InvalidArgumentException.
	 */
	public function test_grant_throws_on_invalid_context(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid context "hacker_mode"' );

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_view_submissions', 1, 'hacker_mode' );
	}

	/**
	 * @test
	 */
	public function test_grant_throws_on_empty_context(): void {
		$this->expectException( \InvalidArgumentException::class );

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_view_submissions', 1, '' );
	}

	// ------------------------------------------------------------------
	// grant() — User not found
	// ------------------------------------------------------------------

	/**
	 * @test
	 * RuntimeException when user does not exist.
	 */
	public function test_grant_throws_when_user_does_not_exist(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'User #999 does not exist' );

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 999, 'dsgvo_form_view_submissions', 1, 'manual' );
	}

	/**
	 * @test
	 * No audit log entry when user does not exist.
	 */
	public function test_grant_does_not_log_when_user_not_found(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->audit_logger->shouldNotReceive( 'log' );

		try {
			$manager = new CapabilityManager( $this->audit_logger );
			$manager->grant( 999, 'dsgvo_form_view_submissions', 1, 'manual' );
		} catch ( \RuntimeException $e ) {
			// Expected.
		}
	}

	// ------------------------------------------------------------------
	// revoke() — Happy path
	// ------------------------------------------------------------------

	/**
	 * @test
	 * DPO-SOLL-F06: revoke() calls remove_cap on the user.
	 */
	public function test_revoke_calls_remove_cap_on_user(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'remove_cap' )
			->once()
			->with( 'dsgvo_form_view_submissions' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )->once();

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 42, 'dsgvo_form_view_submissions', 1, 'manual' );
	}

	/**
	 * @test
	 * DPO-SOLL-F06: revoke() logs capability_revoked with all details.
	 */
	public function test_revoke_logs_capability_revoked_event(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'remove_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				1,
				'capability_revoked',
				null,
				null,
				Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'dsgvo_form_view_submissions' )
						&& str_contains( $msg, '#42' )
						&& str_contains( $msg, 'reader1' )
						&& str_contains( $msg, 'auto_revoke' );
				} )
			);

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 42, 'dsgvo_form_view_submissions', 1, 'auto_revoke' );
	}

	/**
	 * @test
	 * Default context for revoke is 'manual'.
	 */
	public function test_revoke_uses_manual_as_default_context(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'remove_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				1,
				'capability_revoked',
				null,
				null,
				Mockery::on( function ( string $msg ): bool {
					return str_contains( $msg, 'manual' );
				} )
			);

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 42, 'dsgvo_form_view_submissions', 1 );
	}

	// ------------------------------------------------------------------
	// revoke() — Context validation
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_revoke_throws_on_invalid_context(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid context "bogus"' );

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 42, 'dsgvo_form_view_submissions', 1, 'bogus' );
	}

	// ------------------------------------------------------------------
	// revoke() — User not found
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_revoke_throws_when_user_does_not_exist(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'User #999 does not exist' );

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 999, 'dsgvo_form_view_submissions', 1, 'manual' );
	}

	/**
	 * @test
	 */
	public function test_revoke_does_not_log_when_user_not_found(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->audit_logger->shouldNotReceive( 'log' );

		try {
			$manager = new CapabilityManager( $this->audit_logger );
			$manager->revoke( 999, 'dsgvo_form_view_submissions', 1, 'manual' );
		} catch ( \RuntimeException $e ) {
			// Expected.
		}
	}

	// ------------------------------------------------------------------
	// Idempotency — grant/revoke on same capability is safe
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Granting the same capability twice calls add_cap twice (idempotent at WP level).
	 */
	public function test_grant_is_idempotent(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'add_cap' )
			->twice()
			->with( 'dsgvo_form_recipient' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )->twice();

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->grant( 42, 'dsgvo_form_recipient', 1, 'auto_grant' );
		$manager->grant( 42, 'dsgvo_form_recipient', 1, 'auto_grant' );
	}

	/**
	 * @test
	 * Revoking the same capability twice calls remove_cap twice (idempotent at WP level).
	 */
	public function test_revoke_is_idempotent(): void {
		$user = $this->mock_wp_user( 42, 'reader1' );
		$user->shouldReceive( 'remove_cap' )
			->twice()
			->with( 'dsgvo_form_recipient' );

		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->audit_logger->shouldReceive( 'log' )->twice();

		$manager = new CapabilityManager( $this->audit_logger );
		$manager->revoke( 42, 'dsgvo_form_recipient', 1, 'auto_revoke' );
		$manager->revoke( 42, 'dsgvo_form_recipient', 1, 'auto_revoke' );
	}

	// ------------------------------------------------------------------
	// Context validation error message lists all allowed values
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_invalid_context_exception_lists_allowed_values(): void {
		try {
			$manager = new CapabilityManager( $this->audit_logger );
			$manager->grant( 42, 'dsgvo_form_view_submissions', 1, 'invalid' );
			$this->fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'manual', $e->getMessage() );
			$this->assertStringContainsString( 'migration', $e->getMessage() );
			$this->assertStringContainsString( 'auto_grant', $e->getMessage() );
			$this->assertStringContainsString( 'auto_revoke', $e->getMessage() );
		}
	}
}
