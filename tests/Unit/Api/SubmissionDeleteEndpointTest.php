<?php
/**
 * Unit tests for SubmissionDeleteEndpoint REST API.
 *
 * Covers: permission checks via AccessControl, Art. 18 restriction protection,
 * submission not found, cascading deletion via SubmissionDeleter, audit logging,
 * and delete failure handling.
 *
 * @package WpDsgvoForm\Tests\Unit\Api
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Api;

use WpDsgvoForm\Api\SubmissionDeleteEndpoint;
use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Auth\AccessControl;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionDeleteEndpoint.
 */
class SubmissionDeleteEndpointTest extends TestCase {

	private SubmissionDeleter $deleter;
	private AccessControl $access_control;
	private AuditLogger $audit_logger;
	private SubmissionDeleteEndpoint $endpoint;

	protected function setUp(): void {
		parent::setUp();

		$this->deleter        = Mockery::mock( SubmissionDeleter::class );
		$this->access_control = Mockery::mock( AccessControl::class );
		$this->audit_logger   = Mockery::mock( AuditLogger::class );
		$this->endpoint       = new SubmissionDeleteEndpoint(
			$this->deleter,
			$this->access_control,
			$this->audit_logger
		);

		// Default WordPress function mocks.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Mock wpdb for Submission::find.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing(
			function ( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%d', '%s' ], [ '%s', "'%s'" ], $query ), $args );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Creates a mock WP_REST_Request with a given submission ID.
	 */
	private function make_request( int $id ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( $id );
		return $request;
	}

	// ──────────────────────────────────────────────────
	// Permission checks (SEC-AUTH-03)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-AUTH-03 — AccessControl capability check
	 */
	public function test_returns_403_without_delete_permission(): void {
		$this->access_control->shouldReceive( 'can_delete_submission' )
			->with( 1 )
			->once()
			->andReturn( false );

		$request = $this->make_request( 1 );
		$result  = $this->endpoint->check_permissions( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * @test
	 */
	public function test_allows_user_with_delete_permission(): void {
		$this->access_control->shouldReceive( 'can_delete_submission' )
			->with( 1 )
			->once()
			->andReturn( true );

		$request = $this->make_request( 1 );
		$result  = $this->endpoint->check_permissions( $request );

		$this->assertTrue( $result );
	}

	// ──────────────────────────────────────────────────
	// Submission not found
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_returns_404_when_submission_not_found(): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->once()->andReturn( null );

		$request = $this->make_request( 999 );
		$result  = $this->endpoint->handle_delete( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Art. 18 DSGVO — Restricted submissions
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-13 — Restricted submissions cannot be deleted
	 */
	public function test_returns_409_when_submission_is_restricted(): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'              => 7,
				'form_id'         => 1,
				'encrypted_data'  => 'enc',
				'iv'              => 'iv',
				'auth_tag'        => 'tag',
				'submitted_at'    => '2026-04-17',
				'is_read'         => 0,
				'is_restricted'   => 1,
			] );

		$request = $this->make_request( 7 );
		$result  = $this->endpoint->handle_delete( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_locked', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────
	// Successful deletion with audit logging
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 17 DSGVO — Cascading deletion with audit trail
	 */
	public function test_successful_delete_returns_200_and_logs_audit(): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'              => 42,
				'form_id'         => 1,
				'encrypted_data'  => 'enc',
				'iv'              => 'iv',
				'auth_tag'        => 'tag',
				'submitted_at'    => '2026-04-17',
				'is_read'         => 0,
				'is_restricted'   => 0,
			] );

		// SEC-AUDIT-01: Audit log BEFORE deletion.
		$this->audit_logger->shouldReceive( 'log' )
			->with( 1, 'delete', 42, 1 )
			->once();

		$this->deleter->shouldReceive( 'delete' )
			->with( 42 )
			->once()
			->andReturn( true );

		$request = $this->make_request( 42 );
		$result  = $this->endpoint->handle_delete( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['deleted'] );
		$this->assertSame( 42, $result->get_data()['id'] );
	}

	// ──────────────────────────────────────────────────
	// Delete failure
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_returns_500_when_deleter_fails(): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'              => 10,
				'form_id'         => 1,
				'encrypted_data'  => 'enc',
				'iv'              => 'iv',
				'auth_tag'        => 'tag',
				'submitted_at'    => '2026-04-17',
				'is_read'         => 0,
				'is_restricted'   => 0,
			] );

		$this->audit_logger->shouldReceive( 'log' )->once();

		$this->deleter->shouldReceive( 'delete' )
			->with( 10 )
			->once()
			->andReturn( false );

		$request = $this->make_request( 10 );
		$result  = $this->endpoint->handle_delete( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'delete_failed', $result->get_error_code() );
	}
}
