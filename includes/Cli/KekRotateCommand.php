<?php
/**
 * WP-CLI command for KEK (Key Encryption Key) rotation.
 *
 * Provides a command-line interface for the KEK rotation mechanism
 * as described in SEC-ENC-15 (incident response procedure).
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Cli;

defined( 'ABSPATH' ) || exit;

use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\KekRotation;
use WpDsgvoForm\Encryption\KeyManager;

/**
 * Manages DSGVO form encryption keys.
 *
 * ## EXAMPLES
 *
 *     # Preview rotation with auto-generated key
 *     wp dsgvo-form rotate-kek --generate --dry-run
 *
 *     # Rotate with a specific new key
 *     wp dsgvo-form rotate-kek --new-key=<base64>
 *
 *     # Rotate and rehash email lookup hashes
 *     wp dsgvo-form rotate-kek --new-key=<base64> --rehash-lookups
 *
 * @security-critical SEC-SOLL-02 — KEK rotation via CLI
 */
class KekRotateCommand {

	/**
	 * Rotate the KEK and re-encrypt all form DEKs.
	 *
	 * Re-wraps all form Data Encryption Keys (DEKs) from the old KEK
	 * (read from DSGVO_FORM_ENCRYPTION_KEY constant) to a new KEK.
	 * Submissions and files remain unchanged — only the DEK wrapping changes.
	 *
	 * IMPORTANT: After rotation, update wp-config.php with the new key.
	 * Until updated, the plugin will use the old key and decryption will fail.
	 *
	 * ## OPTIONS
	 *
	 * [--new-key=<base64>]
	 * : The new base64-encoded 256-bit encryption key.
	 *
	 * [--generate]
	 * : Auto-generate a cryptographically secure random key.
	 *
	 * [--dry-run]
	 * : Validate all DEKs can be rotated without writing changes.
	 *
	 * [--rehash-lookups]
	 * : Also re-compute email_lookup_hash values (required for data subject search).
	 *   The HMAC key is derived from the KEK, so changing the KEK invalidates
	 *   existing lookup hashes. This decrypts all submissions to extract emails.
	 *
	 * [--batch-size=<number>]
	 * : Submissions per batch for rehash (default: 100).
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run with auto-generated key
	 *     wp dsgvo-form rotate-kek --generate --dry-run
	 *
	 *     # Full rotation with lookup rehash
	 *     wp dsgvo-form rotate-kek --generate --rehash-lookups
	 *
	 *     # Rotation with specific key
	 *     wp dsgvo-form rotate-kek --new-key=<base64-key> --rehash-lookups
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param string[] $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$generate   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'generate', false );
		$rehash     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'rehash-lookups', false );
		$new_key    = $assoc_args['new-key'] ?? '';
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );

		// SEC-SOLL-06: Cap batch size to prevent excessive memory usage.
		if ( $batch_size < 1 || $batch_size > 500 ) {
			$batch_size = min( max( $batch_size, 1 ), 500 );
			\WP_CLI::warning( sprintf( '--batch-size capped to %d (valid range: 1–500).', $batch_size ) );
		}

		if ( $generate && '' !== $new_key ) {
			\WP_CLI::error( 'Cannot use --generate and --new-key together.' );
		}

		if ( ! $generate && '' === $new_key ) {
			\WP_CLI::error( 'Provide --new-key=<base64> or use --generate.' );
		}

		if ( $generate ) {
			$new_key = KekRotation::generate_kek();
			\WP_CLI::log( sprintf( 'Generated new KEK: %s', $new_key ) );
		}

		$key_manager  = new KeyManager();
		$audit_logger = new AuditLogger();
		$rotation     = new KekRotation( $key_manager, $audit_logger );

		if ( $dry_run ) {
			\WP_CLI::log( '--- DRY RUN (no changes will be made) ---' );
		}

		// Step 1: Rotate form DEKs.
		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Step 1: Re-wrapping form DEKs ===' );

		try {
			$result = $rotation->rotate( $new_key, $dry_run );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return; // Unreachable, but satisfies static analysis.
		}

		\WP_CLI::log( sprintf( 'Forms total:   %d', $result['forms_total'] ) );
		\WP_CLI::log( sprintf( 'Forms rotated: %d', $result['forms_rotated'] ) );

		foreach ( $result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		if ( ! $result['success'] ) {
			\WP_CLI::error( 'KEK rotation failed. No changes were applied (rolled back).' );
			return;
		}

		if ( $dry_run ) {
			$this->output_dry_run_summary( $rotation, $result );
			return;
		}

		// Step 2: Rehash lookup hashes (optional).
		if ( $rehash ) {
			$this->execute_rehash( $rotation, $new_key, $batch_size );
		} elseif ( $rotation->has_lookup_hashes() ) {
			\WP_CLI::warning(
				'Email lookup hashes exist but --rehash-lookups was not specified. '
				. 'Data subject search (Art. 15/17) will not work until hashes are recomputed. '
				. 'Re-run with --rehash-lookups after updating wp-config.php, or run now with the same new key.'
			);
		}

		// Step 3: Output instructions.
		\WP_CLI::log( '' );
		\WP_CLI::success( 'KEK rotation complete.' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'IMPORTANT: Update wp-config.php with the new key:' );
		\WP_CLI::log(
			sprintf(
				"define( 'DSGVO_FORM_ENCRYPTION_KEY', '%s' );",
				$result['new_kek_base64']
			)
		);
		\WP_CLI::log( '' );
		\WP_CLI::warning(
			'Until wp-config.php is updated, the plugin will use the OLD key and decryption will FAIL.'
		);
	}

	/**
	 * Outputs dry-run summary with lookup hash warning.
	 */
	private function output_dry_run_summary( KekRotation $rotation, array $result ): void {
		\WP_CLI::success( 'Dry run passed. All DEKs can be rotated.' );

		if ( $rotation->has_lookup_hashes() ) {
			\WP_CLI::warning(
				'Email lookup hashes exist. After rotation, add --rehash-lookups to re-compute them.'
			);
		}
	}

	/**
	 * Executes the HMAC lookup hash rehash step.
	 */
	private function execute_rehash( KekRotation $rotation, string $new_key, int $batch_size ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Step 2: Re-computing email lookup hashes ===' );

		$progress  = null;
		$last_tick = 0;

		$rehash_result = $rotation->rehash_lookups(
			$new_key,
			$batch_size,
			static function ( int $processed, int $total ) use ( &$progress, &$last_tick ): void {
				if ( null === $progress ) {
					$progress = \WP_CLI\Utils\make_progress_bar( 'Rehashing lookups', $total );
				}
				// UX-BUG-01: tick() expects delta, not absolute value.
				$progress->tick( $processed - $last_tick );
				$last_tick = $processed;
			}
		);

		if ( null !== $progress ) {
			$progress->finish();
		}

		\WP_CLI::log( sprintf( 'Submissions total:    %d', $rehash_result['submissions_total'] ) );
		\WP_CLI::log( sprintf( 'Submissions rehashed: %d', $rehash_result['submissions_rehashed'] ) );
		\WP_CLI::log( sprintf( 'Submissions skipped:  %d', $rehash_result['submissions_skipped'] ) );

		foreach ( $rehash_result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		if ( $rehash_result['success'] ) {
			\WP_CLI::success( 'Lookup hashes re-computed.' );
		} else {
			\WP_CLI::warning( 'Some lookup hashes could not be re-computed. See errors above.' );
		}
	}
}
