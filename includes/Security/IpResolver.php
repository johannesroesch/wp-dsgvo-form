<?php
/**
 * Trusted-Proxy-aware IP address resolver.
 *
 * Resolves the client IP address from the request, optionally
 * trusting X-Forwarded-For when the request originates from a
 * configured trusted proxy.
 *
 * SEC-KANN-01: Configurable trusted proxy list.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the real client IP behind reverse proxies.
 *
 * Configuration (choose one):
 *
 * 1. wp-config.php constant (recommended for infrastructure):
 *    define( 'DSGVO_FORM_TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12' );
 *
 * 2. wp_options (for admin UI configuration):
 *    Option key: wpdsgvo_trusted_proxies (comma-separated IPs/CIDRs)
 *
 * When no trusted proxies are configured, only REMOTE_ADDR is used.
 */
class IpResolver {

	/**
	 * wp-config.php constant for trusted proxy list.
	 */
	private const TRUSTED_PROXIES_CONSTANT = 'DSGVO_FORM_TRUSTED_PROXIES';

	/**
	 * wp_options key for trusted proxy list.
	 */
	private const TRUSTED_PROXIES_OPTION = 'wpdsgvo_trusted_proxies';

	/**
	 * Cached list of trusted proxy CIDRs/IPs.
	 *
	 * @var string[]|null
	 */
	private ?array $trusted_proxies = null;

	/**
	 * Resolves the client IP address.
	 *
	 * If REMOTE_ADDR is a trusted proxy, the rightmost untrusted IP
	 * from X-Forwarded-For is returned. Otherwise, REMOTE_ADDR is returned.
	 *
	 * @return string|null The validated IP address, or null if unavailable.
	 */
	public function resolve(): ?string {
		$remote_addr = $this->get_remote_addr();

		if ( null === $remote_addr ) {
			return null;
		}

		$proxies = $this->get_trusted_proxies();

		// No trusted proxies configured — use REMOTE_ADDR only (safe default).
		if ( empty( $proxies ) ) {
			return $remote_addr;
		}

		// REMOTE_ADDR is not a trusted proxy — use it directly.
		if ( ! $this->is_trusted( $remote_addr, $proxies ) ) {
			return $remote_addr;
		}

		// REMOTE_ADDR is trusted — extract client IP from X-Forwarded-For.
		return $this->extract_client_from_xff( $proxies ) ?? $remote_addr;
	}

	/**
	 * Returns the sanitized REMOTE_ADDR.
	 *
	 * @return string|null Validated IP or null.
	 */
	private function get_remote_addr(): ?string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
			return null;
		}

		return $ip;
	}

	/**
	 * Extracts the rightmost untrusted IP from X-Forwarded-For.
	 *
	 * Walks the header right-to-left (closest proxy first), skipping
	 * trusted proxies. The first non-trusted IP is the real client.
	 *
	 * @param string[] $proxies Trusted proxy list.
	 * @return string|null Client IP or null if all IPs are trusted.
	 */
	private function extract_client_from_xff( array $proxies ): ?string {
		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return null;
		}

		$xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$ips = array_map( 'trim', explode( ',', $xff ) );

		// Walk right-to-left: rightmost = closest proxy, leftmost = original client.
		$ips = array_reverse( $ips );

		foreach ( $ips as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
				continue;
			}

			// First non-trusted IP is the real client.
			if ( ! $this->is_trusted( $ip, $proxies ) ) {
				return $ip;
			}
		}

		return null;
	}

	/**
	 * Checks whether an IP address matches any trusted proxy entry.
	 *
	 * Supports both exact IPs and CIDR notation (IPv4 and IPv6).
	 *
	 * @param string   $ip      The IP to check.
	 * @param string[] $proxies List of trusted IPs/CIDRs.
	 * @return bool
	 */
	private function is_trusted( string $ip, array $proxies ): bool {
		foreach ( $proxies as $proxy ) {
			if ( $this->ip_matches_cidr( $ip, $proxy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if an IP matches a CIDR range or exact IP.
	 *
	 * @param string $ip   The IP address to check.
	 * @param string $cidr The CIDR range or exact IP (e.g., '10.0.0.0/8' or '127.0.0.1').
	 * @return bool
	 */
	private function ip_matches_cidr( string $ip, string $cidr ): bool {
		// Exact match (no slash = single IP).
		if ( ! str_contains( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );
		$bits              = (int) $bits;

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		// IPv4 vs IPv6 length mismatch.
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		// Build bitmask.
		$mask = str_repeat( "\xff", (int) ( $bits / 8 ) );

		if ( 0 !== $bits % 8 ) {
			$mask .= chr( 0xff << ( 8 - ( $bits % 8 ) ) & 0xff );
		}

		$mask = str_pad( $mask, strlen( $ip_bin ), "\x00" );

		return ( $ip_bin & $mask ) === ( $subnet_bin & $mask );
	}

	/**
	 * Returns the configured trusted proxy list.
	 *
	 * Priority: wp-config.php constant > wp_options.
	 *
	 * @return string[]
	 */
	private function get_trusted_proxies(): array {
		if ( null !== $this->trusted_proxies ) {
			return $this->trusted_proxies;
		}

		$raw = '';

		// 1. wp-config.php constant (highest priority).
		if ( defined( self::TRUSTED_PROXIES_CONSTANT ) ) {
			$raw = (string) constant( self::TRUSTED_PROXIES_CONSTANT );
		}

		// 2. Fallback to wp_options.
		if ( '' === $raw ) {
			$raw = (string) get_option( self::TRUSTED_PROXIES_OPTION, '' );
		}

		if ( '' === $raw ) {
			$this->trusted_proxies = array();
			return $this->trusted_proxies;
		}

		$entries = array_map( 'trim', explode( ',', $raw ) );
		$entries = array_filter(
			$entries,
			static function ( string $entry ): bool {
				// Validate: must be a valid IP or CIDR.
				if ( str_contains( $entry, '/' ) ) {
					[ $subnet, $bits ] = explode( '/', $entry, 2 );
					if ( filter_var( $subnet, FILTER_VALIDATE_IP ) === false || ! ctype_digit( $bits ) ) {
						return false;
					}
					$bits_int = (int) $bits;
					$max_bits = filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false ? 32 : 128;
					return $bits_int >= 0 && $bits_int <= $max_bits;
				}

				return filter_var( $entry, FILTER_VALIDATE_IP ) !== false;
			}
		);

		$this->trusted_proxies = array_values( $entries );

		return $this->trusted_proxies;
	}
}
