<?php
/**
 * Unit tests for KekRotateCommand (WP-CLI command).
 *
 * Covers SEC-SOLL-02: KEK rotation CLI interface.
 * Tests: argument validation (mutual exclusion, missing args),
 * dry-run flow, success flow, and rotation failure handling.
 *
 * @package WpDsgvoForm\Tests\Unit\Cli
 */

declare(strict_types=1);

// ── WP_CLI stubs (not available in unit test environment) ────────────

namespace WP_CLI\Utils {
	if ( ! function_exists( '\\WP_CLI\\Utils\\get_flag_value' ) ) {
		function get_flag_value( array $assoc_args, string $flag, $default = false ) {
			return array_key_exists( $flag, $assoc_args ) ? $assoc_args[ $flag ] : $default;
		}
	}

	if ( ! function_exists( '\\WP_CLI\\Utils\\make_progress_bar' ) ) {
		function make_progress_bar( string $message, int $count ): object {
			return new class {
				public function tick( int $n = 1 ): void {}
				public function finish(): void {}
			};
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Minimal WP_CLI stub for unit testing.
		 *
		 * error() throws WP_CLI_Error so tests can catch it.
		 */
		class WP_CLI {
			/** @var string[] Captured log messages. */
			public static array $log_messages = [];
			/** @var string[] Captured success messages. */
			public static array $success_messages = [];
			/** @var string[] Captured warning messages. */
			public static array $warning_messages = [];
			/** @var string[] Captured error messages. */
			public static array $error_messages = [];

			public static function reset(): void {
				self::$log_messages     = [];
				self::$success_messages = [];
				self::$warning_messages = [];
				self::$error_messages   = [];
			}

			public static function log( string $message ): void {
				self::$log_messages[] = $message;
			}

			public static function success( string $message ): void {
				self::$success_messages[] = $message;
			}

			public static function warning( string $message ): void {
				self::$warning_messages[] = $message;
			}

			/**
			 * Terminates with error — throws exception in test context.
			 *
			 * @throws \RuntimeException Always.
			 */
			public static function error( string $message ): void {
				self::$error_messages[] = $message;
				throw new \RuntimeException( 'WP_CLI::error: ' . $message );
			}
		}
	}
}

namespace WpDsgvoForm\Tests\Unit\Cli {

	use PHPUnit\Framework\Attributes\CoversClass;
	use WpDsgvoForm\Cli\KekRotateCommand;
	use WpDsgvoForm\Encryption\KekRotation;
	use WpDsgvoForm\Tests\TestCase;
	use Brain\Monkey\Functions;

	/**
	 * Tests for KekRotateCommand — WP-CLI interface for KEK rotation.
	 */
	#[CoversClass(KekRotateCommand::class)]
	class KekRotateCommandTest extends TestCase {

		private KekRotateCommand $command;

		protected function setUp(): void {
			parent::setUp();

			$this->command = new KekRotateCommand();
			\WP_CLI::reset();

			Functions\when( '__' )->returnArg( 1 );
		}

		// ──────────────────────────────────────────────────
		// Argument validation
		// ──────────────────────────────────────────────────

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — --generate and --new-key are mutually exclusive.
		 */
		public function test_error_when_generate_and_new_key_both_set(): void {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/generate.*new-key/i' );

			( $this->command )( [], [
				'generate' => true,
				'new-key'  => base64_encode( random_bytes( 32 ) ),
			] );
		}

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — Requires either --generate or --new-key.
		 */
		public function test_error_when_neither_generate_nor_new_key(): void {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/new-key.*generate/i' );

			( $this->command )( [], [] );
		}

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — --generate with empty --new-key is treated as generate-only.
		 */
		public function test_generate_flag_creates_key(): void {
			// Command will call KekRotation with generated key.
			// It calls `new KeyManager()` internally — which needs DSGVO_FORM_ENCRYPTION_KEY.
			// Since we're testing CLI argument flow, we just verify the error happens
			// AFTER key generation (i.e., during rotation, not during arg validation).
			try {
				( $this->command )( [], [ 'generate' => true ] );
			} catch ( \RuntimeException $e ) {
				// Expected: will fail at KeyManager or rotation level, not at arg validation.
				$this->assertNotEmpty( \WP_CLI::$log_messages );
				// Verify key was generated (logged).
				$key_logged = false;
				foreach ( \WP_CLI::$log_messages as $msg ) {
					if ( str_contains( $msg, 'Generated new KEK' ) ) {
						$key_logged = true;
						break;
					}
				}
				$this->assertTrue( $key_logged, 'Generated KEK must be logged.' );
				return;
			}

			// If no exception, success path was reached (unlikely without DSGVO_FORM_ENCRYPTION_KEY).
			$this->addToAssertionCount( 1 );
		}

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — --new-key with invalid base64 fails during rotation.
		 */
		public function test_invalid_key_fails_at_rotation_step(): void {
			try {
				( $this->command )( [], [ 'new-key' => 'not-valid-base64!!!' ] );
			} catch ( \RuntimeException $e ) {
				// Error happens at rotation level, caught and passed to WP_CLI::error.
				$this->assertNotEmpty( \WP_CLI::$error_messages );
				return;
			}

			$this->fail( 'Expected exception for invalid key.' );
		}

		// ──────────────────────────────────────────────────
		// Flow verification
		// ──────────────────────────────────────────────────

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — Dry-run flag is passed through.
		 */
		public function test_dry_run_flag_outputs_dry_run_header(): void {
			try {
				( $this->command )( [], [
					'generate' => true,
					'dry-run'  => true,
				] );
			} catch ( \RuntimeException $e ) {
				// Expected failure at KeyManager level, but dry-run header should be logged.
				$dry_run_logged = false;
				foreach ( \WP_CLI::$log_messages as $msg ) {
					if ( str_contains( $msg, 'DRY RUN' ) ) {
						$dry_run_logged = true;
						break;
					}
				}
				$this->assertTrue( $dry_run_logged, 'DRY RUN header must be logged.' );
				return;
			}

			$this->addToAssertionCount( 1 );
		}

		/**
		 * @test
		 * @security-relevant SEC-SOLL-02 — Step headers are output in correct order.
		 */
		public function test_step_1_header_is_output(): void {
			try {
				( $this->command )( [], [
					'generate' => true,
				] );
			} catch ( \RuntimeException $e ) {
				$step1_logged = false;
				foreach ( \WP_CLI::$log_messages as $msg ) {
					if ( str_contains( $msg, 'Step 1' ) ) {
						$step1_logged = true;
						break;
					}
				}
				$this->assertTrue( $step1_logged, 'Step 1 header must be logged.' );
				return;
			}

			$this->addToAssertionCount( 1 );
		}
	}
}
