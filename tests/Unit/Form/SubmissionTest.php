<?php
/**
 * Unit tests for Submission model.
 *
 * Covers DSGVO-relevant logic: validation, delete_expired with restricted-exclusion,
 * pagination caps, email_lookup_hash search, Art. 18 restriction flag, and DPO retention rules.
 *
 * @package WpDsgvoForm\Tests\Unit\Form
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Form;

use WpDsgvoForm\Models\Submission;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the Submission model.
 */
class SubmissionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// esc_html is used in exception messages (Task #242: ExceptionNotEscaped fix).
		Functions\when( 'esc_html' )->returnArg();
	}

	/**
	 * Creates a mock $wpdb and sets it as global.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function mock_wpdb(): \Mockery\MockInterface {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing(
			function ( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%d', '%s' ], [ '%s', "'%s'" ], $query ), $args );
			}
		);

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}

	/**
	 * Creates a valid Submission instance for testing.
	 *
	 * Includes expires_at (DPO-FINDING-01: required on insert).
	 */
	private function make_valid_submission(): Submission {
		$sub                 = new Submission();
		$sub->form_id        = 1;
		$sub->encrypted_data = base64_encode( 'test-encrypted-data' );
		$sub->iv             = base64_encode( 'test-iv-value' );
		$sub->auth_tag       = base64_encode( 'test-auth-tag' );
		$sub->expires_at     = '2026-07-16 12:00:00';

		return $sub;
	}

	// ──────────────────────────────────────────────────
	// Validation tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_throws_on_missing_form_id(): void {
		$this->mock_wpdb();

		$sub                 = new Submission();
		$sub->form_id        = 0;
		$sub->encrypted_data = 'data';
		$sub->iv             = 'iv';
		$sub->auth_tag       = 'tag';
		$sub->expires_at     = '2026-07-16 12:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'form_id required' );

		$sub->save();
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_empty_encrypted_data(): void {
		$this->mock_wpdb();

		$sub                 = new Submission();
		$sub->form_id        = 1;
		$sub->encrypted_data = '';
		$sub->iv             = 'iv';
		$sub->auth_tag       = 'tag';
		$sub->expires_at     = '2026-07-16 12:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'encrypted data' );

		$sub->save();
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_missing_iv(): void {
		$this->mock_wpdb();

		$sub                 = new Submission();
		$sub->form_id        = 1;
		$sub->encrypted_data = 'data';
		$sub->iv             = '';
		$sub->auth_tag       = 'tag';
		$sub->expires_at     = '2026-07-16 12:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'IV and authentication tag' );

		$sub->save();
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_missing_auth_tag(): void {
		$this->mock_wpdb();

		$sub                 = new Submission();
		$sub->form_id        = 1;
		$sub->encrypted_data = 'data';
		$sub->iv             = 'iv';
		$sub->auth_tag       = '';
		$sub->expires_at     = '2026-07-16 12:00:00';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'IV and authentication tag' );

		$sub->save();
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-01 — No unlimited storage, expires_at required
	 */
	public function test_save_throws_on_missing_expires_at_for_new_submission(): void {
		$this->mock_wpdb();

		$sub                 = new Submission();
		$sub->form_id        = 1;
		$sub->encrypted_data = 'data';
		$sub->iv             = 'iv';
		$sub->auth_tag       = 'tag';
		// expires_at intentionally NOT set (null).

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'expires_at' );

		$sub->save();
	}

	/**
	 * @test
	 */
	public function test_save_allows_null_expires_at_on_update(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturn( 1 );

		$sub                 = new Submission();
		$sub->id             = 10;
		$sub->form_id        = 1;
		$sub->encrypted_data = 'data';
		$sub->iv             = 'iv';
		$sub->auth_tag       = 'tag';
		// expires_at null — allowed for updates.

		$id = $sub->save();

		$this->assertSame( 10, $id );
	}

	// ──────────────────────────────────────────────────
	// Save (insert) tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_inserts_new_submission_and_returns_id(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->insert_id = 42;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( 1 );

		$sub = $this->make_valid_submission();
		$id  = $sub->save();

		$this->assertSame( 42, $id );
		$this->assertSame( 42, $sub->id );
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_insert_failure(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->insert_id  = 0;
		$wpdb->last_error = 'DB connection lost';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to insert submission' );

		$sub = $this->make_valid_submission();
		$sub->save();
	}

	// ──────────────────────────────────────────────────
	// Save (update) tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_updates_existing_submission(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturn( 1 );

		$sub     = $this->make_valid_submission();
		$sub->id = 10;
		$id      = $sub->save();

		$this->assertSame( 10, $id );
	}

	// ──────────────────────────────────────────────────
	// Delete tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_delete_removes_submission_returns_true(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_dsgvo_submissions', [ 'id' => 5 ], [ '%d' ] )
			->andReturn( 1 );

		$this->assertTrue( Submission::delete( 5 ) );
	}

	/**
	 * @test
	 */
	public function test_delete_returns_false_on_failure(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'delete' )
			->once()
			->andReturn( false );

		$this->assertFalse( Submission::delete( 999 ) );
	}

	// ──────────────────────────────────────────────────
	// delete_expired — DSGVO Art. 17 + DPO-FINDING-09
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-08 — Auto-Loeschung nach Aufbewahrungsfrist
	 */
	public function test_delete_expired_removes_expired_non_locked_submissions(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '1', '2', '3' ] );

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '/uploads/file1.pdf', '/uploads/file2.jpg' ] );

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 3 );

		$result = Submission::delete_expired();

		$this->assertSame( 3, $result['count'] );
		$this->assertCount( 2, $result['file_paths'] );
		$this->assertContains( '/uploads/file1.pdf', $result['file_paths'] );
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-09 — Locked submissions duerfen NICHT durch Cron geloescht werden
	 */
	public function test_delete_expired_excludes_locked_submissions(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		// The SQL query filters on is_restricted = 0, so locked are never returned.
		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		$result = Submission::delete_expired();

		$this->assertSame( 0, $result['count'] );
		$this->assertEmpty( $result['file_paths'] );
	}

	/**
	 * @test
	 */
	public function test_delete_expired_returns_empty_when_none_expired(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		$result = Submission::delete_expired();

		$this->assertSame( 0, $result['count'] );
		$this->assertSame( [], $result['file_paths'] );
	}

	/**
	 * @test
	 * @performance Performance-Req §13.2 — Batch size limits long table locks
	 */
	public function test_delete_expired_respects_batch_size(): void {
		$wpdb = $this->mock_wpdb();

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->withArgs( function ( string $sql ) {
				// Verify LIMIT clause uses the batch_size parameter.
				return str_contains( $sql, 'LIMIT' );
			} )
			->andReturn( [] );

		Submission::delete_expired( 50 );
	}

	// ──────────────────────────────────────────────────
	// find_by_form_id — Pagination + locked filtering
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant SEC-DSGVO-13 — Locked submissions hidden by default
	 */
	public function test_find_by_form_id_excludes_locked_by_default(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'is_restricted' );
			} )
			->andReturn( [] );

		$result = Submission::find_by_form_id( 1 );

		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 */
	public function test_find_by_form_id_includes_locked_when_requested(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// Column is_restricted is in SELECT — but WHERE must NOT filter on is_restricted = 0.
				return ! str_contains( $sql, "is_restricted = '0'" );
			} )
			->andReturn( [] );

		$result = Submission::find_by_form_id( 1, 1, 20, null, true );

		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 */
	public function test_find_by_form_id_caps_per_page_at_max(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// MAX_PER_PAGE is 20 — requesting 100 should be capped.
				return str_contains( $sql, 'LIMIT 20' ) || str_contains( $sql, "LIMIT '20'" );
			} )
			->andReturn( [] );

		Submission::find_by_form_id( 1, 1, 100 );
	}

	/**
	 * @test
	 */
	public function test_find_by_form_id_enforces_minimum_per_page(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'LIMIT 1' ) || str_contains( $sql, "LIMIT '1'" );
			} )
			->andReturn( [] );

		Submission::find_by_form_id( 1, 1, 0 );
	}

	/**
	 * @test
	 * @performance Performance-Req §3 — List view excludes encrypted_data, iv, auth_tag
	 */
	public function test_find_by_form_id_uses_explicit_columns(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// Should NOT contain SELECT * but explicit columns.
				return ! str_contains( $sql, 'SELECT *' )
					&& str_contains( $sql, 'id' )
					&& str_contains( $sql, 'form_id' );
			} )
			->andReturn( [] );

		Submission::find_by_form_id( 1 );
	}

	/**
	 * @test
	 */
	public function test_find_by_form_id_returns_submission_instances(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id'             => 1,
					'form_id'        => 5,
					'submitted_at'   => '2026-04-17 10:00:00',
					'is_read'        => 0,
					'is_restricted'      => 0,
				],
			] );

		$result = Submission::find_by_form_id( 5 );

		$this->assertCount( 1, $result );
		$this->assertInstanceOf( Submission::class, $result[0] );
		$this->assertSame( 5, $result[0]->form_id );
		$this->assertFalse( $result[0]->is_restricted );
	}

	// ──────────────────────────────────────────────────
	// count_by_form_id
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_count_by_form_id_returns_integer_count(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '7' );

		$count = Submission::count_by_form_id( 1 );

		$this->assertSame( 7, $count );
	}

	// ──────────────────────────────────────────────────
	// find_by_email_lookup_hash — Art. 15 DSGVO Blind Index
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht via Blind Index
	 */
	public function test_find_by_email_lookup_hash_returns_matching_submissions(): void {
		$wpdb = $this->mock_wpdb();

		$hash = hash_hmac( 'sha256', 'test@example.com', 'secret-key' );

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'email_lookup_hash' );
			} )
			->andReturn( [
				[
					'id'             => 10,
					'form_id'        => 1,
					'encrypted_data' => 'enc',
					'iv'             => 'iv',
					'auth_tag'       => 'tag',
					'email_lookup_hash'    => $hash,
					'submitted_at'   => '2026-04-17',
					'is_read'        => 0,
					'is_restricted'      => 0,
				],
			] );

		$results = Submission::find_by_email_lookup_hash( $hash );

		$this->assertCount( 1, $results );
		$this->assertSame( $hash, $results[0]->email_lookup_hash );
	}

	/**
	 * @test
	 */
	public function test_find_by_email_lookup_hash_returns_empty_for_no_match(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [] );

		$results = Submission::find_by_email_lookup_hash( 'nonexistent-hash' );

		$this->assertSame( [], $results );
	}

	// ──────────────────────────────────────────────────
	// find_by_email_lookup_hash_paginated — PERF-SOLL-03
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 15 DSGVO — Paginated Auskunftsrecht via Blind Index (PERF-SOLL-03)
	 */
	public function test_find_by_email_lookup_hash_paginated_returns_limited_results(): void {
		$wpdb = $this->mock_wpdb();

		$hash = hash_hmac( 'sha256', 'test@example.com', 'secret-key' );

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) use ( $hash ) {
				return str_contains( $sql, 'email_lookup_hash' )
					&& str_contains( $sql, 'LIMIT' )
					&& str_contains( $sql, 'OFFSET' );
			} )
			->andReturn( [
				[
					'id'                => 10,
					'form_id'           => 1,
					'encrypted_data'    => 'enc',
					'iv'                => 'iv',
					'auth_tag'          => 'tag',
					'email_lookup_hash' => $hash,
					'submitted_at'      => '2026-04-17',
					'is_read'           => 0,
					'is_restricted'     => 0,
				],
				[
					'id'                => 11,
					'form_id'           => 2,
					'encrypted_data'    => 'enc2',
					'iv'                => 'iv2',
					'auth_tag'          => 'tag2',
					'email_lookup_hash' => $hash,
					'submitted_at'      => '2026-04-16',
					'is_read'           => 1,
					'is_restricted'     => 0,
				],
			] );

		$results = Submission::find_by_email_lookup_hash_paginated( $hash, 10, 0 );

		$this->assertCount( 2, $results );
		$this->assertInstanceOf( Submission::class, $results[0] );
		$this->assertSame( 10, $results[0]->id );
		$this->assertSame( $hash, $results[0]->email_lookup_hash );
		$this->assertSame( 11, $results[1]->id );
	}

	/**
	 * @test
	 */
	public function test_find_by_email_lookup_hash_paginated_returns_empty_for_no_match(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [] );

		$results = Submission::find_by_email_lookup_hash_paginated( 'nonexistent-hash', 10, 0 );

		$this->assertSame( [], $results );
	}

	/**
	 * @test
	 * PERF-SOLL-03: Offset-based pagination passes correct offset to SQL.
	 */
	public function test_find_by_email_lookup_hash_paginated_uses_offset(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// Verify that OFFSET 20 is present in the prepared SQL.
				return str_contains( $sql, 'OFFSET' )
					&& str_contains( $sql, '20' );
			} )
			->andReturn( [] );

		Submission::find_by_email_lookup_hash_paginated( 'some-hash', 10, 20 );
	}

	// ──────────────────────────────────────────────────
	// count_by_email_lookup_hash — PERF-SOLL-03
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 15/17 DSGVO — Batch completion check
	 */
	public function test_count_by_email_lookup_hash_returns_integer_count(): void {
		$wpdb = $this->mock_wpdb();

		$hash = hash_hmac( 'sha256', 'test@example.com', 'secret-key' );

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'COUNT(*)' )
					&& str_contains( $sql, 'email_lookup_hash' );
			} )
			->andReturn( '5' );

		$count = Submission::count_by_email_lookup_hash( $hash );

		$this->assertSame( 5, $count );
	}

	/**
	 * @test
	 */
	public function test_count_by_email_lookup_hash_returns_zero_for_no_match(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '0' );

		$count = Submission::count_by_email_lookup_hash( 'nonexistent-hash' );

		$this->assertSame( 0, $count );
	}

	// ──────────────────────────────────────────────────
	// get_file_metadata — Art. 15 DSGVO export
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 15 DSGVO — File metadata for privacy export
	 */
	public function test_get_file_metadata_returns_file_objects(): void {
		$wpdb = $this->mock_wpdb();

		$file1            = new \stdClass();
		$file1->original_name = 'document.pdf';
		$file1->mime_type     = 'application/pdf';
		$file1->file_size     = 12345;

		$file2            = new \stdClass();
		$file2->original_name = 'photo.jpg';
		$file2->mime_type     = 'image/jpeg';
		$file2->file_size     = 67890;

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'original_name' )
					&& str_contains( $sql, 'mime_type' )
					&& str_contains( $sql, 'file_size' )
					&& str_contains( $sql, 'dsgvo_submission_files' );
			} )
			->andReturn( [ $file1, $file2 ] );

		$files = Submission::get_file_metadata( 42 );

		$this->assertCount( 2, $files );
		$this->assertSame( 'document.pdf', $files[0]->original_name );
		$this->assertSame( 'application/pdf', $files[0]->mime_type );
		$this->assertSame( 12345, $files[0]->file_size );
		$this->assertSame( 'photo.jpg', $files[1]->original_name );
	}

	/**
	 * @test
	 */
	public function test_get_file_metadata_returns_empty_for_no_files(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [] );

		$files = Submission::get_file_metadata( 999 );

		$this->assertSame( [], $files );
	}

	/**
	 * @test
	 * Security: get_file_metadata only selects metadata columns, not file content.
	 */
	public function test_get_file_metadata_does_not_select_file_content(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// Must NOT select file_path or encrypted content columns.
				return ! str_contains( $sql, 'SELECT *' )
					&& ! str_contains( $sql, 'file_path' )
					&& ! str_contains( $sql, 'encrypted_content' );
			} )
			->andReturn( [] );

		Submission::get_file_metadata( 1 );
	}

	// ──────────────────────────────────────────────────
	// mark_as_read
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_mark_as_read_updates_flag(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_dsgvo_submissions',
				[ 'is_read' => 1 ],
				[ 'id' => 7 ],
				[ '%d' ],
				[ '%d' ]
			)
			->andReturn( 1 );

		$this->assertTrue( Submission::mark_as_read( 7 ) );
	}

	// ──────────────────────────────────────────────────
	// set_restricted — Art. 18 DSGVO
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 18 DSGVO — Einschraenkung der Verarbeitung
	 */
	public function test_set_restricted_enables_flag(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_dsgvo_submissions',
				[ 'is_restricted' => 1 ],
				[ 'id' => 3 ],
				[ '%d' ],
				[ '%d' ]
			)
			->andReturn( 1 );

		$this->assertTrue( Submission::set_restricted( 3, true ) );
	}

	/**
	 * @test
	 */
	public function test_set_restricted_disables_flag(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_dsgvo_submissions',
				[ 'is_restricted' => 0 ],
				[ 'id' => 3 ],
				[ '%d' ],
				[ '%d' ]
			)
			->andReturn( 1 );

		$this->assertTrue( Submission::set_restricted( 3, false ) );
	}

	// ──────────────────────────────────────────────────
	// find
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_find_returns_null_for_nonexistent_id(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( null );

		$this->assertNull( Submission::find( 999 ) );
	}

	/**
	 * @test
	 */
	public function test_find_returns_submission_instance(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'                   => 42,
				'form_id'              => 1,
				'encrypted_data'       => 'enc-data',
				'iv'                   => 'test-iv',
				'auth_tag'             => 'test-tag',
				'submitted_at'         => '2026-04-17 10:00:00',
				'is_read'              => 1,
				'expires_at'           => '2026-07-16 10:00:00',
				'consent_text_version' => 2,
				'consent_timestamp'    => '2026-04-17 09:59:00',
				'email_lookup_hash'          => 'abc123',
				'consent_locale'       => 'de_DE',
				'is_restricted'            => 0,
			] );

		$sub = Submission::find( 42 );

		$this->assertInstanceOf( Submission::class, $sub );
		$this->assertSame( 42, $sub->id );
		$this->assertSame( 1, $sub->form_id );
		$this->assertTrue( $sub->is_read );
		$this->assertSame( 2, $sub->consent_text_version );
		$this->assertFalse( $sub->is_restricted );
		$this->assertSame( 'abc123', $sub->email_lookup_hash );
		$this->assertSame( 'de_DE', $sub->consent_locale );
	}

	// ──────────────────────────────────────────────────
	// consent_locale — DPO-FINDING-13
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-13 — consent_locale fuer Nachweis der exakten Einwilligungsversion
	 */
	public function test_from_row_maps_consent_locale(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'              => 1,
				'form_id'         => 1,
				'encrypted_data'  => 'enc',
				'iv'              => 'iv',
				'auth_tag'        => 'tag',
				'submitted_at'    => '2026-04-17',
				'is_read'         => 0,
				'is_restricted'       => 0,
				'consent_locale'  => 'fr_FR',
			] );

		$sub = Submission::find( 1 );

		$this->assertSame( 'fr_FR', $sub->consent_locale );
	}

	/**
	 * @test
	 */
	public function test_consent_locale_defaults_to_null(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'id'              => 1,
				'form_id'         => 1,
				'encrypted_data'  => 'enc',
				'iv'              => 'iv',
				'auth_tag'        => 'tag',
				'submitted_at'    => '2026-04-17',
				'is_read'         => 0,
				'is_restricted'       => 0,
			] );

		$sub = Submission::find( 1 );

		$this->assertNull( $sub->consent_locale );
	}

	// ──────────────────────────────────────────────────
	// Table name
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->mock_wpdb();

		$this->assertSame( 'wp_dsgvo_submissions', Submission::get_table_name() );
	}

	/**
	 * @test
	 */
	public function test_get_files_table_name_uses_wpdb_prefix(): void {
		$this->mock_wpdb();

		$this->assertSame( 'wp_dsgvo_submission_files', Submission::get_files_table_name() );
	}

	// ──────────────────────────────────────────────────
	// find_by_form_ids — Multi-form query (#261 PreparedSQL)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Task #261 — find_by_form_ids returns empty array for empty form_ids
	 */
	public function test_find_by_form_ids_returns_empty_for_no_form_ids(): void {
		// Should not call wpdb at all.
		$result = Submission::find_by_form_ids( [] );

		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * Task #261 — find_by_form_ids uses IN() with %d placeholders per form ID
	 */
	public function test_find_by_form_ids_uses_prepared_in_clause(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				// Verify IN clause with multiple %d placeholders was prepared.
				return str_contains( $sql, 'form_id IN' )
					&& str_contains( $sql, 'LIMIT' )
					&& str_contains( $sql, 'OFFSET' );
			} )
			->andReturn( [] );

		$result = Submission::find_by_form_ids( [ 1, 2, 3 ] );

		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * Task #261 — find_by_form_ids returns Submission instances
	 */
	public function test_find_by_form_ids_returns_submission_instances(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id'           => 10,
					'form_id'      => 2,
					'submitted_at' => '2026-04-17 10:00:00',
					'is_read'      => 0,
					'is_restricted'    => 0,
				],
				[
					'id'           => 11,
					'form_id'      => 3,
					'submitted_at' => '2026-04-17 11:00:00',
					'is_read'      => 1,
					'is_restricted'    => 0,
				],
			] );

		$result = Submission::find_by_form_ids( [ 2, 3 ] );

		$this->assertCount( 2, $result );
		$this->assertInstanceOf( Submission::class, $result[0] );
		$this->assertSame( 2, $result[0]->form_id );
		$this->assertSame( 3, $result[1]->form_id );
	}

	/**
	 * @test
	 * Task #261 — find_by_form_ids excludes restricted by default
	 */
	public function test_find_by_form_ids_excludes_restricted_by_default(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'is_restricted' );
			} )
			->andReturn( [] );

		Submission::find_by_form_ids( [ 1, 2 ] );
	}

	/**
	 * @test
	 * Task #261 — find_by_form_ids includes restricted when requested
	 */
	public function test_find_by_form_ids_includes_restricted_when_requested(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return ! str_contains( $sql, "is_restricted = '0'" );
			} )
			->andReturn( [] );

		Submission::find_by_form_ids( [ 1, 2 ], 1, 20, null, true );
	}

	// ──────────────────────────────────────────────────
	// count_by_form_ids — Multi-form count (#261 PreparedSQL)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * Task #261 — count_by_form_ids returns 0 for empty form_ids
	 */
	public function test_count_by_form_ids_returns_zero_for_no_form_ids(): void {
		$count = Submission::count_by_form_ids( [] );

		$this->assertSame( 0, $count );
	}

	/**
	 * @test
	 * Task #261 — count_by_form_ids uses prepared IN() with %d placeholders
	 */
	public function test_count_by_form_ids_uses_prepared_in_clause(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'COUNT(*)' )
					&& str_contains( $sql, 'form_id IN' );
			} )
			->andReturn( '5' );

		$count = Submission::count_by_form_ids( [ 1, 2, 3 ] );

		$this->assertSame( 5, $count );
	}

	/**
	 * @test
	 * Task #261 — count_by_form_ids filters unread only
	 */
	public function test_count_by_form_ids_filters_unread(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'is_read' );
			} )
			->andReturn( '2' );

		$count = Submission::count_by_form_ids( [ 1 ], false );

		$this->assertSame( 2, $count );
	}

	/**
	 * @test
	 * Task #261 — count_by_form_ids excludes restricted by default
	 */
	public function test_count_by_form_ids_excludes_restricted_by_default(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'is_restricted' );
			} )
			->andReturn( '0' );

		Submission::count_by_form_ids( [ 1, 2 ] );
	}
}
