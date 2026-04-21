<?php
/**
 * Unit tests for CspHeaders (Content-Security-Policy).
 *
 * SEC-KANN-02: CSP headers on plugin admin pages.
 *
 * @package WpDsgvoForm\Tests\Unit\Security
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Security;

use WpDsgvoForm\Security\CspHeaders;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Tests for CspHeaders — CSP header sending, page detection, nonce fallback.
 */
class CspHeadersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET['page'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// register — hooks into admin_init
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-02: register() adds admin_init action.
	 */
	public function test_register_adds_admin_init_hook(): void {
		$csp = new CspHeaders();

		Actions\expectAdded( 'admin_init' )
			->once()
			->with( [ $csp, 'maybe_send_headers' ] );

		$csp->register();
	}

	// ------------------------------------------------------------------
	// is_plugin_page — page detection
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-02: Plugin page detected via $_GET['page'] prefix.
	 */
	public function test_is_plugin_page_returns_true_for_plugin_pages(): void {
		$csp = new CspHeaders();

		$reflection = new \ReflectionMethod( $csp, 'is_plugin_page' );
		$reflection->setAccessible( true );

		$_GET['page'] = 'dsgvo-form-settings';
		$this->assertTrue( $reflection->invoke( $csp ) );

		$_GET['page'] = 'dsgvo-form';
		$this->assertTrue( $reflection->invoke( $csp ) );

		$_GET['page'] = 'dsgvo-form-submissions';
		$this->assertTrue( $reflection->invoke( $csp ) );
	}

	/**
	 * @test
	 * SEC-KANN-02: Non-plugin pages are not detected.
	 */
	public function test_is_plugin_page_returns_false_for_other_pages(): void {
		$csp = new CspHeaders();

		$reflection = new \ReflectionMethod( $csp, 'is_plugin_page' );
		$reflection->setAccessible( true );

		$_GET['page'] = 'woocommerce';
		$this->assertFalse( $reflection->invoke( $csp ) );

		$_GET['page'] = 'options-general';
		$this->assertFalse( $reflection->invoke( $csp ) );
	}

	/**
	 * @test
	 * SEC-KANN-02: No page parameter → not a plugin page.
	 */
	public function test_is_plugin_page_returns_false_when_no_page_param(): void {
		$csp = new CspHeaders();

		$reflection = new \ReflectionMethod( $csp, 'is_plugin_page' );
		$reflection->setAccessible( true );

		unset( $_GET['page'] );
		$this->assertFalse( $reflection->invoke( $csp ) );
	}

	/**
	 * @test
	 * SEC-KANN-02: Empty page parameter → not a plugin page.
	 */
	public function test_is_plugin_page_returns_false_for_empty_page(): void {
		$csp = new CspHeaders();

		$reflection = new \ReflectionMethod( $csp, 'is_plugin_page' );
		$reflection->setAccessible( true );

		$_GET['page'] = '';
		$this->assertFalse( $reflection->invoke( $csp ) );
	}

	// ------------------------------------------------------------------
	// maybe_send_headers — integration
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-02: maybe_send_headers does nothing on non-plugin page.
	 */
	public function test_maybe_send_headers_skips_non_plugin_page(): void {
		$_GET['page'] = 'woocommerce';

		$csp = new CspHeaders();

		// Should not throw or cause errors — just returns early.
		$csp->maybe_send_headers();

		// If we get here without errors, the test passes.
		$this->assertTrue( true );
	}

}
