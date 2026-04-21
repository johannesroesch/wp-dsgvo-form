<?php
/**
 * Unit tests for KekRotation (Key Encryption Key rotation).
 *
 * Covers SEC-SOLL-02: KEK rotation mechanism.
 * Tests: validate_and_decode_kek, generate_kek, rotate (dry-run + real),
 * execute_rotate (transaction + rollback), has_lookup_hashes.
 *
 * @package WpDsgvoForm\Tests\Unit\Encryption
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\KekRotation;
use WpDsgvoForm\Encryption\KeyManager;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for KekRotation — KEK rotation mechanism (SEC-SOLL-02).
 */
#[CoversClass(KekRotation::class)]
class KekRotationTest extends TestCase {

	private KeyManager $key_manager;
	private AuditLogger $audit_logger;
	private KekRotation $rotation;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->key_manager  = Mockery::mock( KeyManager::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );
		$this->audit_logger->shouldReceive( 'log' )->byDefault()->andReturn( true );
		$this->rotation     = new KekRotation( $this->key_manager, $this->audit_logger );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$this->wpdb->shouldReceive( 'prepare' )->byDefault()->andReturn( 'SQL' );
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Helper: generates a valid 32-byte KEK, base64-encoded.
	 */
	private function generate_kek(): string {
		return base64_encode( random_bytes( 32 ) );
	}

	/**
	 * Helper: creates a Form with encrypted_dek and dek_iv set.
	 */
	private function make_form( int $id = 1, string $title = 'Test Form' ): Form {
		$form                = new Form();
		$form->id            = $id;
		$form->title         = $title;
		$form->slug          = 'test-form-' . $id;
		$form->is_active     = true;
		$form->legal_basis   = 'consent';
		$form->encrypted_dek = base64_encode( random_bytes( 48 ) );
		$form->dek_iv        = base64_encode( random_bytes( 12 ) );
		return $form;
	}

	// ──────────────────────────────────────────────────
	// generate_kek() — Static key generation
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Generated KEK is valid base64, 32 bytes raw.
	 */
	public function test_generate_kek_returns_valid_base64_32_bytes(): void {
		$kek_b64 = KekRotation::generate_kek();
		$kek_raw = base64_decode( $kek_b64, true );

		$this->assertNotFalse( $kek_raw );
		$this->assertSame( 32, strlen( $kek_raw ) );
		$this->assertSame( 44, strlen( $kek_b64 ) );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Each call generates unique key material.
	 */
	public function test_generate_kek_returns_unique_keys(): void {
		$kek1 = KekRotation::generate_kek();
		$kek2 = KekRotation::generate_kek();

		$this->assertNotSame( $kek1, $kek2 );
	}

	// ──────────────────────────────────────────────────
	// validate_and_decode_kek() — Key validation
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Invalid base64 rejected.
	 */
	public function test_validate_rejects_invalid_base64(): void {
		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( random_bytes( 32 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/base64/i' );

		$this->rotation->rotate( '!!!not-valid-base64!!!', false );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Short key (< 32 bytes) rejected.
	 */
	public function test_validate_rejects_short_key(): void {
		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( random_bytes( 32 ) );

		$short_key = base64_encode( random_bytes( 16 ) ); // Only 16 bytes.

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/32 bytes/i' );

		$this->rotation->rotate( $short_key, false );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Same KEK (old = new) is rejected.
	 */
	public function test_rotate_rejects_same_kek(): void {
		$kek = random_bytes( 32 );
		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $kek );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/different/i' );

		$this->rotation->rotate( base64_encode( $kek ), false );
	}

	// ──────────────────────────────────────────────────
	// rotate() — Empty forms edge case
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — No forms → immediate success.
	 */
	public function test_rotate_empty_forms_returns_success(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		// Form::find_all returns empty array.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$result = $this->rotation->rotate( $new_kek, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['forms_total'] );
		$this->assertSame( 0, $result['forms_rotated'] );
		$this->assertEmpty( $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// rotate() — Dry-run mode
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Dry-run validates without DB writes.
	 */
	public function test_dry_run_validates_without_writes(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		// Form::find_all — one form.
		$form     = $this->make_form();
		$form_row = [
			'id'            => '1',
			'title'         => 'Test Form',
			'slug'          => 'test-form-1',
			'is_active'     => '1',
			'retention_days' => '90',
			'legal_basis'   => 'consent',
			'consent_text'  => '',
			'consent_text_version' => '1',
			'consent_locale' => 'de_DE',
			'recipient_email' => null,
			'encrypted_dek' => $form->encrypted_dek,
			'dek_iv'        => $form->dek_iv,
			'created_at'    => '2026-01-01',
			'updated_at'    => '2026-01-01',
		];
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $form_row ] );

		// Decrypt old → re-encrypt new → decrypt new (round-trip verification).
		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )
			->with( $old_kek, $form->encrypted_dek, $form->dek_iv )
			->andReturn( $dek );

		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )
			->andReturn( [
				'encrypted_dek' => base64_encode( random_bytes( 48 ) ),
				'dek_iv'        => base64_encode( random_bytes( 12 ) ),
			] );

		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )
			->andReturn( $dek ); // Round-trip verification returns same DEK.

		// Dry-run: NO wpdb->update, NO wpdb->query('START TRANSACTION'), NO audit log.
		$this->wpdb->shouldNotReceive( 'update' );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = $this->rotation->rotate( $new_kek, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['forms_total'] );
		$this->assertSame( 1, $result['forms_rotated'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Dry-run detects round-trip verification failure.
	 */
	public function test_dry_run_detects_verification_failure(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		$form     = $this->make_form();
		$form_row = [
			'id' => '1', 'title' => 'Test Form', 'slug' => 'test-form-1',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => '', 'consent_text_version' => '1', 'consent_locale' => 'de_DE',
			'recipient_email' => null, 'encrypted_dek' => $form->encrypted_dek,
			'dek_iv' => $form->dek_iv, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
		];
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $form_row ] );

		// Decrypt ok, re-encrypt ok, but round-trip returns DIFFERENT dek.
		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )
			->andReturn( $dek, random_bytes( 32 ) ); // Second call returns different key.

		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )
			->andReturn( [
				'encrypted_dek' => base64_encode( random_bytes( 48 ) ),
				'dek_iv'        => base64_encode( random_bytes( 12 ) ),
			] );

		$result = $this->rotation->rotate( $new_kek, true );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'verification failed', $result['errors'][0] );
	}

	// ──────────────────────────────────────────────────
	// rotate() — Execute (real rotation with transaction)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Successful rotation uses transaction and commits.
	 */
	public function test_execute_rotate_uses_transaction_and_commits(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		$form     = $this->make_form();
		$form_row = [
			'id' => '1', 'title' => 'Test Form', 'slug' => 'test-form-1',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => '', 'consent_text_version' => '1', 'consent_locale' => 'de_DE',
			'recipient_email' => null, 'encrypted_dek' => $form->encrypted_dek,
			'dek_iv' => $form->dek_iv, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
		];
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $form_row ] );

		// Decrypt + re-encrypt + verify round-trip.
		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )->andReturn( $dek );
		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )->andReturn( [
			'encrypted_dek' => 'new-enc-dek',
			'dek_iv'        => 'new-dek-iv',
		] );

		// Transaction: START → UPDATE → COMMIT.
		$this->wpdb->shouldReceive( 'query' )
			->with( 'START TRANSACTION' )->once()->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )
			->with( 'COMMIT' )->once()->andReturn( true );

		// Audit log for successful rotation.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 1, 'kek_rotation', null, null, Mockery::pattern( '/rotated successfully/' ) )
			->andReturn( true );

		$result = $this->rotation->rotate( $new_kek, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['forms_rotated'] );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — DB update failure triggers ROLLBACK.
	 */
	public function test_execute_rotate_rolls_back_on_db_error(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		$form     = $this->make_form();
		$form_row = [
			'id' => '1', 'title' => 'Test Form', 'slug' => 'test-form-1',
			'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
			'consent_text' => '', 'consent_text_version' => '1', 'consent_locale' => 'de_DE',
			'recipient_email' => null, 'encrypted_dek' => $form->encrypted_dek,
			'dek_iv' => $form->dek_iv, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
		];
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $form_row ] );

		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )->andReturn( $dek );
		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )->andReturn( [
			'encrypted_dek' => 'new-enc-dek',
			'dek_iv'        => 'new-dek-iv',
		] );

		// Transaction: START → UPDATE fails → ROLLBACK.
		$this->wpdb->shouldReceive( 'query' )
			->with( 'START TRANSACTION' )->once()->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );
		$this->wpdb->shouldReceive( 'query' )
			->with( 'ROLLBACK' )->once()->andReturn( true );

		// Audit log for FAILED rotation.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 1, 'kek_rotation', null, null, Mockery::pattern( '/FAILED/' ) )
			->andReturn( true );

		$result = $this->rotation->rotate( $new_kek, false );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// has_lookup_hashes()
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_has_lookup_hashes_returns_true_when_hashes_exist(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '5' );

		$this->assertTrue( $this->rotation->has_lookup_hashes() );
	}

	/**
	 * @test
	 */
	public function test_has_lookup_hashes_returns_false_when_no_hashes(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$this->assertFalse( $this->rotation->has_lookup_hashes() );
	}

	// ──────────────────────────────────────────────────
	// rotate() — Multiple forms
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — All forms rotated in single transaction.
	 */
	public function test_rotate_handles_multiple_forms(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		$form_rows = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$form         = $this->make_form( $i, 'Form ' . $i );
			$form_rows[] = [
				'id' => (string) $i, 'title' => 'Form ' . $i, 'slug' => 'form-' . $i,
				'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
				'consent_text' => '', 'consent_text_version' => '1', 'consent_locale' => 'de_DE',
				'recipient_email' => null, 'encrypted_dek' => $form->encrypted_dek,
				'dek_iv' => $form->dek_iv, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
			];
		}
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $form_rows );

		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )->andReturn( $dek );
		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )->andReturn( [
			'encrypted_dek' => 'new-enc-dek',
			'dek_iv'        => 'new-dek-iv',
		] );

		$this->wpdb->shouldReceive( 'query' )->with( 'START TRANSACTION' )->once()->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->times( 3 )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )->with( 'COMMIT' )->once()->andReturn( true );

		$result = $this->rotation->rotate( $new_kek, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['forms_total'] );
		$this->assertSame( 3, $result['forms_rotated'] );
	}

	/**
	 * @test
	 * @security-relevant SEC-SOLL-02 — Partial failure rolls back ALL forms.
	 */
	public function test_rotate_rollback_on_partial_failure(): void {
		$old_kek = random_bytes( 32 );
		$new_kek = $this->generate_kek();
		$dek     = random_bytes( 32 );

		$this->key_manager->shouldReceive( 'get_kek' )->andReturn( $old_kek );

		$form_rows = [];
		for ( $i = 1; $i <= 2; $i++ ) {
			$form         = $this->make_form( $i );
			$form_rows[] = [
				'id' => (string) $i, 'title' => 'Form ' . $i, 'slug' => 'form-' . $i,
				'is_active' => '1', 'retention_days' => '90', 'legal_basis' => 'consent',
				'consent_text' => '', 'consent_text_version' => '1', 'consent_locale' => 'de_DE',
				'recipient_email' => null, 'encrypted_dek' => $form->encrypted_dek,
				'dek_iv' => $form->dek_iv, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
			];
		}
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $form_rows );

		// First form decrypts OK (2 calls: decrypt + verify); second form decrypt FAILS.
		$decrypt_call = 0;
		$this->key_manager->shouldReceive( 'decrypt_dek_with_kek' )
			->andReturnUsing( function () use ( &$decrypt_call, $dek ): string {
				++$decrypt_call;
				if ( $decrypt_call > 2 ) {
					throw new \RuntimeException( 'Decryption failed for form 2' );
				}
				return $dek;
			} );

		$this->key_manager->shouldReceive( 'encrypt_dek_with_kek' )->andReturn( [
			'encrypted_dek' => 'new-enc-dek',
			'dek_iv'        => 'new-dek-iv',
		] );

		$this->wpdb->shouldReceive( 'query' )->with( 'START TRANSACTION' )->once()->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 ); // First form succeeds.
		$this->wpdb->shouldReceive( 'query' )->with( 'ROLLBACK' )->once()->andReturn( true );

		$result = $this->rotation->rotate( $new_kek, false );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// SEC-SOLL-05/06: sodium_memzero, batch_size, rehash_lookups
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * SEC-SOLL-06: rehash_lookups() accepts custom batch_size parameter.
	 */
	public function test_rehash_lookups_uses_custom_batch_size(): void {
		$new_kek = $this->generate_kek();

		$this->key_manager->shouldReceive( 'derive_hmac_key_from_kek' )
			->once()
			->andReturn( random_bytes( 32 ) );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$result = $this->rotation->rehash_lookups( $new_kek, 50 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['submissions_total'] );
		$this->assertSame( 0, $result['submissions_rehashed'] );
		$this->assertSame( 0, $result['submissions_skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * @test
	 * SEC-SOLL-06: rehash_lookups() accepts optional progress callback.
	 */
	public function test_rehash_lookups_accepts_progress_callback(): void {
		$new_kek = $this->generate_kek();

		$this->key_manager->shouldReceive( 'derive_hmac_key_from_kek' )
			->once()
			->andReturn( random_bytes( 32 ) );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$callback_called = false;
		$result = $this->rotation->rehash_lookups( $new_kek, 100, function () use ( &$callback_called ): void {
			$callback_called = true;
		} );

		// No submissions → callback never invoked.
		$this->assertFalse( $callback_called );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * @test
	 * SEC-SOLL-06: rehash_lookups() result array has all expected keys.
	 */
	public function test_rehash_lookups_result_structure(): void {
		$new_kek = $this->generate_kek();

		$this->key_manager->shouldReceive( 'derive_hmac_key_from_kek' )
			->once()
			->andReturn( random_bytes( 32 ) );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$result = $this->rotation->rehash_lookups( $new_kek );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'submissions_total', $result );
		$this->assertArrayHasKey( 'submissions_rehashed', $result );
		$this->assertArrayHasKey( 'submissions_skipped', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsBool( $result['success'] );
		$this->assertIsInt( $result['submissions_total'] );
		$this->assertIsArray( $result['errors'] );
	}
}
