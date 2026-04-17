<?php
/**
 * Unit tests for FormBlock (Gutenberg block registration + server-side rendering).
 *
 * Covers: register(), rest_get_forms(), render() early returns + happy path,
 * render_field() for all field types, consent checkbox, CAPTCHA asset enqueuing,
 * honeypot field, and DPO-FINDING-13 fail-closed on missing consent text.
 *
 * @package WpDsgvoForm\Tests\Unit\Block
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Block;

use WpDsgvoForm\Block\FormBlock;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Tests for FormBlock.
 */
class FormBlockTest extends TestCase {

	private FormBlock $block;

	protected function setUp(): void {
		parent::setUp();

		$this->block = new FormBlock();

		// Default WordPress function mocks.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/dsgvo-form/v1/submit' );

		// Mock wpdb for Form/Field model queries.
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
	 * Creates a Form object for testing.
	 */
	private function make_form( array $overrides = [] ): Form {
		$form                  = new Form();
		$form->id              = $overrides['id'] ?? 1;
		$form->title           = $overrides['title'] ?? 'Test Form';
		$form->slug            = $overrides['slug'] ?? 'test-form';
		$form->is_active       = $overrides['is_active'] ?? true;
		$form->legal_basis     = $overrides['legal_basis'] ?? 'consent';
		$form->consent_text    = $overrides['consent_text'] ?? 'Ich stimme zu.';
		$form->consent_version = $overrides['consent_version'] ?? 1;
		$form->retention_days  = $overrides['retention_days'] ?? 90;
		$form->success_message = $overrides['success_message'] ?? 'Danke!';
		$form->encrypted_dek   = $overrides['encrypted_dek'] ?? 'enc-dek';
		$form->dek_iv          = $overrides['dek_iv'] ?? 'dek-iv';
		$form->captcha_enabled = $overrides['captcha_enabled'] ?? true;
		return $form;
	}

	/**
	 * Creates a Field object for testing.
	 */
	private function make_field( array $props ): Field {
		$field              = new Field();
		$field->id          = $props['id'] ?? 1;
		$field->form_id     = $props['form_id'] ?? 1;
		$field->field_type  = $props['field_type'] ?? 'text';
		$field->label       = $props['label'] ?? 'Test Field';
		$field->name        = $props['name'] ?? 'test_field';
		$field->placeholder = $props['placeholder'] ?? '';
		$field->is_required = $props['is_required'] ?? false;
		$field->options     = $props['options'] ?? null;
		$field->css_class   = $props['css_class'] ?? '';
		$field->static_content = $props['static_content'] ?? '';
		$field->file_config = $props['file_config'] ?? null;
		return $field;
	}

	/**
	 * Sets up all mocks for a successful render() call.
	 *
	 * @return Form
	 */
	private function setup_render_mocks( array $form_overrides = [], array $fields = [], string $locale = 'de_DE' ): Form {
		$form = $this->make_form( $form_overrides );

		// Form::find via transient cache.
		Functions\when( 'get_transient' )->justReturn( $form );

		// Field::find_by_form_id.
		if ( empty( $fields ) ) {
			$fields = [ $this->make_field( [ 'id' => 1, 'name' => 'vorname', 'label' => 'Vorname' ] ) ];
		}
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn(
			array_map( function ( Field $f ): array {
				return [
					'id'              => $f->id,
					'form_id'         => $f->form_id,
					'field_type'      => $f->field_type,
					'label'           => $f->label,
					'name'            => $f->name,
					'placeholder'     => $f->placeholder,
					'is_required'     => (int) $f->is_required,
					'options'         => $f->options ? json_encode( $f->options ) : null,
					'validation_rules' => null,
					'static_content'  => $f->static_content,
					'css_class'       => $f->css_class,
					'file_config'     => $f->file_config ? json_encode( $f->file_config ) : null,
					'sort_order'      => 0,
					'created_at'      => '2026-04-17',
				];
			}, $fields )
		);

		// WP functions for render.
		Functions\when( 'determine_locale' )->justReturn( $locale );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_dsgvo_nonce" value="nonce123">' );
		Functions\when( 'get_option' )->justReturn( 'https://captcha.repaircafe-bruchsal.de' );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		// ConsentVersion::get_current_version for consent-based forms.
		if ( ( $form_overrides['legal_basis'] ?? 'consent' ) === 'consent' ) {
			$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( [
				'id'                 => '1',
				'form_id'            => (string) $form->id,
				'locale'             => $locale,
				'version'            => (string) ( $form->consent_version ?? 1 ),
				'consent_text'       => $form->consent_text ?? 'Consent text',
				'privacy_policy_url' => null,
				'valid_from'         => '2026-04-17 00:00:00',
				'created_at'         => '2026-04-17 00:00:00',
			] );
		}

		return $form;
	}

	// ──────────────────────────────────────────────────
	// render() — Early returns
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_render_returns_empty_for_zero_form_id(): void {
		$html = $this->block->render( [ 'formId' => 0 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_render_returns_empty_for_missing_form_id(): void {
		$html = $this->block->render( [] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_render_returns_empty_when_form_not_found(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$html = $this->block->render( [ 'formId' => 999 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_render_returns_empty_when_form_inactive(): void {
		$form            = $this->make_form();
		$form->is_active = false;
		Functions\when( 'get_transient' )->justReturn( $form );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_render_returns_empty_when_no_fields(): void {
		$form = $this->make_form();
		Functions\when( 'get_transient' )->justReturn( $form );
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-13 — Fail-Closed: No form if consent text empty
	 */
	public function test_render_returns_empty_when_consent_text_missing(): void {
		$form = $this->make_form( [ 'legal_basis' => 'consent', 'consent_text' => '' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		// Fields exist but consent_text is empty.
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Name', 'name' => 'name', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );

		// ConsentVersion::get_current_version returns null — no version exists.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 * @privacy-relevant DPO-FINDING-13 — Contract basis does NOT require consent text
	 */
	public function test_render_allows_empty_consent_text_for_contract_basis(): void {
		$this->setup_render_mocks( [
			'legal_basis'  => 'contract',
			'consent_text' => '',
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'dsgvo-form', $html );
	}

	// ──────────────────────────────────────────────────
	// render() — Happy path HTML structure
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_render_happy_path_contains_form_structure(): void {
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		// Outer wrapper.
		$this->assertStringContainsString( 'wp-block-dsgvo-form-form', $html );
		// Form element with data attributes.
		$this->assertStringContainsString( 'data-form-id="1"', $html );
		$this->assertStringContainsString( 'data-locale="de_DE"', $html );
		// Nonce field.
		$this->assertStringContainsString( '_dsgvo_nonce', $html );
		// Honeypot field.
		$this->assertStringContainsString( 'website_url', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		// CAPTCHA widget.
		$this->assertStringContainsString( 'captcha-widget', $html );
		// Submit button.
		$this->assertStringContainsString( 'Absenden', $html );
		// Status area.
		$this->assertStringContainsString( 'role="alert"', $html );
	}

	/**
	 * @test
	 * @privacy-relevant Art. 7 DSGVO — Consent checkbox with versioned text
	 */
	public function test_render_includes_consent_checkbox_for_consent_basis(): void {
		$this->setup_render_mocks( [
			'legal_basis'     => 'consent',
			'consent_text'    => 'Ich stimme der Verarbeitung zu.',
			'consent_version' => 3,
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'dsgvo-form__field--consent', $html );
		$this->assertStringContainsString( 'dsgvo_consent', $html );
		$this->assertStringContainsString( 'Ich stimme der Verarbeitung zu.', $html );
		$this->assertStringContainsString( 'dsgvo_consent_version', $html );
	}

	/**
	 * @test
	 */
	public function test_render_excludes_consent_checkbox_for_contract_basis(): void {
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringNotContainsString( 'dsgvo-form__field--consent', $html );
		$this->assertStringNotContainsString( 'dsgvo_consent', $html );
	}

	// ──────────────────────────────────────────────────
	// render_field() — Various field types (via render)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_render_text_field(): void {
		$fields = [
			$this->make_field( [
				'field_type'  => 'text',
				'name'        => 'vorname',
				'label'       => 'Vorname',
				'placeholder' => 'Max',
				'is_required' => true,
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertStringContainsString( 'name="vorname"', $html );
		$this->assertStringContainsString( 'Vorname', $html );
		$this->assertStringContainsString( 'placeholder="Max"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
	}

	/**
	 * @test
	 */
	public function test_render_email_field(): void {
		$fields = [
			$this->make_field( [ 'field_type' => 'email', 'name' => 'email', 'label' => 'E-Mail' ] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
	}

	/**
	 * @test
	 */
	public function test_render_textarea_field(): void {
		$fields = [
			$this->make_field( [ 'field_type' => 'textarea', 'name' => 'message', 'label' => 'Nachricht' ] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'name="message"', $html );
		$this->assertStringContainsString( 'rows="5"', $html );
	}

	/**
	 * @test
	 */
	public function test_render_select_field(): void {
		$fields = [
			$this->make_field( [
				'field_type' => 'select',
				'name'       => 'anrede',
				'label'      => 'Anrede',
				'options'    => [ 'Herr', 'Frau', 'Divers' ],
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( 'Bitte waehlen', $html );
		$this->assertStringContainsString( 'Herr', $html );
		$this->assertStringContainsString( 'Frau', $html );
		$this->assertStringContainsString( 'Divers', $html );
	}

	/**
	 * @test
	 */
	public function test_render_radio_field(): void {
		$fields = [
			$this->make_field( [
				'field_type' => 'radio',
				'name'       => 'kontakt',
				'label'      => 'Kontaktweg',
				'options'    => [ 'Telefon', 'E-Mail' ],
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'role="radiogroup"', $html );
		$this->assertStringContainsString( 'Telefon', $html );
		$this->assertStringContainsString( 'E-Mail', $html );
	}

	/**
	 * @test
	 */
	public function test_render_checkbox_with_options(): void {
		$fields = [
			$this->make_field( [
				'field_type' => 'checkbox',
				'name'       => 'interessen',
				'label'      => 'Interessen',
				'options'    => [ 'Sport', 'Musik' ],
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'dsgvo-form__checkbox-group', $html );
		$this->assertStringContainsString( 'name="interessen[]"', $html );
		$this->assertStringContainsString( 'Sport', $html );
		$this->assertStringContainsString( 'Musik', $html );
	}

	/**
	 * @test
	 */
	public function test_render_file_field_with_accept(): void {
		$fields = [
			$this->make_field( [
				'field_type'  => 'file',
				'name'        => 'dokument',
				'label'       => 'Dokument',
				'file_config' => [ 'allowed_types' => [ 'pdf', 'jpg' ] ],
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'type="file"', $html );
		$this->assertStringContainsString( 'accept=', $html );
		$this->assertStringContainsString( '.pdf', $html );
		$this->assertStringContainsString( '.jpg', $html );
	}

	/**
	 * @test
	 */
	public function test_render_static_field(): void {
		$fields = [
			$this->make_field( [
				'field_type'     => 'static',
				'name'           => 'info',
				'label'          => 'Info',
				'static_content' => '<p>Hinweis zur Datenverarbeitung.</p>',
			] ),
		];
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], $fields );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'dsgvo-form__static', $html );
		$this->assertStringContainsString( 'Hinweis zur Datenverarbeitung.', $html );
		// Static fields should NOT contain an input element for this field.
		$this->assertStringNotContainsString( 'name="info"', $html );
	}

	// ──────────────────────────────────────────────────
	// rest_get_forms()
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_rest_get_forms_returns_active_forms(): void {
		$form1        = $this->make_form( [ 'id' => 1, 'title' => 'Kontakt', 'slug' => 'kontakt' ] );
		$form2        = $this->make_form( [ 'id' => 2, 'title' => 'Bewerbung', 'slug' => 'bewerbung' ] );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )
			->once()
			->andReturn( [
				[
					'id' => 1, 'title' => 'Kontakt', 'slug' => 'kontakt',
					'description' => '', 'success_message' => '', 'email_subject' => '',
					'email_template' => '', 'is_active' => 1, 'retention_days' => 90,
					'encrypted_dek' => 'dek', 'dek_iv' => 'iv', 'legal_basis' => 'consent',
					'purpose' => '', 'consent_text' => 'text', 'consent_version' => 1,
					'created_at' => '2026-04-17', 'updated_at' => '2026-04-17',
				],
				[
					'id' => 2, 'title' => 'Bewerbung', 'slug' => 'bewerbung',
					'description' => '', 'success_message' => '', 'email_subject' => '',
					'email_template' => '', 'is_active' => 1, 'retention_days' => 90,
					'encrypted_dek' => 'dek', 'dek_iv' => 'iv', 'legal_basis' => 'contract',
					'purpose' => '', 'consent_text' => '', 'consent_version' => 1,
					'created_at' => '2026-04-17', 'updated_at' => '2026-04-17',
				],
			] );

		$response = $this->block->rest_get_forms();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 2, $data );
		$this->assertSame( 1, $data[0]['id'] );
		$this->assertSame( 'Kontakt', $data[0]['title'] );
		$this->assertSame( 'kontakt', $data[0]['slug'] );
		// Only id, title, slug — no encrypted_dek etc.
		$this->assertArrayNotHasKey( 'encrypted_dek', $data[0] );
	}

	// ──────────────────────────────────────────────────
	// enqueue_captcha_assets()
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_captcha_script_enqueued_on_render(): void {
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		// Verify the CAPTCHA widget is present in the output.
		$this->assertStringContainsString( 'captcha-widget', $html );
		$this->assertStringContainsString( 'captcha.repaircafe-bruchsal.de', $html );
	}

	/**
	 * @test
	 */
	public function test_captcha_widget_includes_lang_from_locale(): void {
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], [], 'en_US' );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'lang="en"', $html );
	}

	/**
	 * @test
	 */
	public function test_render_excludes_captcha_when_disabled(): void {
		$this->setup_render_mocks( [
			'legal_basis'     => 'contract',
			'captcha_enabled' => false,
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringNotContainsString( 'captcha-widget', $html );
		$this->assertStringNotContainsString( 'dsgvo-form__captcha', $html );
		// Form itself still renders.
		$this->assertStringContainsString( 'dsgvo-form', $html );
		$this->assertStringContainsString( 'Absenden', $html );
	}

	/**
	 * @test
	 */
	public function test_render_includes_captcha_when_enabled(): void {
		$this->setup_render_mocks( [
			'legal_basis'     => 'contract',
			'captcha_enabled' => true,
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'captcha-widget', $html );
		$this->assertStringContainsString( 'dsgvo-form__captcha', $html );
		$this->assertStringContainsString( 'captcha.repaircafe-bruchsal.de', $html );
	}

	// ──────────────────────────────────────────────────
	// get_current_locale()
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_locale_from_determine_locale_used_in_render(): void {
		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ], [], 'fr_FR' );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'data-locale="fr_FR"', $html );
		// CAPTCHA widget lang should use first 2 chars.
		$this->assertStringContainsString( 'lang="fr"', $html );
	}

	// ──────────────────────────────────────────────────
	// SEC-SRI-01: SRI integrity attribute for CAPTCHA script
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SRI-01 — SRI filter added when hash is configured
	 */
	public function test_captcha_script_adds_sri_filter_when_hash_configured(): void {
		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Vorname', 'name' => 'vorname', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_dsgvo_nonce" value="nonce123">' );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			if ( $key === 'wpdsgvo_captcha_sri_hash' ) {
				return 'sha384-testHash123456';
			}
			return 'https://captcha.repaircafe-bruchsal.de';
		} );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		Filters\expectAdded( 'script_loader_tag' )->once();

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertNotEmpty( $html );
	}

	/**
	 * @test
	 * @security-relevant SEC-SRI-01 — No SRI filter when hash is empty
	 */
	public function test_captcha_script_no_sri_filter_when_hash_empty(): void {
		$form = $this->make_form( [ 'legal_basis' => 'contract' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Vorname', 'name' => 'vorname', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_dsgvo_nonce" value="nonce123">' );
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			if ( $key === 'wpdsgvo_captcha_sri_hash' ) {
				return '';
			}
			return 'https://captcha.repaircafe-bruchsal.de';
		} );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		Filters\expectAdded( 'script_loader_tag' )->never();

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertNotEmpty( $html );
	}

	// ──────────────────────────────────────────────────
	// ConsentVersion integration (Task #126)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant Art. 7 DSGVO — Consent text loaded from ConsentVersion model, not Form scalar
	 */
	public function test_render_consent_checkbox_uses_consent_version_text(): void {
		// Setup: consent form — ConsentVersion text comes from the mock row.
		$this->setup_render_mocks( [
			'legal_basis'  => 'consent',
			'consent_text' => 'Versionierter Einwilligungstext v2.',
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'Versionierter Einwilligungstext v2.', $html );
		$this->assertStringContainsString( 'dsgvo-form__field--consent', $html );
	}

	/**
	 * @test
	 * @privacy-relevant DPO MUSS-3 — Fail-Closed: no render when no ConsentVersion for locale
	 */
	public function test_render_returns_empty_when_no_consent_version_for_current_locale(): void {
		// Setup: consent form, but ConsentVersion::get_current_version returns null for fr_FR.
		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Name', 'name' => 'name', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'fr_FR' );

		// No ConsentVersion for fr_FR — get_row returns null.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertSame( '', $html, 'Form must not render without ConsentVersion for current locale (Fail-Closed).' );
	}

	/**
	 * @test
	 * @privacy-relevant Art. 7 DSGVO — Hidden field stores ConsentVersion.id for audit trail
	 */
	public function test_render_consent_checkbox_includes_consent_version_id_hidden_field(): void {
		// Setup manually to control ConsentVersion id=42.
		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Name', 'name' => 'name', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_dsgvo_nonce" value="nonce123">' );
		Functions\when( 'get_option' )->justReturn( 'https://captcha.repaircafe-bruchsal.de' );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		// ConsentVersion with id=42.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( [
			'id'                 => '42',
			'form_id'            => '1',
			'locale'             => 'de_DE',
			'version'            => '2',
			'consent_text'       => 'Einwilligungstext.',
			'privacy_policy_url' => null,
			'valid_from'         => '2026-04-17 00:00:00',
			'created_at'         => '2026-04-17 00:00:00',
		] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		// Hidden field must carry the ConsentVersion.id (42), not the Form scalar version.
		$this->assertStringContainsString( 'name="dsgvo_consent_version"', $html );
		$this->assertStringContainsString( 'value="42"', $html );
	}

	// ──────────────────────────────────────────────────
	// register() — Shortcode registration (Bug-Fix 2)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_register_adds_dsgvo_form_shortcode(): void {
		Functions\when( 'plugin_dir_path' )->justReturn( '/fake/' );
		Functions\when( 'register_block_type' )->justReturn( null );

		Functions\expect( 'add_shortcode' )
			->once()
			->with( 'dsgvo_form', Mockery::type( 'array' ) );

		$block = new FormBlock();
		$block->register();
	}

	// ──────────────────────────────────────────────────
	// shortcode_handler() (Bug-Fix 2)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_shortcode_handler_renders_form_with_valid_id(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( array $defaults, $atts, string $shortcode ): array {
				return array_merge( $defaults, (array) $atts );
			}
		);

		$this->setup_render_mocks( [ 'legal_basis' => 'contract' ] );

		$html = $this->block->shortcode_handler( [ 'id' => '1' ] );

		$this->assertStringContainsString( 'data-form-id="1"', $html );
		$this->assertStringContainsString( 'dsgvo-form', $html );
	}

	/**
	 * @test
	 */
	public function test_shortcode_handler_returns_empty_for_zero_id(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( array $defaults, $atts, string $shortcode ): array {
				return array_merge( $defaults, (array) $atts );
			}
		);

		$html = $this->block->shortcode_handler( [ 'id' => '0' ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_shortcode_handler_defaults_to_zero_when_no_id(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( array $defaults, $atts, string $shortcode ): array {
				return array_merge( $defaults, (array) $atts );
			}
		);

		$html = $this->block->shortcode_handler( [] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 */
	public function test_shortcode_handler_casts_non_numeric_id_to_zero(): void {
		Functions\when( 'shortcode_atts' )->alias(
			function ( array $defaults, $atts, string $shortcode ): array {
				return array_merge( $defaults, (array) $atts );
			}
		);

		$html = $this->block->shortcode_handler( [ 'id' => 'abc' ] );

		$this->assertSame( '', $html );
	}

	// ──────────────────────────────────────────────────
	// admin_notice() — Admin-only error notices (Bug-Fix 2)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_admin_sees_notice_for_zero_form_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->block->render( [ 'formId' => 0 ] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( 'Kein Formular ausgewaehlt.', $html );
	}

	/**
	 * @test
	 */
	public function test_admin_sees_notice_for_missing_form_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->block->render( [] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( 'Kein Formular ausgewaehlt.', $html );
	}

	/**
	 * @test
	 */
	public function test_admin_sees_notice_when_form_not_found(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$html = $this->block->render( [ 'formId' => 999 ] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( '#999', $html );
		$this->assertStringContainsString( 'nicht gefunden', $html );
	}

	/**
	 * @test
	 */
	public function test_admin_sees_notice_when_form_inactive(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$form = $this->make_form( [ 'is_active' => false ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( 'deaktiviert', $html );
	}

	/**
	 * @test
	 */
	public function test_admin_sees_notice_when_no_fields(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$form = $this->make_form();
		Functions\when( 'get_transient' )->justReturn( $form );
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [] );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( 'keine Felder', $html );
	}

	/**
	 * @test
	 * @privacy-relevant DSGVO — Fail-Closed: admin sees notice when no ConsentVersion
	 */
	public function test_admin_sees_notice_when_no_consent_version_for_locale(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$form = $this->make_form( [ 'legal_basis' => 'consent' ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( [
			[
				'id' => 1, 'form_id' => 1, 'field_type' => 'text',
				'label' => 'Name', 'name' => 'name', 'placeholder' => '',
				'is_required' => 0, 'options' => null, 'validation_rules' => null,
				'static_content' => '', 'css_class' => '', 'file_config' => null,
				'sort_order' => 0, 'created_at' => '2026-04-17',
			],
		] );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertStringContainsString( 'dsgvo-form-admin-notice', $html );
		$this->assertStringContainsString( 'Einwilligungstext', $html );
	}

	// ──────────────────────────────────────────────────
	// DSGVO fail-closed: visitors see nothing (Bug-Fix 2)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @privacy-relevant DSGVO — User without capability sees empty string
	 */
	public function test_user_without_capability_sees_empty_on_error(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$html = $this->block->render( [ 'formId' => 0 ] );

		$this->assertSame( '', $html );
	}

	/**
	 * @test
	 * @privacy-relevant DSGVO — Fail-closed for all error conditions when not admin
	 */
	public function test_visitor_sees_empty_for_inactive_form(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$form = $this->make_form( [ 'is_active' => false ] );
		Functions\when( 'get_transient' )->justReturn( $form );

		$html = $this->block->render( [ 'formId' => 1 ] );

		$this->assertSame( '', $html );
	}

	// ──────────────────────────────────────────────────
	// admin_notice() HTML structure + XSS escaping (Bug-Fix 2)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant XSS — admin_notice uses esc_html on message content
	 */
	public function test_admin_notice_html_structure(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->block->render( [ 'formId' => 0 ] );

		// Expected structure: outer div with class, strong label, esc_html'd message.
		$this->assertStringContainsString( 'class="wp-block-dsgvo-form-form dsgvo-form-admin-notice"', $html );
		$this->assertStringContainsString( '<strong>DSGVO Formular:</strong>', $html );
		// No raw HTML injection possible — esc_html applied (returns arg as-is in test).
		$this->assertStringContainsString( 'Kein Formular ausgewaehlt.', $html );
	}
}
