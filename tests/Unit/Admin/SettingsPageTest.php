<?php
/**
 * Unit tests for SettingsPage class.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\SettingsPage;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for plugin settings registration and field rendering.
 */
class SettingsPageTest extends TestCase {

	/**
	 * Stub common WordPress functions used by SettingsPage.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 */
	private function stub_settings_functions( array $skip = array() ): void {
		$return_arg = array( '__', 'esc_html__', 'esc_html', 'esc_url', 'esc_attr' );

		$aliases = array(
			'esc_html_e' => function ( string $text, string $domain = '' ): void {
				echo $text;
			},
			'selected'   => function ( $selected, $current = true, $echo = true ) {
				$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
				if ( $echo ) {
					echo $result;
				}
				return $result;
			},
		);

		$null_returns = array(
			'register_setting',
			'add_settings_section',
			'add_settings_field',
			'settings_fields',
			'do_settings_sections',
			'submit_button',
			'sanitize_text_field',
		);

		foreach ( $return_arg as $func ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->returnArg();
			}
		}

		foreach ( $aliases as $func => $callback ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->alias( $callback );
			}
		}

		foreach ( $null_returns as $func ) {
			if ( ! in_array( $func, $skip, true ) ) {
				Functions\when( $func )->justReturn( null );
			}
		}
	}

	/**
	 * @test
	 */
	public function test_register_settings_registers_six_settings_and_sections(): void {
		$this->stub_settings_functions(
			array( 'register_setting', 'add_settings_section', 'add_settings_field' )
		);

		$registered_options = array();

		Functions\expect( 'register_setting' )
			->times( 6 )
			->andReturnUsing(
				function () use ( &$registered_options ): void {
					$args                 = func_get_args();
					$registered_options[] = $args[1];
				}
			);

		$section_ids = array();

		Functions\expect( 'add_settings_section' )
			->times( 2 )
			->andReturnUsing(
				function () use ( &$section_ids ): void {
					$args          = func_get_args();
					$section_ids[] = $args[0];
				}
			);

		Functions\expect( 'add_settings_field' )
			->times( 6 );

		$page = new SettingsPage();
		$page->register_settings();

		$this->assertContains( 'wpdsgvo_captcha_provider', $registered_options );
		$this->assertContains( 'wpdsgvo_captcha_base_url', $registered_options );
		$this->assertContains( 'wpdsgvo_captcha_sitekey', $registered_options );
		$this->assertContains( 'wpdsgvo_captcha_secret', $registered_options );
		$this->assertContains( 'wpdsgvo_captcha_sri_hash', $registered_options );
		$this->assertContains( 'wpdsgvo_default_retention_days', $registered_options );
		$this->assertContains( 'dsgvo_form_captcha_section', $section_ids );
		$this->assertContains( 'dsgvo_form_general_section', $section_ids );
	}

	/**
	 * @test
	 */
	public function test_render_captcha_provider_field_outputs_select_with_options(): void {
		$this->stub_settings_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_captcha_provider', 'custom' )
			->andReturn( 'custom' );

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_provider_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<select', $output );
		$this->assertStringContainsString( 'wpdsgvo_captcha_provider', $output );
		$this->assertStringContainsString( 'friendly-captcha', $output );
		$this->assertStringContainsString( 'hcaptcha', $output );
		$this->assertStringContainsString( 'selected="selected"', $output );
	}

	/**
	 * @test
	 */
	public function test_render_retention_field_outputs_number_input(): void {
		$this->stub_settings_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_default_retention_days', 90 )
			->andReturn( 90 );

		$page = new SettingsPage();

		ob_start();
		$page->render_retention_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'wpdsgvo_default_retention_days', $output );
		$this->assertStringContainsString( 'value="90"', $output );
		$this->assertStringContainsString( 'min="1"', $output );
		$this->assertStringContainsString( 'max="3650"', $output );
	}

	/**
	 * @test
	 * SEC-CAP-05: register_settings uses sanitize_captcha_base_url callback for base URL.
	 */
	public function test_register_settings_captcha_base_url_uses_https_sanitize_callback(): void {
		$this->stub_settings_functions( array( 'register_setting' ) );

		$sanitize_callbacks = array();

		Functions\expect( 'register_setting' )
			->andReturnUsing(
				function () use ( &$sanitize_callbacks ): void {
					$args = func_get_args();
					if ( isset( $args[2]['sanitize_callback'] ) ) {
						$sanitize_callbacks[ $args[1] ] = $args[2]['sanitize_callback'];
					}
				}
			);

		$page = new SettingsPage();
		$page->register_settings();

		$this->assertArrayHasKey( 'wpdsgvo_captcha_base_url', $sanitize_callbacks );
		$this->assertIsCallable( $sanitize_callbacks['wpdsgvo_captcha_base_url'] );
	}

	/**
	 * @test
	 * SEC-CAP-05: sanitize_captcha_base_url accepts valid HTTPS URL.
	 */
	public function test_sanitize_captcha_base_url_accepts_valid_https_url(): void {
		$this->stub_settings_functions( array( 'sanitize_url' ) );

		Functions\when( 'sanitize_url' )->returnArg();

		$page   = new SettingsPage();
		$result = $page->sanitize_captcha_base_url( 'https://captcha.example.com' );

		$this->assertSame( 'https://captcha.example.com', $result );
	}

	/**
	 * @test
	 * SEC-CAP-05: sanitize_captcha_base_url rejects HTTP URL, falls back to previous value.
	 */
	public function test_sanitize_captcha_base_url_rejects_http_url(): void {
		$this->stub_settings_functions( array( 'sanitize_url', 'add_settings_error', 'get_option' ) );

		Functions\when( 'sanitize_url' )->returnArg();

		Functions\expect( 'add_settings_error' )
			->once()
			->with(
				'wpdsgvo_captcha_base_url',
				'not_https',
				\Mockery::type( 'string' ),
				'error'
			);

		Functions\expect( 'get_option' )
			->once()
			->with( 'wpdsgvo_captcha_base_url', 'https://captcha.repaircafe-bruchsal.de' )
			->andReturn( 'https://captcha.repaircafe-bruchsal.de' );

		$page   = new SettingsPage();
		$result = $page->sanitize_captcha_base_url( 'http://evil.example.com' );

		$this->assertSame( 'https://captcha.repaircafe-bruchsal.de', $result );
	}

	/**
	 * @test
	 * SEC-CAP-05: sanitize_captcha_base_url allows empty string (field cleared).
	 */
	public function test_sanitize_captcha_base_url_accepts_empty_string(): void {
		$this->stub_settings_functions( array( 'sanitize_url' ) );

		Functions\when( 'sanitize_url' )->justReturn( '' );

		$page   = new SettingsPage();
		$result = $page->sanitize_captcha_base_url( '' );

		$this->assertSame( '', $result );
	}

	/**
	 * @test
	 * Custom CAPTCHA is the first option in the provider dropdown.
	 */
	public function test_captcha_provider_field_has_custom_as_first_option(): void {
		$this->stub_settings_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_captcha_provider', 'custom' )
			->andReturn( 'custom' );

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_provider_field();
		$output = ob_get_clean();

		// Extract all <option> values in order.
		preg_match_all( '/value="([^"]+)"/', $output, $matches );

		$this->assertNotEmpty( $matches[1] );
		$this->assertSame( 'custom', $matches[1][0], 'Custom CAPTCHA must be the first option.' );
	}

	/**
	 * @test
	 * SOLL-FIX: sanitize_captcha_provider accepts all valid providers.
	 */
	public function test_sanitize_captcha_provider_accepts_valid_values(): void {
		$page = new SettingsPage();

		$this->assertSame( 'custom', $page->sanitize_captcha_provider( 'custom' ) );
		$this->assertSame( 'friendly-captcha', $page->sanitize_captcha_provider( 'friendly-captcha' ) );
		$this->assertSame( 'hcaptcha', $page->sanitize_captcha_provider( 'hcaptcha' ) );
	}

	/**
	 * @test
	 * SOLL-FIX: sanitize_captcha_provider rejects invalid values, falls back to 'custom'.
	 */
	public function test_sanitize_captcha_provider_rejects_invalid_falls_back_to_custom(): void {
		$page = new SettingsPage();

		$this->assertSame( 'custom', $page->sanitize_captcha_provider( 'recaptcha' ) );
		$this->assertSame( 'custom', $page->sanitize_captcha_provider( '' ) );
		$this->assertSame( 'custom', $page->sanitize_captcha_provider( '<script>alert(1)</script>' ) );
	}

	/**
	 * @test
	 * render() outputs form with settings_fields and Systemstatus section.
	 */
	public function test_render_outputs_form_and_system_status(): void {
		$this->stub_settings_functions( array( 'settings_fields', 'do_settings_sections', 'submit_button' ) );

		Functions\expect( 'settings_fields' )
			->once()
			->with( 'dsgvo_form_settings' );

		Functions\expect( 'do_settings_sections' )
			->once()
			->with( 'dsgvo_form_settings' );

		Functions\expect( 'submit_button' )->once();

		$page = new SettingsPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form method="post" action="options.php">', $output );
		$this->assertStringContainsString( 'Einstellungen', $output );
		$this->assertStringContainsString( 'Systemstatus', $output );
		$this->assertStringContainsString( 'Plugin-Version', $output );
		$this->assertStringContainsString( WPDSGVO_VERSION, $output );
	}

	/**
	 * @test
	 * render_captcha_base_url_field outputs URL input with HTTPS placeholder.
	 */
	public function test_render_captcha_base_url_field_outputs_url_input(): void {
		$this->stub_settings_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_captcha_base_url', 'https://captcha.repaircafe-bruchsal.de' )
			->andReturn( 'https://captcha.repaircafe-bruchsal.de' );

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_base_url_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="url"', $output );
		$this->assertStringContainsString( 'wpdsgvo_captcha_base_url', $output );
		$this->assertStringContainsString( 'https://captcha.repaircafe-bruchsal.de', $output );
		$this->assertStringContainsString( 'HTTPS', $output );
	}
}
