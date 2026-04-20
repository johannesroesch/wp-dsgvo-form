<?php
declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpDsgvoForm\Encryption\KeyManager;

/**
 * Unit tests for KeyManager.
 *
 * Coverage target: 100% (Security-critical code per QUALITY_STANDARDS.md Section 5).
 *
 * Tests KEK access from wp-config.php, DEK envelope encryption,
 * HMAC key derivation, and email lookup hash (SEC-ENC-01 through SEC-ENC-15).
 */
#[CoversClass(KeyManager::class)]
class KeyManagerTest extends TestCase
{

	private KeyManager $key_manager;

	protected function setUp(): void
	{
		// Load escaping stubs (esc_html/esc_attr) for separate-process tests.
		// Brain\Monkey tests handle this via Patchwork; we need real stubs here.
		require_once dirname( __DIR__, 2 ) . '/stubs/escaping.php';

		// Do NOT define KEK here — separate-process tests need it absent.
		$this->key_manager = new KeyManager();
	}

	/**
	 * Defines the test KEK constant if not already defined.
	 *
	 * Uses a fixed 32-byte key for deterministic tests.
	 * Test data only — no real encryption keys.
	 */
	private static function define_test_kek(): void
	{
		if ( ! defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) ) {
			define( 'DSGVO_FORM_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 32 ) ) );
		}
	}

	// ─── KEK availability (SEC-ENC-04: fail-closed) ─────────

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_is_kek_available_returns_false_when_not_configured(): void
	{
		$km = new KeyManager();
		$this->assertFalse( $km->is_kek_available() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_is_kek_available_returns_false_for_empty_string(): void
	{
		define( 'DSGVO_FORM_ENCRYPTION_KEY', '' );
		$km = new KeyManager();
		$this->assertFalse( $km->is_kek_available() );
	}

	public function test_is_kek_available_returns_true_when_configured(): void
	{
		self::define_test_kek();
		$this->assertTrue( $this->key_manager->is_kek_available() );
	}

	// ─── KEK retrieval (SEC-ENC-01, SEC-ENC-03) ─────────────

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_kek_throws_runtime_exception_when_not_configured(): void
	{
		$km = new KeyManager();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not defined in wp-config\.php/' );
		$km->get_kek();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_kek_throws_on_invalid_base64_key(): void
	{
		define( 'DSGVO_FORM_ENCRYPTION_KEY', '!!!not-valid-base64!!!' );
		$km = new KeyManager();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/valid base64-encoded 256-bit key/' );
		$km->get_kek();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_get_kek_throws_on_wrong_length_key(): void
	{
		define( 'DSGVO_FORM_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 16 ) ) );
		$km = new KeyManager();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/valid base64-encoded 256-bit key/' );
		$km->get_kek();
	}

	public function test_get_kek_returns_raw_32_byte_key(): void
	{
		self::define_test_kek();
		$kek = $this->key_manager->get_kek();
		$this->assertSame( 32, strlen( $kek ) );
		$this->assertSame( str_repeat( "\x01", 32 ), $kek );
	}

	public function test_get_kek_constant_name_returns_expected_value(): void
	{
		$this->assertSame( 'DSGVO_FORM_ENCRYPTION_KEY', $this->key_manager->get_kek_constant_name() );
	}

	// ─── DEK generation (SEC-ENC-03) ────────────────────────

	public function test_generate_dek_returns_32_random_bytes(): void
	{
		$dek = $this->key_manager->generate_dek();
		$this->assertSame( 32, strlen( $dek ) );
	}

	public function test_generate_dek_produces_unique_keys_per_call(): void
	{
		$dek1 = $this->key_manager->generate_dek();
		$dek2 = $this->key_manager->generate_dek();
		$this->assertNotSame( $dek1, $dek2 );
	}

	// ─── DEK envelope encryption (SEC-ENC-05/06) ────────────

	public function test_encrypt_dek_returns_base64_encoded_components(): void
	{
		self::define_test_kek();
		$dek    = $this->key_manager->generate_dek();
		$result = $this->key_manager->encrypt_dek( $dek );

		$this->assertArrayHasKey( 'encrypted_dek', $result );
		$this->assertArrayHasKey( 'dek_iv', $result );
		$this->assertNotFalse( base64_decode( $result['encrypted_dek'], true ) );
		$this->assertNotFalse( base64_decode( $result['dek_iv'], true ) );
	}

	public function test_encrypt_dek_iv_is_12_bytes(): void
	{
		self::define_test_kek();
		$dek    = $this->key_manager->generate_dek();
		$result = $this->key_manager->encrypt_dek( $dek );
		$iv     = base64_decode( $result['dek_iv'], true );

		$this->assertSame( 12, strlen( $iv ) );
	}

	public function test_encrypt_dek_generates_unique_iv_per_call(): void
	{
		self::define_test_kek();
		$dek     = $this->key_manager->generate_dek();
		$result1 = $this->key_manager->encrypt_dek( $dek );
		$result2 = $this->key_manager->encrypt_dek( $dek );

		$this->assertNotSame( $result1['dek_iv'], $result2['dek_iv'] );
	}

	public function test_encrypt_decrypt_dek_roundtrip_preserves_original(): void
	{
		self::define_test_kek();
		$dek       = $this->key_manager->generate_dek();
		$encrypted = $this->key_manager->encrypt_dek( $dek );
		$decrypted = $this->key_manager->decrypt_dek(
			$encrypted['encrypted_dek'],
			$encrypted['dek_iv']
		);

		$this->assertSame( $dek, $decrypted );
	}

	public function test_decrypt_dek_with_tampered_data_throws_exception(): void
	{
		self::define_test_kek();
		$dek       = $this->key_manager->generate_dek();
		$encrypted = $this->key_manager->encrypt_dek( $dek );

		$tampered    = base64_decode( $encrypted['encrypted_dek'], true );
		$tampered[0] = chr( ord( $tampered[0] ) ^ 0xFF );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/decryption failed/i' );
		$this->key_manager->decrypt_dek( base64_encode( $tampered ), $encrypted['dek_iv'] );
	}

	public function test_decrypt_dek_with_invalid_base64_throws_exception(): void
	{
		self::define_test_kek();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid base64/' );
		$this->key_manager->decrypt_dek(
			'!!!invalid!!!',
			base64_encode( str_repeat( "\x00", 12 ) )
		);
	}

	public function test_decrypt_dek_with_wrong_iv_length_throws_exception(): void
	{
		self::define_test_kek();
		$dek       = $this->key_manager->generate_dek();
		$encrypted = $this->key_manager->encrypt_dek( $dek );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid IV length/' );
		$this->key_manager->decrypt_dek(
			$encrypted['encrypted_dek'],
			base64_encode( str_repeat( "\x00", 8 ) )
		);
	}

	public function test_decrypt_dek_with_short_data_throws_exception(): void
	{
		self::define_test_kek();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/too short/' );
		$this->key_manager->decrypt_dek(
			base64_encode( str_repeat( "\x00", 10 ) ),
			base64_encode( str_repeat( "\x00", 12 ) )
		);
	}

	public function test_encrypt_dek_rejects_invalid_length_dek(): void
	{
		self::define_test_kek();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/exactly 32 bytes/' );
		$this->key_manager->encrypt_dek( str_repeat( "\x01", 16 ) );
	}

	// ─── HMAC key derivation (SEC-ENC-14) ───────────────────

	public function test_derive_hmac_key_returns_32_bytes(): void
	{
		self::define_test_kek();
		$hmac_key = $this->key_manager->derive_hmac_key();
		$this->assertSame( 32, strlen( $hmac_key ) );
	}

	public function test_derive_hmac_key_is_different_from_kek(): void
	{
		self::define_test_kek();
		$kek      = $this->key_manager->get_kek();
		$hmac_key = $this->key_manager->derive_hmac_key();
		$this->assertNotSame( $kek, $hmac_key );
	}

	public function test_derive_hmac_key_is_deterministic(): void
	{
		self::define_test_kek();
		$key1 = $this->key_manager->derive_hmac_key();
		$key2 = $this->key_manager->derive_hmac_key();
		$this->assertSame( $key1, $key2 );
	}

	// ─── Lookup hash (SEC-ENC-13, DSGVO Art. 15/17) ────────

	public function test_calculate_lookup_hash_is_deterministic_for_same_email(): void
	{
		self::define_test_kek();
		$hash1 = $this->key_manager->calculate_lookup_hash( 'test@example.com' );
		$hash2 = $this->key_manager->calculate_lookup_hash( 'test@example.com' );
		$this->assertSame( $hash1, $hash2 );
	}

	public function test_calculate_lookup_hash_is_hex_encoded_64_chars(): void
	{
		self::define_test_kek();
		$hash = $this->key_manager->calculate_lookup_hash( 'test@example.com' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function test_calculate_lookup_hash_normalizes_email_case(): void
	{
		self::define_test_kek();
		$hash_lower = $this->key_manager->calculate_lookup_hash( 'test@example.com' );
		$hash_upper = $this->key_manager->calculate_lookup_hash( 'TEST@EXAMPLE.COM' );
		$hash_mixed = $this->key_manager->calculate_lookup_hash( 'Test@Example.COM' );
		$this->assertSame( $hash_lower, $hash_upper );
		$this->assertSame( $hash_lower, $hash_mixed );
	}

	public function test_calculate_lookup_hash_trims_whitespace(): void
	{
		self::define_test_kek();
		$hash_clean   = $this->key_manager->calculate_lookup_hash( 'test@example.com' );
		$hash_trimmed = $this->key_manager->calculate_lookup_hash( '  test@example.com  ' );
		$this->assertSame( $hash_clean, $hash_trimmed );
	}

	public function test_calculate_lookup_hash_differs_for_different_emails(): void
	{
		self::define_test_kek();
		$hash1 = $this->key_manager->calculate_lookup_hash( 'alice@example.com' );
		$hash2 = $this->key_manager->calculate_lookup_hash( 'bob@example.com' );
		$this->assertNotSame( $hash1, $hash2 );
	}
}
