<?php
/**
 * Unit tests for IpResolver (Trusted-Proxy-aware IP resolution).
 *
 * SEC-KANN-01: Configurable trusted proxy list.
 *
 * @package WpDsgvoForm\Tests\Unit\Security
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Security;

use WpDsgvoForm\Security\IpResolver;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for IpResolver — rightmost-untrusted algorithm, CIDR matching.
 */
class IpResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// No trusted proxies configured — always returns REMOTE_ADDR
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: No proxies configured → REMOTE_ADDR returned.
	 */
	public function test_resolve_returns_remote_addr_when_no_proxies_configured(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: No REMOTE_ADDR → null returned.
	 */
	public function test_resolve_returns_null_when_no_remote_addr(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		unset( $_SERVER['REMOTE_ADDR'] );

		$resolver = new IpResolver();
		$this->assertNull( $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: Empty REMOTE_ADDR → null returned.
	 */
	public function test_resolve_returns_null_when_remote_addr_empty(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$_SERVER['REMOTE_ADDR'] = '';

		$resolver = new IpResolver();
		$this->assertNull( $resolver->resolve() );
	}

	/**
	 * @test
	 * Invalid REMOTE_ADDR (not a valid IP) → null returned.
	 */
	public function test_resolve_returns_null_for_invalid_remote_addr(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$resolver = new IpResolver();
		$this->assertNull( $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: XFF ignored when no proxies configured (spoofing protection).
	 */
	public function test_resolve_ignores_xff_when_no_proxies_configured(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$_SERVER['REMOTE_ADDR']          = '203.0.113.50';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// Trusted proxies configured — XFF evaluation
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: Single trusted proxy → rightmost untrusted from XFF.
	 */
	public function test_resolve_returns_rightmost_untrusted_ip_from_xff(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1' );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 10.0.0.1';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: Multiple proxy hops — skips all trusted, returns client.
	 */
	public function test_resolve_skips_multiple_trusted_proxies(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1,10.0.0.2' );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.42, 10.0.0.2, 10.0.0.1';

		$resolver = new IpResolver();
		$this->assertSame( '198.51.100.42', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: REMOTE_ADDR is not a trusted proxy → REMOTE_ADDR returned (XFF ignored).
	 */
	public function test_resolve_uses_remote_addr_when_not_trusted(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1' );

		$_SERVER['REMOTE_ADDR']          = '203.0.113.99';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.99', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: No XFF header when trusted proxy → falls back to REMOTE_ADDR.
	 */
	public function test_resolve_fallback_to_remote_addr_when_no_xff(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1' );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$resolver = new IpResolver();
		$this->assertSame( '10.0.0.1', $resolver->resolve() );
	}

	/**
	 * @test
	 * All IPs in XFF are trusted → falls back to REMOTE_ADDR.
	 */
	public function test_resolve_returns_remote_addr_when_all_xff_trusted(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1,10.0.0.2,10.0.0.3' );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.3, 10.0.0.2';

		$resolver = new IpResolver();
		$this->assertSame( '10.0.0.1', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// CIDR matching — IPv4
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: CIDR range matching for IPv4 trusted proxies.
	 */
	public function test_resolve_matches_cidr_ipv4_range(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.0/8' );

		$_SERVER['REMOTE_ADDR']          = '10.255.255.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42, 10.1.2.3';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.42', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: /16 CIDR range matches correctly.
	 */
	public function test_resolve_matches_cidr_16_range(): void {
		Functions\when( 'get_option' )->justReturn( '172.16.0.0/12' );

		$_SERVER['REMOTE_ADDR']          = '172.20.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$resolver = new IpResolver();
		$this->assertSame( '8.8.8.8', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// CIDR matching — IPv6
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: IPv6 CIDR range matching.
	 */
	public function test_resolve_matches_cidr_ipv6_range(): void {
		Functions\when( 'get_option' )->justReturn( 'fd00::/8' );

		$_SERVER['REMOTE_ADDR']          = 'fd12:3456:789a::1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1';

		$resolver = new IpResolver();
		$this->assertSame( '2001:db8::1', $resolver->resolve() );
	}

	/**
	 * @test
	 * SEC-KANN-01: IPv6 exact match for trusted proxy.
	 */
	public function test_resolve_matches_exact_ipv6(): void {
		Functions\when( 'get_option' )->justReturn( '::1' );

		$_SERVER['REMOTE_ADDR']          = '::1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::42';

		$resolver = new IpResolver();
		$this->assertSame( '2001:db8::42', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// Invalid entries in trusted proxies — silently filtered
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: Invalid entries in proxy list are filtered out.
	 */
	public function test_resolve_filters_invalid_proxy_entries(): void {
		Functions\when( 'get_option' )->justReturn( 'not-valid, 10.0.0.1, !!!bad!!!' );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		$resolver = new IpResolver();
		// 10.0.0.1 is valid trusted proxy → XFF extracted.
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	/**
	 * @test
	 * Invalid IPs in XFF are skipped.
	 */
	public function test_resolve_skips_invalid_ips_in_xff(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1' );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, not-an-ip, 10.0.0.1';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// Caching — proxy list is loaded once per instance
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Proxy config is cached within instance (multiple resolve() calls).
	 */
	public function test_resolve_caches_proxy_config(): void {
		$call_count = 0;
		Functions\when( 'get_option' )->alias( function () use ( &$call_count ) {
			++$call_count;
			return '10.0.0.1';
		} );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		$resolver = new IpResolver();
		$resolver->resolve();
		$resolver->resolve();

		// get_option should only be called once due to internal caching.
		$this->assertSame( 1, $call_count );
	}

	// ------------------------------------------------------------------
	// XFF spoofing protection
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: XFF with spoofed IPs — rightmost-untrusted protects against spoofing.
	 */
	public function test_resolve_rightmost_untrusted_prevents_spoofing(): void {
		Functions\when( 'get_option' )->justReturn( '10.0.0.1' );

		// Attacker sets XFF to fake IP, but real chain is: client → proxy (10.0.0.1).
		// XFF: "fake-ip, real-client-ip, 10.0.0.1"
		// Rightmost-untrusted: skip 10.0.0.1 (trusted) → real-client-ip is returned.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.50, 10.0.0.1';

		$resolver = new IpResolver();
		// 203.0.113.50 is the rightmost untrusted IP (not the attacker-spoofed 1.1.1.1).
		$this->assertSame( '203.0.113.50', $resolver->resolve() );
	}

	// ------------------------------------------------------------------
	// Configuration sources — constant > option
	// IMPORTANT: This test MUST be LAST because define() persists for the
	// entire PHP process and cannot be undone. All tests above rely on
	// get_option() being the only proxy source.
	// ------------------------------------------------------------------

	/**
	 * @test
	 * SEC-KANN-01: wp-config constant takes priority over wp_options.
	 */
	public function test_resolve_uses_constant_over_option(): void {
		if ( defined( 'DSGVO_FORM_TRUSTED_PROXIES' ) ) {
			$this->markTestSkipped( 'DSGVO_FORM_TRUSTED_PROXIES already defined.' );
		}

		define( 'DSGVO_FORM_TRUSTED_PROXIES', '192.168.1.0/24' );

		// get_option should NOT be used when constant is defined.
		Functions\when( 'get_option' )->justReturn( '10.0.0.0/8' );

		$_SERVER['REMOTE_ADDR']          = '192.168.1.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';

		$resolver = new IpResolver();
		$this->assertSame( '203.0.113.10', $resolver->resolve() );
	}
}
