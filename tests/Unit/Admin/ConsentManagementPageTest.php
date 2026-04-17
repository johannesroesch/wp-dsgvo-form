<?php
/**
 * Unit tests for ConsentManagementPage (Admin-UI Task #125).
 *
 * Tests consent text versioning admin interface:
 * - Permission checks (dsgvo_form_manage capability)
 * - Form lookup and validation
 * - Save flow: locale validation, empty text (fail-closed), unchanged text,
 *   HTTPS URL requirement, success, RuntimeException handling
 * - Render output: locale tabs, editor form, version history
 * - Nonce verification
 *
 * @package WpDsgvoForm\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Admin;

use WpDsgvoForm\Admin\ConsentManagementPage;
use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for ConsentManagementPage admin interface.
 *
 * Security requirements: CONSENT-I18N-01 through 05, Art. 7 DSGVO.
 */
class ConsentManagementPageTest extends TestCase {

	private ConsentManagementPage $page;
	private array $original_get  = [];
	private array $original_post = [];
	private array $original_server = [];

	protected function setUp(): void {
		parent::setUp();

		$this->page            = new ConsentManagementPage();
		$this->original_get    = $_GET;
		$this->original_post   = $_POST;
		$this->original_server = $_SERVER;

		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		$_GET    = $this->original_get;
		$_POST   = $this->original_post;
		$_SERVER = $this->original_server;
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Stubs common WordPress functions for rendering.
	 */
	private function stub_wp_functions(): void {
		$return_arg = [
			'__', 'esc_html__', 'esc_html', 'esc_url', 'esc_attr',
			'sanitize_text_field', 'wp_kses_post', 'esc_url_raw',
			'esc_textarea', 'wp_strip_all_tags',
			'wp_unslash',
		];
		foreach ( $return_arg as $func ) {
			Functions\when( $func )->returnArg();
		}

		Functions\when( 'esc_html_e' )->alias( function ( string $text ): void {
			echo $text;
		} );
		Functions\when( 'esc_attr_e' )->alias( function ( string $text ): void {
			echo $text;
		} );
		Functions\when( 'admin_url' )->alias( function ( string $path = '' ): string {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'absint' )->alias( function ( $val ): int {
			return abs( (int) $val );
		} );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_date' )->justReturn( '17.04.2026 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-17 10:00:00' );
	}

	/**
	 * Creates a Form object for testing.
	 */
	private function create_form( int $id = 1, string $legal_basis = 'consent' ): Form {
		$form              = new Form();
		$form->id          = $id;
		$form->title       = 'Kontaktformular';
		$form->legal_basis = $legal_basis;
		return $form;
	}

	/**
	 * Creates a ConsentVersion object for testing.
	 */
	private function create_version(
		int $form_id = 1,
		string $locale = 'de_DE',
		int $version = 1,
		string $text = 'Ich stimme der Datenverarbeitung zu.',
		?string $url = null
	): ConsentVersion {
		$cv                     = new ConsentVersion();
		$cv->id                 = $version;
		$cv->form_id            = $form_id;
		$cv->locale             = $locale;
		$cv->version            = $version;
		$cv->consent_text       = $text;
		$cv->privacy_policy_url = $url;
		$cv->valid_from         = '2026-04-17 10:00:00';
		$cv->created_at         = '2026-04-17 10:00:00';
		return $cv;
	}

	/**
	 * Mocks Form::find() to return a form (or null).
	 */
	private function mock_form_find( ?Form $form ): void {
		Functions\when( 'get_transient' )->justReturn( $form );
	}

	/**
	 * Mocks ConsentVersion::find_all_by_form() and get_current_version().
	 *
	 * Uses $wpdb mock since static methods use global $wpdb.
	 *
	 * @param ConsentVersion[] $all_versions All versions for find_all_by_form.
	 * @param ConsentVersion|null $current Current version for get_current_version.
	 */
	private function mock_consent_versions( array $all_versions, ?ConsentVersion $current = null ): void {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// find_all_by_form returns rows.
		$rows = array_map( function ( ConsentVersion $v ): array {
			return [
				'id'                 => $v->id,
				'form_id'            => $v->form_id,
				'locale'             => $v->locale,
				'version'            => $v->version,
				'consent_text'       => $v->consent_text,
				'privacy_policy_url' => $v->privacy_policy_url,
				'valid_from'         => $v->valid_from,
				'created_at'         => $v->created_at,
			];
		}, $all_versions );

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );

		if ( $current !== null ) {
			$wpdb->shouldReceive( 'get_row' )->andReturn( [
				'id'                 => $current->id,
				'form_id'            => $current->form_id,
				'locale'             => $current->locale,
				'version'            => $current->version,
				'consent_text'       => $current->consent_text,
				'privacy_policy_url' => $current->privacy_policy_url,
				'valid_from'         => $current->valid_from,
				'created_at'         => $current->created_at,
			] );
		} else {
			$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		}

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Sets up POST submission context.
	 */
	private function setup_post_save( int $form_id, string $locale, string $text, string $url = '' ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['dsgvo_consent_action'] = 'save';
		$_POST['consent_locale']       = $locale;
		$_POST['consent_text']         = $text;
		$_POST['privacy_policy_url']   = $url;
	}

	/**
	 * Mocks redirect to throw exception (prevents exit from killing PHPUnit).
	 *
	 * @param string &$redirected_url Captures the redirect URL.
	 * @return void
	 */
	private function mock_redirect_throws( string &$redirected_url = '' ): void {
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ) use ( &$redirected_url ): void {
				$redirected_url = $url;
				// Use LogicException (not RuntimeException) to avoid being caught
				// by handle_save()'s catch(\RuntimeException) block.
				throw new \LogicException( 'redirect_exit' );
			}
		);
	}

	// ------------------------------------------------------------------
	// Permission check
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-01 — Only users with dsgvo_form_manage can access.
	 */
	public function test_render_dies_without_manage_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias( function () use ( &$died ): void {
			$died = true;
			throw new \RuntimeException( 'wp_die' );
		} );
		Functions\when( 'esc_html__' )->returnArg();

		try {
			$this->page->render();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $died );
	}

	// ------------------------------------------------------------------
	// Form not found
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_dies_when_form_not_found(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ): int {
			return abs( (int) $val );
		} );
		$_GET['form_id'] = '999';

		// Form::find() returns null (via get_transient mock + wpdb).
		Functions\when( 'get_transient' )->justReturn( false );
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$died = false;
		Functions\when( 'wp_die' )->alias( function () use ( &$died ): void {
			$died = true;
			throw new \RuntimeException( 'wp_die' );
		} );
		Functions\when( 'esc_html__' )->returnArg();

		try {
			$this->page->render();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $died );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_dies_when_form_id_is_zero(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $val ): int {
			return abs( (int) $val );
		} );
		$_GET = []; // No form_id.

		$died = false;
		Functions\when( 'wp_die' )->alias( function () use ( &$died ): void {
			$died = true;
			throw new \RuntimeException( 'wp_die' );
		} );
		Functions\when( 'esc_html__' )->returnArg();

		try {
			$this->page->render();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $died );
	}

	// ------------------------------------------------------------------
	// Render output — locale tabs
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-01 — All six supported locales shown as tabs.
	 */
	public function test_render_shows_all_six_locale_tabs(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Deutsch', $output );
		$this->assertStringContainsString( 'English', $output );
		$this->assertStringContainsString( 'Français', $output );
		$this->assertStringContainsString( 'Español', $output );
		$this->assertStringContainsString( 'Italiano', $output );
		$this->assertStringContainsString( 'Svenska', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_marks_active_locale_tab(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$_GET['locale']  = 'en_US';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		// Active tab has nav-tab-active class.
		$this->assertMatchesRegularExpression(
			'/nav-tab nav-tab-active[^>]*>\\s*English/',
			$output
		);
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_defaults_locale_to_de_DE(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		// No locale in GET.
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/nav-tab nav-tab-active[^>]*>\\s*Deutsch/',
			$output
		);
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_falls_back_to_de_DE_for_invalid_locale(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$_GET['locale']  = 'xx_XX';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/nav-tab nav-tab-active[^>]*>\\s*Deutsch/',
			$output
		);
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render output — editor form
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_shows_editor_form_with_textarea(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'consent_text', $output );
		$this->assertStringContainsString( 'privacy_policy_url', $output );
		$this->assertStringContainsString( 'dsgvo_consent_action', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_shows_fail_closed_warning_when_no_text(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Fail-Closed', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_shows_legal_basis_notice_for_contract_forms(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form( 1, 'contract' );
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Vertrag', $output );
		$this->assertStringContainsString( 'optional', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render output — version history
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-03 — Version history displayed as read-only.
	 */
	public function test_render_shows_version_history_table(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$v1 = $this->create_version( 1, 'de_DE', 1, 'Text Version 1' );
		$v2 = $this->create_version( 1, 'de_DE', 2, 'Text Version 2' );

		$this->mock_consent_versions( [ $v2, $v1 ], $v2 );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Versions-Historie', $output );
		$this->assertStringContainsString( 'Art. 7', $output );
		$this->assertStringContainsString( 'Text Version 1', $output );
		$this->assertStringContainsString( 'Text Version 2', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @test
	 */
	public function test_render_shows_current_version_info(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$current = $this->create_version( 1, 'de_DE', 3, 'Aktueller Text' );
		$this->mock_consent_versions( [ $current ], $current );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Aktuelle Version', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — invalid locale
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-01 — Only supported locales accepted.
	 */
	public function test_save_rejects_invalid_locale(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'xx_XX', 'Some text' );

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'error', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'Ungueltige Sprache', $notice_set['message'] ?? '' );
	}

	// ------------------------------------------------------------------
	// Save flow — empty text (Fail-Closed, DPO-FINDING-13)
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security DPO-FINDING-13 — Empty consent text triggers fail-closed warning.
	 */
	public function test_save_rejects_empty_text_fail_closed(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', '   ' ); // Whitespace-only text.

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'warning', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'Fail-Closed', $notice_set['message'] ?? '' );
	}

	// ------------------------------------------------------------------
	// Save flow — unchanged text
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-04 — No new version for unchanged text.
	 */
	public function test_save_skips_when_text_unchanged(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$existing_text = 'Ich stimme der Datenverarbeitung zu.';
		$this->setup_post_save( 1, 'de_DE', $existing_text );

		// Mock ConsentVersion::get_current_version returns matching text.
		// privacy_policy_url must be '' (not null) to match POST's empty string.
		$current = $this->create_version( 1, 'de_DE', 2, $existing_text, '' );
		$this->mock_consent_versions( [ $current ], $current );

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'info', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'unveraendert', $notice_set['message'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — HTTP privacy URL rejected
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-HTTPS — Privacy policy URL must use HTTPS.
	 */
	public function test_save_rejects_http_privacy_url(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', 'Neuer Text', 'http://example.com/datenschutz' );

		// No existing version → get_current_version returns null.
		$this->mock_consent_versions( [], null );

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'error', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'HTTPS', $notice_set['message'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — success
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-02 — New version created on text change.
	 */
	public function test_save_creates_new_version_on_text_change(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', 'Komplett neuer Text', 'https://example.com/datenschutz' );

		// No existing version.
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// get_current_version → null (no existing).
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		// save() → auto-increment: get_var for max version.
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		// save() → insert.
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$wpdb->insert_id = 1;
		$GLOBALS['wpdb'] = $wpdb;

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'success', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'gespeichert', $notice_set['message'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — RuntimeException from save()
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_save_handles_runtime_exception(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', 'Neuer Text' );

		// No existing version.
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// get_current_version → null.
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		// save() → auto-increment max version.
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		// save() → insert fails.
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$wpdb->insert_id  = 0;
		$wpdb->last_error = 'Insert failed';
		$GLOBALS['wpdb'] = $wpdb;

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'error', $notice_set['type'] ?? '' );
		$this->assertStringContainsString( 'Fehler', $notice_set['message'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — HTTPS privacy URL accepted
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_save_accepts_https_privacy_url(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', 'Text mit URL', 'https://example.com/datenschutz' );

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$wpdb->insert_id = 1;
		$GLOBALS['wpdb'] = $wpdb;

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'success', $notice_set['type'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Save flow — empty privacy URL accepted (optional field)
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_save_accepts_empty_privacy_url(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$this->setup_post_save( 1, 'de_DE', 'Text ohne URL', '' );

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$wpdb->insert_id = 1;
		$GLOBALS['wpdb'] = $wpdb;

		$notice_set = [];
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ) use ( &$notice_set ): bool {
			$notice_set = $value;
			return true;
		} );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			$this->assertSame( 'redirect_exit', $e->getMessage() );
		}

		$this->assertSame( 'success', $notice_set['type'] ?? '' );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Nonce verification
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security SEC-CSRF — Save action requires valid nonce.
	 */
	public function test_save_calls_check_admin_referer_with_form_specific_nonce(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form( 42 );
		$this->mock_form_find( $form );
		$_GET['form_id'] = '42';

		$this->setup_post_save( 42, 'xx_XX', 'Text' ); // Invalid locale to short-circuit after nonce.

		$nonce_checked = '';
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturnUsing( function ( string $action ) use ( &$nonce_checked ): bool {
				$nonce_checked = $action;
				return true;
			} );

		Functions\when( 'set_transient' )->justReturn( true );
		$this->mock_redirect_throws();

		try {
			$this->page->render();
		} catch ( \LogicException $e ) {
			// Expected — redirect after invalid locale.
		}

		$this->assertSame( 'dsgvo_consent_save_42', $nonce_checked );
	}

	// ------------------------------------------------------------------
	// Render — title includes form name
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_includes_form_title_in_heading(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$form->title = 'Mein Kontaktformular';
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Mein Kontaktformular', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render — back link
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_includes_back_link(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';
		$this->mock_consent_versions( [] );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Zurueck zur Uebersicht', $output );
		$this->assertStringContainsString( 'dsgvo-form', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render — checkmark icon for locales with text
	// ------------------------------------------------------------------

	/**
	 * @test
	 * @security CONSENT-I18N-05 — Visual indicator for completed locale texts.
	 */
	public function test_render_shows_checkmark_for_locales_with_text(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$v_de = $this->create_version( 1, 'de_DE', 1, 'Deutscher Text' );
		$this->mock_consent_versions( [ $v_de ], $v_de );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		// Checkmark icon next to the de_DE tab.
		$this->assertStringContainsString( 'dashicons-yes', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render — privacy URL in version history
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_shows_privacy_url_in_version_history(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$v = $this->create_version( 1, 'de_DE', 1, 'Text', 'https://example.com/privacy' );
		$this->mock_consent_versions( [ $v ], $v );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'https://example.com/privacy', $output );
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Render — dash for empty privacy URL in history
	// ------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_render_shows_dash_for_empty_privacy_url(): void {
		$this->stub_wp_functions();
		Functions\when( 'current_user_can' )->justReturn( true );

		$form = $this->create_form();
		$this->mock_form_find( $form );
		$_GET['form_id'] = '1';

		$v = $this->create_version( 1, 'de_DE', 1, 'Text', null );
		$this->mock_consent_versions( [ $v ], $v );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		// Em-dash or "—" for empty URL.
		$this->assertMatchesRegularExpression( '/—/', $output );
		unset( $GLOBALS['wpdb'] );
	}
}
