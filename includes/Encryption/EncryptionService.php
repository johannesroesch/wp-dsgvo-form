<?php
declare(strict_types=1);

namespace WpDsgvoForm\Encryption;

defined('ABSPATH') || exit;

/**
 * AES-256-GCM encryption service for the DSGVO Form plugin.
 *
 * Provides authenticated encryption for submission data and uploaded files
 * using envelope encryption (KEK → DEK → Data).
 *
 * Security requirements: SEC-ENC-05 through SEC-ENC-12.
 *
 * Storage formats (three distinct packings, each matching its DB schema):
 *
 * | Method             | Format                                       | DB columns              |
 * |--------------------|----------------------------------------------|-------------------------|
 * | encrypt()          | Separate base64: ciphertext, iv, tag         | 3 columns (submissions) |
 * | KeyManager::       | base64(ciphertext + tag) + separate base64(iv)| 2 columns (forms DEK)  |
 * |   encrypt_dek()    |                                              |                         |
 * | encrypt_raw_key()  | base64(iv + tag + ciphertext)                | 1 column (file DEK)     |
 *
 * All formats are cryptographically equivalent (AES-256-GCM); the packing
 * varies to match the number of available DB columns per entity.
 */
class EncryptionService {

	private const CIPHER_METHOD = 'aes-256-gcm';
	private const IV_LENGTH     = 12;
	private const KEY_LENGTH    = 32;
	private const TAG_LENGTH    = 16;

	private KeyManager $key_manager;

	/**
	 * @param KeyManager $key_manager Key management service.
	 */
	public function __construct(KeyManager $key_manager) {
		$this->key_manager = $key_manager;
	}

	/**
	 * Checks whether the encryption system is fully operational.
	 *
	 * Returns false (fail-closed per SEC-ENC-04) if the KEK is missing
	 * or the required OpenSSL cipher is not available.
	 *
	 * @return bool True if encryption can be performed.
	 */
	public function is_available(): bool {
		if (!$this->key_manager->is_kek_available()) {
			return false;
		}

		if (!in_array(self::CIPHER_METHOD, openssl_get_cipher_methods(), true)) {
			return false;
		}

		return true;
	}

	/**
	 * Encrypts plaintext using AES-256-GCM with a random IV.
	 *
	 * Each call generates a fresh IV (SEC-ENC-06). Returns separate
	 * base64-encoded components matching the DB schema columns.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @param string $key       Raw 256-bit encryption key.
	 * @return array{ciphertext: string, iv: string, tag: string} Base64-encoded values.
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt(string $plaintext, string $key): array {
		$this->validate_key($key);

		$iv  = random_bytes(self::IV_LENGTH);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ($ciphertext === false) {
			throw new \RuntimeException('Encryption failed: ' . esc_html( (string) openssl_error_string() ));
		}

		return [
			'ciphertext' => base64_encode($ciphertext),
			'iv'         => base64_encode($iv),
			'tag'        => base64_encode($tag),
		];
	}

	/**
	 * Decrypts AES-256-GCM ciphertext.
	 *
	 * Verifies the authentication tag to detect tampering (SEC-ENC-05).
	 *
	 * @param string $ciphertext_base64 Base64-encoded ciphertext.
	 * @param string $iv_base64         Base64-encoded initialization vector.
	 * @param string $tag_base64        Base64-encoded GCM authentication tag.
	 * @param string $key               Raw 256-bit encryption key.
	 * @return string Decrypted plaintext.
	 * @throws \RuntimeException If decryption fails or data was tampered with.
	 */
	public function decrypt(
		string $ciphertext_base64,
		string $iv_base64,
		string $tag_base64,
		string $key
	): string {
		$this->validate_key($key);

		$ciphertext = base64_decode($ciphertext_base64, true);
		$iv         = base64_decode($iv_base64, true);
		$tag        = base64_decode($tag_base64, true);

		if ($ciphertext === false || $iv === false || $tag === false) {
			throw new \RuntimeException('Invalid base64 encoding in encrypted data.');
		}

		if (strlen($iv) !== self::IV_LENGTH) {
			throw new \RuntimeException(
				'Invalid IV length: expected ' . (int) self::IV_LENGTH
				. ' bytes, got ' . strlen($iv) . '.'
			);
		}

		if (strlen($tag) !== self::TAG_LENGTH) {
			throw new \RuntimeException(
				'Invalid authentication tag length: expected ' . (int) self::TAG_LENGTH
				. ' bytes, got ' . strlen($tag) . '.'
			);
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ($plaintext === false) {
			throw new \RuntimeException(
				'Decryption failed. The key may be incorrect or the data '
				. 'may have been tampered with.'
			);
		}

		return $plaintext;
	}

	/**
	 * Encrypts submission data for DB storage.
	 *
	 * JSON-encodes the field values and encrypts with the form's DEK (SEC-ENC-10).
	 * Returns values matching the dsgvo_submissions table columns.
	 *
	 * @param array<string, mixed> $data                   Form field values as associative array.
	 * @param string $encrypted_dek_base64   Base64-encoded encrypted DEK from dsgvo_forms.
	 * @param string $dek_iv_base64          Base64-encoded DEK IV from dsgvo_forms.
	 * @return array{encrypted_data: string, iv: string, auth_tag: string} Base64-encoded values.
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt_submission(
		array $data,
		string $encrypted_dek_base64,
		string $dek_iv_base64
	): array {
		$dek  = $this->key_manager->decrypt_dek($encrypted_dek_base64, $dek_iv_base64);
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		$result = $this->encrypt($json, $dek);

		return [
			'encrypted_data' => $result['ciphertext'],
			'iv'             => $result['iv'],
			'auth_tag'       => $result['tag'],
		];
	}

	/**
	 * Decrypts submission data from DB storage.
	 *
	 * @param string $encrypted_data_base64  Base64-encoded ciphertext from dsgvo_submissions.
	 * @param string $iv_base64              Base64-encoded IV from dsgvo_submissions.
	 * @param string $auth_tag_base64        Base64-encoded auth tag from dsgvo_submissions.
	 * @param string $encrypted_dek_base64   Base64-encoded encrypted DEK from dsgvo_forms.
	 * @param string $dek_iv_base64          Base64-encoded DEK IV from dsgvo_forms.
	 * @return array<string, mixed> Decrypted form field values.
	 * @throws \RuntimeException If decryption fails or JSON is invalid.
	 */
	public function decrypt_submission(
		string $encrypted_data_base64,
		string $iv_base64,
		string $auth_tag_base64,
		string $encrypted_dek_base64,
		string $dek_iv_base64
	): array {
		$dek  = $this->key_manager->decrypt_dek($encrypted_dek_base64, $dek_iv_base64);
		$json = $this->decrypt($encrypted_data_base64, $iv_base64, $auth_tag_base64, $dek);

		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		if (!is_array($data)) {
			throw new \RuntimeException('Decrypted submission data is not a valid JSON object.');
		}

		return $data;
	}

	/**
	 * Encrypts file contents with a per-file DEK.
	 *
	 * Architecture (§3.4): Each file gets its own DEK, which is encrypted
	 * with the form's DEK for storage in dsgvo_submission_files.encrypted_key.
	 *
	 * @param string $file_contents              Raw file content.
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @return array{encrypted_content: string, iv: string, tag: string, encrypted_key: string}
	 */
	public function encrypt_file(
		string $file_contents,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64
	): array {
		$form_dek = $this->key_manager->decrypt_dek(
			$encrypted_form_dek_base64,
			$form_dek_iv_base64
		);

		$file_dek  = $this->key_manager->generate_dek();
		$encrypted = $this->encrypt($file_contents, $file_dek);

		$encrypted_file_dek = $this->encrypt_raw_key($file_dek, $form_dek);

		return [
			'encrypted_content' => $encrypted['ciphertext'],
			'iv'                => $encrypted['iv'],
			'tag'               => $encrypted['tag'],
			'encrypted_key'     => $encrypted_file_dek,
		];
	}

	/**
	 * Decrypts file contents.
	 *
	 * @param string $encrypted_content_base64   Base64-encoded encrypted file content.
	 * @param string $iv_base64                  Base64-encoded IV.
	 * @param string $tag_base64                 Base64-encoded authentication tag.
	 * @param string $encrypted_key_base64       Base64-encoded encrypted file-DEK (packed).
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @return string Raw file contents.
	 */
	public function decrypt_file(
		string $encrypted_content_base64,
		string $iv_base64,
		string $tag_base64,
		string $encrypted_key_base64,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64
	): string {
		$form_dek = $this->key_manager->decrypt_dek(
			$encrypted_form_dek_base64,
			$form_dek_iv_base64
		);

		$file_dek = $this->decrypt_raw_key($encrypted_key_base64, $form_dek);

		return $this->decrypt($encrypted_content_base64, $iv_base64, $tag_base64, $file_dek);
	}

	/**
	 * Calculates the HMAC-SHA256 lookup hash for an email address.
	 *
	 * Convenience method that delegates to KeyManager (SEC-ENC-13/14).
	 *
	 * @param string $email The email address to hash.
	 * @return string Hex-encoded HMAC-SHA256 hash.
	 */
	public function calculate_email_lookup_hash(string $email): string {
		return $this->key_manager->calculate_lookup_hash($email);
	}

	/**
	 * Returns the KeyManager instance.
	 *
	 * @return KeyManager
	 */
	public function get_key_manager(): KeyManager {
		return $this->key_manager;
	}

	/**
	 * Encrypts a raw key with another key, packing IV+tag+ciphertext into one base64 blob.
	 *
	 * Used for file-DEK encryption with form-DEK.
	 *
	 * @param string $key_to_encrypt Raw key bytes to encrypt.
	 * @param string $wrapping_key   Raw key bytes to encrypt with.
	 * @return string Base64-encoded packed blob (iv + tag + ciphertext).
	 * @throws \RuntimeException If encryption fails.
	 */
	private function encrypt_raw_key(string $key_to_encrypt, string $wrapping_key): string {
		$this->validate_key($wrapping_key);

		$iv  = random_bytes(self::IV_LENGTH);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$key_to_encrypt,
			self::CIPHER_METHOD,
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ($ciphertext === false) {
			throw new \RuntimeException('Key encryption failed: ' . esc_html( (string) openssl_error_string() ));
		}

		return base64_encode($iv . $tag . $ciphertext);
	}

	/**
	 * Decrypts a raw key from a packed base64 blob (iv + tag + ciphertext).
	 *
	 * @param string $packed_base64 Base64-encoded packed blob.
	 * @param string $wrapping_key  Raw key bytes to decrypt with.
	 * @return string Raw decrypted key bytes.
	 * @throws \RuntimeException If decryption fails.
	 */
	private function decrypt_raw_key(string $packed_base64, string $wrapping_key): string {
		$this->validate_key($wrapping_key);

		$packed = base64_decode($packed_base64, true);

		if ($packed === false) {
			throw new \RuntimeException('Invalid base64 encoding in encrypted key.');
		}

		$min_length = self::IV_LENGTH + self::TAG_LENGTH + 1;
		if (strlen($packed) < $min_length) {
			throw new \RuntimeException('Encrypted key data is too short.');
		}

		$iv         = substr($packed, 0, self::IV_LENGTH);
		$tag        = substr($packed, self::IV_LENGTH, self::TAG_LENGTH);
		$ciphertext = substr($packed, self::IV_LENGTH + self::TAG_LENGTH);

		$key = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ($key === false) {
			throw new \RuntimeException(
				'Key decryption failed. The wrapping key may be incorrect '
				. 'or the data may have been tampered with.'
			);
		}

		return $key;
	}

	/**
	 * Validates that a key is the correct length.
	 *
	 * @param string $key Raw key bytes.
	 * @throws \RuntimeException If key length is incorrect.
	 */
	private function validate_key(string $key): void {
		if (strlen($key) !== self::KEY_LENGTH) {
			throw new \RuntimeException(
				'Encryption key must be exactly ' . (int) self::KEY_LENGTH . ' bytes (256 bits), '
				. 'got ' . strlen($key) . '.'
			);
		}
	}
}
