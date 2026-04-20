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

	// ------------------------------------------------------------------
	// get_template — all 6 locales covered
	// ------------------------------------------------------------------

	/**
	 * @test
	 * LEGAL-I18N-04: Exactly 6 locales have templates.
	 */
	public function test_all_supported_locales_have_templates(): void {
		$locales = array_keys( ConsentVersion::SUPPORTED_LOCALES );

		foreach ( $locales as $locale ) {
			$text = ConsentTemplateHelper::get_template( $locale );
			$this->assertNotEmpty( $text, "Template for locale '{$locale}' must not be empty." );
		}

		$this->assertCount( 6, $locales );
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
		$this->assertSame( '', ConsentTemplateHelper::get_template( 'nl_NL' ) );
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
			[ 'nl_NL' => 'Nederlands' ]
		);

		Filters\expectApplied( 'wpdsgvo_supported_locales' )
			->once()
			->andReturn( $extended );

		$locales = ConsentTemplateHelper::get_available_locales();

		$this->assertArrayHasKey( 'nl_NL', $locales );
		$this->assertCount( 7, $locales );
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
}
