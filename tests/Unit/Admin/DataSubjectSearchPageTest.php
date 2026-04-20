<?php
/**
 * Unit tests for DataSubjectSearchPage class.
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 * @privacy-relevant Art. 15 DSGVO — Auskunftsrecht
 * @privacy-relevant Art. 17 DSGVO — Recht auf Loeschung
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\DataSubjectSearchPage;
use WpDsgvoForm\Audit\AuditLogger;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for DataSubjectSearchPage (LEGAL-RIGHTS-02).
 *
 * Covers:
 * - Capability check (dsgvo_form_manage)
 * - Nonce validation
 * - HMAC email_lookup_hash search
 * - Audit logging on every search
 * - Result table structure (ID, form, date, status)
 * - Hidden submenu (no direct menu entry)
 * - get_url() helper
 */
class DataSubjectSearchPageTest extends TestCase {

	private EncryptionService|Mockery\MockInterface $encryption;
	private AuditLogger|Mockery\MockInterface $audit_logger;
	private DataSubjectSearchPage $page;
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->encryption   = Mockery::mock( EncryptionService::class );
		$this->audit_logger = Mockery::mock( AuditLogger::class );
		$this->page         = new DataSubjectSearchPage( $this->encryption, $this->audit_logger );

		// Mock wpdb for static model methods.
		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Common WP function stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text ): void {
				echo $text;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_POST['_wpdsgvo_nonce'], $_POST['subject_email'] );
		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// Capability Check Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Unauthorized user triggers wp_die with 403.
	 */
	public function test_render_denies_access_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$wp_die_args = [];

		Functions\when( 'wp_die' )->alias(
			function ( string $message, string $title, array $args ) use ( &$wp_die_args ): void {
				$wp_die_args = [
					'message' => $message,
					'title'   => $title,
					'args'    => $args,
				];
				// Simulate WP execution halt.
				throw new \RuntimeException( 'wp_die_called' );
			}
		);

		$caught = false;

		try {
			$this->page->render();
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() === 'wp_die_called' ) {
				$caught = true;
			}
		}

		$this->assertTrue( $caught, 'wp_die must be called for unauthorized users.' );
		$this->assertSame( 403, $wp_die_args['args']['response'] );
	}

	/**
	 * @test
	 * Authorized user sees the search form.
	 */
	public function test_render_shows_search_form_for_authorized_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'Betroffenen-Suche', $output );
		$this->assertStringContainsString( 'subject_email', $output );
		$this->assertStringContainsString( 'type="email"', $output );
	}

	// ──────────────────────────────────────────────
	// Nonce Validation Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Search is not executed without nonce.
	 */
	public function test_render_does_not_search_without_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );

		// No $_POST at all.
		$this->encryption->shouldNotReceive( 'calculate_email_lookup_hash' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$output = $this->capture_render();

		// No result section.
		$this->assertStringNotContainsString( '<hr>', $output );
	}

	/**
	 * @test
	 * Search is not executed with invalid nonce.
	 */
	public function test_render_does_not_search_with_invalid_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$_POST['_wpdsgvo_nonce'] = 'invalid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldNotReceive( 'calculate_email_lookup_hash' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$output = $this->capture_render();

		$this->assertStringNotContainsString( '<hr>', $output );
	}

	// ──────────────────────────────────────────────
	// Search & HMAC Lookup Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Valid search uses HMAC hash to find submissions.
	 */
	public function test_search_uses_hmac_hash_for_lookup(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->once()
			->with( 'test@example.com' )
			->andReturn( 'hmac_hash_abc' );

		$this->mock_submission_lookup( 'hmac_hash_abc', [] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->andReturn( true );

		$output = $this->capture_render();

		// Shows "no results" notice.
		$this->assertStringContainsString( 'Keine Einsendungen', $output );
	}

	/**
	 * @test
	 * Empty email does not trigger search.
	 */
	public function test_search_not_triggered_for_empty_email(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = '';

		Functions\when( 'sanitize_email' )->justReturn( '' );

		$this->encryption->shouldNotReceive( 'calculate_email_lookup_hash' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$output = $this->capture_render();

		$this->assertStringNotContainsString( '<hr>', $output );
	}

	/**
	 * @test
	 * Invalid email does not trigger search.
	 */
	public function test_search_not_triggered_for_invalid_email(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'not-an-email';

		Functions\when( 'sanitize_email' )->justReturn( 'not-an-email' );
		Functions\when( 'is_email' )->justReturn( false );

		$this->encryption->shouldNotReceive( 'calculate_email_lookup_hash' );
		$this->audit_logger->shouldNotReceive( 'log' );

		$output = $this->capture_render();

		$this->assertStringNotContainsString( '<hr>', $output );
	}

	// ──────────────────────────────────────────────
	// Audit Log Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * SEC-AUDIT-01: Every search is audit-logged with result count.
	 */
	public function test_search_logs_audit_with_result_count(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$rows = [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
			$this->make_submission_row( 2, 10, '2026-04-20 11:00:00' ),
		];
		$this->mock_submission_lookup( 'hmac_hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				7,
				'view',
				null,
				null,
				\Mockery::on( fn( $msg ) => str_contains( $msg, '2 results' ) && str_contains( $msg, 'hash=' ) )
			)
			->andReturn( true );

		$this->capture_render();
	}

	/**
	 * @test
	 * Audit log records zero results correctly.
	 */
	public function test_search_logs_audit_with_zero_results(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'nobody@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_none' );

		$this->mock_submission_lookup( 'hmac_hash_none', [] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->audit_logger->shouldReceive( 'log' )
			->once()
			->with(
				1,
				'view',
				null,
				null,
				\Mockery::on( fn( $msg ) => str_contains( $msg, '0 results' ) && str_contains( $msg, 'hash=' ) )
			)
			->andReturn( true );

		$this->capture_render();
	}

	// ──────────────────────────────────────────────
	// Result Table Tests
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Result table contains submission ID, form title, and date.
	 */
	public function test_result_table_contains_correct_data(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$rows = [ $this->make_submission_row( 42, 10, '2026-04-20 14:30:00' ) ];
		$this->mock_submission_lookup( 'hmac_hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 14:30' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		// Table structure.
		$this->assertStringContainsString( '<table class="widefat striped"', $output );
		// Submission ID.
		$this->assertStringContainsString( '42', $output );
		// Form title.
		$this->assertStringContainsString( 'Kontaktformular', $output );
		// Formatted date.
		$this->assertStringContainsString( '20.04.2026 14:30', $output );
		// View link.
		$this->assertStringContainsString( 'submission_id=42', $output );
		$this->assertStringContainsString( 'Anzeigen', $output );
	}

	/**
	 * @test
	 * Result count heading uses correct singular/plural form.
	 */
	public function test_result_heading_shows_count(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$rows = [
			$this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ),
			$this->make_submission_row( 2, 10, '2026-04-20 11:00:00' ),
			$this->make_submission_row( 3, 10, '2026-04-20 12:00:00' ),
		];
		$this->mock_submission_lookup( 'hmac_hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( '_n' )->returnArg();
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		// printf with count replaces %d.
		$this->assertStringContainsString( '3', $output );
	}

	/**
	 * @test
	 * Deleted form shows placeholder text in results.
	 */
	public function test_result_table_shows_placeholder_for_deleted_form(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$rows = [ $this->make_submission_row( 1, 999, '2026-04-20 10:00:00' ) ];
		$this->mock_submission_lookup( 'hmac_hash_abc', $rows );
		$this->mock_form_query( 999, null );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( '(Formular geloescht)', $output );
	}

	/**
	 * @test
	 * Restricted submission shows Art. 18 status.
	 */
	public function test_result_table_shows_restricted_status(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$row = $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' );
		$row['is_restricted'] = '1';
		$this->mock_submission_lookup( 'hmac_hash_abc', [ $row ] );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'Gesperrt (Art. 18)', $output );
	}

	/**
	 * @test
	 * Unread submission shows "Ungelesen" status.
	 */
	public function test_result_table_shows_unread_status(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$row = $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' );
		$row['is_read'] = '0';
		$this->mock_submission_lookup( 'hmac_hash_abc', [ $row ] );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'Ungelesen', $output );
	}

	/**
	 * @test
	 * Read submission shows "Gelesen" status.
	 */
	public function test_result_table_shows_read_status(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$row = $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' );
		$row['is_read'] = '1';
		$this->mock_submission_lookup( 'hmac_hash_abc', [ $row ] );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'Gelesen', $output );
	}

	// ──────────────────────────────────────────────
	// Hidden Submenu Test
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Page slug constant matches expected value.
	 */
	public function test_page_slug_constant(): void {
		$this->assertSame( 'dsgvo-form-subject-search', DataSubjectSearchPage::PAGE_SLUG );
	}

	/**
	 * @test
	 * get_url() returns correct admin URL.
	 */
	public function test_get_url_returns_admin_url(): void {
		Functions\when( 'admin_url' )->alias(
			function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$url = DataSubjectSearchPage::get_url();

		$this->assertStringContainsString( 'admin.php?page=dsgvo-form-subject-search', $url );
	}

	// ──────────────────────────────────────────────
	// No Results Notice
	// ──────────────────────────────────────────────

	/**
	 * @test
	 * Search with no results shows notice-info.
	 */
	public function test_no_results_shows_info_notice(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'nobody@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_none' );

		$this->mock_submission_lookup( 'hmac_hash_none', [] );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'notice notice-info', $output );
		$this->assertStringContainsString( 'Keine Einsendungen', $output );
	}

	/**
	 * @test
	 * Search results include privacy tools hint.
	 */
	public function test_results_include_privacy_tools_hint(): void {
		$this->stub_search_functions();

		$_POST['_wpdsgvo_nonce'] = 'valid_nonce';
		$_POST['subject_email']  = 'test@example.com';

		$this->encryption->shouldReceive( 'calculate_email_lookup_hash' )
			->andReturn( 'hmac_hash_abc' );

		$rows = [ $this->make_submission_row( 1, 10, '2026-04-20 10:00:00' ) ];
		$this->mock_submission_lookup( 'hmac_hash_abc', $rows );
		$this->mock_form_query( 10, $this->make_form_row( 10, 'Kontaktformular' ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '20.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->audit_logger->shouldReceive( 'log' )->once()->andReturn( true );

		$output = $this->capture_render();

		$this->assertStringContainsString( 'Werkzeuge', $output );
		$this->assertStringContainsString( 'Art. 15/17', $output );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Captures the HTML output of render().
	 */
	private function capture_render(): string {
		ob_start();
		$this->page->render();
		return ob_get_clean();
	}

	/**
	 * Stubs common WordPress functions for search scenarios.
	 */
	private function stub_search_functions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( '_n' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Mocks $wpdb for Submission::find_by_email_lookup_hash().
	 *
	 * @param string  $expected_hash The expected HMAC hash.
	 * @param array[] $rows          Submission row arrays.
	 */
	private function mock_submission_lookup( string $expected_hash, array $rows ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'email_lookup_hash' ) && ! str_contains( $sql, 'LIMIT' ) ),
				$expected_hash
			)
			->andReturn( "SELECT * FROM wp_dsgvo_submissions WHERE email_lookup_hash = '{$expected_hash}' ORDER BY submitted_at DESC" );

		$this->wpdb->shouldReceive( 'get_results' )
			->with(
				\Mockery::on( fn( $sql ) => str_contains( $sql, 'email_lookup_hash' ) ),
				'ARRAY_A'
			)
			->andReturn( $rows ?: null );
	}

	/**
	 * Mocks $wpdb for Form::find() query.
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

	/**
	 * Creates a submission row array.
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
	 * Creates a form row array.
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
}
