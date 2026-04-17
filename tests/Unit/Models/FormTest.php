<?php
/**
 * Unit tests for Form model.
 *
 * @package WpDsgvoForm\Tests\Unit\Models
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Models;

use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for Form CRUD, validation, caching, and consent versioning.
 *
 * Covers: find (cache hit/miss), find_by_slug, find_all, save (insert/update),
 * delete, validate, consent version auto-increment, DEK generation requirement,
 * slug uniqueness.
 */
class FormTest extends TestCase {

	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Helper: returns a database row array representing a stored form.
	 */
	private function make_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => '1',
				'title'           => 'Kontaktformular',
				'slug'            => 'kontaktformular',
				'description'     => 'Beschreibung',
				'success_message' => 'Danke',
				'email_subject'   => 'Neue Anfrage',
				'email_template'  => '{{name}}',
				'is_active'       => '1',
				'retention_days'  => '90',
				'encrypted_dek'   => 'enc_dek_data',
				'dek_iv'          => 'iv_data',
				'legal_basis'     => 'consent',
				'purpose'         => 'Kontaktaufnahme',
				'consent_text'    => 'Ich stimme zu.',
				'consent_version' => '1',
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			],
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// get_table_name
	// ------------------------------------------------------------------

	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_dsgvo_forms', Form::get_table_name() );
	}

	// ------------------------------------------------------------------
	// find — cache hit
	// ------------------------------------------------------------------

	public function test_find_returns_cached_form_instance(): void {
		$cached_form     = new Form();
		$cached_form->id = 42;

		Functions\expect( 'get_transient' )
			->once()
			->with( 'dsgvo_form_42' )
			->andReturn( $cached_form );

		$result = Form::find( 42 );

		$this->assertSame( $cached_form, $result );
	}

	// ------------------------------------------------------------------
	// find — cache miss, DB hit
	// ------------------------------------------------------------------

	public function test_find_queries_db_on_cache_miss_and_caches_result(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'dsgvo_form_1' )
			->andReturn( false );

		$row = $this->make_row();

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$cached_form = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( string $key, $value, int $ttl ) use ( &$cached_form ): bool {
					$this->assertSame( 'dsgvo_form_1', $key );
					$this->assertSame( 3600, $ttl );
					$cached_form = $value;
					return true;
				}
			);

		$result = Form::find( 1 );

		$this->assertInstanceOf( Form::class, $result );
		$this->assertSame( 1, $result->id );
		$this->assertSame( 'Kontaktformular', $result->title );
		$this->assertSame( 'kontaktformular', $result->slug );
		$this->assertSame( 90, $result->retention_days );
		$this->assertSame( 'consent', $result->legal_basis );
		$this->assertSame( $result, $cached_form );
	}

	// ------------------------------------------------------------------
	// find — cache miss, DB miss
	// ------------------------------------------------------------------

	public function test_find_returns_null_when_not_in_db(): void {
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( false );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( Form::find( 999 ) );
	}

	// ------------------------------------------------------------------
	// find_by_slug
	// ------------------------------------------------------------------

	public function test_find_by_slug_returns_form(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $this->make_row( [ 'slug' => 'kontakt' ] ) );

		$result = Form::find_by_slug( 'kontakt' );

		$this->assertInstanceOf( Form::class, $result );
		$this->assertSame( 'kontakt', $result->slug );
	}

	public function test_find_by_slug_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( Form::find_by_slug( 'nonexistent' ) );
	}

	// ------------------------------------------------------------------
	// find_all
	// ------------------------------------------------------------------

	public function test_find_all_returns_all_forms(): void {
		$rows = [
			$this->make_row( [ 'id' => '1', 'title' => 'Form A' ] ),
			$this->make_row( [ 'id' => '2', 'title' => 'Form B' ] ),
		];

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$forms = Form::find_all();

		$this->assertCount( 2, $forms );
		$this->assertSame( 'Form A', $forms[0]->title );
		$this->assertSame( 'Form B', $forms[1]->title );
	}

	public function test_find_all_active_only_filters_by_is_active(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		$forms = Form::find_all( true );

		$this->assertSame( [], $forms );
	}

	public function test_find_all_returns_empty_array_on_null_results(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$forms = Form::find_all();

		$this->assertSame( [], $forms );
	}

	// ------------------------------------------------------------------
	// validate — title required
	// ------------------------------------------------------------------

	public function test_validate_rejects_empty_title(): void {
		$form        = new Form();
		$form->title = '';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Form title must not be empty.' );

		$form->save();
	}

	public function test_validate_rejects_whitespace_only_title(): void {
		$form        = new Form();
		$form->title = '   ';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Form title must not be empty.' );

		$form->save();
	}

	// ------------------------------------------------------------------
	// validate — retention_days (DPO-FINDING-01)
	// ------------------------------------------------------------------

	public function test_validate_rejects_retention_days_zero(): void {
		$form                 = new Form();
		$form->title          = 'Test';
		$form->retention_days = 0;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Retention days must be between 1 and 3650' );

		$form->save();
	}

	public function test_validate_rejects_retention_days_over_3650(): void {
		$form                 = new Form();
		$form->title          = 'Test';
		$form->retention_days = 3651;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Retention days must be between 1 and 3650' );

		$form->save();
	}

	public function test_validate_accepts_retention_days_at_boundaries(): void {
		$form                 = new Form();
		$form->title          = 'Min';
		$form->retention_days = 1;

		Functions\when( 'sanitize_title' )->returnArg();
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		// Should pass validation but fail at KeyManager check.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'KeyManager is required' );

		$form->save();
	}

	public function test_validate_accepts_retention_days_max(): void {
		$form                 = new Form();
		$form->title          = 'Max';
		$form->retention_days = 3650;

		Functions\when( 'sanitize_title' )->returnArg();
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'KeyManager is required' );

		$form->save();
	}

	// ------------------------------------------------------------------
	// validate — legal_basis (SEC-DSGVO-14)
	// ------------------------------------------------------------------

	public function test_validate_rejects_invalid_legal_basis(): void {
		$form              = new Form();
		$form->title       = 'Test';
		$form->legal_basis = 'legitimate_interest';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Legal basis must be "consent" or "contract"' );

		$form->save();
	}

	public function test_validate_accepts_contract_legal_basis(): void {
		$form              = new Form();
		$form->title       = 'Vertrag';
		$form->legal_basis = 'contract';

		Functions\when( 'sanitize_title' )->returnArg();
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'KeyManager is required' );

		$form->save();
	}

	// ------------------------------------------------------------------
	// save — insert requires KeyManager
	// ------------------------------------------------------------------

	public function test_insert_throws_without_key_manager(): void {
		$form        = new Form();
		$form->title = 'Neues Formular';

		Functions\when( 'sanitize_title' )->returnArg();

		// ensure_unique_slug DB check.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'KeyManager is required when creating a new form' );

		$form->save();
	}

	// ------------------------------------------------------------------
	// save — insert with KeyManager
	// ------------------------------------------------------------------

	public function test_insert_generates_dek_and_stores_form(): void {
		$form        = new Form();
		$form->title = 'Neues Formular';

		Functions\when( 'sanitize_title' )->returnArg();

		// ensure_unique_slug — slug is unique.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$key_manager = Mockery::mock( KeyManager::class );
		$key_manager->shouldReceive( 'generate_dek' )
			->once()
			->andReturn( 'raw_dek_bytes' );
		$key_manager->shouldReceive( 'encrypt_dek' )
			->once()
			->with( 'raw_dek_bytes' )
			->andReturn( [
				'encrypted_dek' => 'encrypted_data',
				'dek_iv'        => 'iv_value',
			] );

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 7;

		$id = $form->save( $key_manager );

		$this->assertSame( 7, $id );
		$this->assertSame( 7, $form->id );
		$this->assertSame( 'encrypted_data', $form->encrypted_dek );
		$this->assertSame( 'iv_value', $form->dek_iv );
		$this->assertSame( 1, $form->consent_version );
		$this->assertSame( 'encrypted_data', $inserted_data['encrypted_dek'] );
		$this->assertSame( 'iv_value', $inserted_data['dek_iv'] );
	}

	// ------------------------------------------------------------------
	// save — insert failure (insert_id = 0)
	// ------------------------------------------------------------------

	public function test_insert_throws_on_db_failure(): void {
		$form        = new Form();
		$form->title = 'Fail';

		Functions\when( 'sanitize_title' )->returnArg();

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$key_manager = Mockery::mock( KeyManager::class );
		$key_manager->shouldReceive( 'generate_dek' )->andReturn( 'dek' );
		$key_manager->shouldReceive( 'encrypt_dek' )->andReturn( [
			'encrypted_dek' => 'x',
			'dek_iv'        => 'y',
		] );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = 'Duplicate entry';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to insert form' );

		$form->save( $key_manager );
	}

	// ------------------------------------------------------------------
	// save — slug generation via sanitize_title
	// ------------------------------------------------------------------

	public function test_insert_generates_slug_from_title(): void {
		$form        = new Form();
		$form->title = 'Mein Formular';

		Functions\expect( 'sanitize_title' )
			->once()
			->with( 'Mein Formular' )
			->andReturn( 'mein-formular' );

		// ensure_unique_slug — unique.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$key_manager = Mockery::mock( KeyManager::class );
		$key_manager->shouldReceive( 'generate_dek' )->andReturn( 'dek' );
		$key_manager->shouldReceive( 'encrypt_dek' )->andReturn( [
			'encrypted_dek' => 'x',
			'dek_iv'        => 'y',
		] );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 1;

		$form->save( $key_manager );

		$this->assertSame( 'mein-formular', $form->slug );
	}

	// ------------------------------------------------------------------
	// save — slug uniqueness with counter suffix
	// ------------------------------------------------------------------

	public function test_insert_appends_suffix_for_duplicate_slug(): void {
		$form        = new Form();
		$form->title = 'Kontakt';

		Functions\when( 'sanitize_title' )->justReturn( 'kontakt' );

		// First call: slug 'kontakt' is taken (returns existing id).
		// Second call: slug 'kontakt-2' is free (returns null).
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturn( '5', null );

		$key_manager = Mockery::mock( KeyManager::class );
		$key_manager->shouldReceive( 'generate_dek' )->andReturn( 'dek' );
		$key_manager->shouldReceive( 'encrypt_dek' )->andReturn( [
			'encrypted_dek' => 'x',
			'dek_iv'        => 'y',
		] );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 3;

		$form->save( $key_manager );

		$this->assertSame( 'kontakt-2', $form->slug );
	}

	// ------------------------------------------------------------------
	// save — update (consent text unchanged)
	// ------------------------------------------------------------------

	public function test_update_keeps_consent_version_when_text_unchanged(): void {
		$form                  = new Form();
		$form->id              = 5;
		$form->title           = 'Updated';
		$form->slug            = 'updated';
		$form->consent_text    = 'Original consent.';
		$form->consent_version = 3;

		// Direct DB read for consent comparison.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'consent_text'    => 'Original consent.',
				'consent_version' => '3',
			] );

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_5' );

		$id = $form->save();

		$this->assertSame( 5, $id );
		$this->assertSame( 3, $form->consent_version );
	}

	// ------------------------------------------------------------------
	// save — update with consent version auto-increment (LEGAL-TEMPLATE-06)
	// ------------------------------------------------------------------

	public function test_update_increments_consent_version_on_text_change(): void {
		$form               = new Form();
		$form->id           = 5;
		$form->title        = 'Updated';
		$form->slug         = 'updated';
		$form->consent_text = 'Neuer Einwilligungstext.';

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( [
				'consent_text'    => 'Alter Einwilligungstext.',
				'consent_version' => '2',
			] );

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		Functions\when( 'delete_transient' )->justReturn( true );

		$form->save();

		$this->assertSame( 3, $form->consent_version );
	}

	// ------------------------------------------------------------------
	// save — update with no existing row (new consent — edge case)
	// ------------------------------------------------------------------

	public function test_update_handles_null_existing_row(): void {
		$form               = new Form();
		$form->id           = 99;
		$form->title        = 'Ghost';
		$form->slug         = 'ghost';
		$form->consent_text = 'Text';

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		Functions\when( 'delete_transient' )->justReturn( true );

		$form->save();

		// Consent version should not increment when existing row is null.
		$this->assertSame( 1, $form->consent_version );
	}

	// ------------------------------------------------------------------
	// delete
	// ------------------------------------------------------------------

	public function test_delete_removes_form_and_invalidates_cache(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_dsgvo_forms', [ 'id' => 10 ], [ '%d' ] )
			->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_10' );

		$this->assertTrue( Form::delete( 10 ) );
	}

	public function test_delete_returns_false_on_db_failure(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->andReturn( false );

		Functions\when( 'delete_transient' )->justReturn( true );

		$this->assertFalse( Form::delete( 999 ) );
	}

	// ------------------------------------------------------------------
	// invalidate_cache
	// ------------------------------------------------------------------

	public function test_invalidate_cache_deletes_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_42' );

		Form::invalidate_cache( 42 );
	}

	// ------------------------------------------------------------------
	// from_row — property mapping
	// ------------------------------------------------------------------

	public function test_find_maps_all_properties_from_db_row(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$row = $this->make_row( [
			'is_active'       => '0',
			'retention_days'  => '365',
			'consent_version' => '5',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		Functions\when( 'set_transient' )->justReturn( true );

		$form = Form::find( 1 );

		$this->assertFalse( $form->is_active );
		$this->assertSame( 365, $form->retention_days );
		$this->assertSame( 5, $form->consent_version );
		$this->assertSame( 'Beschreibung', $form->description );
		$this->assertSame( 'Danke', $form->success_message );
		$this->assertSame( 'Neue Anfrage', $form->email_subject );
		$this->assertSame( '{{name}}', $form->email_template );
		$this->assertSame( 'enc_dek_data', $form->encrypted_dek );
		$this->assertSame( 'iv_data', $form->dek_iv );
		$this->assertSame( 'Kontaktaufnahme', $form->purpose );
		$this->assertSame( 'Ich stimme zu.', $form->consent_text );
		$this->assertSame( '2026-01-01 00:00:00', $form->created_at );
	}
}
