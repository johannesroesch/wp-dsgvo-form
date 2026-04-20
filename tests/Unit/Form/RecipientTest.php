<?php
/**
 * Unit tests for Recipient model.
 *
 * Covers DSGVO-relevant logic: duplicate assignment prevention,
 * form-based access control via user-form mapping, and validation.
 *
 * @package WpDsgvoForm\Tests\Unit\Form
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Form;

use WpDsgvoForm\Models\Recipient;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the Recipient model.
 */
class RecipientTest extends TestCase {

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

	// ──────────────────────────────────────────────────
	// Validation tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_throws_on_missing_form_id(): void {
		$this->mock_wpdb();

		$recipient          = new Recipient();
		$recipient->form_id = 0;
		$recipient->user_id = 1;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'form_id required' );

		$recipient->save();
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_missing_user_id(): void {
		$this->mock_wpdb();

		$recipient          = new Recipient();
		$recipient->form_id = 1;
		$recipient->user_id = 0;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'user_id required' );

		$recipient->save();
	}

	// ──────────────────────────────────────────────────
	// Duplicate prevention
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 5 DSGVO — Datenminimierung, keine doppelten Zuweisungen
	 */
	public function test_save_throws_on_duplicate_assignment(): void {
		$wpdb = $this->mock_wpdb();

		// exists() returns true.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '1' );

		$recipient          = new Recipient();
		$recipient->form_id = 1;
		$recipient->user_id = 5;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already assigned' );

		$recipient->save();
	}

	// ──────────────────────────────────────────────────
	// Save (insert) tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_inserts_new_recipient_and_returns_id(): void {
		$wpdb = $this->mock_wpdb();

		// exists() returns false.
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '0' );

		$wpdb->insert_id = 15;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( 1 );

		$recipient          = new Recipient();
		$recipient->form_id = 1;
		$recipient->user_id = 5;

		$id = $recipient->save();

		$this->assertSame( 15, $id );
		$this->assertSame( 15, $recipient->id );
	}

	/**
	 * @test
	 */
	public function test_save_throws_on_insert_failure(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '0' );

		$wpdb->insert_id  = 0;
		$wpdb->last_error = 'DB error';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( false );

		$recipient          = new Recipient();
		$recipient->form_id = 1;
		$recipient->user_id = 5;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to insert recipient' );

		$recipient->save();
	}

	// ──────────────────────────────────────────────────
	// Save (update) tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_save_updates_existing_recipient(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturn( 1 );

		$recipient          = new Recipient();
		$recipient->id      = 10;
		$recipient->form_id = 1;
		$recipient->user_id = 5;

		$id = $recipient->save();

		$this->assertSame( 10, $id );
	}

	// ──────────────────────────────────────────────────
	// exists() — Duplicate check helper
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_exists_returns_true_for_existing_assignment(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '1' );

		$this->assertTrue( Recipient::exists( 1, 5 ) );
	}

	/**
	 * @test
	 */
	public function test_exists_returns_false_for_nonexistent_assignment(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '0' );

		$this->assertFalse( Recipient::exists( 1, 99 ) );
	}

	// ──────────────────────────────────────────────────
	// get_form_ids_for_user — Reader access control
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 5 DSGVO — Reader sieht nur zugewiesene Formulare
	 */
	public function test_get_form_ids_for_user_returns_assigned_forms(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [ '1', '3', '7' ] );

		$form_ids = Recipient::get_form_ids_for_user( 5 );

		$this->assertSame( [ 1, 3, 7 ], $form_ids );
	}

	/**
	 * @test
	 */
	public function test_get_form_ids_for_user_returns_empty_for_unassigned_user(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_col' )
			->once()
			->andReturn( [] );

		$form_ids = Recipient::get_form_ids_for_user( 99 );

		$this->assertSame( [], $form_ids );
	}

	// ──────────────────────────────────────────────────
	// Delete tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_delete_removes_recipient_returns_true(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_dsgvo_form_recipients', [ 'id' => 5 ], [ '%d' ] )
			->andReturn( 1 );

		$this->assertTrue( Recipient::delete( 5 ) );
	}

	/**
	 * @test
	 */
	public function test_delete_by_form_and_user_removes_assignment(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'delete' )
			->once()
			->with(
				'wp_dsgvo_form_recipients',
				[ 'form_id' => 1, 'user_id' => 5 ],
				[ '%d', '%d' ]
			)
			->andReturn( 1 );

		$this->assertTrue( Recipient::delete_by_form_and_user( 1, 5 ) );
	}

	// ──────────────────────────────────────────────────
	// find_by_form_id
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_find_by_form_id_returns_recipient_instances(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id'                 => 1,
					'form_id'            => 3,
					'user_id'            => 10,
					'notify_email'       => 1,
					'role_justification' => 'Datenschutzbeauftragter',
					'created_at'         => '2026-04-17 10:00:00',
				],
			] );

		$results = Recipient::find_by_form_id( 3 );

		$this->assertCount( 1, $results );
		$this->assertInstanceOf( Recipient::class, $results[0] );
		$this->assertSame( 10, $results[0]->user_id );
		$this->assertTrue( $results[0]->notify_email );
		$this->assertSame( 'Datenschutzbeauftragter', $results[0]->role_justification );
	}

	// ──────────────────────────────────────────────────
	// find_notifiable_by_form_id
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_find_notifiable_by_form_id_filters_by_notify_email(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_results' )
			->once()
			->withArgs( function ( string $sql ) {
				return str_contains( $sql, 'notify_email' );
			} )
			->andReturn( [] );

		$results = Recipient::find_notifiable_by_form_id( 1 );

		$this->assertSame( [], $results );
	}

	// ──────────────────────────────────────────────────
	// find — Single recipient
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_find_returns_null_for_nonexistent_id(): void {
		$wpdb = $this->mock_wpdb();

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( null );

		$this->assertNull( Recipient::find( 999 ) );
	}

	// ──────────────────────────────────────────────────
	// Table name
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->mock_wpdb();

		$this->assertSame( 'wp_dsgvo_form_recipients', Recipient::get_table_name() );
	}
}
