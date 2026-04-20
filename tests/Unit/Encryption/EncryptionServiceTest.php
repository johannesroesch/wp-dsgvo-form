<?php
declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;

/**
 * Unit tests for EncryptionService.
 *
 * Coverage target: 100% (Security-critical code per QUALITY_STANDARDS.md Section 5).
 *
 * Tests AES-256-GCM encryption/decryption, submission envelope encryption,
 * file per-DEK encryption, and email lookup hash delegation (SEC-ENC-05 through SEC-ENC-12).
 */
#[CoversClass(EncryptionService::class)]
class EncryptionServiceTest extends TestCase
{

	private EncryptionService $service;
	private KeyManager $key_manager;

	protected function setUp(): void
	{
		// Load escaping stubs (esc_html/esc_attr) for separate-process tests.
		// Brain\Monkey tests handle this via Patchwork; we need real stubs here.
		require_once dirname( __DIR__, 2 ) . '/stubs/escaping.php';

		// Do NOT define KEK here — separate-process tests need it absent.
		$this->key_manager = new KeyManager();
		$this->service     = new EncryptionService( $this->key_manager );
	}

	/**
	 * Defines the test KEK constant if not already defined.
	 */
	private static function define_test_kek(): void
	{
		if ( ! defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) ) {
			define( 'DSGVO_FORM_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 32 ) ) );
		}
	}

	/**
	 * Returns a random 32-byte encryption key for testing.
	 */
	private function generate_test_key(): string
	{
		return random_bytes( 32 );
	}

	/**
	 * Creates encrypted DEK components for testing envelope encryption.
	 *
	 * @return array{encrypted_dek: string, dek_iv: string, raw_dek: string}
	 */
	private function create_test_dek(): array
	{
		self::define_test_kek();
		$dek       = $this->key_manager->generate_dek();
		$encrypted = $this->key_manager->encrypt_dek( $dek );
		return [
			'encrypted_dek' => $encrypted['encrypted_dek'],
			'dek_iv'        => $encrypted['dek_iv'],
			'raw_dek'       => $dek,
		];
	}

	// ─── Availability check (SEC-ENC-04: fail-closed) ───────

	public function test_is_available_returns_true_with_kek_and_openssl(): void
	{
		self::define_test_kek();
		$this->assertTrue( $this->service->is_available() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_is_available_returns_false_without_kek(): void
	{
		$km  = new KeyManager();
		$svc = new EncryptionService( $km );
		$this->assertFalse( $svc->is_available() );
	}

	// ─── Basic encrypt: output format ───────────────────────

	public function test_encrypt_returns_base64_encoded_components(): void
	{
		$key    = $this->generate_test_key();
		$result = $this->service->encrypt( 'test data', $key );

		$this->assertArrayHasKey( 'ciphertext', $result );
		$this->assertArrayHasKey( 'iv', $result );
		$this->assertArrayHasKey( 'tag', $result );
		$this->assertNotFalse( base64_decode( $result['ciphertext'], true ) );
		$this->assertNotFalse( base64_decode( $result['iv'], true ) );
		$this->assertNotFalse( base64_decode( $result['tag'], true ) );
	}

	public function test_encrypt_generates_12_byte_iv(): void
	{
		$key    = $this->generate_test_key();
		$result = $this->service->encrypt( 'test', $key );
		$iv     = base64_decode( $result['iv'], true );

		$this->assertSame( 12, strlen( $iv ) );
	}

	public function test_encrypt_generates_16_byte_auth_tag(): void
	{
		$key    = $this->generate_test_key();
		$result = $this->service->encrypt( 'test', $key );
		$tag    = base64_decode( $result['tag'], true );

		$this->assertSame( 16, strlen( $tag ) );
	}

	// ─── Encrypt/decrypt roundtrip (SEC-ENC-06) ─────────────

	public function test_encrypt_decrypt_roundtrip_preserves_plaintext(): void
	{
		$key       = $this->generate_test_key();
		$plaintext = 'Sensitive form data with Umlaute: äöüß';

		$encrypted = $this->service->encrypt( $plaintext, $key );
		$decrypted = $this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			$encrypted['tag'],
			$key
		);

		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_encrypt_generates_unique_iv_per_call(): void
	{
		$key     = $this->generate_test_key();
		$result1 = $this->service->encrypt( 'same data', $key );
		$result2 = $this->service->encrypt( 'same data', $key );

		$this->assertNotSame( $result1['iv'], $result2['iv'] );
	}

	public function test_encrypt_produces_different_ciphertext_for_same_plaintext(): void
	{
		$key     = $this->generate_test_key();
		$result1 = $this->service->encrypt( 'same data', $key );
		$result2 = $this->service->encrypt( 'same data', $key );

		$this->assertNotSame( $result1['ciphertext'], $result2['ciphertext'] );
	}

	public function test_encrypt_with_empty_string_roundtrips(): void
	{
		$key       = $this->generate_test_key();
		$encrypted = $this->service->encrypt( '', $key );
		$decrypted = $this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			$encrypted['tag'],
			$key
		);

		$this->assertSame( '', $decrypted );
	}

	public function test_encrypt_with_unicode_data_roundtrips_correctly(): void
	{
		$key       = $this->generate_test_key();
		$plaintext = "日本語テスト Ünïcödé Ñoño";

		$encrypted = $this->service->encrypt( $plaintext, $key );
		$decrypted = $this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			$encrypted['tag'],
			$key
		);

		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_encrypt_with_large_data_roundtrips(): void
	{
		$key       = $this->generate_test_key();
		$plaintext = str_repeat( 'A', 1024 * 1024 );

		$encrypted = $this->service->encrypt( $plaintext, $key );
		$decrypted = $this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			$encrypted['tag'],
			$key
		);

		$this->assertSame( $plaintext, $decrypted );
	}

	// ─── Encrypt error handling ─────────────────────────────

	public function test_encrypt_with_invalid_key_length_throws_exception(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/exactly 32 bytes/' );
		$this->service->encrypt( 'test', str_repeat( "\x01", 16 ) );
	}

	public function test_encrypt_with_empty_key_throws_exception(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/exactly 32 bytes/' );
		$this->service->encrypt( 'test', '' );
	}

	// ─── Decrypt error handling (tamper detection) ──────────

	public function test_decrypt_with_wrong_key_throws_exception(): void
	{
		$key1      = $this->generate_test_key();
		$key2      = $this->generate_test_key();
		$encrypted = $this->service->encrypt( 'secret', $key1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Decryption failed/' );
		$this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			$encrypted['tag'],
			$key2
		);
	}

	public function test_decrypt_with_tampered_ciphertext_throws_exception(): void
	{
		$key       = $this->generate_test_key();
		$encrypted = $this->service->encrypt( 'secret data', $key );

		$ciphertext    = base64_decode( $encrypted['ciphertext'], true );
		$ciphertext[0] = chr( ord( $ciphertext[0] ) ^ 0xFF );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Decryption failed/' );
		$this->service->decrypt(
			base64_encode( $ciphertext ),
			$encrypted['iv'],
			$encrypted['tag'],
			$key
		);
	}

	public function test_decrypt_with_tampered_auth_tag_throws_exception(): void
	{
		$key       = $this->generate_test_key();
		$encrypted = $this->service->encrypt( 'secret data', $key );

		$tag    = base64_decode( $encrypted['tag'], true );
		$tag[0] = chr( ord( $tag[0] ) ^ 0xFF );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Decryption failed/' );
		$this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			base64_encode( $tag ),
			$key
		);
	}

	public function test_decrypt_with_invalid_iv_length_throws_exception(): void
	{
		$key       = $this->generate_test_key();
		$encrypted = $this->service->encrypt( 'test', $key );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid IV length/' );
		$this->service->decrypt(
			$encrypted['ciphertext'],
			base64_encode( str_repeat( "\x00", 8 ) ),
			$encrypted['tag'],
			$key
		);
	}

	public function test_decrypt_with_invalid_tag_length_throws_exception(): void
	{
		$key       = $this->generate_test_key();
		$encrypted = $this->service->encrypt( 'test', $key );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid authentication tag length/' );
		$this->service->decrypt(
			$encrypted['ciphertext'],
			$encrypted['iv'],
			base64_encode( str_repeat( "\x00", 8 ) ),
			$key
		);
	}

	public function test_decrypt_with_invalid_base64_throws_exception(): void
	{
		$key = $this->generate_test_key();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid base64/' );
		$this->service->decrypt(
			'!!!invalid!!!',
			base64_encode( str_repeat( "\x00", 12 ) ),
			base64_encode( str_repeat( "\x00", 16 ) ),
			$key
		);
	}

	// ─── Submission encryption (SEC-ENC-10, envelope) ───────

	public function test_encrypt_decrypt_submission_roundtrip(): void
	{
		$test_dek = $this->create_test_dek();
		$data     = [
			'name'    => 'Max Mustermann',
			'email'   => 'max@example.com',
			'message' => 'Test-Nachricht mit Ümlauten',
		];

		$encrypted = $this->service->encrypt_submission(
			$data,
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$this->assertArrayHasKey( 'encrypted_data', $encrypted );
		$this->assertArrayHasKey( 'iv', $encrypted );
		$this->assertArrayHasKey( 'auth_tag', $encrypted );

		$decrypted = $this->service->decrypt_submission(
			$encrypted['encrypted_data'],
			$encrypted['iv'],
			$encrypted['auth_tag'],
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$this->assertSame( $data, $decrypted );
	}

	public function test_encrypt_submission_produces_unique_iv_per_call(): void
	{
		$test_dek = $this->create_test_dek();
		$data     = [ 'field' => 'value' ];

		$result1 = $this->service->encrypt_submission( $data, $test_dek['encrypted_dek'], $test_dek['dek_iv'] );
		$result2 = $this->service->encrypt_submission( $data, $test_dek['encrypted_dek'], $test_dek['dek_iv'] );

		$this->assertNotSame( $result1['iv'], $result2['iv'] );
		$this->assertNotSame( $result1['encrypted_data'], $result2['encrypted_data'] );
	}

	public function test_decrypt_submission_returns_associative_array(): void
	{
		$test_dek = $this->create_test_dek();
		$data     = [ 'key1' => 'val1', 'key2' => 42, 'key3' => true ];

		$encrypted = $this->service->encrypt_submission( $data, $test_dek['encrypted_dek'], $test_dek['dek_iv'] );
		$decrypted = $this->service->decrypt_submission(
			$encrypted['encrypted_data'],
			$encrypted['iv'],
			$encrypted['auth_tag'],
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$this->assertIsArray( $decrypted );
		$this->assertArrayHasKey( 'key1', $decrypted );
		$this->assertSame( 'val1', $decrypted['key1'] );
	}

	public function test_encrypted_submission_data_does_not_contain_plaintext(): void
	{
		$test_dek = $this->create_test_dek();
		$data     = [
			'email' => 'secret@example.com',
			'name'  => 'Secret Name',
		];

		$encrypted     = $this->service->encrypt_submission( $data, $test_dek['encrypted_dek'], $test_dek['dek_iv'] );
		$raw_encrypted = base64_decode( $encrypted['encrypted_data'], true );

		$this->assertStringNotContainsString( 'secret@example.com', $raw_encrypted );
		$this->assertStringNotContainsString( 'Secret Name', $raw_encrypted );
	}

	// ─── File encryption (per-file DEK, Architecture §3.4) ─

	public function test_encrypt_decrypt_file_roundtrip(): void
	{
		$test_dek     = $this->create_test_dek();
		$file_content = 'PDF file content simulation: ' . str_repeat( 'X', 256 );

		$encrypted = $this->service->encrypt_file(
			$file_content,
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$this->assertArrayHasKey( 'encrypted_content', $encrypted );
		$this->assertArrayHasKey( 'iv', $encrypted );
		$this->assertArrayHasKey( 'tag', $encrypted );
		$this->assertArrayHasKey( 'encrypted_key', $encrypted );

		$decrypted = $this->service->decrypt_file(
			$encrypted['encrypted_content'],
			$encrypted['iv'],
			$encrypted['tag'],
			$encrypted['encrypted_key'],
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$this->assertSame( $file_content, $decrypted );
	}

	public function test_file_encryption_uses_unique_per_file_dek(): void
	{
		$test_dek = $this->create_test_dek();

		$result1 = $this->service->encrypt_file( 'file1', $test_dek['encrypted_dek'], $test_dek['dek_iv'] );
		$result2 = $this->service->encrypt_file( 'file1', $test_dek['encrypted_dek'], $test_dek['dek_iv'] );

		$this->assertNotSame( $result1['encrypted_key'], $result2['encrypted_key'] );
	}

	public function test_encrypted_file_content_does_not_contain_plaintext(): void
	{
		$test_dek     = $this->create_test_dek();
		$file_content = 'This is a recognizable secret file content string';

		$encrypted = $this->service->encrypt_file(
			$file_content,
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$raw = base64_decode( $encrypted['encrypted_content'], true );
		$this->assertStringNotContainsString( 'recognizable secret', $raw );
	}

	public function test_decrypt_file_with_tampered_key_throws_exception(): void
	{
		$test_dek = $this->create_test_dek();

		$encrypted = $this->service->encrypt_file(
			'secret file',
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);

		$packed    = base64_decode( $encrypted['encrypted_key'], true );
		$packed[0] = chr( ord( $packed[0] ) ^ 0xFF );

		$this->expectException( \RuntimeException::class );
		$this->service->decrypt_file(
			$encrypted['encrypted_content'],
			$encrypted['iv'],
			$encrypted['tag'],
			base64_encode( $packed ),
			$test_dek['encrypted_dek'],
			$test_dek['dek_iv']
		);
	}

	// ─── Email lookup hash delegation (SEC-ENC-13/14) ───────

	public function test_calculate_email_lookup_hash_delegates_to_key_manager(): void
	{
		self::define_test_kek();
		$email         = 'test@example.com';
		$expected_hash = $this->key_manager->calculate_lookup_hash( $email );
		$actual_hash   = $this->service->calculate_email_lookup_hash( $email );

		$this->assertSame( $expected_hash, $actual_hash );
	}

	// ─── KeyManager accessor ────────────────────────────────

	public function test_get_key_manager_returns_injected_instance(): void
	{
		$this->assertSame( $this->key_manager, $this->service->get_key_manager() );
	}
}
