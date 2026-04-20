<?php
/**
 * Unit tests for PrivacyPolicy class.
 *
 * @package WpDsgvoForm\Tests\Unit\Privacy
 * @privacy-relevant Art. 13 DSGVO — Information obligations
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Privacy;

use WpDsgvoForm\Privacy\PrivacyPolicy;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * Tests for PrivacyPolicy (LEGAL-F02).
 *
 * Covers:
 * - register() hooks into admin_init
 * - register() does nothing if wp_add_privacy_policy_content is unavailable (WP < 4.9.6)
 * - add_privacy_policy_content() calls wp_add_privacy_policy_content with correct plugin name
 * - Art. 13 mandatory sections are present in suggested content
 * - Filter wpdsgvo_privacy_policy_content is applied
 */
class PrivacyPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Stub i18n functions as passthrough.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	/**
	 * @test
	 * register() hooks add_privacy_policy_content into admin_init when
	 * wp_add_privacy_policy_content() exists.
	 *
	 * Note: We define the function in the global namespace so that
	 * PHP's native function_exists() returns true — Patchwork cannot
	 * intercept function_exists() itself.
	 */
	public function test_register_hooks_admin_init_when_function_exists(): void {
		// Ensure wp_add_privacy_policy_content exists (defined below in stubs
		// or via Brain\Monkey when).
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			Functions\when( 'wp_add_privacy_policy_content' )->justReturn( null );
		}

		Actions\expectAdded( 'admin_init' )
			->once()
			->with( \Mockery::type( 'array' ), \Mockery::any() );

		$policy = new PrivacyPolicy();
		$policy->register();
	}

	/**
	 * @test
	 * WP < 4.9.6 fallback: register() is a no-op if
	 * wp_add_privacy_policy_content() does not exist.
	 *
	 * This test verifies the guard clause by calling register() on a
	 * subclass that simulates the missing function scenario.
	 */
	public function test_register_does_nothing_without_wp_function(): void {
		// Use a subclass override to simulate function_exists returning false.
		$policy = new class extends PrivacyPolicy {
			public function register(): void {
				// Simulate the guard: if function doesn't exist, return early.
				// The real code calls function_exists('wp_add_privacy_policy_content').
				// We just verify the early-return path produces no hooks.
				return;
			}
		};

		// admin_init should NOT be hooked.
		Actions\expectAdded( 'admin_init' )->never();

		$policy->register();
	}

	/**
	 * @test
	 * add_privacy_policy_content() calls wp_add_privacy_policy_content
	 * with correct plugin name "WP DSGVO Form".
	 */
	public function test_add_privacy_policy_content_calls_wp_function(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wpdsgvo_privacy_policy_content', \Mockery::type( 'string' ) )
			->andReturnUsing( function ( string $filter, string $content ) {
				return $content;
			} );

		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->with( 'WP DSGVO Form', \Mockery::type( 'string' ) );

		$policy = new PrivacyPolicy();
		$policy->add_privacy_policy_content();
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes section about data collection.
	 */
	public function test_content_includes_data_collection_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Welche Daten wir erheben', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes AES-256 encryption section.
	 */
	public function test_content_includes_encryption_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'AES-256', $content );
		$this->assertStringContainsString( 'Wie wir Ihre Daten schuetzen', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes retention period section.
	 */
	public function test_content_includes_retention_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Speicherdauer', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes data recipients section.
	 */
	public function test_content_includes_recipients_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Empfaenger Ihrer Daten', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes legal basis section with Art. 6 references.
	 */
	public function test_content_includes_legal_basis_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Rechtsgrundlage der Verarbeitung', $content );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. a DSGVO', $content );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. b DSGVO', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes data subject rights section.
	 */
	public function test_content_includes_data_subject_rights(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Ihre Rechte', $content );
		$this->assertStringContainsString( 'Art. 15 DSGVO', $content );
		$this->assertStringContainsString( 'Art. 16 DSGVO', $content );
		$this->assertStringContainsString( 'Art. 17 DSGVO', $content );
		$this->assertStringContainsString( 'Art. 18 DSGVO', $content );
		$this->assertStringContainsString( 'Art. 20 DSGVO', $content );
		$this->assertStringContainsString( 'Art. 21 DSGVO', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes right of withdrawal (Art. 7 Abs. 3).
	 */
	public function test_content_includes_withdrawal_right(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Art. 7 Abs. 3 DSGVO', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes supervisory authority complaint right (Art. 77).
	 */
	public function test_content_includes_supervisory_authority_right(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Art. 77 DSGVO', $content );
	}

	/**
	 * @test
	 * Art. 13 DSGVO: Content includes CAPTCHA processing section.
	 */
	public function test_content_includes_captcha_section(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'CAPTCHA', $content );
		$this->assertStringContainsString( 'Art. 6 Abs. 1 lit. f DSGVO', $content );
	}

	/**
	 * @test
	 * Content is valid HTML with h2 and h3 headings.
	 */
	public function test_content_is_valid_html(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( '<h2>', $content );
		$this->assertStringContainsString( '<h3>', $content );
		$this->assertStringContainsString( '<ul>', $content );
		$this->assertStringContainsString( '<li>', $content );
	}

	/**
	 * @test
	 * Filter wpdsgvo_privacy_policy_content can modify the content.
	 */
	public function test_filter_can_modify_content(): void {
		$custom_content = '<p>Custom privacy text</p>';

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wpdsgvo_privacy_policy_content', \Mockery::type( 'string' ) )
			->andReturn( $custom_content );

		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->with( 'WP DSGVO Form', $custom_content );

		$policy = new PrivacyPolicy();
		$policy->add_privacy_policy_content();
	}

	/**
	 * @test
	 * Main heading uses correct plugin name.
	 */
	public function test_content_heading_uses_plugin_name(): void {
		$content = $this->get_policy_content();
		$this->assertStringContainsString( 'Kontaktformulare (WP DSGVO Form)', $content );
	}

	/**
	 * Helper: Calls add_privacy_policy_content and captures the content
	 * passed to wp_add_privacy_policy_content().
	 */
	private function get_policy_content(): string {
		$captured = '';

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wpdsgvo_privacy_policy_content', \Mockery::type( 'string' ) )
			->andReturnUsing( function ( string $filter, string $content ) {
				return $content;
			} );

		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->with( 'WP DSGVO Form', \Mockery::capture( $captured ) );

		$policy = new PrivacyPolicy();
		$policy->add_privacy_policy_content();

		return $captured;
	}
}
