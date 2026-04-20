<?php
/**
 * Form edit/builder page.
 *
 * PHP-based admin page for creating and editing DSGVO forms including
 * their field definitions.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Admin;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Form;

/**
 * Displays the form builder admin page.
 */
class FormEditPage {

	/**
	 * Error message from a failed save attempt (set by maybe_save_and_redirect).
	 *
	 * @var string|null
	 */
	private ?string $save_error = null;

	/**
	 * Form ID associated with a save error.
	 *
	 * @var int
	 */
	private int $save_error_form_id = 0;

	/**
	 * Human-readable labels for field types (MUSS-UX-05).
	 */
	private const FIELD_TYPE_LABELS = [
		'text'     => 'Text',
		'email'    => 'E-Mail',
		'tel'      => 'Telefon',
		'textarea' => 'Textbereich',
		'select'   => 'Auswahl (Dropdown)',
		'radio'    => 'Auswahl (Radio)',
		'checkbox' => 'Checkbox',
		'date'     => 'Datum',
		'file'     => 'Datei-Upload',
		'static'   => 'Statischer Inhalt',
	];

	/**
	 * Handle POST save before output to allow redirects (PRG pattern).
	 *
	 * Called on the load-{page} hook — before WordPress sends any output.
	 * On success: redirects and exits (render() is never reached).
	 * On failure: stores error for render() to display.
	 */
	public function maybe_save_and_redirect(): void {
		if ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST' ) {
			return;
		}

		check_admin_referer( 'dsgvo_form_save' );

		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-dsgvo-form' ) );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		try {
			$form = $this->build_form_from_post( $form_id );

			$key_manager = $form->id === 0 ? new KeyManager() : null;
			$saved_id    = $form->save( $key_manager );

			$this->save_fields( $saved_id );

			$redirect = add_query_arg(
				[
					'page'    => AdminMenu::MENU_SLUG,
					'action'  => 'edit',
					'form_id' => $saved_id,
					'saved'   => '1',
				],
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;

		} catch ( \RuntimeException $e ) {
			$this->save_error         = esc_html( $e->getMessage() );
			$this->save_error_form_id = $form_id;
		}
	}

	/**
	 * Render the form builder page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'dsgvo_form_manage' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-dsgvo-form' ) );
		}

		// Display save error stored by maybe_save_and_redirect().
		if ( $this->save_error !== null ) {
			$this->render_save_error( $this->save_error, $this->save_error_form_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter, no state change
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$form    = $form_id > 0 ? Form::find( $form_id ) : null;
		$fields  = $form_id > 0 ? Field::find_by_form_id( $form_id ) : [];
		$title   = $form !== null
			? __( 'Formular bearbeiten', 'wp-dsgvo-form' )
			: __( 'Neues Formular', 'wp-dsgvo-form' );

		$this->render_page( $title, $form ?? new Form(), $fields );
	}

	/**
	 * Builds and sanitizes a Form object from POST data.
	 */
	private function build_form_from_post( int $form_id ): Form {
		$form = $form_id > 0 ? Form::find( $form_id ) : new Form();

		if ( $form === null ) {
			$form = new Form();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in maybe_save_and_redirect()
		$form->title           = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$form->description     = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$form->success_message = sanitize_textarea_field( wp_unslash( $_POST['success_message'] ?? '' ) );
		$form->email_subject   = sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? '' ) );
		$form->email_template  = sanitize_textarea_field( wp_unslash( $_POST['email_template'] ?? '' ) );
		$legal_basis_raw       = sanitize_key( wp_unslash( $_POST['legal_basis'] ?? '' ) );
		$form->legal_basis     = in_array( $legal_basis_raw, [ 'consent', 'contract' ], true )
			? $legal_basis_raw
			: 'consent';
		$form->purpose         = sanitize_textarea_field( wp_unslash( $_POST['purpose'] ?? '' ) );
		$form->is_active       = isset( $_POST['is_active'] );
		$form->captcha_enabled = isset( $_POST['captcha_enabled'] );
		$form->retention_days  = min( max( (int) wp_unslash( $_POST['retention_days'] ?? 90 ), 1 ), 3650 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $form;
	}

	/**
	 * Renders the page with an error message after a failed save.
	 */
	private function render_save_error( string $message, int $form_id ): void {
		$form   = $form_id > 0 ? Form::find( $form_id ) : new Form();
		$fields = $form_id > 0 ? Field::find_by_form_id( $form_id ) : [];
		$title  = $form_id > 0
			? __( 'Formular bearbeiten', 'wp-dsgvo-form' )
			: __( 'Neues Formular', 'wp-dsgvo-form' );

		add_settings_error(
			'dsgvo_form_save',
			'save_error',
			$message,
			'error'
		);
		$this->render_page( $title, $form instanceof Form ? $form : new Form(), $fields );
	}

	/**
	 * Saves all submitted fields for a form.
	 */
	private function save_fields( int $form_id ): void {
		$post_data = $this->extract_field_post_data();
		$keep_ids  = [];
		$count     = count( $post_data['types'] );

		for ( $i = 0; $i < $count; $i++ ) {
			$field = $this->build_field_from_post( $form_id, $i, $post_data );
			if ( $field === null ) {
				continue;
			}

			$field->save();
			$keep_ids[] = $field->id;
		}

		$this->delete_removed_fields( $form_id, $keep_ids );
	}

	/**
	 * Extracts and sanitizes all field data from POST.
	 *
	 * @return array{ids: int[], types: string[], labels: string[], names: string[], placeholders: string[], required: string[], css_classes: string[], options: array, static: string[], file_config: array}
	 */
	private function extract_field_post_data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in maybe_save_and_redirect()
		// array_values() ensures contiguous keys after DOM reorder (Fix: Array-Key-Reorder).
		return [
			'ids'          => array_values( array_map( 'absint', (array) wp_unslash( $_POST['field_id'] ?? [] ) ) ),
			'types'        => array_values( array_map( 'sanitize_key', (array) wp_unslash( $_POST['field_type'] ?? [] ) ) ),
			'labels'       => array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['field_label'] ?? [] ) ) ),
			'names'        => array_values( array_map( 'sanitize_key', (array) wp_unslash( $_POST['field_name'] ?? [] ) ) ),
			'placeholders' => array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['field_placeholder'] ?? [] ) ) ),
			'required'     => array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['field_required'] ?? [] ) ) ),
			'css_classes'  => array_values( array_map( 'sanitize_html_class', (array) wp_unslash( $_POST['field_css_class'] ?? [] ) ) ),
			'options'      => array_values( (array) wp_unslash( $_POST['field_options'] ?? [] ) ),
			'static'       => array_values( array_map( 'wp_kses_post', (array) wp_unslash( $_POST['field_static_content'] ?? [] ) ) ),
			'file_config'  => [
				'allowed_types' => array_values( (array) wp_unslash( $_POST['field_file_types'] ?? [] ) ),
				'max_size'      => array_values( (array) wp_unslash( $_POST['field_file_max_size'] ?? [] ) ),
			],
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Builds a single Field object from POST data at the given index.
	 *
	 * @return Field|null The field, or null if validation fails.
	 */
	private function build_field_from_post( int $form_id, int $i, array $post_data ): ?Field {
		$field_type = $post_data['types'][ $i ] ?? '';
		if ( ! in_array( $field_type, Field::ALLOWED_TYPES, true ) ) {
			return null;
		}

		$label = $post_data['labels'][ $i ] ?? '';
		if ( trim( $label ) === '' ) {
			return null;
		}

		$field_db_id = $post_data['ids'][ $i ] ?? 0;
		$field       = $field_db_id > 0 ? ( Field::find( $field_db_id ) ?? new Field() ) : new Field();
		$field->form_id     = $form_id;
		$field->field_type  = $field_type;
		$field->label       = $label;
		$field->name        = $post_data['names'][ $i ] ?? sanitize_key( $label );
		if ( $field->name === '' ) {
			$field->name = 'field_' . ( $i + 1 );
		}
		$field->placeholder    = $post_data['placeholders'][ $i ] ?? '';
		$field->is_required    = in_array( (string) $i, $post_data['required'], true );
		$field->css_class      = $post_data['css_classes'][ $i ] ?? '';
		$field->sort_order     = $i;
		$field->static_content = $post_data['static'][ $i ] ?? '';

		$field->options = $this->parse_field_options( $post_data['options'][ $i ] ?? '' );

		if ( $field_type === 'file' ) {
			$field->file_config = $this->parse_file_config( $post_data, $i );
		}

		return $field;
	}

	/**
	 * Parses newline-separated options string into a sanitized array.
	 *
	 * @param mixed $raw_options Raw options string from POST data.
	 * @return array|null Parsed options or null if empty.
	 */
	private function parse_field_options( $raw_options ): ?array {
		if ( ! is_string( $raw_options ) || trim( $raw_options ) === '' ) {
			return null;
		}

		$parsed = array_values( array_filter( array_map(
			fn( $opt ) => sanitize_text_field( trim( $opt ) ),
			explode( "\n", $raw_options )
		) ) );

		return $parsed ?: null;
	}

	/**
	 * Parses file upload configuration from POST data for a given field index.
	 *
	 * @param array $post_data All extracted POST data.
	 * @param int   $index     Field index.
	 * @return array{allowed_types: string[], max_size_mb: int} File configuration.
	 */
	private function parse_file_config( array $post_data, int $index ): array {
		$raw_types = sanitize_text_field( wp_unslash( $post_data['file_config']['allowed_types'][ $index ] ?? '' ) );
		$max_size  = absint( $post_data['file_config']['max_size'][ $index ] ?? 5 );
		$max_size  = min( max( $max_size, 1 ), 50 );

		$allowed = [];
		if ( trim( $raw_types ) !== '' ) {
			$allowed = array_values( array_filter( array_map(
				fn( $t ) => sanitize_key( trim( $t ) ),
				explode( ',', $raw_types )
			) ) );
		}

		return [
			'allowed_types' => $allowed,
			'max_size_mb'   => $max_size,
		];
	}

	/**
	 * Deletes fields that were removed from the form.
	 */
	private function delete_removed_fields( int $form_id, array $keep_ids ): void {
		$existing = Field::find_by_form_id( $form_id );
		foreach ( $existing as $existing_field ) {
			if ( ! in_array( $existing_field->id, $keep_ids, true ) ) {
				Field::delete( $existing_field->id );
			}
		}
	}

	/**
	 * Renders the form builder HTML page.
	 *
	 * @param string  $title  Page title.
	 * @param Form    $form   Form object (new or existing).
	 * @param Field[] $fields Existing fields.
	 */
	private function render_page( string $title, Form $form, array $fields ): void {
		$back_url = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change
		$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];

		$this->render_page_header( $title, $back_url, $saved );
		$this->render_form_settings( $form );
		$this->render_email_settings( $form );
		$this->render_fields_section( $fields );
		$this->render_page_footer( $fields );
	}

	/**
	 * Renders page header with title, back link, notices.
	 */
	private function render_page_header( string $title, string $back_url, bool $saved ): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Zurueck zur Uebersicht', 'wp-dsgvo-form' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Formular gespeichert.', 'wp-dsgvo-form' ); ?></p>
				</div>
			<?php endif; ?>

			<?php settings_errors( 'dsgvo_form_save' ); ?>
		<?php
	}

	/**
	 * Renders the general form settings section.
	 */
	private function render_form_settings( Form $form ): void {
		$this->render_form_settings_general( $form );
		$this->render_form_settings_toggles( $form );
	}

	/**
	 * Renders the general form settings: title, description, legal basis, purpose, retention.
	 */
	private function render_form_settings_general( Form $form ): void {
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'dsgvo_form_save' ); ?>
				<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form->id ); ?>">

				<h2><?php esc_html_e( 'Allgemeine Einstellungen', 'wp-dsgvo-form' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dsgvo-title"><?php esc_html_e( 'Titel', 'wp-dsgvo-form' ); ?> <span style="color:red">*</span></label></th>
						<td><input type="text" id="dsgvo-title" name="title" class="regular-text"
							value="<?php echo esc_attr( $form->title ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="dsgvo-description"><?php esc_html_e( 'Beschreibung', 'wp-dsgvo-form' ); ?></label></th>
						<td><textarea id="dsgvo-description" name="description" rows="3" class="large-text"><?php
							echo esc_textarea( $form->description );
						?></textarea></td>
					</tr>
					<tr>
						<th><label for="dsgvo-legal-basis"><?php esc_html_e( 'Rechtsgrundlage', 'wp-dsgvo-form' ); ?></label></th>
						<td>
							<select id="dsgvo-legal-basis" name="legal_basis">
								<option value="consent" <?php selected( $form->legal_basis, 'consent' ); ?>>
									<?php esc_html_e( 'Einwilligung (Art. 6 Abs. 1 lit. a DSGVO)', 'wp-dsgvo-form' ); ?>
								</option>
								<option value="contract" <?php selected( $form->legal_basis, 'contract' ); ?>>
									<?php esc_html_e( 'Vertragserfuellung (Art. 6 Abs. 1 lit. b DSGVO)', 'wp-dsgvo-form' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="dsgvo-purpose"><?php esc_html_e( 'Verarbeitungszweck', 'wp-dsgvo-form' ); ?></label></th>
						<td><textarea id="dsgvo-purpose" name="purpose" rows="2" class="large-text"><?php
							echo esc_textarea( $form->purpose );
						?></textarea></td>
					</tr>
					<tr>
						<th><label for="dsgvo-retention"><?php esc_html_e( 'Aufbewahrungsdauer (Tage)', 'wp-dsgvo-form' ); ?></label></th>
						<td>
							<input type="number" id="dsgvo-retention" name="retention_days" min="1" max="3650"
								value="<?php echo esc_attr( (string) $form->retention_days ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '1–3650 Tage (DPO-FINDING-01)', 'wp-dsgvo-form' ); ?></p>
						</td>
					</tr>
				</table>
		<?php
	}

	/**
	 * Renders the form settings toggles: active status and CAPTCHA.
	 */
	private function render_form_settings_toggles( Form $form ): void {
		?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Status', 'wp-dsgvo-form' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="is_active" <?php checked( $form->is_active ); ?>>
								<?php esc_html_e( 'Formular aktiv', 'wp-dsgvo-form' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CAPTCHA', 'wp-dsgvo-form' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="captcha_enabled" <?php checked( $form->captcha_enabled ); ?>>
								<?php esc_html_e( 'CAPTCHA aktivieren', 'wp-dsgvo-form' ); ?>
							</label>
						</td>
					</tr>
				</table>
		<?php
	}

	/**
	 * Renders the email notification settings section.
	 */
	private function render_email_settings( Form $form ): void {
		?>
				<h2><?php esc_html_e( 'E-Mail-Benachrichtigung', 'wp-dsgvo-form' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dsgvo-email-subject"><?php esc_html_e( 'Betreff', 'wp-dsgvo-form' ); ?></label></th>
						<td><input type="text" id="dsgvo-email-subject" name="email_subject" class="regular-text"
							value="<?php echo esc_attr( $form->email_subject ); ?>"></td>
					</tr>
					<tr>
						<th><label for="dsgvo-email-template"><?php esc_html_e( 'E-Mail-Vorlage', 'wp-dsgvo-form' ); ?></label></th>
						<td>
							<textarea id="dsgvo-email-template" name="email_template" rows="5" class="large-text"><?php
								echo esc_textarea( $form->email_template );
							?></textarea>
							<p class="description"><?php esc_html_e( 'Verfuegbare Platzhalter: {field_name}', 'wp-dsgvo-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="dsgvo-success-msg"><?php esc_html_e( 'Erfolgsmeldung', 'wp-dsgvo-form' ); ?></label></th>
						<td><textarea id="dsgvo-success-msg" name="success_message" rows="2" class="large-text"><?php
							echo esc_textarea( $form->success_message );
						?></textarea></td>
					</tr>
				</table>
		<?php
	}

	/**
	 * Renders the fields section with existing field rows.
	 */
	private function render_fields_section( array $fields ): void {
		?>
				<h2><?php esc_html_e( 'Formularfelder', 'wp-dsgvo-form' ); ?></h2>

				<div id="dsgvo-fields-container">
					<?php foreach ( $fields as $index => $field ) : ?>
						<?php $this->render_field_row( $index, $field ); ?>
					<?php endforeach; ?>
				</div>

				<p>
					<button type="button" id="dsgvo-add-field" class="button">
						<?php esc_html_e( '+ Feld hinzufuegen', 'wp-dsgvo-form' ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Formular speichern', 'wp-dsgvo-form' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the page footer: field template, CSS, JavaScript.
	 */
	private function render_page_footer( array $fields ): void {
		?>
		<template id="dsgvo-field-template">
			<?php $this->render_field_row( '__INDEX__', null ); ?>
		</template>

		<?php
		$this->render_inline_styles();
		$this->render_inline_script( count( $fields ) );
	}

	/**
	 * Renders a single field row for the form builder.
	 *
	 * @param int|string $index  Row index (or '__INDEX__' for the template).
	 * @param Field|null $field  Existing field or null for new rows.
	 */
	private function render_field_row( $index, ?Field $field ): void {
		$id          = $field ? $field->id : 0;
		$type        = $field ? $field->field_type : 'text';
		$label       = $field ? $field->label : '';
		$name        = $field ? $field->name : '';
		$placeholder = $field ? $field->placeholder : '';
		$required    = $field ? $field->is_required : false;
		$css_class   = $field ? $field->css_class : '';
		$options_str = $field ? implode( "\n", $field->get_options() ) : '';
		$static_html = $field ? $field->static_content : '';
		$file_config = $field ? $field->get_file_config() : [ 'allowed_types' => [], 'max_size_mb' => 5 ];

		$idx = esc_attr( (string) $index );

		$this->render_field_row_header( $idx, $id );
		$this->render_field_row_grid( $idx, $type, $label, $name, $placeholder, $required, $css_class );
		$this->render_field_row_extras( $idx, $type, $options_str, $static_html, $file_config );
	}

	/**
	 * Renders field row header with title and action buttons.
	 */
	private function render_field_row_header( string $idx, int $id ): void {
		?>
		<div class="dsgvo-field-row" data-field-db-id="<?php echo esc_attr( (string) $id ); ?>">
			<h4>
				<span><?php esc_html_e( 'Feld', 'wp-dsgvo-form' ); ?> #<?php echo esc_html( (string) ( (int) $idx + 1 ) ); ?></span>
				<span class="dsgvo-field-actions">
					<button type="button" class="dsgvo-move-field-up button-link" title="<?php esc_attr_e( 'Nach oben', 'wp-dsgvo-form' ); ?>">&#9650;</button>
					<button type="button" class="dsgvo-move-field-down button-link" title="<?php esc_attr_e( 'Nach unten', 'wp-dsgvo-form' ); ?>">&#9660;</button>
					<button type="button" class="dsgvo-remove-field button-link" style="color:#d63638;">
						<?php esc_html_e( 'Entfernen', 'wp-dsgvo-form' ); ?>
					</button>
				</span>
			</h4>
			<input type="hidden" name="field_id[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php
	}

	/**
	 * Renders the field grid with type, label, name, placeholder, css, required.
	 */
	private function render_field_row_grid( string $idx, string $type, string $label, string $name, string $placeholder, bool $required, string $css_class ): void {
		?>
			<div class="dsgvo-field-grid">
				<div>
					<label for="dsgvo-field-type-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Typ', 'wp-dsgvo-form' ); ?></label>
					<select id="dsgvo-field-type-<?php echo esc_attr( $idx ); ?>" name="field_type[<?php echo esc_attr( $idx ); ?>]" class="dsgvo-type-select">
						<?php foreach ( self::FIELD_TYPE_LABELS as $slug => $type_label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
								<?php echo esc_html( $type_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="dsgvo-field-label-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Bezeichnung', 'wp-dsgvo-form' ); ?> *</label>
					<input type="text" id="dsgvo-field-label-<?php echo esc_attr( $idx ); ?>" name="field_label[<?php echo esc_attr( $idx ); ?>]"
						value="<?php echo esc_attr( $label ); ?>" required>
				</div>
				<div class="dsgvo-name-row" style="display:flex;flex-direction:column;">
					<label for="dsgvo-field-name-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Feldname (HTML name-Attribut)', 'wp-dsgvo-form' ); ?></label>
					<input type="text" id="dsgvo-field-name-<?php echo esc_attr( $idx ); ?>" name="field_name[<?php echo esc_attr( $idx ); ?>]"
						value="<?php echo esc_attr( $name ); ?>"
						placeholder="<?php esc_attr_e( 'z.B. vorname', 'wp-dsgvo-form' ); ?>">
				</div>
				<div class="dsgvo-placeholder-row" style="display:flex;flex-direction:column;">
					<label for="dsgvo-field-ph-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Platzhalter', 'wp-dsgvo-form' ); ?></label>
					<input type="text" id="dsgvo-field-ph-<?php echo esc_attr( $idx ); ?>" name="field_placeholder[<?php echo esc_attr( $idx ); ?>]"
						value="<?php echo esc_attr( $placeholder ); ?>">
				</div>
				<div>
					<label for="dsgvo-field-css-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'CSS-Klasse', 'wp-dsgvo-form' ); ?></label>
					<input type="text" id="dsgvo-field-css-<?php echo esc_attr( $idx ); ?>" name="field_css_class[<?php echo esc_attr( $idx ); ?>]"
						value="<?php echo esc_attr( $css_class ); ?>">
				</div>
				<div>
					<label>
						<input type="checkbox" name="field_required[]" value="<?php echo esc_attr( $idx ); ?>"
							<?php checked( $required ); ?>>
						<?php esc_html_e( 'Pflichtfeld', 'wp-dsgvo-form' ); ?>
					</label>
				</div>
			</div>
		<?php
	}

	/**
	 * Renders options, static content, and file config sections for a field row.
	 */
	private function render_field_row_extras( string $idx, string $type, string $options_str, string $static_html, array $file_config ): void {
		$allowed_types_str = implode( ', ', $file_config['allowed_types'] ?? [] );
		$max_size          = $file_config['max_size_mb'] ?? 5;
		?>
			<div class="dsgvo-options-section" style="margin-top:8px;">
				<label for="dsgvo-field-opts-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Optionen (eine pro Zeile)', 'wp-dsgvo-form' ); ?></label>
				<textarea id="dsgvo-field-opts-<?php echo esc_attr( $idx ); ?>" name="field_options[<?php echo esc_attr( $idx ); ?>]" rows="4"
					style="width:100%;font-family:monospace;"><?php echo esc_textarea( $options_str ); ?></textarea>
			</div>

			<div class="dsgvo-static-section" style="margin-top:8px;">
				<label for="dsgvo-field-static-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Statischer Inhalt (HTML erlaubt)', 'wp-dsgvo-form' ); ?></label>
				<textarea id="dsgvo-field-static-<?php echo esc_attr( $idx ); ?>" name="field_static_content[<?php echo esc_attr( $idx ); ?>]" rows="4"
					style="width:100%;"><?php echo esc_textarea( $static_html ); ?></textarea>
			</div>

			<div class="dsgvo-file-config-section" style="margin-top:8px;">
				<div class="dsgvo-field-grid">
					<div>
						<label for="dsgvo-field-ftypes-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Erlaubte Dateitypen', 'wp-dsgvo-form' ); ?></label>
						<input type="text" id="dsgvo-field-ftypes-<?php echo esc_attr( $idx ); ?>" name="field_file_types[<?php echo esc_attr( $idx ); ?>]"
							value="<?php echo esc_attr( $allowed_types_str ); ?>"
							placeholder="<?php esc_attr_e( 'z.B. pdf, jpg, png', 'wp-dsgvo-form' ); ?>">
						<p class="description"><?php esc_html_e( 'Kommagetrennt. Leer = alle erlaubten WordPress-Typen.', 'wp-dsgvo-form' ); ?></p>
					</div>
					<div>
						<label for="dsgvo-field-fsize-<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( 'Max. Dateigroesse (MB)', 'wp-dsgvo-form' ); ?></label>
						<input type="number" id="dsgvo-field-fsize-<?php echo esc_attr( $idx ); ?>" name="field_file_max_size[<?php echo esc_attr( $idx ); ?>]"
							value="<?php echo esc_attr( (string) $max_size ); ?>" min="1" max="50" class="small-text">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders inline CSS for the form builder.
	 */
	private function render_inline_styles(): void {
		?>
		<style>
		.dsgvo-field-row { border: 1px solid #ddd; padding: 12px; margin-bottom: 10px; background: #fafafa; position: relative; }
		.dsgvo-field-row h4 { margin: 0 0 10px; display: flex; justify-content: space-between; align-items: center; }
		.dsgvo-field-actions { display: flex; gap: 8px; align-items: center; }
		.dsgvo-field-actions .button-link { font-size: 12px; cursor: pointer; background: none; border: none; padding: 2px 4px; }
		.dsgvo-move-field-up, .dsgvo-move-field-down { color: #2271b1; }
		.dsgvo-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
		.dsgvo-field-grid label { display: block; font-weight: 600; margin-bottom: 3px; font-size: 12px; }
		.dsgvo-field-grid input, .dsgvo-field-grid select, .dsgvo-field-grid textarea { width: 100%; }
		.dsgvo-field-options { display: none; }
		.dsgvo-field-options.visible { display: block; }
		.dsgvo-file-config-section { display: none; }
		</style>
		<?php
	}

	/**
	 * Renders inline JavaScript for dynamic field management.
	 */
	private function render_inline_script( int $field_count ): void {
		?>
		<script>
		(function() {
			var container = document.getElementById('dsgvo-fields-container');
			var template  = document.getElementById('dsgvo-field-template');
			var addBtn    = document.getElementById('dsgvo-add-field');
			var fieldCount = <?php echo (int) $field_count; ?>;

			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted JS from internal methods
			echo $this->render_script_bind_row();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted JS from internal methods
			echo $this->render_script_toggle_sections();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted JS from internal methods
			echo $this->render_script_init();
			?>
		})();
		</script>
		<?php
	}

	/**
	 * Returns JavaScript for the bindRow() function.
	 *
	 * Handles event listeners for up/down/remove buttons and delegates
	 * type-change events to toggleFieldSections().
	 *
	 * @return string JavaScript code block.
	 */
	private function render_script_bind_row(): string {
		$confirm_msg = wp_json_encode( __( 'Feld wirklich entfernen? Das Feld wird beim Speichern geloescht.', 'wp-dsgvo-form' ) );

		return sprintf(
			"function bindRow(row) {\n"
			. "var removeBtn = row.querySelector('.dsgvo-remove-field');\n"
			. "if (removeBtn) {\n"
			. "removeBtn.addEventListener('click', function() {\n"
			. "var dbId = parseInt(row.getAttribute('data-field-db-id') || '0', 10);\n"
			. "if (dbId > 0) {\n"
			. "if (!confirm(%s)) {\n"
			. "return;\n"
			. "}\n"
			. "}\n"
			. "row.remove();\n"
			. "});\n"
			. "}\n"
			. "var upBtn = row.querySelector('.dsgvo-move-field-up');\n"
			. "var downBtn = row.querySelector('.dsgvo-move-field-down');\n"
			. "if (upBtn) {\n"
			. "upBtn.addEventListener('click', function() {\n"
			. "var prev = row.previousElementSibling;\n"
			. "if (prev) { container.insertBefore(row, prev); }\n"
			. "});\n"
			. "}\n"
			. "if (downBtn) {\n"
			. "downBtn.addEventListener('click', function() {\n"
			. "var next = row.nextElementSibling;\n"
			. "if (next) { container.insertBefore(next, row); }\n"
			. "});\n"
			. "}\n"
			. "var typeSelect = row.querySelector('.dsgvo-type-select');\n"
			. "if (typeSelect) {\n"
			. "typeSelect.addEventListener('change', function() { toggleFieldSections(row, this.value); });\n"
			. "toggleFieldSections(row, typeSelect.value);\n"
			. "}\n"
			. "}",
			$confirm_msg
		);
	}

	/**
	 * Returns JavaScript for the toggleFieldSections() function.
	 *
	 * Controls visibility of options, static content, and file config
	 * sections based on the selected field type.
	 *
	 * @return string JavaScript code block.
	 */
	private function render_script_toggle_sections(): string {
		return "function toggleFieldSections(row, type) {\n"
			. "	var optionsSection = row.querySelector('.dsgvo-options-section');\n"
			. "	var staticSection  = row.querySelector('.dsgvo-static-section');\n"
			. "	var fileSection    = row.querySelector('.dsgvo-file-config-section');\n"
			. "	var nameRow        = row.querySelector('.dsgvo-name-row');\n"
			. "	var placeholderRow = row.querySelector('.dsgvo-placeholder-row');\n"
			. "\n"
			. "	if (optionsSection) {\n"
			. "		optionsSection.style.display = ['radio','select','checkbox'].includes(type) ? 'block' : 'none';\n"
			. "	}\n"
			. "	if (staticSection) {\n"
			. "		staticSection.style.display = type === 'static' ? 'block' : 'none';\n"
			. "	}\n"
			. "	if (fileSection) {\n"
			. "		fileSection.style.display = type === 'file' ? 'block' : 'none';\n"
			. "	}\n"
			. "	if (nameRow) {\n"
			. "		nameRow.style.display = type === 'static' ? 'none' : 'flex';\n"
			. "	}\n"
			. "	if (placeholderRow) {\n"
			. "		placeholderRow.style.display = ['text','email','tel','textarea','date'].includes(type) ? 'flex' : 'none';\n"
			. "	}\n"
			. "}";
	}

	/**
	 * Returns JavaScript for initialization and add-field handler.
	 *
	 * @return string JavaScript code block.
	 */
	private function render_script_init(): string {
		return "addBtn.addEventListener('click', function() {\n"
			. "	var html = template.innerHTML.replace(/__INDEX__/g, fieldCount);\n"
			. "	var div = document.createElement('div');\n"
			. "	div.innerHTML = html;\n"
			. "	container.appendChild(div.firstElementChild);\n"
			. "	fieldCount++;\n"
			. "	bindRow(container.lastElementChild);\n"
			. "});\n"
			. "\n"
			. "var rows = container.querySelectorAll('.dsgvo-field-row');\n"
			. "rows.forEach(function(row) { bindRow(row); });";
	}
}
