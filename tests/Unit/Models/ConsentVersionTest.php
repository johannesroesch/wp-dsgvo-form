<?php
/**
 * Unit tests for ConsentVersion model.
 *
 * @package WpDsgvoForm\Tests\Unit\Models
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Models;

use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for ConsentVersion CRUD, validation, immutability, and prepared statements.
 *
 * Covers: find(), get_current_version(), find_by_form_and_locale(),
 * find_all_by_form(), save() INSERT-only (Art. 7 immutability),
 * validation (empty consent_text, invalid locale, missing form_id),
 * auto-increment version, Prepared Statements (SEC-SQL-01).
 */
class ConsentVersionTest extends TestCase {

	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb             = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix     = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$GLOBALS['wpdb']        = $this->wpdb;

		// esc_html is used in exception messages (Task #242: ExceptionNotEscaped fix).
		Functions\when( 'esc_html' )->returnArg();

		// ARCH-v104-03: validate() now calls Form::find() to verify parent form
		// has legal_basis = 'consent'. Mock get_transient to return a consent-form
		// for any dsgvo_form_* cache key, so existing save tests continue to pass.
		Functions\when( 'get_transient' )->alias( function ( $key ) {
			if ( is_string( $key ) && strpos( $key, 'dsgvo_form_' ) === 0 ) {
				$form_id = (int) substr( $key, strlen( 'dsgvo_form_' ) );
				if ( $form_id > 0 ) {
					$form              = new Form();
					$form->id          = $form_id;
					$form->title       = 'Test Form';
					$form->legal_basis = 'consent';
					$form->retention_days = 90;
					return $form;
				}
			}
			return false;
		} );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Helper: returns a database row array representing a stored consent version.
	 */
	private function make_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                 => '1',
				'form_id'            => '5',
				'locale'             => 'de_DE',
				'version'            => '1',
				'consent_text'       => 'Ich stimme der Verarbeitung meiner Daten zu.',
				'privacy_policy_url' => 'https://example.com/datenschutz',
				'valid_from'         => '2026-04-17 10:00:00',
				'created_at'         => '2026-04-17 10:00:00',
			],
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// get_table_name
	// ------------------------------------------------------------------

	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_dsgvo_consent_versions', ConsentVersion::get_table_name() );
	}

	// ------------------------------------------------------------------
	// find — DB hit
	// ------------------------------------------------------------------

	public function test_find_returns_consent_version_by_id(): void {
		$row = $this->make_row();

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = ConsentVersion::find( 1 );

		$this->assertInstanceOf( ConsentVersion::class, $result );
		$this->assertSame( 1, $result->id );
		$this->assertSame( 5, $result->form_id );
		$this->assertSame( 'de_DE', $result->locale );
		$this->assertSame( 1, $result->version );
		$this->assertSame( 'Ich stimme der Verarbeitung meiner Daten zu.', $result->consent_text );
		$this->assertSame( 'https://example.com/datenschutz', $result->privacy_policy_url );
		$this->assertSame( '2026-04-17 10:00:00', $result->valid_from );
		$this->assertSame( '2026-04-17 10:00:00', $result->created_at );
	}

	// ------------------------------------------------------------------
	// find — DB miss
	// ------------------------------------------------------------------

	public function test_find_returns_null_when_not_in_db(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( ConsentVersion::find( 999 ) );
	}

	// ------------------------------------------------------------------
	// get_current_version — returns latest version
	// ------------------------------------------------------------------

	public function test_get_current_version_returns_latest_version_for_form_and_locale(): void {
		$row = $this->make_row( [ 'version' => '3' ] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = ConsentVersion::get_current_version( 5, 'de_DE' );

		$this->assertInstanceOf( ConsentVersion::class, $result );
		$this->assertSame( 3, $result->version );
		$this->assertSame( 5, $result->form_id );
		$this->assertSame( 'de_DE', $result->locale );
	}

	// ------------------------------------------------------------------
	// get_current_version — no versions exist
	// ------------------------------------------------------------------

	public function test_get_current_version_returns_null_when_no_versions_exist(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( ConsentVersion::get_current_version( 999, 'en_US' ) );
	}

	// ------------------------------------------------------------------
	// find_by_form_and_locale — exact version lookup (Art. 7)
	// ------------------------------------------------------------------

	public function test_find_by_form_and_locale_returns_exact_version(): void {
		$row = $this->make_row( [ 'version' => '2', 'locale' => 'en_US' ] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = ConsentVersion::find_by_form_and_locale( 5, 'en_US', 2 );

		$this->assertInstanceOf( ConsentVersion::class, $result );
		$this->assertSame( 2, $result->version );
		$this->assertSame( 'en_US', $result->locale );
	}

	public function test_find_by_form_and_locale_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( ConsentVersion::find_by_form_and_locale( 5, 'fr_FR', 99 ) );
	}

	// ------------------------------------------------------------------
	// find_all_by_form
	// ------------------------------------------------------------------

	public function test_find_all_by_form_returns_all_versions(): void {
		$rows = [
			$this->make_row( [ 'id' => '1', 'locale' => 'de_DE', 'version' => '2' ] ),
			$this->make_row( [ 'id' => '2', 'locale' => 'de_DE', 'version' => '1' ] ),
			$this->make_row( [ 'id' => '3', 'locale' => 'en_US', 'version' => '1' ] ),
		];

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$results = ConsentVersion::find_all_by_form( 5 );

		$this->assertCount( 3, $results );
		$this->assertSame( 'de_DE', $results[0]->locale );
		$this->assertSame( 2, $results[0]->version );
		$this->assertSame( 'en_US', $results[2]->locale );
	}

	public function test_find_all_by_form_returns_empty_array_when_none_exist(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$results = ConsentVersion::find_all_by_form( 999 );

		$this->assertSame( [], $results );
	}

	// ------------------------------------------------------------------
	// save — INSERT-only (immutability, Art. 7 DSGVO)
	// ------------------------------------------------------------------

	public function test_save_inserts_new_consent_version(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->version      = 1;
		$cv->consent_text = 'Einwilligungstext Version 1.';

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 10;

		$id = $cv->save();

		$this->assertSame( 10, $id );
		$this->assertSame( 10, $cv->id );
		$this->assertSame( 5, $inserted_data['form_id'] );
		$this->assertSame( 'de_DE', $inserted_data['locale'] );
		$this->assertSame( 1, $inserted_data['version'] );
		$this->assertSame( 'Einwilligungstext Version 1.', $inserted_data['consent_text'] );
		$this->assertSame( '2026-04-17 12:00:00', $inserted_data['valid_from'] );
	}

	// ------------------------------------------------------------------
	// save — auto-increment version
	// ------------------------------------------------------------------

	public function test_save_auto_increments_version_when_version_is_zero(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->version      = 0;
		$cv->consent_text = 'Neuer Einwilligungstext.';

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		// MAX(version) returns 3 → next should be 4.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 11;

		$cv->save();

		$this->assertSame( 4, $cv->version );
	}

	public function test_save_auto_increment_starts_at_1_when_no_versions_exist(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 7;
		$cv->locale       = 'en_US';
		$cv->version      = 0;
		$cv->consent_text = 'First consent text.';

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		// MAX(version) returns null (cast to 0) → next should be 1.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 12;

		$cv->save();

		$this->assertSame( 1, $cv->version );
	}

	// ------------------------------------------------------------------
	// save — includes privacy_policy_url when set
	// ------------------------------------------------------------------

	public function test_save_includes_privacy_policy_url_when_set(): void {
		$cv                     = new ConsentVersion();
		$cv->form_id            = 5;
		$cv->locale             = 'de_DE';
		$cv->version            = 1;
		$cv->consent_text       = 'Text mit Datenschutzlink.';
		$cv->privacy_policy_url = 'https://example.com/datenschutz';

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 13;

		$cv->save();

		$this->assertArrayHasKey( 'privacy_policy_url', $inserted_data );
		$this->assertSame( 'https://example.com/datenschutz', $inserted_data['privacy_policy_url'] );
	}

	public function test_save_excludes_privacy_policy_url_when_null(): void {
		$cv                     = new ConsentVersion();
		$cv->form_id            = 5;
		$cv->locale             = 'de_DE';
		$cv->version            = 1;
		$cv->consent_text       = 'Text ohne Datenschutzlink.';
		$cv->privacy_policy_url = null;

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 14;

		$cv->save();

		$this->assertArrayNotHasKey( 'privacy_policy_url', $inserted_data );
	}

	// ------------------------------------------------------------------
	// save — DB insert failure
	// ------------------------------------------------------------------

	public function test_save_throws_on_db_insert_failure(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->version      = 1;
		$cv->consent_text = 'Text.';

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = 'Duplicate entry';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to insert consent version' );

		$cv->save();
	}

	// ------------------------------------------------------------------
	// validate — empty consent_text (DPO-FINDING-13)
	// ------------------------------------------------------------------

	public function test_validate_rejects_empty_consent_text(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->consent_text = '';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion must contain consent text (DPO-FINDING-13).' );

		$cv->save();
	}

	public function test_validate_rejects_whitespace_only_consent_text(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->consent_text = '   ';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion must contain consent text (DPO-FINDING-13).' );

		$cv->save();
	}

	// ------------------------------------------------------------------
	// validate — form_id required
	// ------------------------------------------------------------------

	public function test_validate_rejects_zero_form_id(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 0;
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion must belong to a form (form_id required).' );

		$cv->save();
	}

	public function test_validate_rejects_negative_form_id(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = -1;
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion must belong to a form (form_id required).' );

		$cv->save();
	}

	// ------------------------------------------------------------------
	// validate — locale format (xx_XX)
	// ------------------------------------------------------------------

	public function test_validate_rejects_invalid_locale_format(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'deutsch';
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion locale must match format xx_XX.' );

		$cv->save();
	}

	public function test_validate_rejects_empty_locale(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = '';
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion locale must match format xx_XX.' );

		$cv->save();
	}

	public function test_validate_accepts_all_supported_locales(): void {
		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$supported_locales = [ 'de_DE', 'en_US', 'fr_FR', 'es_ES', 'it_IT', 'sv_SE' ];

		foreach ( $supported_locales as $locale ) {
			$cv               = new ConsentVersion();
			$cv->form_id      = 5;
			$cv->locale       = $locale;
			$cv->version      = 1;
			$cv->consent_text = 'Consent text for ' . $locale;

			$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
			$this->wpdb->insert_id = 100;

			$cv->save();

			$this->assertSame( 100, $cv->id, "Locale {$locale} should be accepted." );
		}
	}

	/**
	 * Locales with valid xx_XX format but not in SUPPORTED_LOCALES must be rejected.
	 *
	 * @dataProvider unsupported_locales_provider
	 */
	public function test_validate_rejects_unsupported_locales( string $locale ): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = $locale;
		$cv->version      = 1;
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage(
			sprintf( 'ConsentVersion locale "%s" is not in the supported locales list.', $locale )
		);

		$cv->save();
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsupported_locales_provider(): array {
		return [
			'tr_TR' => [ 'tr_TR' ],
			'ar_SA' => [ 'ar_SA' ],
			'pt_PT' => [ 'pt_PT' ],
			'ja_JP' => [ 'ja_JP' ],
		];
	}

	// ------------------------------------------------------------------
	// save — preserves explicit valid_from when set
	// ------------------------------------------------------------------

	public function test_save_preserves_explicit_valid_from(): void {
		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->version      = 1;
		$cv->consent_text = 'Text.';
		$cv->valid_from   = '2026-05-01 00:00:00';

		$inserted_data = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$inserted_data ): int {
					$inserted_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 15;

		$cv->save();

		$this->assertSame( '2026-05-01 00:00:00', $inserted_data['valid_from'] );
	}

	// ------------------------------------------------------------------
	// from_row — property mapping with defaults
	// ------------------------------------------------------------------

	public function test_from_row_maps_all_properties_correctly(): void {
		$row = $this->make_row( [
			'id'                 => '42',
			'form_id'            => '7',
			'locale'             => 'en_US',
			'version'            => '3',
			'consent_text'       => 'I consent.',
			'privacy_policy_url' => null,
			'valid_from'         => '2026-03-01 08:00:00',
			'created_at'         => '2026-03-01 08:00:00',
		] );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = ConsentVersion::find( 42 );

		$this->assertSame( 42, $result->id );
		$this->assertSame( 7, $result->form_id );
		$this->assertSame( 'en_US', $result->locale );
		$this->assertSame( 3, $result->version );
		$this->assertSame( 'I consent.', $result->consent_text );
		$this->assertNull( $result->privacy_policy_url );
		$this->assertSame( '2026-03-01 08:00:00', $result->valid_from );
		$this->assertSame( '2026-03-01 08:00:00', $result->created_at );
	}

	// ------------------------------------------------------------------
	// SEC-SQL-01: All queries use $wpdb->prepare()
	// ------------------------------------------------------------------

	public function test_find_uses_prepared_statement(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				\Mockery::on( fn( string $sql ) => str_contains( $sql, 'WHERE id = %d' ) ),
				1
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		ConsentVersion::find( 1 );
	}

	public function test_get_current_version_uses_prepared_statement(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				\Mockery::on(
					fn( string $sql ) => str_contains( $sql, 'form_id = %d' )
						&& str_contains( $sql, 'locale = %s' )
						&& str_contains( $sql, 'ORDER BY version DESC LIMIT 1' )
				),
				5,
				'de_DE'
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		ConsentVersion::get_current_version( 5, 'de_DE' );
	}

	public function test_find_by_form_and_locale_uses_prepared_statement(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				\Mockery::on(
					fn( string $sql ) => str_contains( $sql, 'form_id = %d' )
						&& str_contains( $sql, 'locale = %s' )
						&& str_contains( $sql, 'version = %d' )
				),
				5,
				'de_DE',
				2
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		ConsentVersion::find_by_form_and_locale( 5, 'de_DE', 2 );
	}

	public function test_find_all_by_form_uses_prepared_statement(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				\Mockery::on(
					fn( string $sql ) => str_contains( $sql, 'form_id = %d' )
						&& str_contains( $sql, 'ORDER BY locale ASC, version DESC' )
				),
				5
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

		ConsentVersion::find_all_by_form( 5 );
	}

	// ------------------------------------------------------------------
	// ARCH-v104-03: validate — parent form must exist
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security ARCH-v104-03 — ConsentVersion cannot reference a non-existent form.
	 */
	public function test_validate_rejects_consent_version_for_nonexistent_form(): void {
		// Override get_transient to NOT return a form (cache miss).
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Form::find() falls through to wpdb — return null (form not in DB).
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( '' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$cv               = new ConsentVersion();
		$cv->form_id      = 999;
		$cv->locale       = 'de_DE';
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ConsentVersion references a non-existent form.' );

		$cv->save();
	}

	// ------------------------------------------------------------------
	// ARCH-v104-03: validate — parent form must have legal_basis = 'consent'
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security ARCH-v104-03 — ConsentVersion cannot be created for non-consent forms.
	 */
	public function test_validate_rejects_consent_version_for_non_consent_legal_basis(): void {
		// Return a form with legal_basis = 'contract' instead of 'consent'.
		Functions\when( 'get_transient' )->alias( function ( $key ) {
			if ( is_string( $key ) && strpos( $key, 'dsgvo_form_' ) === 0 ) {
				$form              = new Form();
				$form->id          = 5;
				$form->title       = 'Contract Form';
				$form->legal_basis = 'contract';
				$form->retention_days = 90;
				return $form;
			}
			return false;
		} );

		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'de_DE';
		$cv->consent_text = 'Text.';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'legal_basis is "contract", not "consent"' );

		$cv->save();
	}

	// ------------------------------------------------------------------
	// ARCH-v104-02 / Task #211: SUPPORTED_LOCALES structure
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security ARCH-v104-02 — SUPPORTED_LOCALES must be an associative array
	 *           with locale codes as keys and display labels as values.
	 */
	public function test_supported_locales_is_associative_with_locale_keys_and_labels(): void {
		$locales = ConsentVersion::SUPPORTED_LOCALES;

		$this->assertNotEmpty( $locales );

		foreach ( $locales as $locale => $label ) {
			// Key must be a locale code (xx_XX format).
			$this->assertMatchesRegularExpression(
				'/^[a-z]{2}_[A-Z]{2}$/',
				$locale,
				"Locale key '{$locale}' must match xx_XX format."
			);
			// Value must be a non-empty display label string.
			$this->assertIsString( $label );
			$this->assertNotEmpty( $label, "Label for '{$locale}' must not be empty." );
		}

		// Verify the six expected locales are present.
		$this->assertArrayHasKey( 'de_DE', $locales );
		$this->assertArrayHasKey( 'en_US', $locales );
		$this->assertArrayHasKey( 'fr_FR', $locales );
		$this->assertArrayHasKey( 'es_ES', $locales );
		$this->assertArrayHasKey( 'it_IT', $locales );
		$this->assertArrayHasKey( 'sv_SE', $locales );
	}

	// ------------------------------------------------------------------
	// ARCH-v104-02 / Task #211: Filter extensibility
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security ARCH-v104-02 — wpdsgvo_supported_locales filter allows extending locales.
	 */
	public function test_validate_accepts_locale_added_via_filter(): void {
		// pt_BR has valid xx_XX format but is NOT in SUPPORTED_LOCALES.
		// Adding it via filter should make it accepted.
		Filters\expectApplied( 'wpdsgvo_supported_locales' )
			->once()
			->andReturnUsing( function ( array $locales ): array {
				$locales['pt_BR'] = 'Português';
				return $locales;
			} );

		Functions\when( 'current_time' )->justReturn( '2026-04-17 12:00:00' );

		$cv               = new ConsentVersion();
		$cv->form_id      = 5;
		$cv->locale       = 'pt_BR';
		$cv->version      = 1;
		$cv->consent_text = 'Texto de consentimento.';

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 100;

		$cv->save();

		$this->assertSame( 100, $cv->id );
	}
}
