<?php
/**
 * Unit tests for PrivacyHandler class.
 *
 * @package WpDsgvoForm\Tests\Unit\Privacy
 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht (Exporter)
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung (Eraser)
 * @privacy-relevant Art. 18 DSGVO — Einschraenkung der Verarbeitung (Eraser guard)
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Privacy;

use WpDsgvoForm\Privacy\PrivacyHandler;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Api\SubmissionDeleter;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Tests for PrivacyHandler (LEGAL-F01).
 *
 * Covers:
 * - register() hooks exporter and eraser filters
 * - register_exporter() / register_eraser() add correct callbacks
 * - export_personal_data() — HMAC hash lookup, decryption, batch pagination, metadata fields
 * - export_personal_data() — decrypt_submission_safe() error handling
 * - erase_personal_data() — Art. 18 is_restricted guard, SubmissionDeleter, audit logging
 * - erase_personal_data() — batch pagination, failure handling
 */
class PrivacyHandlerTest extends TestCase {

	private EncryptionService|Mockery\MockInterface $encryption;
	private SubmissionDeleter|Mockery\MockInterface $deleter;
	private AuditLogger|Mockery\MockInterface $audit_logger;
	private PrivacyHandler $handler;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->encryption   = Mockery::mock( EncryptionService::class );
		$this->deleter      = Mockery::mock( SubmissionDeleter::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );

		$this->handler = new PrivacyHandler(
			$this->encryption,
			$this->deleter,
			$this->audit_logger
		);

		// Mock wpdb for static model methods.
		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Stub i18n and WP utility functions.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// register() Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * register() hooks both exporter and eraser filters.
	 */
	public function test_register_hooks_exporter_and_eraser(): void {
		Filters\expectAdded( 'wp_privacy_personal_data_exporters' )
			->once()
			->with( [ $this->handler, 'register_exporter' ], \Mockery::any() );

		Filters\expectAdded( 'wp_privacy_personal_data_erasers' )
			->once()
			->with( [ $this->handler, 'register_eraser' ], \Mockery::any() );

		$this->handler->register();
	}

	/**
	 * @test
	 * register_exporter() adds correct entry to exporters array.
	 */
	public function test_register_exporter_adds_callback(): void {
		$result = $this->handler->register_exporter( [] );

		$this->assertArrayHasKey( 'wp-dsgvo-form', $result );
		$this->assertSame( 'WP DSGVO Form', $result['wp-dsgvo-form']['exporter_friendly_name'] );
		$this->assertIsCallable( $result['wp-dsgvo-form']['callback'] );
	}

	/**
	 * @test
	 * register_eraser() adds correct entry to erasers array.
	 */
	public function test_register_eraser_adds_callback(): void {
		$result = $this->handler->register_eraser( [] );

		$this->assertArrayHasKey( 'wp-dsgvo-form', $result );
		$this->assertSame( 'WP DSGVO Form', $result['wp-dsgvo-form']['eraser_friendly_name'] );
		$this->assertIsCallable( $result['wp-dsgvo-form']['callback'] );
	}

	/**
	 * @test
	 * register_exporter() preserves existing exporters.
	 */
	public function test_register_exporter_preserves_existing(): void {
		$existing = [ 'other-plugin' => [ 'exporter_friendly_name' => 'Other' ] ];
		$result   = $this->handler->register_exporter( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result );
		$this->assertArrayHasKey( 'wp-dsgvo-form', $result );
	}

	/**
	 * @test
	 * register_eraser() preserves existing erasers.
	 */
	public function test_register_eraser_preserves_existing(): void {
		$existing = [ 'other-plugin' => [ 'eraser_friendly_name' => 'Other' ] ];
		$result   = $this->handler->register_eraser( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result );
		$this->assertArrayHasKey( 'wp-dsgvo-form', $result );
	}

	// ──────────────────────────────────────────────
	// export_personal_data() Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Art. 15: export uses HMAC hash to find submissions.
	 */
	public function test_export_uses_hmac_hash_for_lookup(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->once()
			->with( 'test@example.com' )
			->andReturn( 'hmac_hash_abc' );

		$this->mock_submission_query( 'hmac_hash_abc', [] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * Art. 15: export returns decrypted field values.
	 */
	public function test_export_returns_decrypted_data(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->once()
			->andReturn( [ 'name' => 'Max Mustermann', 'email' => 'test@example.com' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertCount( 1, $result['data'] );
		$item = $result['data'][0];

		$this->assertSame( 'wp-dsgvo-form-submissions', $item['group_id'] );
		$this->assertSame( 'submission-1', $item['item_id'] );

		$field_names = array_column( $item['data'], 'name' );
		$this->assertContains( 'name', $field_names );
		$this->assertContains( 'email', $field_names );
	}

	/**
	 * @test
	 * Art. 15: export includes metadata fields (form title, submitted_at).
	 */
	public function test_export_includes_metadata_fields(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [ 'name' => 'Test' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$field_names = array_column( $result['data'][0]['data'], 'name' );
		$this->assertContains( 'Formular', $field_names );
		$this->assertContains( 'Eingereicht am', $field_names );
	}

	/**
	 * @test
	 * Art. 15: export includes consent timestamp when present.
	 */
	public function test_export_includes_consent_timestamp(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$row = $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' );
		$row['consent_timestamp'] = '2026-04-20 09:59:00';
		$this->mock_submission_query( 'hash_abc', [ $row ] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [ 'name' => 'Test' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$field_names = array_column( $result['data'][0]['data'], 'name' );
		$this->assertContains( 'Einwilligung erteilt am', $field_names );
	}

	/**
	 * @test
	 * Art. 15: export omits consent timestamp when null.
	 */
	public function test_export_omits_consent_timestamp_when_null(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [ 'name' => 'Test' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$field_names = array_column( $result['data'][0]['data'], 'name' );
		$this->assertNotContains( 'Einwilligung erteilt am', $field_names );
	}

	/**
	 * @test
	 * Art. 15: export skips submissions where form was deleted.
	 */
	public function test_export_skips_submission_without_form(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 999, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 999, null );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * decrypt_submission_safe() returns placeholder on decryption failure.
	 */
	public function test_export_returns_placeholder_on_decryption_failure(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->once()
			->andThrow( new \RuntimeException( 'KEK rotation' ) );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertCount( 1, $result['data'] );
		$field_names = array_column( $result['data'][0]['data'], 'name' );
		$this->assertContains( 'Hinweis', $field_names );
	}

	/**
	 * @test
	 * Art. 15: export handles non-string field values via wp_json_encode.
	 */
	public function test_export_encodes_non_string_values(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
		] );

		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [
				'name'    => 'Max',
				'options' => [ 'a', 'b' ],
			] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$data_pairs = [];
		foreach ( $result['data'][0]['data'] as $item ) {
			$data_pairs[ $item['name'] ] = $item['value'];
		}
		$this->assertSame( 'Max', $data_pairs['name'] );
		$this->assertSame( '["a","b"]', $data_pairs['options'] );
	}

	/**
	 * @test
	 * Batch pagination: done=false when more submissions remain.
	 */
	public function test_export_pagination_not_done_with_more_submissions(): void {
		// Create 25 submission rows (> BATCH_SIZE=20).
		$rows = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[] = $this->make_submission_row( $i, 10, '2026-04-20 10:00:00' );
		}

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [ 'name' => 'Test' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertCount( 20, $result['data'] );
		$this->assertFalse( $result['done'] );
	}

	/**
	 * @test
	 * Batch pagination: page 2 returns remaining submissions and done=true.
	 */
	public function test_export_pagination_page_2_completes(): void {
		$rows = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[] = $this->make_submission_row( $i, 10, '2026-04-20 10:00:00' );
		}

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		$this->encryption->shouldReceive( 'decrypt_submission' )
			->andReturn( [ 'name' => 'Test' ] );

		$result = $this->handler->export_personal_data( 'test@example.com', 2 );

		$this->assertCount( 5, $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * Export with no submissions returns empty data and done=true.
	 */
	public function test_export_no_submissions_returns_empty(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [] );

		$result = $this->handler->export_personal_data( 'test@example.com', 1 );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	// ──────────────────────────────────────────────
	// erase_personal_data() Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Art. 17: erase uses HMAC hash for lookup.
	 */
	public function test_erase_uses_hmac_hash_for_lookup(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->once()
			->with( 'test@example.com' )
			->andReturn( 'hmac_hash_abc' );

		$this->mock_submission_query( 'hmac_hash_abc', [] );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 0, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * Art. 17: successful deletion increments items_removed and logs audit.
	 */
	public function test_erase_deletes_and_logs_audit(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 42, 10, '2026-04-20 10:00:00' ),
		] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 42 )
			->andReturn( true );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 1, 'delete', 42, 10, 'Privacy erasure request (Art. 17 DSGVO)' )
			->andReturn( true );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * Art. 18: restricted submissions are NOT deleted (is_restricted guard).
	 */
	public function test_erase_skips_restricted_submissions(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$row = $this->make_submission_row( 42, 10, '2026-04-20 10:00:00' );
		$row['is_restricted'] = '1';
		$this->mock_submission_query( 'hash_abc', [ $row ] );

		// SubmissionDeleter should NOT be called.
		$this->deleter->shouldNotReceive( 'delete' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 0, $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( '#42', $result['messages'][0] );
		$this->assertStringContainsString( 'Art. 18', $result['messages'][0] );
	}

	/**
	 * @test
	 * Art. 18: mix of restricted and normal submissions.
	 */
	public function test_erase_handles_mixed_restricted_and_normal(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$row_normal     = $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' );
		$row_restricted = $this->make_submission_row( 2, 10, '2026-04-20 10:00:00' );
		$row_restricted['is_restricted'] = '1';
		$row_normal2    = $this->make_submission_row( 3, 10, '2026-04-20 10:00:00' );

		$this->mock_submission_query( 'hash_abc', [ $row_normal, $row_restricted, $row_normal2 ] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->deleter->shouldReceive( 'delete' )->with( 1 )->once()->andReturn( true );
		$this->deleter->shouldReceive( 'delete' )->with( 3 )->once()->andReturn( true );

		$this->audit_logger->shouldReceive( 'log' )->twice()->andReturn( true );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 2, $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
	}

	/**
	 * @test
	 * Failed deletion sets items_retained and adds error message.
	 */
	public function test_erase_handles_deletion_failure(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 42, 10, '2026-04-20 10:00:00' ),
		] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->deleter->shouldReceive( 'delete' )
			->once()
			->with( 42 )
			->andReturn( false );

		// Audit log should NOT be called on failed deletion.
		$this->audit_logger->shouldNotReceive( 'log' );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 0, $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( '#42', $result['messages'][0] );
	}

	/**
	 * @test
	 * Batch pagination: done=false when more submissions remain.
	 */
	public function test_erase_pagination_not_done(): void {
		$rows = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[] = $this->make_submission_row( $i, 10, '2026-04-20 10:00:00' );
		}

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', $rows );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->deleter->shouldReceive( 'delete' )->andReturn( true );
		$this->audit_logger->shouldReceive( 'log' )->andReturn( true );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 20, $result['items_removed'] );
		$this->assertFalse( $result['done'] );
	}

	/**
	 * @test
	 * Erase with no submissions returns zero removed and done=true.
	 */
	public function test_erase_no_submissions(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [] );

		$result = $this->handler->erase_personal_data( 'test@example.com', 1 );

		$this->assertSame( 0, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( [], $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @test
	 * Art. 5 Abs. 2: Audit log entries are NOT deleted by eraser (accountability).
	 */
	public function test_erase_does_not_delete_audit_logs(): void {
		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hash_abc' );

		$this->mock_submission_query( 'hash_abc', [
			$this->make_submission_row( 42, 10, '2026-04-20 10:00:00' ),
		] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->deleter->shouldReceive( 'delete' )->andReturn( true );

		// Audit logger should log the deletion, never delete existing entries.
		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with( 1, 'delete', 42, 10, \Mockery::type( 'string' ) )
			->andReturn( true );

		$this->audit_logger->shouldNotReceive( 'cleanup_old_entries' );
		$this->audit_logger->shouldNotReceive( 'cleanup_ip_addresses' );

		$this->handler->erase_personal_data( 'test@example.com', 1 );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Creates a submission row array (as returned by $wpdb->get_results).
	 */
	private function make_submission_row( int $id, int $form_id, string $submitted_at ): array {
		return [
			'id'                   => (string) $id,
			'form_id'              => (string) $form_id,
			'encrypted_data'       => 'enc_data_' . $id,
			'iv'                   => 'iv_data_' . $id,
			'auth_tag'             => 'tag_data_' . $id,
			'submitted_at'         => $submitted_at,
			'is_read'              => '0',
			'expires_at'           => null,
			'consent_text_version' => null,
			'consent_timestamp'    => null,
			'email_lookup_hash'    => null,
			'consent_locale'       => null,
			'consent_version_id'   => null,
			'is_restricted'        => '0',
		];
	}

	/**
	 * Creates a form row array (as returned by $wpdb->get_row).
	 */
	private function make_form_row( int $id, string $title ): array {
		return [
			'id'              => (string) $id,
			'title'           => $title,
			'slug'            => strtolower( str_replace( ' ', '-', $title ) ),
			'description'     => 'Description',
			'success_message' => 'Danke',
			'email_subject'   => 'Neue Anfrage',
			'email_template'  => '{{name}}',
			'is_active'       => '1',
			'captcha_enabled' => '1',
			'retention_days'  => '90',
			'encrypted_dek'   => 'enc_dek_data',
			'dek_iv'          => 'iv_data',
			'legal_basis'     => 'consent',
			'purpose'         => 'Kontaktanfrage',
			'consent_text'    => 'Ich stimme zu',
			'consent_version' => '1',
			'created_at'      => '2026-01-01 00:00:00',
			'updated_at'      => '2026-01-01 00:00:00',
		];
	}

	/**
	 * Mocks $wpdb to return submission rows for find_by_email_lookup_hash().
	 *
	 * @param string  $expected_hash The expected HMAC hash.
	 * @param array[] $rows          Submission row arrays to return.
	 */
	private function mock_submission_query( string $expected_hash, array $rows ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'email_lookup_hash' ) ),
				$expected_hash
			)
			->andReturn( "SELECT * FROM wp_dsgvo_submissions WHERE email_lookup_hash = '{$expected_hash}'" );

		$this->wpdb->shouldReceive( 'get_results' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'email_lookup_hash' ) ),
				'ARRAY_A'
			)
			->andReturn( $rows ?: null );
	}

	/**
	 * Mocks $wpdb to return a form row for Form::find().
	 *
	 * @param int        $form_id  The expected form ID.
	 * @param array|null $form_row Form row array or null for not found.
	 */
	private function mock_form_query( int $form_id, ?array $form_row ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'dsgvo_forms' ) ),
				$form_id
			)
			->andReturn( "SELECT * FROM wp_dsgvo_forms WHERE id = {$form_id}" );

		$this->wpdb->shouldReceive( 'get_row' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'dsgvo_forms' ) ),
				'ARRAY_A'
			)
			->andReturn( $form_row );
	}
}
