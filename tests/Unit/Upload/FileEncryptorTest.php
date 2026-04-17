<?php
declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Upload\FileEncryptor;

/**
 * Unit tests for FileEncryptor.
 *
 * Tests pack/unpack binary blob format, encrypt/decrypt delegation,
 * error handling, and full roundtrip with real crypto.
 *
 * Security requirements: SEC-FILE-07, SEC-ENC-10 (per-file DEK encryption).
 */
#[CoversClass(FileEncryptor::class)]
class FileEncryptorTest extends TestCase
{

	private FileEncryptor $encryptor;
	private EncryptionService $encryption;
	private KeyManager $key_manager;

	protected function setUp(): void
	{
		self::define_test_kek();
		$this->key_manager = new KeyManager();
		$this->encryption  = new EncryptionService( $this->key_manager );
		$this->encryptor   = new FileEncryptor( $this->encryption );
	}

	private static function define_test_kek(): void
	{
		if ( ! defined( 'DSGVO_FORM_ENCRYPTION_KEY' ) ) {
			define( 'DSGVO_FORM_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 32 ) ) );
		}
	}

	/**
	 * Creates encrypted DEK components for testing.
	 *
	 * @return array{encrypted_dek: string, dek_iv: string}
	 */
	private function create_test_dek(): array
	{
		$dek = $this->key_manager->generate_dek();
		return $this->key_manager->encrypt_dek( $dek );
	}

	// ─── Pack/unpack roundtrip ──────────────────────────────────

	public function test_pack_unpack_roundtrip_preserves_components(): void
	{
		$form_dek = $this->create_test_dek();

		$encrypted = $this->encryptor->encrypt(
			'Test file content',
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$blob    = $this->encryptor->pack_for_storage( $encrypted );
		$unpacked = $this->encryptor->unpack_from_storage( $blob );

		$this->assertSame( $encrypted['encrypted_content'], $unpacked['encrypted_content'] );
		$this->assertSame( $encrypted['iv'], $unpacked['iv'] );
		$this->assertSame( $encrypted['tag'], $unpacked['tag'] );
	}

	public function test_pack_produces_binary_blob_with_expected_structure(): void
	{
		$form_dek = $this->create_test_dek();

		$encrypted = $this->encryptor->encrypt(
			'X',
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$blob = $this->encryptor->pack_for_storage( $encrypted );

		$content_len = strlen( base64_decode( $encrypted['encrypted_content'], true ) );
		// Blob = ciphertext + IV (12) + tag (16).
		$this->assertSame( $content_len + 12 + 16, strlen( $blob ) );
	}

	// ─── Pack/unpack error handling ─────────────────────────────

	public function test_pack_with_invalid_base64_throws_exception(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid base64/' );

		$this->encryptor->pack_for_storage( [
			'encrypted_content' => '!!!invalid!!!',
			'iv'                => base64_encode( str_repeat( "\x00", 12 ) ),
			'tag'               => base64_encode( str_repeat( "\x00", 16 ) ),
		] );
	}

	public function test_unpack_too_short_blob_throws_exception(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/too short/' );

		// Minimum = IV (12) + tag (16) + 1 byte ciphertext = 29 bytes.
		$this->encryptor->unpack_from_storage( str_repeat( "\x00", 28 ) );
	}

	// ─── Full encrypt/decrypt roundtrip with real crypto ────────

	public function test_encrypt_decrypt_full_roundtrip(): void
	{
		$form_dek     = $this->create_test_dek();
		$file_content = 'Sensitive PDF content: ' . str_repeat( 'ABC', 100 );

		$encrypted = $this->encryptor->encrypt(
			$file_content,
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$decrypted = $this->encryptor->decrypt(
			$encrypted['encrypted_content'],
			$encrypted['iv'],
			$encrypted['tag'],
			$encrypted['encrypted_key'],
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$this->assertSame( $file_content, $decrypted );
	}

	public function test_pack_unpack_then_decrypt_roundtrip(): void
	{
		$form_dek     = $this->create_test_dek();
		$file_content = 'File stored on disk simulation';

		// Encrypt → pack → store (simulated) → unpack → decrypt.
		$encrypted = $this->encryptor->encrypt(
			$file_content,
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$blob     = $this->encryptor->pack_for_storage( $encrypted );
		$unpacked = $this->encryptor->unpack_from_storage( $blob );

		$decrypted = $this->encryptor->decrypt(
			$unpacked['encrypted_content'],
			$unpacked['iv'],
			$unpacked['tag'],
			$encrypted['encrypted_key'],
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$this->assertSame( $file_content, $decrypted );
	}

	public function test_encrypted_blob_does_not_contain_plaintext(): void
	{
		$form_dek     = $this->create_test_dek();
		$file_content = 'This is a recognizable secret string in the file';

		$encrypted = $this->encryptor->encrypt(
			$file_content,
			$form_dek['encrypted_dek'],
			$form_dek['dek_iv']
		);

		$blob = $this->encryptor->pack_for_storage( $encrypted );
		$this->assertStringNotContainsString( 'recognizable secret', $blob );
	}

	// ─── Availability delegation ────────────────────────────────

	public function test_is_available_delegates_to_encryption_service(): void
	{
		$this->assertTrue( $this->encryptor->is_available() );
	}
}
