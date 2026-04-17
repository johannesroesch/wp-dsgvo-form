<?php
/**
 * File encryption adapter.
 *
 * Delegates file-level encryption to EncryptionService with pack/unpack helpers.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Upload;

defined('ABSPATH') || exit;

use WpDsgvoForm\Encryption\EncryptionService;

/**
 * Handles encryption and decryption of uploaded files.
 *
 * Provides a focused API for file-level encryption operations,
 * delegating the cryptographic work to EncryptionService.
 * Each file receives its own DEK (Data Encryption Key), which is
 * encrypted with the form's DEK for storage.
 *
 * Architecture reference: ARCHITECTURE.md §3.4
 * Security requirements: SEC-FILE-07, SEC-ENC-10.
 */
class FileEncryptor
{

	private EncryptionService $encryption;

	/**
	 * @param EncryptionService $encryption Encryption service instance.
	 */
	public function __construct(EncryptionService $encryption)
	{
		$this->encryption = $encryption;
	}

	/**
	 * Encrypts raw file contents with a fresh per-file DEK.
	 *
	 * The per-file DEK is encrypted with the form DEK (envelope encryption).
	 *
	 * @param string $file_contents              Raw file content.
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @return array{encrypted_content: string, iv: string, tag: string, encrypted_key: string}
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt(
		string $file_contents,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64
	): array {
		return $this->encryption->encrypt_file(
			$file_contents,
			$encrypted_form_dek_base64,
			$form_dek_iv_base64
		);
	}

	/**
	 * Decrypts encrypted file contents using the file's encrypted DEK.
	 *
	 * @param string $encrypted_content_base64   Base64-encoded encrypted file content.
	 * @param string $iv_base64                  Base64-encoded IV.
	 * @param string $tag_base64                 Base64-encoded authentication tag.
	 * @param string $encrypted_key_base64       Base64-encoded encrypted file-DEK (packed).
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @return string Raw decrypted file contents.
	 * @throws \RuntimeException If decryption fails or data was tampered with.
	 */
	public function decrypt(
		string $encrypted_content_base64,
		string $iv_base64,
		string $tag_base64,
		string $encrypted_key_base64,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64
	): string {
		return $this->encryption->decrypt_file(
			$encrypted_content_base64,
			$iv_base64,
			$tag_base64,
			$encrypted_key_base64,
			$encrypted_form_dek_base64,
			$form_dek_iv_base64
		);
	}

	/**
	 * Packs encrypted file components into a single binary blob for disk storage.
	 *
	 * Format: ciphertext + iv (12 bytes) + tag (16 bytes).
	 * This format is used when storing the encrypted file on the filesystem.
	 *
	 * @param array{encrypted_content: string, iv: string, tag: string} $encrypted
	 *     The encrypt() result (base64-encoded components).
	 * @return string Raw binary blob for file_put_contents().
	 * @throws \RuntimeException If base64 decoding fails.
	 */
	public function pack_for_storage(array $encrypted): string
	{
		$content = base64_decode($encrypted['encrypted_content'], true);
		$iv      = base64_decode($encrypted['iv'], true);
		$tag     = base64_decode($encrypted['tag'], true);

		if ($content === false || $iv === false || $tag === false) {
			throw new \RuntimeException('Invalid base64 encoding in encrypted file data.');
		}

		return $content . $iv . $tag;
	}

	/**
	 * Unpacks a binary blob from disk back into base64-encoded components.
	 *
	 * Reverses pack_for_storage() so the components can be passed to decrypt().
	 *
	 * @param string $blob Raw binary blob from file_get_contents().
	 * @return array{encrypted_content: string, iv: string, tag: string}
	 *     Base64-encoded components ready for decrypt().
	 * @throws \RuntimeException If blob is too short.
	 */
	public function unpack_from_storage(string $blob): array
	{
		$iv_length  = 12;
		$tag_length = 16;
		$blob_len   = strlen($blob);
		$min_length = $iv_length + $tag_length + 1;

		if ($blob_len < $min_length) {
			throw new \RuntimeException('Encrypted file data is too short to unpack.');
		}

		$ciphertext_len = $blob_len - $iv_length - $tag_length;

		return [
			'encrypted_content' => base64_encode(substr($blob, 0, $ciphertext_len)),
			'iv'                => base64_encode(substr($blob, $ciphertext_len, $iv_length)),
			'tag'               => base64_encode(substr($blob, $ciphertext_len + $iv_length, $tag_length)),
		];
	}

	/**
	 * Checks whether the encryption system is available.
	 *
	 * @return bool True if encryption can be performed.
	 */
	public function is_available(): bool
	{
		return $this->encryption->is_available();
	}
}
