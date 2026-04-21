<?php
/**
 * KEK (Key Encryption Key) rotation mechanism.
 *
 * Re-wraps all form DEKs with a new KEK and optionally re-computes
 * email lookup hashes (HMAC key is derived from KEK).
 *
 * SEC-ENC-15: Key rotation process for KEK compromise incident response.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Encryption;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Models\Submission;

/**
 * Handles KEK rotation by re-encrypting all form DEKs.
 *
 * Process (SEC-ENC-15):
 * 1. Decrypt each form's DEK with old KEK
 * 2. Re-encrypt each DEK with new KEK
 * 3. Verify round-trip integrity
 * 4. Update database atomically (transaction)
 * 5. Optionally re-compute email_lookup_hash (HMAC key changes with KEK)
 * 6. Audit-log the rotation
 *
 * Post-rotation: Admin MUST update DSGVO_FORM_ENCRYPTION_KEY in wp-config.php.
 *
 * @security-critical SEC-SOLL-02 — KEK rotation mechanism
 */
class KekRotation {

	private const KEY_LENGTH = 32;

	private KeyManager $key_manager;
	private AuditLogger $audit_logger;

	public function __construct( KeyManager $key_manager, AuditLogger $audit_logger ) {
		$this->key_manager  = $key_manager;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Rotates the KEK by re-encrypting all form DEKs.
	 *
	 * @param string $new_kek_base64 Base64-encoded new 256-bit KEK.
	 * @param bool   $dry_run        If true, validate without writing changes.
	 * @return array{
	 *     success: bool,
	 *     forms_total: int,
	 *     forms_rotated: int,
	 *     errors: string[],
	 *     new_kek_base64: string
	 * }
	 * @throws \RuntimeException If old KEK is not available or new KEK is invalid.
	 */
	public function rotate( string $new_kek_base64, bool $dry_run = false ): array {
		$old_kek = $this->key_manager->get_kek();
		$new_kek = $this->validate_and_decode_kek( $new_kek_base64 );

		try {

		if ( $old_kek === $new_kek ) {
			throw new \RuntimeException( 'New KEK must be different from current KEK.' );
		}

		$forms = Form::find_all();

		$result = [
			'success'        => true,
			'forms_total'    => count( $forms ),
			'forms_rotated'  => 0,
			'errors'         => [],
			'new_kek_base64' => $new_kek_base64,
		];

		if ( empty( $forms ) ) {
			return $result;
		}

		if ( $dry_run ) {
			return $this->dry_run_rotate( $forms, $old_kek, $new_kek, $result );
		}

		return $this->execute_rotate( $forms, $old_kek, $new_kek, $result );

		} finally {
			// SEC-SOLL-05: Clear KEK material from memory.
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $old_kek );
				sodium_memzero( $new_kek );
			}
		}
	}

	/**
	 * Re-computes email_lookup_hash for all submissions after KEK rotation.
	 *
	 * Since the HMAC key is derived from the KEK (SEC-ENC-14), changing the KEK
	 * invalidates all existing lookup hashes. This method decrypts each submission
	 * to extract the email, then re-hashes with the new HMAC key.
	 *
	 * Must run BEFORE wp-config.php is updated (old KEK still in constant),
	 * because form DEKs in the DB are already re-encrypted with new KEK.
	 *
	 * @param string $new_kek_base64 Base64-encoded new KEK (same as used in rotate()).
	 * @param int    $batch_size     Submissions per batch (default 100).
	 * @param callable|null $progress_callback Called with (int $processed, int $total) per batch.
	 * @return array{
	 *     success: bool,
	 *     submissions_total: int,
	 *     submissions_rehashed: int,
	 *     submissions_skipped: int,
	 *     errors: string[]
	 * }
	 */
	public function rehash_lookups(
		string $new_kek_base64,
		int $batch_size = 100,
		?callable $progress_callback = null
	): array {
		$new_kek      = $this->validate_and_decode_kek( $new_kek_base64 );
		$new_hmac_key = $this->key_manager->derive_hmac_key_from_kek( $new_kek );

		try {

		$result = [
			'success'                 => true,
			'submissions_total'       => 0,
			'submissions_rehashed'    => 0,
			'submissions_skipped'     => 0,
			'errors'                  => [],
		];

		// Load all forms and decrypt their DEKs with NEW KEK (DB already has re-wrapped DEKs).
		$forms = Form::find_all();
		$deks  = [];

		foreach ( $forms as $form ) {
			try {
				$deks[ $form->id ] = $this->key_manager->decrypt_dek_with_kek(
					$new_kek,
					$form->encrypted_dek,
					$form->dek_iv
				);
			} catch ( \Throwable $e ) {
				$result['errors'][] = sprintf(
					'Form #%d (%s): Cannot decrypt DEK — %s',
					$form->id,
					$form->title,
					$e->getMessage()
				);
				$result['success'] = false;
			}
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		// Count total submissions with lookup hashes.
		global $wpdb;
		$sub_table = Submission::get_table_name();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$sub_table}` WHERE email_lookup_hash IS NOT NULL AND email_lookup_hash != %s",
				''
			)
		);

		$result['submissions_total'] = $total;

		if ( $total === 0 ) {
			return $result;
		}

		// Process in batches.
		$encryption = new EncryptionService( $this->key_manager );
		$offset     = 0;

		while ( $offset < $total ) {
			$submissions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, form_id, encrypted_data, iv, auth_tag, email_lookup_hash FROM `{$sub_table}` WHERE email_lookup_hash IS NOT NULL AND email_lookup_hash != %s ORDER BY id ASC LIMIT %d OFFSET %d",
					'',
					$batch_size,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $submissions ) ) {
				break;
			}

			foreach ( $submissions as $row ) {
				$sub_id  = (int) $row['id'];
				$form_id = (int) $row['form_id'];

				if ( ! isset( $deks[ $form_id ] ) ) {
					++$result['submissions_skipped'];
					continue;
				}

				try {
					$json = $encryption->decrypt(
						$row['encrypted_data'],
						$row['iv'],
						$row['auth_tag'],
						$deks[ $form_id ]
					);

					$data  = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
					$email = $this->find_email_in_data( $data );

					if ( $email === null ) {
						++$result['submissions_skipped'];
						continue;
					}

					$new_hash = $this->key_manager->calculate_lookup_hash_with_key( $new_hmac_key, $email );

					$wpdb->update(
						$sub_table,
						[ 'email_lookup_hash' => $new_hash ],
						[ 'id' => $sub_id ],
						[ '%s' ],
						[ '%d' ]
					);

					++$result['submissions_rehashed'];
				} catch ( \Throwable $e ) {
					++$result['submissions_skipped'];
					$result['errors'][] = sprintf(
						'Submission #%d: %s',
						$sub_id,
						$e->getMessage()
					);
				}
			}

			$offset += $batch_size;

			if ( $progress_callback !== null ) {
				$progress_callback( min( $offset, $total ), $total );
			}
		}

		// Clear DEKs from memory.
		foreach ( $deks as &$dek ) {
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $dek );
			}
		}
		unset( $dek );

		// Audit log.
		$this->audit_logger->log(
			get_current_user_id(),
			'kek_rotation_rehash',
			null,
			null,
			sprintf(
				'Lookup hashes recomputed: %d rehashed, %d skipped, %d errors',
				$result['submissions_rehashed'],
				$result['submissions_skipped'],
				count( $result['errors'] )
			)
		);

		return $result;

		} finally {
			// SEC-SOLL-05: Clear KEK and HMAC key material from memory.
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $new_kek );
				sodium_memzero( $new_hmac_key );
			}
		}
	}

	/**
	 * Checks whether any submissions have email_lookup_hash values.
	 *
	 * @return bool True if lookup hashes exist that would be invalidated by rotation.
	 */
	public function has_lookup_hashes(): bool {
		global $wpdb;
		$table = Submission::get_table_name();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE email_lookup_hash IS NOT NULL AND email_lookup_hash != %s",
				''
			)
		);

		return $count > 0;
	}

	/**
	 * Generates a new random KEK.
	 *
	 * @return string Base64-encoded 256-bit key (44 characters).
	 */
	public static function generate_kek(): string {
		return base64_encode( random_bytes( self::KEY_LENGTH ) );
	}

	/**
	 * Validates and decodes a base64-encoded KEK.
	 *
	 * @throws \RuntimeException If the key is invalid.
	 */
	private function validate_and_decode_kek( string $kek_base64 ): string {
		$kek = base64_decode( $kek_base64, true );

		if ( $kek === false || strlen( $kek ) !== self::KEY_LENGTH ) {
			throw new \RuntimeException(
				'KEK must be a valid base64-encoded 256-bit key '
				. '(32 bytes raw, 44 characters base64).'
			);
		}

		return $kek;
	}

	/**
	 * Dry-run: validates all DEKs can be decrypted and re-encrypted.
	 *
	 * @param Form[]               $forms   All forms.
	 * @param string               $old_kek Raw old KEK.
	 * @param string               $new_kek Raw new KEK.
	 * @param array<string, mixed> $result  Result array to populate.
	 * @return array<string, mixed> Populated result.
	 */
	private function dry_run_rotate( array $forms, string $old_kek, string $new_kek, array $result ): array {
		foreach ( $forms as $form ) {
			try {
				$dek = $this->key_manager->decrypt_dek_with_kek(
					$old_kek,
					$form->encrypted_dek,
					$form->dek_iv
				);

				$re_encrypted = $this->key_manager->encrypt_dek_with_kek( $new_kek, $dek );

				$verified_dek = $this->key_manager->decrypt_dek_with_kek(
					$new_kek,
					$re_encrypted['encrypted_dek'],
					$re_encrypted['dek_iv']
				);

				if ( ! hash_equals( $dek, $verified_dek ) ) {
					throw new \RuntimeException( 'DEK verification failed after re-encryption.' );
				}

				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $dek );
					sodium_memzero( $verified_dek );
				}

				++$result['forms_rotated'];
			} catch ( \Throwable $e ) {
				$result['errors'][] = sprintf(
					'Form #%d (%s): %s',
					$form->id,
					$form->title,
					$e->getMessage()
				);
				$result['success'] = false;
			}
		}

		return $result;
	}

	/**
	 * Executes KEK rotation with transaction and rollback support.
	 *
	 * Uses MySQL transaction to ensure atomicity: either all form DEKs
	 * are re-wrapped or none are (rollback on any failure).
	 *
	 * @param Form[]               $forms   All forms.
	 * @param string               $old_kek Raw old KEK.
	 * @param string               $new_kek Raw new KEK.
	 * @param array<string, mixed> $result  Result array to populate.
	 * @return array<string, mixed> Populated result.
	 */
	private function execute_rotate( array $forms, string $old_kek, string $new_kek, array $result ): array {
		global $wpdb;
		$table = Form::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction control not available via $wpdb API.
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $forms as $form ) {
				$dek = $this->key_manager->decrypt_dek_with_kek(
					$old_kek,
					$form->encrypted_dek,
					$form->dek_iv
				);

				$re_encrypted = $this->key_manager->encrypt_dek_with_kek( $new_kek, $dek );

				// Verify round-trip before committing.
				$verified_dek = $this->key_manager->decrypt_dek_with_kek(
					$new_kek,
					$re_encrypted['encrypted_dek'],
					$re_encrypted['dek_iv']
				);

				if ( ! hash_equals( $dek, $verified_dek ) ) {
					throw new \RuntimeException(
						sprintf( 'Form #%d: DEK verification failed after re-encryption.', $form->id )
					);
				}

				// Clear sensitive material from memory.
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $dek );
					sodium_memzero( $verified_dek );
				}

				$updated = $wpdb->update(
					$table,
					[
						'encrypted_dek' => $re_encrypted['encrypted_dek'],
						'dek_iv'        => $re_encrypted['dek_iv'],
					],
					[ 'id' => $form->id ],
					[ '%s', '%s' ],
					[ '%d' ]
				);

				if ( $updated === false ) {
					throw new \RuntimeException(
						sprintf( 'Form #%d: DB update failed: %s', $form->id, esc_html( $wpdb->last_error ) )
					);
				}

				// Invalidate form cache (transients may hold old encrypted DEK).
				Form::invalidate_cache( $form->id );

				++$result['forms_rotated'];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction control.
			$wpdb->query( 'COMMIT' );

			// SEC-AUDIT-01: Audit-log successful rotation.
			$this->audit_logger->log(
				get_current_user_id(),
				'kek_rotation',
				null,
				null,
				sprintf( 'KEK rotated successfully: %d forms re-encrypted', $result['forms_rotated'] )
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction rollback.
			$wpdb->query( 'ROLLBACK' );

			$result['errors'][] = $e->getMessage();
			$result['success']  = false;

			// SEC-AUDIT-01: Audit-log failed rotation.
			$this->audit_logger->log(
				get_current_user_id(),
				'kek_rotation',
				null,
				null,
				sprintf( 'KEK rotation FAILED (rolled back): %s', $e->getMessage() )
			);
		}

		return $result;
	}

	/**
	 * Searches decrypted submission data for an email field value.
	 *
	 * Mirrors SubmitEndpoint::find_email_in_data() logic for consistency.
	 *
	 * @param array<string, mixed> $data Decrypted field values.
	 * @return string|null The first email address found, or null.
	 */
	private function find_email_in_data( array $data ): ?string {
		foreach ( $data as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return null;
	}
}
