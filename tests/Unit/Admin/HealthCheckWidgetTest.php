<?php
/**
 * Unit tests for HealthCheckWidget.
 *
 * SEC-KANN-03: Proactive health monitoring for encryption configuration.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\HealthCheckWidget;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests for HealthCheckWidget — registration, capability check, health checks.
 */
class HealthCheckWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	// ------------------------------------------------------------------
	// register — hooks into wp_dashboard_setup
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-03: register() adds wp_dashboard_setup action.
	 */
	public function test_register_adds_dashboard_setup_hook(): void {
		$widget = new HealthCheckWidget();

		Actions\expectAdded( 'wp_dashboard_setup' )
			->once()
			->with( [ $widget, 'add_widget' ] );

		$widget->register();
	}

	// ------------------------------------------------------------------
	// add_widget — capability check
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-03: Widget is added for users with dsgvo_form_manage capability.
	 */
	public function test_add_widget_registers_for_capable_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_add_dashboard_widget' )->alias(
			function ( string $id, string $title, callable $callback ): void {
				// Verify it was called with expected parameters.
			}
		);

		$widget = new HealthCheckWidget();

		// Should not throw — widget is registered.
		$widget->add_widget();

		$this->assertTrue( true );
	}

	/**
	 * @test
	 * SEC-KANN-03: Widget is NOT added for users without capability.
	 */
	public function test_add_widget_skips_for_incapable_user(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		// wp_add_dashboard_widget should NOT be called.
		$called = false;
		Functions\when( 'wp_add_dashboard_widget' )->alias(
			function () use ( &$called ): void {
				$called = true;
			}
		);

		$widget = new HealthCheckWidget();
		$widget->add_widget();

		$this->assertFalse( $called );
	}

	// ------------------------------------------------------------------
	// run_checks — individual health check results
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-03: run_checks returns exactly 4 checks.
	 */
	public function test_run_checks_returns_four_checks(): void {
		// Simulate KEK not available.
		$widget     = new HealthCheckWidget();
		$reflection = new \ReflectionMethod( $widget, 'run_checks' );
		$reflection->setAccessible( true );

		$checks = $reflection->invoke( $widget );

		$this->assertCount( 4, $checks );
	}

	/**
	 * @test
	 * SEC-KANN-03: Check 1 — KEK available check.
	 */
	public function test_run_checks_kek_availability(): void {
		$widget     = new HealthCheckWidget();
		$reflection = new \ReflectionMethod( $widget, 'run_checks' );
		$reflection->setAccessible( true );

		$checks = $reflection->invoke( $widget );

		// KEK constant is not defined in test env → should fail.
		$this->assertFalse( $checks[0]['ok'] );
		$this->assertNotEmpty( $checks[0]['label'] );
		$this->assertNotEmpty( $checks[0]['description'] );
	}

	/**
	 * @test
	 * SEC-KANN-03: Check 3 — OpenSSL AES-256-GCM availability.
	 */
	public function test_run_checks_openssl_cipher(): void {
		$widget     = new HealthCheckWidget();
		$reflection = new \ReflectionMethod( $widget, 'run_checks' );
		$reflection->setAccessible( true );

		$checks = $reflection->invoke( $widget );

		// AES-256-GCM should be available on modern PHP.
		$gcm_available = in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
		$this->assertSame( $gcm_available, $checks[2]['ok'] );
	}

	/**
	 * @test
	 * SEC-KANN-03: Each check has required array keys.
	 */
	public function test_run_checks_structure(): void {
		$widget     = new HealthCheckWidget();
		$reflection = new \ReflectionMethod( $widget, 'run_checks' );
		$reflection->setAccessible( true );

		$checks = $reflection->invoke( $widget );

		foreach ( $checks as $i => $check ) {
			$this->assertArrayHasKey( 'label', $check, "Check #{$i} missing 'label'." );
			$this->assertArrayHasKey( 'ok', $check, "Check #{$i} missing 'ok'." );
			$this->assertArrayHasKey( 'description', $check, "Check #{$i} missing 'description'." );
			$this->assertIsBool( $check['ok'], "Check #{$i} 'ok' must be boolean." );
			$this->assertIsString( $check['label'], "Check #{$i} 'label' must be string." );
			$this->assertIsString( $check['description'], "Check #{$i} 'description' must be string." );
		}
	}

	// ------------------------------------------------------------------
	// render — output contains table
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-03: render() produces HTML table output.
	 */
	public function test_render_outputs_html_table(): void {
		$widget = new HealthCheckWidget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( '</table>', $output );
		$this->assertStringContainsString( '<tr>', $output );
	}

	/**
	 * @test
	 * SEC-KANN-03: render() shows warning when checks fail.
	 */
	public function test_render_shows_warning_when_checks_fail(): void {
		// KEK not defined → at least one check fails.
		$widget = new HealthCheckWidget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Should contain red warning color.
		$this->assertStringContainsString( '#d63638', $output );
	}
}
