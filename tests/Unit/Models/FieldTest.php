<?php
/**
 * Unit tests for Field model.
 *
 * @package WpDsgvoForm\Tests\Unit\Models
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Models;

use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for Field CRUD, validation, JSON encoding/decoding,
 * sort order, and form cache invalidation.
 */
class FieldTest extends TestCase {

	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']    = $this->wpdb;

		// esc_html is used in exception messages (Task #242: ExceptionNotEscaped fix).
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Helper: returns a database row representing a stored field.
	 */
	private function make_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'               => '1',
				'form_id'          => '10',
				'field_type'       => 'text',
				'label'            => 'Name',
				'name'             => 'name',
				'placeholder'      => 'Ihr Name',
				'is_required'      => '1',
				'options'          => null,
				'validation_rules' => null,
				'static_content'   => '',
				'file_config'      => null,
				'css_class'        => 'col-6',
				'sort_order'       => '0',
				'created_at'       => '2026-01-01 00:00:00',
			],
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// get_table_name
	// ------------------------------------------------------------------

	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_dsgvo_fields', Field::get_table_name() );
	}

	// ------------------------------------------------------------------
	// ALLOWED_TYPES constant
	// ------------------------------------------------------------------

	public function test_allowed_types_contains_all_expected_types(): void {
		$expected = [
			'text', 'email', 'tel', 'textarea', 'checkbox',
			'radio', 'select', 'date', 'file', 'static',
		];

		$this->assertSame( $expected, Field::ALLOWED_TYPES );
	}

	public function test_allowed_types_has_exactly_10_entries(): void {
		$this->assertCount( 10, Field::ALLOWED_TYPES );
	}

	// ------------------------------------------------------------------
	// find
	// ------------------------------------------------------------------

	public function test_find_returns_field_instance(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $this->make_row() );

		$field = Field::find( 1 );

		$this->assertInstanceOf( Field::class, $field );
		$this->assertSame( 1, $field->id );
		$this->assertSame( 10, $field->form_id );
		$this->assertSame( 'text', $field->field_type );
		$this->assertSame( 'Name', $field->label );
		$this->assertSame( 'name', $field->name );
		$this->assertSame( 'Ihr Name', $field->placeholder );
		$this->assertTrue( $field->is_required );
		$this->assertSame( 'col-6', $field->css_class );
		$this->assertSame( 0, $field->sort_order );
	}

	public function test_find_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( Field::find( 999 ) );
	}

	// ------------------------------------------------------------------
	// find_by_form_id
	// ------------------------------------------------------------------

	public function test_find_by_form_id_returns_ordered_fields(): void {
		$rows = [
			$this->make_row( [ 'id' => '1', 'sort_order' => '0', 'label' => 'Vorname' ] ),
			$this->make_row( [ 'id' => '2', 'sort_order' => '1', 'label' => 'E-Mail' ] ),
		];

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$fields = Field::find_by_form_id( 10 );

		$this->assertCount( 2, $fields );
		$this->assertSame( 'Vorname', $fields[0]->label );
		$this->assertSame( 'E-Mail', $fields[1]->label );
		$this->assertSame( 0, $fields[0]->sort_order );
		$this->assertSame( 1, $fields[1]->sort_order );
	}

	public function test_find_by_form_id_returns_empty_array_on_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( [], Field::find_by_form_id( 99 ) );
	}

	// ------------------------------------------------------------------
	// validate — form_id required
	// ------------------------------------------------------------------

	public function test_validate_rejects_zero_form_id(): void {
		$field             = new Field();
		$field->form_id    = 0;
		$field->field_type = 'text';
		$field->label      = 'Name';
		$field->name       = 'name';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Field must belong to a form (form_id required).' );

		$field->save();
	}

	// ------------------------------------------------------------------
	// validate — field_type must be in ALLOWED_TYPES
	// ------------------------------------------------------------------

	public function test_validate_rejects_invalid_field_type(): void {
		$field             = new Field();
		$field->form_id    = 1;
		$field->field_type = 'password';
		$field->label      = 'Passwort';
		$field->name       = 'password';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid field type "password"' );

		$field->save();
	}

	// ------------------------------------------------------------------
	// validate — label required
	// ------------------------------------------------------------------

	public function test_validate_rejects_empty_label(): void {
		$field             = new Field();
		$field->form_id    = 1;
		$field->field_type = 'text';
		$field->label      = '';
		$field->name       = 'field';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Field label must not be empty.' );

		$field->save();
	}

	// ------------------------------------------------------------------
	// validate — name required
	// ------------------------------------------------------------------

	public function test_validate_rejects_empty_name(): void {
		$field             = new Field();
		$field->form_id    = 1;
		$field->field_type = 'text';
		$field->label      = 'Label';
		$field->name       = '  ';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Field name must not be empty.' );

		$field->save();
	}

	// ------------------------------------------------------------------
	// save — insert
	// ------------------------------------------------------------------

	public function test_save_inserts_new_field_and_invalidates_form_cache(): void {
		$field             = new Field();
		$field->form_id    = 10;
		$field->field_type = 'email';
		$field->label      = 'E-Mail';
		$field->name       = 'email';

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 42;

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_10' );

		$id = $field->save();

		$this->assertSame( 42, $id );
		$this->assertSame( 42, $field->id );
	}

	public function test_save_insert_throws_on_db_failure(): void {
		$field             = new Field();
		$field->form_id    = 10;
		$field->field_type = 'text';
		$field->label      = 'Fail';
		$field->name       = 'fail';

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = 'insert failed';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to insert field' );

		$field->save();
	}

	// ------------------------------------------------------------------
	// save — update
	// ------------------------------------------------------------------

	public function test_save_updates_existing_field_and_invalidates_cache(): void {
		$field             = new Field();
		$field->id         = 5;
		$field->form_id    = 10;
		$field->field_type = 'textarea';
		$field->label      = 'Nachricht';
		$field->name       = 'message';

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_10' );

		$id = $field->save();

		$this->assertSame( 5, $id );
	}

	// ------------------------------------------------------------------
	// delete
	// ------------------------------------------------------------------

	public function test_delete_removes_field_and_invalidates_form_cache(): void {
		// Field::delete() calls find() first, then wpdb->delete().
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $this->make_row( [ 'id' => '3', 'form_id' => '10' ] ) );

		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_dsgvo_fields', [ 'id' => 3 ], [ '%d' ] )
			->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_10' );

		$this->assertTrue( Field::delete( 3 ) );
	}

	public function test_delete_returns_false_on_db_failure(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		$this->assertFalse( Field::delete( 999 ) );
	}

	// ------------------------------------------------------------------
	// delete_by_form_id
	// ------------------------------------------------------------------

	public function test_delete_by_form_id_removes_all_and_invalidates_cache(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_10' );

		$count = Field::delete_by_form_id( 10 );

		$this->assertSame( 3, $count );
	}

	// ------------------------------------------------------------------
	// reorder
	// ------------------------------------------------------------------

	public function test_reorder_sets_sort_order_from_array_index(): void {
		$field_ids = [ 30, 10, 20 ];

		$this->wpdb->shouldReceive( 'update' )
			->times( 3 )
			->andReturn( 1 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'dsgvo_form_5' );

		$result = Field::reorder( 5, $field_ids );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// JSON decode — options, validation_rules, file_config
	// ------------------------------------------------------------------

	public function test_from_row_decodes_json_options(): void {
		$row = $this->make_row( [
			'field_type' => 'select',
			'options'    => '["Option A","Option B","Option C"]',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$field = Field::find( 1 );

		$this->assertSame( [ 'Option A', 'Option B', 'Option C' ], $field->get_options() );
	}

	public function test_from_row_decodes_json_validation_rules(): void {
		$row = $this->make_row( [
			'validation_rules' => '{"min_length":3,"max_length":100}',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$field = Field::find( 1 );

		$this->assertSame(
			[ 'min_length' => 3, 'max_length' => 100 ],
			$field->get_validation_rules()
		);
	}

	public function test_from_row_decodes_json_file_config(): void {
		$row = $this->make_row( [
			'field_type'  => 'file',
			'file_config' => '{"max_size":5242880,"allowed_types":["pdf","jpg"]}',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$field = Field::find( 1 );

		$expected = [
			'max_size'      => 5242880,
			'allowed_types' => [ 'pdf', 'jpg' ],
		];
		$this->assertSame( $expected, $field->get_file_config() );
	}

	public function test_null_json_fields_return_empty_arrays(): void {
		$field = new Field();

		$this->assertSame( [], $field->get_options() );
		$this->assertSame( [], $field->get_validation_rules() );
		$this->assertSame( [], $field->get_file_config() );
	}

	public function test_from_row_handles_invalid_json_gracefully(): void {
		$row = $this->make_row( [
			'options'          => 'not valid json',
			'validation_rules' => '{broken',
			'file_config'      => '42',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$field = Field::find( 1 );

		// Invalid JSON → null → get_*() returns [].
		$this->assertSame( [], $field->get_options() );
		$this->assertSame( [], $field->get_validation_rules() );
		$this->assertSame( [], $field->get_file_config() );
	}

	// ------------------------------------------------------------------
	// save — JSON encode (options round-trip)
	// ------------------------------------------------------------------

	public function test_save_encodes_options_as_json(): void {
		$field             = new Field();
		$field->form_id    = 10;
		$field->field_type = 'select';
		$field->label      = 'Auswahl';
		$field->name       = 'choice';
		$field->options    = [ 'Ja', 'Nein', 'Vielleicht' ];

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;

		Functions\when( 'delete_transient' )->justReturn( true );

		$field->save();

		$this->assertSame(
			'["Ja","Nein","Vielleicht"]',
			$inserted_data['options']
		);
	}

	public function test_save_stores_null_for_empty_options(): void {
		$field             = new Field();
		$field->form_id    = 10;
		$field->field_type = 'text';
		$field->label      = 'Name';
		$field->name       = 'name';
		$field->options    = [];

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 1;

		Functions\when( 'delete_transient' )->justReturn( true );

		$field->save();

		$this->assertNull( $inserted_data['options'] );
	}

	// ------------------------------------------------------------------
	// from_row — property defaults for missing keys
	// ------------------------------------------------------------------

	public function test_from_row_uses_defaults_for_missing_keys(): void {
		$minimal_row = [
			'id'      => '5',
			'form_id' => '1',
		];

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $minimal_row );

		$field = Field::find( 5 );

		$this->assertSame( 5, $field->id );
		$this->assertSame( 1, $field->form_id );
		$this->assertSame( 'text', $field->field_type );
		$this->assertSame( '', $field->label );
		$this->assertSame( '', $field->name );
		$this->assertSame( '', $field->placeholder );
		$this->assertFalse( $field->is_required );
		$this->assertNull( $field->options );
		$this->assertNull( $field->validation_rules );
		$this->assertSame( '', $field->static_content );
		$this->assertNull( $field->file_config );
		$this->assertSame( '', $field->css_class );
		$this->assertSame( 0, $field->sort_order );
	}
}
