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
}
