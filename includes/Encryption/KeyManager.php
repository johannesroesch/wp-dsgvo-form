<?php
declare(strict_types=1);

namespace WpDsgvoForm\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Manages encryption keys for the DSGVO Form plugin.
 *
 * Handles KEK (Key Encryption Key) access from wp-config.php,
 * DEK (Data Encryption Key) generation and envelope encryption,
 * and HMAC key derivation for email lookup hashes.
 *
 * Security requirements: SEC-ENC-01 through SEC-ENC-15.
 */
class KeyManager {

	/**
	 * Name of the wp-config.php constant holding the base64-encoded KEK.
	 */
	private const KEK_CONSTANT = 'DSGVO_FORM_ENCRYPTION_KEY';

	/**
	 * Context string for HMAC key derivation (SEC-ENC-14).
	 */
	private const HMAC_CONTEXT = 'dsgvo_form_lookup_hash_key';

	private const CIPHER_METHOD = 'aes-256-gcm';
	private const IV_LENGTH     = 12;
	private const KEY_LENGTH    = 32;
	private const TAG_LENGTH    = 16;

	/**
	 * Checks whether the KEK (Master Key) is configured and available.
	 *
	 * @return bool True if the KEK constant is defined and non-empty.
	 */
	public function is_kek_available(): bool {
		if ( ! defined( self::KEK_CONSTANT ) ) {
			return false;
		}

		$value = constant( self::KEK_CONSTANT );

		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Returns the decoded KEK (Key Encryption Key) from wp-config.php.
	 *
	 * The KEK must be a base64-encoded 256-bit (32 byte) key stored as
	 * the DSGVO_FORM_ENCRYPTION_KEY constant. (SEC-ENC-01, SEC-ENC-03)
	 *
	 * @return string Raw 32-byte KEK.
	 * @throws \RuntimeException If KEK is not configured or invalid.
	 */
	public function get_kek(): string {
		if ( ! $this->is_kek_available() ) {
			throw new \RuntimeException(
				esc_html( self::KEK_CONSTANT ) . ' is not defined in wp-config.php. '
				. 'The encryption system is disabled. (SEC-ENC-04)'
			);
		}

		$kek_base64 = constant( self::KEK_CONSTANT );
		$kek        = base64_decode( $kek_base64, true );

		if ( false === $kek || strlen( $kek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException(
				esc_html( self::KEK_CONSTANT ) . ' must be a valid base64-encoded 256-bit key '
				. '(32 bytes raw, 44 characters base64). (SEC-ENC-03)'
			);
		}

		return $kek;
	}

	/**
	 * Generates a new random DEK (Data Encryption Key).
	 *
	 * Uses cryptographically secure random bytes. (SEC-ENC-03)
	 *
	 * @return string Raw 32-byte DEK.
	 */
	public function generate_dek(): string {
		return random_bytes( self::KEY_LENGTH );
	}

	/**
	 * Encrypts a DEK with the KEK using AES-256-GCM (Envelope Encryption).
	 *
	 * The authentication tag is appended to the ciphertext before base64 encoding,
	 * matching the dsgvo_forms.encrypted_dek column format.
	 *
	 * @param string $dek Raw 32-byte DEK to encrypt.
	 * @return array{encrypted_dek: string, dek_iv: string} Base64-encoded values for DB storage.
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt_dek( string $dek ): array {
		if ( strlen( $dek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException( 'DEK must be exactly 32 bytes.' );
		}

		$kek = $this->get_kek();
		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$dek,
			self::CIPHER_METHOD,
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'DEK encryption failed: ' . esc_html( (string) openssl_error_string() ) );
		}

		return array(
			'encrypted_dek' => base64_encode( $ciphertext . $tag ),
			'dek_iv'        => base64_encode( $iv ),
		);
	}

	/**
	 * Decrypts a DEK using the KEK.
	 *
	 * @param string $encrypted_dek_base64 Base64-encoded ciphertext+tag from dsgvo_forms.encrypted_dek.
	 * @param string $dek_iv_base64        Base64-encoded IV from dsgvo_forms.dek_iv.
	 * @return string Raw 32-byte DEK.
	 * @throws \RuntimeException If decryption fails or data is corrupted.
	 */
	public function decrypt_dek( string $encrypted_dek_base64, string $dek_iv_base64 ): string {
		$kek = $this->get_kek();

		$encrypted_with_tag = base64_decode( $encrypted_dek_base64, true );
		$iv                 = base64_decode( $dek_iv_base64, true );

		if ( false === $encrypted_with_tag || false === $iv ) {
			throw new \RuntimeException( 'Invalid base64 encoding in DEK data.' );
		}

		if ( strlen( $iv ) !== self::IV_LENGTH ) {
			throw new \RuntimeException(
				'Invalid IV length: expected ' . (int) self::IV_LENGTH . ' bytes, '
				. 'got ' . strlen( $iv ) . '.'
			);
		}

		$min_length = self::KEY_LENGTH + self::TAG_LENGTH;
		if ( strlen( $encrypted_with_tag ) < $min_length ) {
			throw new \RuntimeException( 'Encrypted DEK data is too short.' );
		}

		$tag        = substr( $encrypted_with_tag, -self::TAG_LENGTH );
		$ciphertext = substr( $encrypted_with_tag, 0, -self::TAG_LENGTH );

		$dek = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $dek ) {
			throw new \RuntimeException(
				'DEK decryption failed. The master key may be incorrect '
				. 'or the data may have been tampered with.'
			);
		}

		return $dek;
	}

	/**
	 * Derives the HMAC key from the KEK using HMAC-SHA256 with a fixed context.
	 *
	 * Per SEC-ENC-14: The HMAC key MUST be derived from the encryption key,
	 * NOT use the encryption key directly. This prevents key-reuse vulnerabilities.
	 *
	 * @return string Raw 32-byte HMAC key.
	 * @throws \RuntimeException If KEK is not available.
	 */
	public function derive_hmac_key(): string {
		$kek = $this->get_kek();

		return hash_hmac( 'sha256', self::HMAC_CONTEXT, $kek, true );
	}

	/**
	 * Calculates the HMAC-SHA256 lookup hash for an email address.
	 *
	 * Used for DSGVO data subject requests (Art. 15, 17, 20 DSGVO) per SEC-ENC-13.
	 * Allows searching for submissions by email without decrypting all records.
	 *
	 * @param string $email The email address to hash.
	 * @return string Hex-encoded HMAC-SHA256 hash for DB storage.
	 */
	public function calculate_lookup_hash( string $email ): string {
		$hmac_key         = $this->derive_hmac_key();
		$email_normalized = strtolower( trim( $email ) );

		return hash_hmac( 'sha256', $email_normalized, $hmac_key );
	}

	/**
	 * Decrypts a DEK using an explicit KEK (for key rotation).
	 *
	 * Unlike decrypt_dek(), this accepts the KEK directly instead of
	 * reading from the DSGVO_FORM_ENCRYPTION_KEY constant.
	 *
	 * @param string $kek                  Raw 32-byte KEK.
	 * @param string $encrypted_dek_base64 Base64-encoded ciphertext+tag from dsgvo_forms.encrypted_dek.
	 * @param string $dek_iv_base64        Base64-encoded IV from dsgvo_forms.dek_iv.
	 * @return string Raw 32-byte DEK.
	 * @throws \RuntimeException If decryption fails or data is corrupted.
	 *
	 * @security-critical SEC-SOLL-02 — KEK rotation support
	 */
	public function decrypt_dek_with_kek( string $kek, string $encrypted_dek_base64, string $dek_iv_base64 ): string {
		if ( strlen( $kek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException( 'KEK must be exactly 32 bytes.' );
		}

		$encrypted_with_tag = base64_decode( $encrypted_dek_base64, true );
		$iv                 = base64_decode( $dek_iv_base64, true );

		if ( false === $encrypted_with_tag || false === $iv ) {
			throw new \RuntimeException( 'Invalid base64 encoding in DEK data.' );
		}

		if ( strlen( $iv ) !== self::IV_LENGTH ) {
			throw new \RuntimeException(
				'Invalid IV length: expected ' . (int) self::IV_LENGTH . ' bytes, '
				. 'got ' . strlen( $iv ) . '.'
			);
		}

		$min_length = self::KEY_LENGTH + self::TAG_LENGTH;
		if ( strlen( $encrypted_with_tag ) < $min_length ) {
			throw new \RuntimeException( 'Encrypted DEK data is too short.' );
		}

		$tag        = substr( $encrypted_with_tag, -self::TAG_LENGTH );
		$ciphertext = substr( $encrypted_with_tag, 0, -self::TAG_LENGTH );

		$dek = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $dek ) {
			throw new \RuntimeException(
				'DEK decryption failed. The provided key may be incorrect '
				. 'or the data may have been tampered with.'
			);
		}

		return $dek;
	}

	/**
	 * Encrypts a DEK using an explicit KEK (for key rotation).
	 *
	 * Unlike encrypt_dek(), this accepts the KEK directly instead of
	 * reading from the DSGVO_FORM_ENCRYPTION_KEY constant.
	 *
	 * @param string $kek Raw 32-byte KEK.
	 * @param string $dek Raw 32-byte DEK to encrypt.
	 * @return array{encrypted_dek: string, dek_iv: string} Base64-encoded values for DB storage.
	 * @throws \RuntimeException If encryption fails.
	 *
	 * @security-critical SEC-SOLL-02 — KEK rotation support
	 */
	public function encrypt_dek_with_kek( string $kek, string $dek ): array {
		if ( strlen( $kek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException( 'KEK must be exactly 32 bytes.' );
		}

		if ( strlen( $dek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException( 'DEK must be exactly 32 bytes.' );
		}

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$dek,
			self::CIPHER_METHOD,
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'DEK encryption failed: ' . esc_html( (string) openssl_error_string() ) );
		}

		return array(
			'encrypted_dek' => base64_encode( $ciphertext . $tag ),
			'dek_iv'        => base64_encode( $iv ),
		);
	}

	/**
	 * Derives the HMAC key from an explicit KEK (for key rotation).
	 *
	 * Unlike derive_hmac_key(), this accepts the KEK directly.
	 *
	 * @param string $kek Raw 32-byte KEK.
	 * @return string Raw 32-byte HMAC key.
	 *
	 * @security-critical SEC-SOLL-02 — KEK rotation support
	 */
	public function derive_hmac_key_from_kek( string $kek ): string {
		if ( strlen( $kek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException( 'KEK must be exactly 32 bytes.' );
		}

		return hash_hmac( 'sha256', self::HMAC_CONTEXT, $kek, true );
	}

	/**
	 * Calculates the HMAC-SHA256 lookup hash using an explicit HMAC key.
	 *
	 * Unlike calculate_lookup_hash(), this accepts the HMAC key directly
	 * instead of deriving it from the KEK constant.
	 *
	 * @param string $hmac_key Raw 32-byte HMAC key.
	 * @param string $email    The email address to hash.
	 * @return string Hex-encoded HMAC-SHA256 hash for DB storage.
	 *
	 * @security-critical SEC-SOLL-02 — KEK rotation support
	 */
	public function calculate_lookup_hash_with_key( string $hmac_key, string $email ): string {
		$email_normalized = strtolower( trim( $email ) );

		return hash_hmac( 'sha256', $email_normalized, $hmac_key );
	}

	/**
	 * Returns the name of the KEK constant for diagnostic purposes.
	 *
	 * @return string The constant name.
	 */
	public function get_kek_constant_name(): string {
		return self::KEK_CONSTANT;
	}
}
