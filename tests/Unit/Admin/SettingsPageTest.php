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
 *
 * Updated for Task #278: CAPTCHA settings simplified — provider, base_url,
 * sitekey, and sri_hash settings removed. Only captcha_secret and
 * default_retention_days remain.
 */
class SettingsPageTest extends TestCase {

	/**
	 * Stub common WordPress functions used by SettingsPage.
	 *
	 * @param string[] $skip Function names to NOT stub.
	 */
	private function stub_settings_functions( array $skip = array() ): void {
		$return_arg = array( '__', 'esc_html__', 'esc_url' );

		$aliases = array(
			'esc_html_e' => function ( string $text, string $domain = '' ): void {
				echo $text;
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
	 * 4 settings: captcha_secret, retention_days, controller_name, controller_email.
	 */
	public function test_register_settings_registers_four_settings_and_two_sections(): void {
		$this->stub_settings_functions(
			array( 'register_setting', 'add_settings_section', 'add_settings_field' )
		);

		$registered_options = array();

		Functions\expect( 'register_setting' )
			->times( 4 )
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
			->times( 4 );

		$page = new SettingsPage();
		$page->register_settings();

		$this->assertContains( 'wpdsgvo_captcha_secret', $registered_options );
		$this->assertContains( 'wpdsgvo_default_retention_days', $registered_options );
		$this->assertContains( 'wpdsgvo_controller_name', $registered_options );
		$this->assertContains( 'wpdsgvo_controller_email', $registered_options );
		$this->assertContains( 'dsgvo_form_captcha_section', $section_ids );
		$this->assertContains( 'dsgvo_form_general_section', $section_ids );
	}

	/**
	 * @test
	 * Task #278: Removed settings are NOT registered.
	 */
	public function test_register_settings_does_not_register_removed_options(): void {
		$this->stub_settings_functions(
			array( 'register_setting' )
		);

		$registered_options = array();

		Functions\expect( 'register_setting' )
			->andReturnUsing(
				function () use ( &$registered_options ): void {
					$args                 = func_get_args();
					$registered_options[] = $args[1];
				}
			);

		$page = new SettingsPage();
		$page->register_settings();

		$this->assertNotContains( 'wpdsgvo_captcha_provider', $registered_options );
		$this->assertNotContains( 'wpdsgvo_captcha_base_url', $registered_options );
		$this->assertNotContains( 'wpdsgvo_captcha_sitekey', $registered_options );
		$this->assertNotContains( 'wpdsgvo_captcha_sri_hash', $registered_options );
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
	 * Task #278: render_captcha_section shows WPDSGVO_CAPTCHA_URL constant.
	 */
	public function test_render_captcha_section_displays_constant_url(): void {
		$this->stub_settings_functions();

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( WPDSGVO_CAPTCHA_URL, $output );
		$this->assertStringContainsString( 'WPDSGVO_CAPTCHA_URL', $output );
	}

	/**
	 * @test
	 * Task #278: render_captcha_secret_field outputs password input.
	 */
	public function test_render_captcha_secret_field_outputs_password_input(): void {
		$this->stub_settings_functions( array( 'get_option' ) );

		Functions\expect( 'get_option' )
			->with( 'wpdsgvo_captcha_secret', '' )
			->andReturn( 'test-secret' );

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_secret_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringContainsString( 'wpdsgvo_captcha_secret', $output );
		$this->assertStringContainsString( 'test-secret', $output );
	}

	/**
	 * @test
	 * sanitize_retention_days clamps to minimum 1.
	 */
	public function test_sanitize_retention_days_minimum_is_one(): void {
		$this->stub_settings_functions();

		Functions\when( 'absint' )->alias( function ( $value ): int {
			return abs( (int) $value );
		} );

		$page = new SettingsPage();

		$this->assertSame( 1, $page->sanitize_retention_days( 0 ) );
		$this->assertSame( 10, $page->sanitize_retention_days( -10 ) );
		$this->assertSame( 1, $page->sanitize_retention_days( 1 ) );
	}

	/**
	 * @test
	 * sanitize_retention_days clamps to maximum 3650.
	 */
	public function test_sanitize_retention_days_maximum_is_3650(): void {
		$this->stub_settings_functions();

		Functions\when( 'absint' )->alias( function ( $value ): int {
			return abs( (int) $value );
		} );

		$page = new SettingsPage();

		$this->assertSame( 3650, $page->sanitize_retention_days( 9999 ) );
		$this->assertSame( 3650, $page->sanitize_retention_days( 3650 ) );
		$this->assertSame( 90, $page->sanitize_retention_days( 90 ) );
	}

	/**
	 * @test
	 * LEGAL-F06: render_captcha_section shows AVV/Art. 28 DSGVO hint.
	 */
	public function test_render_captcha_section_displays_avv_hint(): void {
		$this->stub_settings_functions();

		$page = new SettingsPage();

		ob_start();
		$page->render_captcha_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Auftragsverarbeitungsvertrag', $output );
		$this->assertStringContainsString( 'Art. 28 DSGVO', $output );
		$this->assertStringContainsString( 'Datenschutzbeauftragten', $output );
	}

	/**
	 * @test
	 * sanitize_retention_days uses sanitize_text_field on wpdsgvo_captcha_secret.
	 */
	public function test_register_settings_captcha_secret_uses_sanitize_text_field(): void {
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

		$this->assertArrayHasKey( 'wpdsgvo_captcha_secret', $sanitize_callbacks );
		$this->assertSame( 'sanitize_text_field', $sanitize_callbacks['wpdsgvo_captcha_secret'] );
	}
}
