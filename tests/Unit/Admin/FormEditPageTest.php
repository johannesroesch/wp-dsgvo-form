<?php
/**
 * Unit tests for FormEditPage class.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\FormEditPage;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for the form builder page rendering.
 */
class FormEditPageTest extends TestCase {

	/**
	 * Backup of $_GET superglobal.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get = array();

	/**
	 * Backup of $_POST superglobal.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post = array();

	/**
	 * Backup of $_SERVER superglobal.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_server = array();

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_get    = $_GET;
		$this->original_post   = $_POST;
		$this->original_server = $_SERVER;
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		$_GET    = $this->original_get;
		$_POST   = $this->original_post;
		$_SERVER = $this->original_server;
		parent::tearDown();
	}

	/**
	 * Stub common WordPress functions used by page rendering.
	 */
	private function stub_page_functions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo $text;
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo $text;
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $val ): int {
				return abs( (int) $val );
			}
		);
		Functions\when( 'settings_errors' )->justReturn();
		Functions\when( 'wp_nonce_field' )->justReturn();
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, bool $echo = true ): string {
				$result = $selected == $current ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, bool $echo = true ): string {
				$result = $checked == $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'submit_button' )->alias(
			function ( string $text = 'Save' ): void {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
	}

	/**
	 * @test
	 */
	public function test_render_shows_edit_title_when_editing(): void {
		$this->stub_page_functions();

		$_GET['form_id'] = '0';

		$page = new FormEditPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Neues Formular', $output );
		$this->assertStringContainsString( 'form-table', $output );
		$this->assertStringContainsString( 'dsgvo-fields-container', $output );
	}

	/**
	 * @test
	 */
	public function test_render_shows_new_form_title_when_no_form_id(): void {
		$this->stub_page_functions();

		unset( $_GET['form_id'] );

		$page = new FormEditPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Neues Formular', $output );
		$this->assertStringContainsString( 'form-table', $output );
		$this->assertStringContainsString( 'dsgvo-fields-container', $output );
	}

	// ──────────────────────────────────────────────────
	// maybe_save_and_redirect() — PRG pattern (Bug-Fix 1)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_maybe_save_and_redirect_returns_early_on_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();

		$this->assertTrue( true, 'maybe_save_and_redirect returns silently on GET.' );
	}

	/**
	 * @test
	 */
	public function test_maybe_save_and_redirect_returns_early_when_request_method_missing(): void {
		unset( $_SERVER['REQUEST_METHOD'] );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();

		$this->assertTrue( true, 'maybe_save_and_redirect returns silently when REQUEST_METHOD not set.' );
	}

	/**
	 * @test
	 */
	public function test_maybe_save_and_redirect_verifies_nonce_on_post(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( 'dsgvo_form_save' )
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'nonce_verified' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'nonce_verified' );

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();
	}

	/**
	 * @test
	 */
	public function test_maybe_save_and_redirect_dies_when_no_capability(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		Functions\expect( 'wp_die' )
			->once()
			->with( 'Keine Berechtigung.' )
			->andReturnUsing(
				function (): never {
					throw new \RuntimeException( 'wp_die: no capability' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: no capability' );

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();
	}

	/**
	 * @test
	 */
	public function test_maybe_save_and_redirect_stores_error_on_validation_failure(): void {
		$this->stub_page_functions();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['form_id']         = '0';
		$_POST['title']           = '';     // Empty title triggers RuntimeException in Form::validate().
		$_POST['description']     = 'test';
		$_POST['success_message'] = '';
		$_POST['email_subject']   = '';
		$_POST['email_template']  = '';
		$_POST['legal_basis']     = 'consent';
		$_POST['purpose']         = '';
		$_POST['retention_days']  = '90';

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();

		// No redirect, no exit — error is stored.
		// Verify by calling render() and checking for the error message.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$captured_error ): void {
				$captured_error = $message;
			}
		);

		$captured_error = '';

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertNotEmpty( $captured_error, 'add_settings_error should be called with the error message.' );
		$this->assertStringContainsString( 'title', strtolower( $captured_error ) );
		$this->assertStringContainsString( 'form-table', $output );
	}

	/**
	 * @test
	 */
	public function test_render_shows_save_error_instead_of_normal_page(): void {
		$this->stub_page_functions();

		// Use reflection to set save_error directly (simulates failed save).
		$page = new FormEditPage();
		$ref  = new \ReflectionObject( $page );

		$prop_error = $ref->getProperty( 'save_error' );
		$prop_error->setAccessible( true );
		$prop_error->setValue( $page, 'Test-Fehlermeldung' );

		$prop_id = $ref->getProperty( 'save_error_form_id' );
		$prop_id->setAccessible( true );
		$prop_id->setValue( $page, 0 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$captured_error = '';
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message ) use ( &$captured_error ): void {
				$captured_error = $message;
			}
		);

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertSame( 'Test-Fehlermeldung', $captured_error );
		$this->assertStringContainsString( 'form-table', $output );
	}

	// ──────────────────────────────────────────────────
	// FINDING-07: file_config must be array, not JSON string
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 *
	 * Regression test for FINDING-07: build_field_from_post() must assign
	 * an array to $field->file_config (not a JSON string via wp_json_encode).
	 * JSON encoding happens later in Field::to_db_array() via encode_json().
	 */
	public function test_build_field_from_post_sets_file_config_as_array(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'file' ),
			'labels'       => array( 'Dokument' ),
			'names'        => array( 'dokument' ),
			'placeholders' => array( '' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( 'full' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( 'pdf, jpg' ),
				'max_size'      => array( '10' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'file', $field->field_type );
		$this->assertIsArray( $field->file_config, 'file_config must be an array, not a JSON string (FINDING-07).' );
		$this->assertArrayHasKey( 'allowed_types', $field->file_config );
		$this->assertArrayHasKey( 'max_size_mb', $field->file_config );
	}

	// ──────────────────────────────────────────────────
	// EscapeOutput / Heredoc Fix (Task #243): XSS-Prävention
	// ──────────────────────────────────────────────────

	/**
	 * Stub escape functions with realistic HTML escaping behavior.
	 *
	 * Unlike stub_page_functions() which uses returnArg() (identity),
	 * this method applies actual escaping so we can verify XSS payloads
	 * are neutralized in the rendered output.
	 */
	private function stub_page_functions_with_escaping(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->alias(
			function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_attr' )->alias(
			function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_textarea' )->alias(
			function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_url' )->alias(
			function ( string $url ): string {
				return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text, string $domain = '' ): void {
				echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $val ): int {
				return abs( (int) $val );
			}
		);
		Functions\when( 'settings_errors' )->justReturn();
		Functions\when( 'wp_nonce_field' )->justReturn();
		Functions\when( 'selected' )->alias(
			function ( $selected, $current = true, bool $echo = true ): string {
				$result = $selected == $current ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'checked' )->alias(
			function ( $checked, $current = true, bool $echo = true ): string {
				$result = $checked == $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			}
		);
		Functions\when( 'submit_button' )->alias(
			function ( string $text = 'Save' ): void {
				echo '<input type="submit" value="' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '">';
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
	}

	/**
	 * @test
	 *
	 * Verifies that the page title in the <h1> is rendered through esc_html
	 * (Task #243: EscapeOutput/Heredoc fix). The page title comes from __()
	 * and is wrapped in esc_html() in render_page_header().
	 */
	public function test_render_page_header_escapes_title(): void {
		$this->stub_page_functions_with_escaping();

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_page_header' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, '<img src=x onerror=alert(1)>', 'https://example.com/wp-admin/', false );
		$output = ob_get_clean();

		// XSS payload in title must be escaped.
		$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $output );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $output );
		// Title is wrapped in <h1>.
		$this->assertStringContainsString( '<h1>', $output );
	}

	/**
	 * @test
	 *
	 * Verifies that render_field_row() escapes XSS payloads in field label,
	 * name, placeholder, and CSS class (Task #243: EscapeOutput/Heredoc fix).
	 */
	public function test_render_field_row_escapes_xss_in_field_values(): void {
		$this->stub_page_functions_with_escaping();

		$xss_payload = '<script>alert("XSS")</script>';

		$field              = new \WpDsgvoForm\Models\Field();
		$field->id          = 1;
		$field->form_id     = 1;
		$field->field_type  = 'text';
		$field->label       = $xss_payload;
		$field->name        = $xss_payload;
		$field->placeholder = $xss_payload;
		$field->css_class   = $xss_payload;
		$field->is_required = false;
		$field->sort_order  = 0;

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_field_row' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 0, $field );
		$output = ob_get_clean();

		// Raw XSS payload must NOT appear in the output.
		$this->assertStringNotContainsString( '<script>alert("XSS")</script>', $output );

		// Escaped version MUST appear (proves escaping occurred).
		$escaped = htmlspecialchars( $xss_payload, ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( $escaped, $output );
	}

	/**
	 * @test
	 *
	 * Verifies that textarea fields (description, purpose, email_template) are
	 * escaped with esc_textarea (Task #243: EscapeOutput/Heredoc fix).
	 */
	public function test_render_escapes_xss_in_textarea_fields(): void {
		$this->stub_page_functions_with_escaping();

		$xss_payload = '</textarea><script>alert("XSS")</script>';

		$_GET['form_id'] = '0';

		// Use reflection to invoke render_page with a crafted Form.
		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 0;
		$form->title       = 'Safe Title';
		$form->description = $xss_payload;
		$form->purpose     = $xss_payload;
		$form->email_template = $xss_payload;

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_page' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'Neues Formular', $form, [] );
		$output = ob_get_clean();

		// Raw payload must NOT appear — especially the closing </textarea> tag.
		$this->assertStringNotContainsString( '</textarea><script>', $output );
		$this->assertStringNotContainsString( 'alert("XSS")', $output );
	}

	/**
	 * @test
	 *
	 * Verifies that save_error messages go through add_settings_error
	 * (WordPress escapes these) and are NOT output as raw HTML
	 * (Task #243: EscapeOutput/Heredoc fix — Exception messages).
	 */
	public function test_render_save_error_passes_message_via_settings_error(): void {
		$this->stub_page_functions_with_escaping();

		$xss_error = '<img src=x onerror=alert("XSS")>';

		$page = new FormEditPage();
		$ref  = new \ReflectionObject( $page );

		$prop_error = $ref->getProperty( 'save_error' );
		$prop_error->setAccessible( true );
		$prop_error->setValue( $page, $xss_error );

		$prop_id = $ref->getProperty( 'save_error_form_id' );
		$prop_id->setAccessible( true );
		$prop_id->setValue( $page, 0 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$captured_message = '';
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type ) use ( &$captured_message ): void {
				$captured_message = $message;
			}
		);

		ob_start();
		$page->render();
		$output = ob_get_clean();

		// Error message is passed to add_settings_error, not echoed directly.
		$this->assertSame( $xss_error, $captured_message );

		// The form page still renders (shows form-table).
		$this->assertStringContainsString( 'form-table', $output );
	}

	/**
	 * @test
	 *
	 * Verifies that render_script_bind_row uses wp_json_encode for
	 * the confirm message — no raw string concatenation in JS
	 * (Task #243: EscapeOutput/Heredoc fix).
	 */
	public function test_render_script_bind_row_uses_json_encode_for_confirm(): void {
		$this->stub_page_functions_with_escaping();

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_script_bind_row' );
		$method->setAccessible( true );

		$js = $method->invoke( $page );

		// The JS must contain confirm() with a JSON-encoded string.
		$this->assertStringContainsString( 'confirm(', $js );
		// The message should NOT be a raw PHP string; it should be JSON-safe.
		$this->assertStringNotContainsString( "confirm('", $js );
	}

	/**
	 * @test
	 *
	 * Verifies that esc_attr() is applied to field IDs in HTML attributes
	 * to prevent attribute injection (Task #243: EscapeOutput/Heredoc fix).
	 */
	public function test_render_field_row_header_escapes_field_id_attribute(): void {
		$this->stub_page_functions_with_escaping();

		$field              = new \WpDsgvoForm\Models\Field();
		$field->id          = 42;
		$field->form_id     = 1;
		$field->field_type  = 'text';
		$field->label       = 'Name';
		$field->name        = 'name';
		$field->is_required = false;
		$field->sort_order  = 0;

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_field_row_header' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, '0', 42 );
		$output = ob_get_clean();

		// data-field-db-id should contain the escaped ID value.
		$this->assertStringContainsString( 'data-field-db-id="42"', $output );
		// Hidden input should contain the escaped ID.
		$this->assertStringContainsString( 'value="42"', $output );
	}

	/**
	 * @test
	 *
	 * Verifies that select/radio field options textarea is escaped
	 * with esc_textarea (Task #243: EscapeOutput/Heredoc fix).
	 */
	public function test_render_field_row_extras_escapes_options_textarea(): void {
		$this->stub_page_functions_with_escaping();

		$xss_options = "Option A\n<script>alert('XSS')</script>\nOption C";

		$field              = new \WpDsgvoForm\Models\Field();
		$field->id          = 1;
		$field->form_id     = 1;
		$field->field_type  = 'select';
		$field->label       = 'Auswahl';
		$field->name        = 'auswahl';
		$field->is_required = false;
		$field->options     = [ 'Option A', '<script>alert(\'XSS\')</script>', 'Option C' ];
		$field->sort_order  = 0;

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_field_row_extras' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, '0', 'select', $xss_options, '', [ 'allowed_types' => [], 'max_size_mb' => 5 ] );
		$output = ob_get_clean();

		// Raw script tag must NOT appear in the textarea output.
		$this->assertStringNotContainsString( "<script>alert('XSS')</script>", $output );
		// Escaped version must appear.
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * @test
	 *
	 * Non-file field types must NOT have file_config set.
	 */
	public function test_build_field_from_post_skips_file_config_for_non_file_type(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'text' ),
			'labels'       => array( 'Name' ),
			'names'        => array( 'name' ),
			'placeholders' => array( 'Ihr Name' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( 'full' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( '' ),
				'max_size'      => array( '5' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'text', $field->field_type );
		$this->assertNull( $field->file_config, 'Non-file fields must not have file_config set.' );
	}

	// ──────────────────────────────────────────────────
	// render_field_row() — Refactoring regression tests (Task #175)
	// Ensures identical output after match-expression refactoring
	// and extraction of 6 private render methods.
	// ──────────────────────────────────────────────────

	/**
	 * Helper: creates a Field with a specific type for render_field_row() testing.
	 *
	 * @param string $type         Field type slug.
	 * @param array  $extra_props  Additional properties to set.
	 * @return \WpDsgvoForm\Models\Field
	 */
	private function make_field( string $type, array $extra_props = [] ): \WpDsgvoForm\Models\Field {
		$field              = new \WpDsgvoForm\Models\Field();
		$field->id          = $extra_props['id'] ?? 1;
		$field->form_id     = 1;
		$field->field_type  = $type;
		$field->label       = $extra_props['label'] ?? 'Test Label';
		$field->name        = $extra_props['name'] ?? 'test_field';
		$field->placeholder = $extra_props['placeholder'] ?? '';
		$field->is_required = $extra_props['is_required'] ?? false;
		$field->css_class   = $extra_props['css_class'] ?? '';
		$field->sort_order  = 0;
		$field->options     = $extra_props['options'] ?? null;
		$field->static_content = $extra_props['static_content'] ?? '';
		$field->file_config    = $extra_props['file_config'] ?? null;

		return $field;
	}

	/**
	 * Helper: renders a field row via reflection and returns the HTML output.
	 *
	 * @param int|string                     $index  Row index.
	 * @param \WpDsgvoForm\Models\Field|null $field  Field to render.
	 * @return string HTML output.
	 */
	private function render_field_row_output( $index, $field ): string {
		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_field_row' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, $index, $field );
		return ob_get_clean();
	}

	/**
	 * @test
	 */
	public function test_render_field_row_text_type_has_correct_structure(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'text', [ 'label' => 'Vorname', 'name' => 'vorname', 'placeholder' => 'Max' ] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'dsgvo-field-row', $output );
		$this->assertStringContainsString( 'dsgvo-field-grid', $output );
		$this->assertStringContainsString( 'Vorname', $output );
		$this->assertStringContainsString( 'vorname', $output );
		$this->assertStringContainsString( 'Max', $output );
		$this->assertStringContainsString( 'selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_email_type_selects_email(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'email', [ 'label' => 'E-Mail' ] );
		$output = $this->render_field_row_output( 0, $field );

		// The email option should be selected.
		$this->assertStringContainsString( 'value="email"  selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_textarea_selects_textarea(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'textarea' );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="textarea"  selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_select_type_shows_options_section(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'select', [
			'options' => [ 'Ja', 'Nein', 'Vielleicht' ],
		] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="select"  selected="selected"', $output );
		$this->assertStringContainsString( 'dsgvo-options-section', $output );
		// Options textarea should contain the options as one per line.
		$this->assertStringContainsString( "Ja\nNein\nVielleicht", $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_radio_type_shows_options(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'radio', [
			'options' => [ 'Option A', 'Option B' ],
		] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="radio"  selected="selected"', $output );
		$this->assertStringContainsString( "Option A\nOption B", $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_checkbox_type_selected(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'checkbox' );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="checkbox"  selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_date_type_selected(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'date' );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="date"  selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_tel_type_selected(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'tel' );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="tel"  selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_file_type_shows_file_config(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'file', [
			'file_config' => [ 'allowed_types' => [ 'pdf', 'jpg' ], 'max_size_mb' => 10 ],
		] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="file"  selected="selected"', $output );
		$this->assertStringContainsString( 'dsgvo-file-config-section', $output );
		$this->assertStringContainsString( 'pdf, jpg', $output );
		$this->assertStringContainsString( 'value="10"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_static_type_shows_static_section(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'static', [
			'static_content' => '<p>Hinweis zur Datenverarbeitung</p>',
		] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'value="static"  selected="selected"', $output );
		$this->assertStringContainsString( 'dsgvo-static-section', $output );
		$this->assertStringContainsString( 'Hinweis zur Datenverarbeitung', $output );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_null_field_renders_empty_template(): void {
		$this->stub_page_functions();

		$output = $this->render_field_row_output( '__INDEX__', null );

		// Template row: default type=text, empty values.
		$this->assertStringContainsString( 'dsgvo-field-row', $output );
		$this->assertStringContainsString( 'value="text"  selected="selected"', $output );
		$this->assertStringContainsString( '__INDEX__', $output );
	}

	// ──────────────────────────────────────────────────
	// #257 MissingUnslash: wp_unslash() before sanitize_*()
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security OWASP — wp_unslash() applied before sanitize_text_field() on POST data.
	 *
	 * WordPress adds magic quotes to $_POST. Without wp_unslash() first,
	 * backslash-escaped input like O\'Reilly would remain as O\'Reilly
	 * instead of being correctly sanitized to O'Reilly.
	 */
	public function test_build_form_from_post_unslashes_before_sanitize(): void {
		$this->stub_page_functions();

		// Simulate WordPress magic quotes: backslash before apostrophe.
		$_POST['form_id']         = '0';
		$_POST['title']           = "O\\'Reilly Test";
		$_POST['description']     = "Beschreibung mit Apostroph\\'s";
		$_POST['success_message'] = 'Danke!';
		$_POST['email_subject']   = "Betreff\\\\Escaped";
		$_POST['email_template']  = 'Template';
		$_POST['legal_basis']     = 'consent';
		$_POST['purpose']         = "Zweck\\'s";
		$_POST['retention_days']  = '90';

		// Use realistic wp_unslash that actually strips slashes.
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				if ( is_string( $value ) ) {
					return stripslashes( $value );
				}
				if ( is_array( $value ) ) {
					return array_map( 'stripslashes', $value );
				}
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_form_from_post' );
		$method->setAccessible( true );

		$form = $method->invoke( $page, 0 );

		// After wp_unslash, the backslash-escaped apostrophes should be clean.
		$this->assertSame( "O'Reilly Test", $form->title );
		$this->assertSame( "Beschreibung mit Apostroph's", $form->description );
		$this->assertSame( "Betreff\\Escaped", $form->email_subject );
		$this->assertSame( "Zweck's", $form->purpose );
	}

	/**
	 * @test
	 * @security OWASP — wp_unslash() applied to field arrays in extract_field_post_data().
	 */
	public function test_extract_field_post_data_unslashes_field_arrays(): void {
		$this->stub_page_functions();

		// Simulate magic-quoted field data.
		$_POST['field_id']             = [ '0' ];
		$_POST['field_type']           = [ 'text' ];
		$_POST['field_label']          = [ "O\\'Reilly" ];
		$_POST['field_name']           = [ 'oreilly' ];
		$_POST['field_placeholder']    = [ "Platzhalter\\'s" ];
		$_POST['field_required']       = [];
		$_POST['field_css_class']      = [ 'cls' ];
		$_POST['field_options']        = [ '' ];
		$_POST['field_static_content'] = [ '' ];
		$_POST['field_file_types']     = [ '' ];
		$_POST['field_file_max_size']  = [ '5' ];

		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				if ( is_string( $value ) ) {
					return stripslashes( $value );
				}
				if ( is_array( $value ) ) {
					return array_map( function ( $v ) {
						return is_string( $v ) ? stripslashes( $v ) : $v;
					}, $value );
				}
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'extract_field_post_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $page );

		// Backslash-escaped apostrophes must be unslashed.
		$this->assertSame( "O'Reilly", $data['labels'][0] );
		$this->assertSame( "Platzhalter's", $data['placeholders'][0] );
	}

	/**
	 * @test
	 * @security OWASP — REQUEST_METHOD is also unslashed before comparison.
	 */
	public function test_maybe_save_returns_early_when_request_method_is_not_post(): void {
		// Simulate magic-quoted REQUEST_METHOD (unusual but edge case).
		$_SERVER['REQUEST_METHOD'] = "GET\\'";

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		);

		Functions\expect( 'check_admin_referer' )->never();

		$page = new FormEditPage();
		$page->maybe_save_and_redirect();

		$this->assertTrue( true, 'GET request (after unslash) should not trigger nonce check.' );
	}

	// ──────────────────────────────────────────────────
	// #256 NonceVerification.Missing — Nonce tests
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security SEC-CSRF — Nonce action is exactly 'dsgvo_form_save'.
	 */
	public function test_maybe_save_nonce_action_is_dsgvo_form_save(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$verified_action = '';
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturnUsing( function ( string $action ) use ( &$verified_action ): never {
				$verified_action = $action;
				throw new \RuntimeException( 'nonce_check' );
			} );

		try {
			$page = new FormEditPage();
			$page->maybe_save_and_redirect();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( 'dsgvo_form_save', $verified_action );
	}

	/**
	 * @test
	 * @security SEC-CSRF — Nonce is verified before capability check.
	 */
	public function test_maybe_save_nonce_verified_before_capability_check(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$call_order = [];

		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturnUsing( function () use ( &$call_order ): bool {
				$call_order[] = 'nonce';
				return true;
			} );

		Functions\when( 'current_user_can' )->alias(
			function () use ( &$call_order ): bool {
				$call_order[] = 'capability';
				return false;
			}
		);

		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing( function (): never {
				throw new \RuntimeException( 'wp_die' );
			} );

		try {
			$page = new FormEditPage();
			$page->maybe_save_and_redirect();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( [ 'nonce', 'capability' ], $call_order );
	}

	/**
	 * @test
	 */
	public function test_render_field_row_required_checkbox_is_checked(): void {
		$this->stub_page_functions();

		$field  = $this->make_field( 'text', [ 'is_required' => true ] );
		$output = $this->render_field_row_output( 0, $field );

		$this->assertStringContainsString( 'checked="checked"', $output );
	}

	// ──────────────────────────────────────────────────
	// build_field_from_post() — Width POST handling (FEP-01, Task #347)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_build_field_from_post_sets_width_from_post_data(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'text' ),
			'labels'       => array( 'Vorname' ),
			'names'        => array( 'vorname' ),
			'placeholders' => array( '' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( 'half' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( '' ),
				'max_size'      => array( '5' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'half', $field->width );
	}

	/**
	 * @test
	 */
	public function test_build_field_from_post_sets_third_width(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'email' ),
			'labels'       => array( 'E-Mail' ),
			'names'        => array( 'email' ),
			'placeholders' => array( '' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( 'third' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( '' ),
				'max_size'      => array( '5' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'third', $field->width );
	}

	/**
	 * @test
	 */
	public function test_build_field_from_post_falls_back_to_full_for_invalid_width(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'text' ),
			'labels'       => array( 'Feld' ),
			'names'        => array( 'feld' ),
			'placeholders' => array( '' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( 'quarter' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( '' ),
				'max_size'      => array( '5' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'full', $field->width, 'Invalid width "quarter" must fall back to "full".' );
	}

	/**
	 * @test
	 */
	public function test_build_field_from_post_falls_back_to_full_for_empty_string_width(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		$post_data = array(
			'ids'          => array( 0 ),
			'types'        => array( 'text' ),
			'labels'       => array( 'Feld' ),
			'names'        => array( 'feld' ),
			'placeholders' => array( '' ),
			'required'     => array(),
			'css_classes'  => array( '' ),
			'widths'       => array( '' ),
			'options'      => array( '' ),
			'static'       => array( '' ),
			'file_config'  => array(
				'allowed_types' => array( '' ),
				'max_size'      => array( '5' ),
			),
		);

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_field_from_post' );
		$method->setAccessible( true );

		$field = $method->invoke( $page, 42, 0, $post_data );

		$this->assertNotNull( $field );
		$this->assertSame( 'full', $field->width, 'Empty string width must fall back to "full".' );
	}

	// ──────────────────────────────────────────────────
	// build_form_from_post() — locale_override (FEP-03, Task #347)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_build_form_from_post_locale_mode_auto_sets_null(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_POST['form_id']         = '0';
		$_POST['title']           = 'Kontakt';
		$_POST['description']     = '';
		$_POST['success_message'] = 'Danke';
		$_POST['email_subject']   = '';
		$_POST['email_template']  = '';
		$_POST['legal_basis']     = 'consent';
		$_POST['purpose']         = '';
		$_POST['retention_days']  = '90';
		$_POST['locale_mode']     = 'auto';

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_form_from_post' );
		$method->setAccessible( true );

		$form = $method->invoke( $page, 0 );

		$this->assertNull( $form->locale_override, 'locale_mode=auto must set locale_override to null.' );
	}

	/**
	 * @test
	 */
	public function test_build_form_from_post_locale_mode_fixed_stores_value(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_POST['form_id']          = '0';
		$_POST['title']            = 'Kontakt';
		$_POST['description']      = '';
		$_POST['success_message']  = 'Danke';
		$_POST['email_subject']    = '';
		$_POST['email_template']   = '';
		$_POST['legal_basis']      = 'consent';
		$_POST['purpose']          = '';
		$_POST['retention_days']   = '90';
		$_POST['locale_mode']      = 'fixed';
		$_POST['locale_override']  = 'en_US';

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_form_from_post' );
		$method->setAccessible( true );

		$form = $method->invoke( $page, 0 );

		$this->assertSame( 'en_US', $form->locale_override );
	}

	/**
	 * @test
	 */
	public function test_build_form_from_post_locale_mode_fixed_with_empty_value_sets_null(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_POST['form_id']          = '0';
		$_POST['title']            = 'Kontakt';
		$_POST['description']      = '';
		$_POST['success_message']  = 'Danke';
		$_POST['email_subject']    = '';
		$_POST['email_template']   = '';
		$_POST['legal_basis']      = 'consent';
		$_POST['purpose']          = '';
		$_POST['retention_days']   = '90';
		$_POST['locale_mode']      = 'fixed';
		$_POST['locale_override']  = '';

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_form_from_post' );
		$method->setAccessible( true );

		$form = $method->invoke( $page, 0 );

		$this->assertNull( $form->locale_override, 'Fixed mode with empty value must set locale_override to null.' );
	}

	/**
	 * @test
	 */
	public function test_build_form_from_post_missing_locale_mode_defaults_to_auto(): void {
		$this->stub_page_functions();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_POST['form_id']         = '0';
		$_POST['title']           = 'Kontakt';
		$_POST['description']     = '';
		$_POST['success_message'] = 'Danke';
		$_POST['email_subject']   = '';
		$_POST['email_template']  = '';
		$_POST['legal_basis']     = 'consent';
		$_POST['purpose']         = '';
		$_POST['retention_days']  = '90';
		// No locale_mode set at all.

		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'build_form_from_post' );
		$method->setAccessible( true );

		$form = $method->invoke( $page, 0 );

		$this->assertNull( $form->locale_override, 'Missing locale_mode must default to null (auto).' );
	}

	// ──────────────────────────────────────────────────
	// UX-I18N-03: Locale warning div — inline warning
	// ──────────────────────────────────────────────────

	/**
	 * Helper: renders render_page() with a Form via Reflection and returns HTML.
	 *
	 * @param Form $form   Form object.
	 * @param array $fields Optional field list.
	 * @return string Rendered HTML output.
	 */
	private function render_page_output( \WpDsgvoForm\Models\Form $form, array $fields = [] ): string {
		$page   = new FormEditPage();
		$method = new \ReflectionMethod( $page, 'render_page' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'Formular bearbeiten', $form, $fields );
		return ob_get_clean();
	}

	/**
	 * @test
	 * UX-I18N-03: Locale warning div is rendered for existing consent form.
	 */
	public function test_locale_warning_rendered_for_existing_consent_form(): void {
		$this->stub_page_functions();

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return $value;
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( [ 'de_DE', 'en_US' ] );
		$GLOBALS['wpdb'] = $wpdb;

		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 5;
		$form->title       = 'Kontaktformular';
		$form->legal_basis = 'consent';

		$output = $this->render_page_output( $form );

		$this->assertStringContainsString( 'id="dsgvo-locale-warning"', $output );
		$this->assertStringContainsString( 'data-locales=', $output );
		$this->assertStringContainsString( 'data-labels=', $output );
		$this->assertStringContainsString( 'data-template=', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'display:none', $output );
	}

	/**
	 * @test
	 * UX-I18N-03: Locale warning div is NOT rendered for new form (id=0).
	 */
	public function test_locale_warning_not_rendered_for_new_form(): void {
		$this->stub_page_functions();

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return $value;
			}
		);

		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 0;
		$form->title       = 'Neues Formular';
		$form->legal_basis = 'consent';

		$output = $this->render_page_output( $form );

		$this->assertStringNotContainsString( 'id="dsgvo-locale-warning"', $output );
	}

	/**
	 * @test
	 * UX-I18N-03: Locale warning div is NOT rendered for non-consent form.
	 */
	public function test_locale_warning_not_rendered_for_non_consent_form(): void {
		$this->stub_page_functions();

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return $value;
			}
		);

		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 5;
		$form->title       = 'Kontaktformular';
		$form->legal_basis = 'legitimate_interest';

		$output = $this->render_page_output( $form );

		$this->assertStringNotContainsString( 'id="dsgvo-locale-warning"', $output );
	}

	/**
	 * @test
	 * UX-I18N-03: data-locales contains the locales returned by get_locales_with_versions.
	 */
	public function test_locale_warning_data_locales_contains_db_values(): void {
		$this->stub_page_functions();

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return $value;
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( [ 'de_DE', 'fr_FR' ] );
		$GLOBALS['wpdb'] = $wpdb;

		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 3;
		$form->title       = 'Test';
		$form->legal_basis = 'consent';

		$output = $this->render_page_output( $form );

		// data-locales should contain JSON-encoded array with de_DE and fr_FR.
		$this->assertStringContainsString( 'de_DE', $output );
		$this->assertStringContainsString( 'fr_FR', $output );
	}

	/**
	 * @test
	 * UX-I18N-03: data-template contains the warning message with %s placeholder.
	 */
	public function test_locale_warning_data_template_has_placeholder(): void {
		$this->stub_page_functions();

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ): string {
				return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return $value;
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$form              = new \WpDsgvoForm\Models\Form();
		$form->id          = 1;
		$form->title       = 'Test';
		$form->legal_basis = 'consent';

		$output = $this->render_page_output( $form );

		// data-template should contain %s placeholder and reference "Consent-Verwaltung".
		$this->assertStringContainsString( '%s', $output );
		$this->assertStringContainsString( 'Consent-Verwaltung', $output );
	}
}
