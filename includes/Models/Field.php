<?php
declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Field model — CRUD for the dsgvo_fields table.
 *
 * Manages form field definitions including type configuration,
 * validation rules, and sort order.
 */
class Field {

	/**
	 * Allowed field types matching ARCHITECTURE.md §2.2.
	 */
	public const ALLOWED_TYPES = array(
		'text',
		'email',
		'tel',
		'textarea',
		'checkbox',
		'radio',
		'select',
		'date',
		'file',
		'static',
	);

	/**
	 * Allowed field width values for grid layout (FEP-01).
	 */
	public const ALLOWED_WIDTHS = array(
		'full',
		'half',
		'third',
	);

	public int $id             = 0;
	public int $form_id        = 0;
	public string $field_type  = 'text';
	public string $label       = '';
	public string $name        = '';
	public string $placeholder = '';
	public bool $is_required   = false;
	/**
	 * @var array<int, string>|null
	 */
	public ?array $options = null;
	/**
	 * @var array<string, mixed>|null
	 */
	public ?array $validation_rules = null;
	public string $static_content   = '';
	/**
	 * @var array<string, mixed>|null
	 */
	public ?array $file_config = null;
	public string $css_class   = '';
	public string $width       = 'full';
	public int $sort_order     = 0;
	public string $created_at  = '';

	/**
	 * Returns the full table name with WordPress prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsgvo_fields';
	}

	/**
	 * Finds a field by ID.
	 */
	public static function find( int $id ): ?self {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Returns all fields for a form, ordered by sort_order.
	 *
	 * @return self[]
	 */
	public static function find_by_form_id( int $form_id ): array {
		global $wpdb;
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE form_id = %d ORDER BY sort_order ASC, id ASC",
				$form_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Saves the field (insert or update).
	 *
	 * @return int The field ID.
	 * @throws \RuntimeException On validation failure.
	 */
	public function save(): int {
		$this->validate();

		global $wpdb;
		$table = self::get_table_name();
		$data  = $this->to_db_array();

		if ( 0 === $this->id ) {
			$wpdb->insert( $table, $data, self::get_formats( $data ) );

			if ( 0 === $wpdb->insert_id ) {
				throw new \RuntimeException( 'Failed to insert field: ' . esc_html( $wpdb->last_error ) );
			}

			$this->id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update(
				$table,
				$data,
				array( 'id' => $this->id ),
				self::get_formats( $data ),
				array( '%d' )
			);
		}

		// Invalidate parent form cache so field changes are reflected.
		if ( $this->form_id > 0 ) {
			Form::invalidate_cache( $this->form_id );
		}

		return $this->id;
	}

	/**
	 * Deletes a field by ID.
	 */
	public static function delete( int $id ): bool {
		$field = self::find( $id );

		global $wpdb;
		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( null !== $field && $field->form_id > 0 ) {
			Form::invalidate_cache( $field->form_id );
		}

		return false !== $result;
	}

	/**
	 * Deletes all fields belonging to a form.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function delete_by_form_id( int $form_id ): int {
		global $wpdb;
		$table = self::get_table_name();

		$count = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE form_id = %d", $form_id )
		);

		Form::invalidate_cache( $form_id );

		return (int) $count;
	}

	/**
	 * Reorders fields for a form by setting sort_order from the array index.
	 *
	 * @param int   $form_id   The form ID.
	 * @param int[] $field_ids Ordered array of field IDs.
	 * @return bool True on success.
	 */
	public static function reorder( int $form_id, array $field_ids ): bool {
		global $wpdb;
		$table = self::get_table_name();

		foreach ( $field_ids as $index => $field_id ) {
			$wpdb->update(
				$table,
				array( 'sort_order' => $index ),
				array(
					'id'      => (int) $field_id,
					'form_id' => $form_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		Form::invalidate_cache( $form_id );

		return true;
	}

	/**
	 * Returns decoded options (for radio/select/checkbox fields).
	 *
	 * @return array<int, string>
	 */
	public function get_options(): array {
		return $this->options ?? array();
	}

	/**
	 * Returns decoded validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public function get_validation_rules(): array {
		return $this->validation_rules ?? array();
	}

	/**
	 * Returns decoded file upload configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_file_config(): array {
		return $this->file_config ?? array();
	}

	/**
	 * Validates field data before save.
	 *
	 * @throws \RuntimeException On validation failure.
	 */
	private function validate(): void {
		if ( $this->form_id < 1 ) {
			throw new \RuntimeException( 'Field must belong to a form (form_id required).' );
		}

		if ( ! in_array( $this->field_type, self::ALLOWED_TYPES, true ) ) {
			throw new \RuntimeException(
				'Invalid field type "' . esc_html( $this->field_type ) . '". Allowed: '
				. implode( ', ', self::ALLOWED_TYPES ) . '.'
			);
		}

		if ( trim( $this->label ) === '' ) {
			throw new \RuntimeException( 'Field label must not be empty.' );
		}

		if ( trim( $this->name ) === '' ) {
			throw new \RuntimeException( 'Field name must not be empty.' );
		}

		if ( ! in_array( $this->width, self::ALLOWED_WIDTHS, true ) ) {
			throw new \RuntimeException(
				'Invalid field width "' . esc_html( $this->width ) . '". Allowed: '
				. implode( ', ', self::ALLOWED_WIDTHS ) . '.'
			);
		}
	}

	/**
	 * Creates a Field instance from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private static function from_row( array $row ): self {
		$field                   = new self();
		$field->id               = (int) ( $row['id'] ?? 0 );
		$field->form_id          = (int) ( $row['form_id'] ?? 0 );
		$field->field_type       = (string) ( $row['field_type'] ?? 'text' );
		$field->label            = (string) ( $row['label'] ?? '' );
		$field->name             = (string) ( $row['name'] ?? '' );
		$field->placeholder      = (string) ( $row['placeholder'] ?? '' );
		$field->is_required      = (bool) ( $row['is_required'] ?? false );
		$field->options          = self::decode_json( $row['options'] ?? null );
		$field->validation_rules = self::decode_json( $row['validation_rules'] ?? null );
		$field->static_content   = (string) ( $row['static_content'] ?? '' );
		$field->file_config      = self::decode_json( $row['file_config'] ?? null );
		$field->css_class        = (string) ( $row['css_class'] ?? '' );
		$field->width            = (string) ( $row['width'] ?? 'full' );
		$field->sort_order       = (int) ( $row['sort_order'] ?? 0 );
		$field->created_at       = (string) ( $row['created_at'] ?? '' );

		return $field;
	}

	/**
	 * Converts field properties to an associative array for DB operations.
	 *
	 * @return array<string, mixed>
	 */
	private function to_db_array(): array {
		return array(
			'form_id'          => $this->form_id,
			'field_type'       => $this->field_type,
			'label'            => $this->label,
			'name'             => $this->name,
			'placeholder'      => $this->placeholder,
			'is_required'      => (int) $this->is_required,
			'options'          => self::encode_json( $this->options ),
			'validation_rules' => self::encode_json( $this->validation_rules ),
			'static_content'   => $this->static_content,
			'file_config'      => self::encode_json( $this->file_config ),
			'css_class'        => $this->css_class,
			'width'            => $this->width,
			'sort_order'       => $this->sort_order,
		);
	}

	/**
	 * Safely decodes a JSON column value.
	 *
	 * @return array<mixed>|null
	 */
	private static function decode_json( ?string $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Encodes an array to JSON for DB storage, or null if empty.
	 *
	 * @param array<int|string, mixed>|null $value Value to encode.
	 */
	private static function encode_json( ?array $value ): ?string {
		if ( null === $value || array() === $value ) {
			return null;
		}

		return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns wpdb format specifiers matching the data array.
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return string[]
	 */
	private static function get_formats( array $data ): array {
		$formats = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
