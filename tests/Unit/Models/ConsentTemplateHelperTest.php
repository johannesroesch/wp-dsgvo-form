<?php
/**
 * Unit tests for ConsentTemplateHelper class.
 *
 * @package WpDsgvoForm\Tests\Unit\Models
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Models;

use WpDsgvoForm\Models\ConsentTemplateHelper;
use WpDsgvoForm\Models\ConsentVersion;
use WpDsgvoForm\Models\Form;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests for ConsentTemplateHelper (LEGAL-I18N-04).
 *
 * Covers: get_template() for all 6 locales, unsupported locale handling,
 * DRAFT marker presence, apply_filters integration, get_available_locales()
 * delegation to ConsentVersion::SUPPORTED_LOCALES.
 */
class ConsentTemplateHelperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Brain\Monkey handles apply_filters natively — no stub needed.
	}

	// ------------------------------------------------------------------
	// get_template — valid locales return non-empty text
	// ------------------------------------------------------------------

	/**
	 * @test
	 * LEGAL-I18N-04: German template returns non-empty text.
	 */
	public function test_get_template_returns_german_text(): void {
		$text = ConsentTemplateHelper::get_template( 'de_DE' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'Einwilligung', $text );
	}

	/**
	 * @test
	 * LEGAL-I18N-04: English template returns non-empty text.
	 */
	public function test_get_template_returns_english_text(): void {
		$text = ConsentTemplateHelper::get_template( 'en_US' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'consent', $text );
	}

	/**
	 * @test
	 * LEGAL-I18N-04: French template returns non-empty text.
	 */
	public function test_get_template_returns_french_text(): void {
		$text = ConsentTemplateHelper::get_template( 'fr_FR' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'consens', $text );
	}

	/**
	 * @test
	 * LEGAL-I18N-04: Spanish template returns non-empty text.
	 */
	public function test_get_template_returns_spanish_text(): void {
		$text = ConsentTemplateHelper::get_template( 'es_ES' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'consentimiento', $text );
	}

	/**
	 * @test
	 * LEGAL-I18N-04: Italian template returns non-empty text.
	 */
	public function test_get_template_returns_italian_text(): void {
		$text = ConsentTemplateHelper::get_template( 'it_IT' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'Acconsento', $text );
	}

	/**
	 * @test
	 * LEGAL-I18N-04: Swedish template returns non-empty text.
	 */
	public function test_get_template_returns_swedish_text(): void {
		$text = ConsentTemplateHelper::get_template( 'sv_SE' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'samtycker', $text );
	}

	/**
	 * @test
	 * CT-05: Dutch (nl_NL) template returns non-empty text.
	 */
	public function test_get_template_returns_dutch_text(): void {
		$text = ConsentTemplateHelper::get_template( 'nl_NL' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'toestemming', $text );
	}

	/**
	 * @test
	 * CT-05: Polish (pl_PL) template returns non-empty text.
	 */
	public function test_get_template_returns_polish_text(): void {
		$text = ConsentTemplateHelper::get_template( 'pl_PL' );

		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'zgod', $text );
	}

	// ------------------------------------------------------------------
	// get_template — all 8 locales covered
	// ------------------------------------------------------------------

	/**
	 * @test
	 * LEGAL-I18N-04: Exactly 8 locales have templates.
	 */
	public function test_all_supported_locales_have_templates(): void {
		$locales = array_keys( ConsentVersion::SUPPORTED_LOCALES );

		foreach ( $locales as $locale ) {
			$text = ConsentTemplateHelper::get_template( $locale );
			$this->assertNotEmpty( $text, "Template for locale '{$locale}' must not be empty." );
		}

		$this->assertCount( 8, $locales );
	}

	// ------------------------------------------------------------------
	// get_template — DRAFT marker
	// ------------------------------------------------------------------

	/**
	 * @test
	 * All templates contain the <!-- DRAFT --> marker.
	 */
	public function test_all_templates_contain_draft_marker(): void {
		$locales = array_keys( ConsentVersion::SUPPORTED_LOCALES );

		foreach ( $locales as $locale ) {
			$text = ConsentTemplateHelper::get_template( $locale );
			$this->assertStringContainsString(
				'<!-- DRAFT -->',
				$text,
				"Template for '{$locale}' must contain <!-- DRAFT --> marker."
			);
		}
	}

	// ------------------------------------------------------------------
	// get_template — unsupported locale returns empty string
	// ------------------------------------------------------------------

	/**
	 * @test
	 * Unsupported locale returns empty string (no fallback).
	 */
	public function test_get_template_returns_empty_for_unsupported_locale(): void {
		$this->assertSame( '', ConsentTemplateHelper::get_template( 'pt_BR' ) );
	}

	/**
	 * @test
	 * Completely invalid locale returns empty string.
	 */
	public function test_get_template_returns_empty_for_invalid_locale(): void {
		$this->assertSame( '', ConsentTemplateHelper::get_template( 'xyz' ) );
	}

	/**
	 * @test
	 * Empty string locale returns empty string.
	 */
	public function test_get_template_returns_empty_for_empty_locale(): void {
		$this->assertSame( '', ConsentTemplateHelper::get_template( '' ) );
	}

	/**
	 * @test
	 * Short locale codes (de, en) are NOT supported — must be full xx_XX format.
	 */
	public function test_get_template_returns_empty_for_short_locale_code(): void {
		$this->assertSame( '', ConsentTemplateHelper::get_template( 'de' ) );
		$this->assertSame( '', ConsentTemplateHelper::get_template( 'en' ) );
	}

	// ------------------------------------------------------------------
	// get_template — apply_filters integration
	// ------------------------------------------------------------------

	/**
	 * @test
	 * get_template() passes result through 'wpdsgvo_consent_template' filter.
	 */
	public function test_get_template_applies_filter(): void {
		Filters\expectApplied( 'wpdsgvo_consent_template' )
			->once()
			->with( \Mockery::type( 'string' ), 'de_DE' );

		ConsentTemplateHelper::get_template( 'de_DE' );
	}

	/**
	 * @test
	 * Filter can modify the template text.
	 */
	public function test_get_template_filter_can_modify_text(): void {
		Filters\expectApplied( 'wpdsgvo_consent_template' )
			->once()
			->andReturn( 'Custom filtered text' );

		$text = ConsentTemplateHelper::get_template( 'de_DE' );

		$this->assertSame( 'Custom filtered text', $text );
	}

	// ------------------------------------------------------------------
	// get_available_locales — delegates to ConsentVersion::SUPPORTED_LOCALES
	// ------------------------------------------------------------------

	/**
	 * @test
	 * LEGAL-I18N-04: get_available_locales returns SUPPORTED_LOCALES.
	 */
	public function test_get_available_locales_returns_supported_locales(): void {
		$locales = ConsentTemplateHelper::get_available_locales();

		$this->assertSame( ConsentVersion::SUPPORTED_LOCALES, $locales );
	}

	/**
	 * @test
	 * get_available_locales passes through 'wpdsgvo_supported_locales' filter.
	 */
	public function test_get_available_locales_applies_filter(): void {
		Filters\expectApplied( 'wpdsgvo_supported_locales' )
			->once()
			->with( ConsentVersion::SUPPORTED_LOCALES );

		ConsentTemplateHelper::get_available_locales();
	}

	/**
	 * @test
	 * Filter can extend available locales.
	 */
	public function test_get_available_locales_filter_can_add_locale(): void {
		$extended = array_merge(
			ConsentVersion::SUPPORTED_LOCALES,
			[ 'pt_BR' => 'Português' ]
		);

		Filters\expectApplied( 'wpdsgvo_supported_locales' )
			->once()
			->andReturn( $extended );

		$locales = ConsentTemplateHelper::get_available_locales();

		$this->assertArrayHasKey( 'pt_BR', $locales );
		$this->assertCount( 9, $locales );
	}

	// ------------------------------------------------------------------
	// Template content quality — each mentions revocation right
	// ------------------------------------------------------------------

	/**
	 * @test
	 * All templates mention the right to revoke consent (DSGVO Art. 7 Abs. 3).
	 */
	public function test_all_templates_mention_revocation(): void {
		$revocation_keywords = [
			'de_DE' => 'widerrufen',
			'en_US' => 'revoke',
			'fr_FR' => 'revoquer',
			'es_ES' => 'revocar',
			'it_IT' => 'revocare',
			'nl_NL' => 'intrekken',
			'pl_PL' => 'wycofa',
			'sv_SE' => 'aterkalla',
		];

		foreach ( $revocation_keywords as $locale => $keyword ) {
			$text = ConsentTemplateHelper::get_template( $locale );
			$this->assertStringContainsString(
				$keyword,
				$text,
				"Template for '{$locale}' must mention revocation right (Art. 7 Abs. 3 DSGVO)."
			);
		}
	}

	// ------------------------------------------------------------------
	// resolve_placeholders — DPO-SOLL-F04/F05
	// ------------------------------------------------------------------

	/**
	 * Helper: creates a Form with retention_days for placeholder tests.
	 */
	private function create_form( int $retention_days = 90 ): Form {
		$form                = new Form();
		$form->id            = 1;
		$form->title         = 'Test Form';
		$form->slug          = 'test-form';
		$form->legal_basis   = 'consent';
		$form->retention_days = $retention_days;
		return $form;
	}

	/**
	 * @test
	 * DPO-SOLL-F04: {retention_days} is replaced with form's retention period.
	 */
	public function test_resolve_placeholders_replaces_retention_days(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$template = 'Daten werden nach {retention_days} Tagen geloescht.';
		$form     = $this->create_form( 30 );

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form );

		$this->assertSame( 'Daten werden nach 30 Tagen geloescht.', $result );
	}

	/**
	 * @test
	 * DPO-SOLL-F05: {controller_email} is replaced with configured email.
	 */
	public function test_resolve_placeholders_replaces_controller_email(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			return match ( $key ) {
				'wpdsgvo_controller_email' => 'datenschutz@example.com',
				'wpdsgvo_controller_name'  => 'Test GmbH',
				default                    => $default,
			};
		} );

		$template = 'E-Mail an {controller_email}';
		$form     = $this->create_form();

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form );

		$this->assertSame( 'E-Mail an datenschutz@example.com', $result );
	}

	/**
	 * @test
	 * DPO-SOLL-F05: {controller_name} is replaced with configured name.
	 */
	public function test_resolve_placeholders_replaces_controller_name(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			return match ( $key ) {
				'wpdsgvo_controller_name'  => 'Muster GmbH',
				'wpdsgvo_controller_email' => 'info@muster.de',
				default                    => $default,
			};
		} );

		$template = 'verarbeitet durch {controller_name}';
		$form     = $this->create_form();

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form );

		$this->assertSame( 'verarbeitet durch Muster GmbH', $result );
	}

	/**
	 * @test
	 * {privacy_policy_url} is replaced only when URL is provided.
	 */
	public function test_resolve_placeholders_replaces_privacy_url_when_provided(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$template = '<a href="{privacy_policy_url}">Datenschutz</a>';
		$form     = $this->create_form();

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form, 'https://example.com/privacy' );

		$this->assertSame( '<a href="https://example.com/privacy">Datenschutz</a>', $result );
	}

	/**
	 * @test
	 * {privacy_policy_url} is NOT replaced when URL is empty.
	 */
	public function test_resolve_placeholders_keeps_privacy_url_placeholder_when_empty(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$template = '<a href="{privacy_policy_url}">Datenschutz</a>';
		$form     = $this->create_form();

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form, '' );

		$this->assertStringContainsString( '{privacy_policy_url}', $result );
	}

	/**
	 * @test
	 * All placeholders resolved together in a real template.
	 */
	public function test_resolve_placeholders_replaces_all_placeholders(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			return match ( $key ) {
				'wpdsgvo_controller_name'  => 'ACME Corp',
				'wpdsgvo_controller_email' => 'privacy@acme.com',
				default                    => $default,
			};
		} );

		$template = '{controller_name} speichert {retention_days} Tage. Kontakt: {controller_email}. URL: {privacy_policy_url}';
		$form     = $this->create_form( 60 );

		$result = ConsentTemplateHelper::resolve_placeholders( $template, $form, 'https://acme.com/privacy' );

		$this->assertStringContainsString( 'ACME Corp', $result );
		$this->assertStringContainsString( '60', $result );
		$this->assertStringContainsString( 'privacy@acme.com', $result );
		$this->assertStringContainsString( 'https://acme.com/privacy', $result );
		$this->assertStringNotContainsString( '{', $result );
	}

	// ------------------------------------------------------------------
	// get_resolved_template — UX-TMPL-01
	// ------------------------------------------------------------------

	/**
	 * @test
	 * UX-TMPL-01: get_resolved_template returns resolved text for valid locale.
	 */
	public function test_get_resolved_template_returns_resolved_text(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			return match ( $key ) {
				'wpdsgvo_controller_name'  => 'Test GmbH',
				'wpdsgvo_controller_email' => 'test@example.com',
				default                    => $default,
			};
		} );

		$form   = $this->create_form( 90 );
		$result = ConsentTemplateHelper::get_resolved_template( 'de_DE', $form, 'https://example.com/datenschutz' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'Test GmbH', $result );
		$this->assertStringContainsString( '90', $result );
		$this->assertStringContainsString( 'test@example.com', $result );
		$this->assertStringContainsString( 'https://example.com/datenschutz', $result );
		$this->assertStringNotContainsString( '{controller_name}', $result );
		$this->assertStringNotContainsString( '{retention_days}', $result );
	}

	/**
	 * @test
	 * UX-TMPL-01: get_resolved_template returns empty string for unsupported locale.
	 */
	public function test_get_resolved_template_returns_empty_for_unsupported_locale(): void {
		$form   = $this->create_form();
		$result = ConsentTemplateHelper::get_resolved_template( 'pt_BR', $form );

		$this->assertSame( '', $result );
	}

	/**
	 * @test
	 * UX-TMPL-01: get_resolved_template works for all 8 supported locales.
	 */
	public function test_get_resolved_template_works_for_all_locales(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, $default = '' ) {
			return match ( $key ) {
				'wpdsgvo_controller_name'  => 'Test GmbH',
				'wpdsgvo_controller_email' => 'info@test.de',
				default                    => $default,
			};
		} );

		$form    = $this->create_form( 365 );
		$locales = array_keys( ConsentVersion::SUPPORTED_LOCALES );

		foreach ( $locales as $locale ) {
			$result = ConsentTemplateHelper::get_resolved_template( $locale, $form, 'https://test.de/privacy' );
			$this->assertNotEmpty( $result, "Resolved template for '{$locale}' must not be empty." );
			$this->assertStringContainsString( 'Test GmbH', $result );
			$this->assertStringContainsString( '365', $result );
		}
	}
}
