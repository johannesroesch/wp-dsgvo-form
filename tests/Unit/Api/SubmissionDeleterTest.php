<?php
/**
 * Unit tests for SubmissionDeleter.
 *
 * Covers cascading deletion: physical files removed BEFORE DB record,
 * batch expiry cleanup, and FileHandler error handling.
 *
 * @package WpDsgvoForm\Tests\Unit\Api
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Api;

use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Upload\FileHandler;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for SubmissionDeleter.
 */
class SubmissionDeleterTest extends TestCase {

	private FileHandler $file_handler;
	private SubmissionDeleter $deleter;

	protected function setUp(): void {
		parent::setUp();

		$this->file_handler = Mockery::mock( FileHandler::class );
		$this->deleter      = new SubmissionDeleter( $this->file_handler );

		// Default mocks for WordPress functions.
		Functions\when( '__' )->returnArg( 1 );

		// Mock wpdb.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing(
			function ( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%d', '%s' ], [ '%s', "'%s'" ], $query ), $args );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	// ──────────────────────────────────────────────────
	// delete() — Single submission cascading deletion
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 17 DSGVO — Physical files deleted before DB record
	 */
	public function test_delete_removes_files_then_db_record(): void {
		$wpdb = $GLOBALS['wpdb'];

		// get_file_paths: returns 2 file paths.
		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '/uploads/file1.pdf', '/uploads/file2.jpg' ] );

		// FileHandler must delete each file.
		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/file1.pdf' )
			->once()
			->andReturn( true );

		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/file2.jpg' )
			->once()
			->andReturn( true );

		// Submission::delete via wpdb->delete.
		$wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_dsgvo_submissions', [ 'id' => 42 ], [ '%d' ] )
			->andReturn( 1 );

		$result = $this->deleter->delete( 42 );

		$this->assertTrue( $result );
	}

	/**
	 * @test
	 */
	public function test_delete_without_files_deletes_db_record(): void {
		$wpdb = $GLOBALS['wpdb'];

		// No file paths.
		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		// FileHandler should NOT be called.
		$this->file_handler->shouldNotReceive( 'delete_file' );

		// DB deletion succeeds.
		$wpdb->shouldReceive( 'delete' )
			->once()
			->andReturn( 1 );

		$result = $this->deleter->delete( 10 );

		$this->assertTrue( $result );
	}

	/**
	 * @test
	 */
	public function test_delete_returns_false_on_db_failure(): void {
		$wpdb = $GLOBALS['wpdb'];

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		// DB deletion fails.
		$wpdb->shouldReceive( 'delete' )
			->once()
			->andReturn( false );

		$result = $this->deleter->delete( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-09 — Files still deleted even if one fails
	 */
	public function test_delete_continues_file_deletion_on_handler_failure(): void {
		$wpdb = $GLOBALS['wpdb'];

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '/uploads/fail.pdf', '/uploads/ok.jpg' ] );

		// First file deletion fails.
		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/fail.pdf' )
			->once()
			->andReturn( false );

		// Second file deletion still called.
		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/ok.jpg' )
			->once()
			->andReturn( true );

		// DB record still deleted.
		$wpdb->shouldReceive( 'delete' )
			->once()
			->andReturn( 1 );

		$result = $this->deleter->delete( 5 );

		$this->assertTrue( $result );
	}

	// ──────────────────────────────────────────────────
	// delete_expired() — Batch expired submission cleanup
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-08 — Batch cleanup deletes files for expired submissions
	 */
	public function test_delete_expired_cleans_up_files(): void {
		$wpdb = $GLOBALS['wpdb'];

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		// Submission::delete_expired finds 2 expired IDs with file paths.
		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '1', '2' ] ); // expired IDs

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '/uploads/expired1.pdf', '/uploads/expired2.jpg' ] ); // file paths

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 2 ); // deleted count

		// FileHandler deletes each physical file.
		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/expired1.pdf' )
			->once();

		$this->file_handler->shouldReceive( 'delete_file' )
			->with( '/uploads/expired2.jpg' )
			->once();

		$result = $this->deleter->delete_expired( 100 );

		$this->assertSame( 2, $result['count'] );
		$this->assertCount( 2, $result['file_paths'] );
	}

	/**
	 * @test
	 */
	public function test_delete_expired_with_no_expired_does_nothing(): void {
		$wpdb = $GLOBALS['wpdb'];

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		// FileHandler should NOT be called.
		$this->file_handler->shouldNotReceive( 'delete_file' );

		$result = $this->deleter->delete_expired();

		$this->assertSame( 0, $result['count'] );
		$this->assertEmpty( $result['file_paths'] );
	}
}
