<?php
/**
 * Unit tests for FieldValidator.
 *
 * Covers server-side field validation: type-specific rules (SEC-VAL-01 through SEC-VAL-08),
 * whitelist approach (SEC-VAL-02), max length enforcement (SEC-VAL-03),
 * required field checks, option whitelisting, pattern validation, and sanitization.
 *
 * @package WpDsgvoForm\Tests\Unit\Validation
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Validation;

use WpDsgvoForm\Validation\FieldValidator;
use WpDsgvoForm\Models\Field;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the FieldValidator.
 */
class FieldValidatorTest extends TestCase {

	private FieldValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new FieldValidator();

		// Mock WordPress sanitization and i18n functions.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
	}

	/**
	 * Helper: creates a Field with given properties.
	 */
	private function make_field( array $props = [] ): Field {
		$field              = new Field();
		$field->id          = $props['id'] ?? 1;
		$field->form_id     = $props['form_id'] ?? 1;
		$field->field_type  = $props['field_type'] ?? 'text';
		$field->label       = $props['label'] ?? 'Test Field';
		$field->name        = $props['name'] ?? 'test_field';
		$field->is_required = $props['is_required'] ?? false;
		$field->options     = $props['options'] ?? null;
		$field->validation_rules = $props['validation_rules'] ?? null;

		return $field;
	}

	// ──────────────────────────────────────────────────
	// Required field validation (SEC-VAL-01)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-01 — Required field must not be empty
	 */
	public function test_required_field_returns_error_when_empty(): void {
		$field = $this->make_field( [
			'name'        => 'email',
			'field_type'  => 'text',
			'is_required' => true,
		] );

		$result = $this->validator->validate( [ 'email' => '' ], [ $field ] );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertArrayHasKey( 'email', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_required_field_returns_error_when_missing(): void {
		$field = $this->make_field( [
			'name'        => 'name',
			'field_type'  => 'text',
			'is_required' => true,
		] );

		$result = $this->validator->validate( [], [ $field ] );

		$this->assertArrayHasKey( 'name', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_optional_empty_field_returns_empty_string(): void {
		$field = $this->make_field( [
			'name'        => 'phone',
			'field_type'  => 'text',
			'is_required' => false,
		] );

		$result = $this->validator->validate( [ 'phone' => '' ], [ $field ] );

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( '', $result['sanitized']['phone'] );
	}

	// ──────────────────────────────────────────────────
	// Whitelist approach (SEC-VAL-02)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-02 — Unknown fields are silently discarded
	 */
	public function test_unknown_fields_are_silently_discarded(): void {
		$field = $this->make_field( [
			'name'       => 'known_field',
			'field_type' => 'text',
		] );

		$result = $this->validator->validate(
			[
				'known_field'   => 'valid',
				'unknown_field' => 'injected',
				'another_bad'   => 'hacker',
			],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertArrayHasKey( 'known_field', $result['sanitized'] );
		$this->assertArrayNotHasKey( 'unknown_field', $result['sanitized'] );
		$this->assertArrayNotHasKey( 'another_bad', $result['sanitized'] );
	}

	/**
	 * @test
	 */
	public function test_static_fields_are_skipped(): void {
		$field = $this->make_field( [
			'name'       => 'info_block',
			'field_type' => 'static',
		] );

		$result = $this->validator->validate( [ 'info_block' => 'anything' ], [ $field ] );

		$this->assertEmpty( $result['errors'] );
		$this->assertArrayNotHasKey( 'info_block', $result['sanitized'] );
	}

	// ──────────────────────────────────────────────────
	// Text field validation (SEC-VAL-03, SEC-VAL-06)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-03 — Max length enforcement, hard limit 10000
	 */
	public function test_text_field_rejects_value_exceeding_max_length(): void {
		$field = $this->make_field( [
			'name'             => 'title',
			'field_type'       => 'text',
			'validation_rules' => [ 'max_length' => 50 ],
		] );

		$result = $this->validator->validate(
			[ 'title' => str_repeat( 'a', 51 ) ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'title', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_text_field_accepts_value_within_max_length(): void {
		$field = $this->make_field( [
			'name'             => 'title',
			'field_type'       => 'text',
			'validation_rules' => [ 'max_length' => 50 ],
		] );

		$result = $this->validator->validate(
			[ 'title' => str_repeat( 'a', 50 ) ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( str_repeat( 'a', 50 ), $result['sanitized']['title'] );
	}

	/**
	 * @test
	 * @security-relevant SEC-VAL-03 — Hard limit 10000 even without configured max
	 */
	public function test_text_field_hard_limit_at_10000_chars(): void {
		$field = $this->make_field( [
			'name'       => 'message',
			'field_type' => 'text',
			// No max_length configured — hard limit applies.
		] );

		$result = $this->validator->validate(
			[ 'message' => str_repeat( 'x', 10001 ) ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'message', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_text_field_accepts_custom_pattern(): void {
		$field = $this->make_field( [
			'name'             => 'postcode',
			'field_type'       => 'text',
			'validation_rules' => [ 'pattern' => '/^\d{5}$/' ],
		] );

		$result = $this->validator->validate(
			[ 'postcode' => '76646' ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( '76646', $result['sanitized']['postcode'] );
	}

	/**
	 * @test
	 */
	public function test_text_field_rejects_value_not_matching_pattern(): void {
		$field = $this->make_field( [
			'name'             => 'postcode',
			'field_type'       => 'text',
			'validation_rules' => [ 'pattern' => '/^\d{5}$/' ],
		] );

		$result = $this->validator->validate(
			[ 'postcode' => 'abcde' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'postcode', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Email validation (SEC-VAL-04)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-04 — Email validation via WP is_email
	 */
	public function test_email_field_accepts_valid_email(): void {
		Functions\when( 'is_email' )->justReturn( true );

		$field = $this->make_field( [
			'name'       => 'email',
			'field_type' => 'email',
		] );

		$result = $this->validator->validate(
			[ 'email' => 'test@example.com' ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( 'test@example.com', $result['sanitized']['email'] );
	}

	/**
	 * @test
	 */
	public function test_email_field_rejects_invalid_email(): void {
		Functions\when( 'is_email' )->justReturn( false );

		$field = $this->make_field( [
			'name'       => 'email',
			'field_type' => 'email',
		] );

		$result = $this->validator->validate(
			[ 'email' => 'not-an-email' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'email', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Phone validation (SEC-VAL-05)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-05 — Phone number regex validation
	 */
	public function test_phone_field_accepts_valid_phone(): void {
		$field = $this->make_field( [
			'name'       => 'phone',
			'field_type' => 'tel',
		] );

		$result = $this->validator->validate(
			[ 'phone' => '+49 7251 12345' ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_phone_field_rejects_invalid_phone(): void {
		$field = $this->make_field( [
			'name'       => 'phone',
			'field_type' => 'tel',
		] );

		$result = $this->validator->validate(
			[ 'phone' => 'not a phone <script>alert(1)</script>' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'phone', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Textarea validation (SEC-VAL-06)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_textarea_field_rejects_value_exceeding_max_length(): void {
		$field = $this->make_field( [
			'name'             => 'message',
			'field_type'       => 'textarea',
			'validation_rules' => [ 'max_length' => 500 ],
		] );

		$result = $this->validator->validate(
			[ 'message' => str_repeat( 'a', 501 ) ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'message', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Checkbox / Radio / Select (SEC-VAL-07)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-07 — Only whitelisted option values accepted
	 */
	public function test_checkbox_rejects_non_whitelisted_value(): void {
		$field = $this->make_field( [
			'name'       => 'topics',
			'field_type' => 'checkbox',
			'options'    => [ 'datenschutz', 'technik', 'recht' ],
		] );

		$result = $this->validator->validate(
			[ 'topics' => [ 'datenschutz', 'injected_value' ] ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'topics', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_checkbox_accepts_whitelisted_values(): void {
		$field = $this->make_field( [
			'name'       => 'topics',
			'field_type' => 'checkbox',
			'options'    => [ 'datenschutz', 'technik', 'recht' ],
		] );

		$result = $this->validator->validate(
			[ 'topics' => [ 'datenschutz', 'recht' ] ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( [ 'datenschutz', 'recht' ], $result['sanitized']['topics'] );
	}

	/**
	 * @test
	 */
	public function test_radio_rejects_non_whitelisted_value(): void {
		$field = $this->make_field( [
			'name'       => 'priority',
			'field_type' => 'radio',
			'options'    => [ 'low', 'medium', 'high' ],
		] );

		$result = $this->validator->validate(
			[ 'priority' => 'critical' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'priority', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_select_rejects_non_whitelisted_value(): void {
		$field = $this->make_field( [
			'name'       => 'category',
			'field_type' => 'select',
			'options'    => [ 'anfrage', 'beschwerde' ],
		] );

		$result = $this->validator->validate(
			[ 'category' => 'hacked' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'category', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Date validation (SEC-VAL-08)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-08 — Date format validation
	 */
	public function test_date_field_accepts_valid_date(): void {
		$field = $this->make_field( [
			'name'       => 'birthday',
			'field_type' => 'date',
		] );

		$result = $this->validator->validate(
			[ 'birthday' => '2000-06-15' ],
			[ $field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( '2000-06-15', $result['sanitized']['birthday'] );
	}

	/**
	 * @test
	 */
	public function test_date_field_rejects_invalid_date(): void {
		$field = $this->make_field( [
			'name'       => 'birthday',
			'field_type' => 'date',
		] );

		$result = $this->validator->validate(
			[ 'birthday' => '2000-13-45' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'birthday', $result['errors'] );
	}

	/**
	 * @test
	 */
	public function test_date_field_rejects_non_date_string(): void {
		$field = $this->make_field( [
			'name'       => 'birthday',
			'field_type' => 'date',
		] );

		$result = $this->validator->validate(
			[ 'birthday' => 'not-a-date' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'birthday', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// ReDoS protection (SEC-VAL-10)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-VAL-10 — Input exceeding 1000 chars rejected before pattern match
	 */
	public function test_pattern_rejects_input_exceeding_1000_chars(): void {
		$field = $this->make_field( [
			'name'             => 'code',
			'field_type'       => 'text',
			'validation_rules' => [
				'pattern'    => '/^[a-z]+$/',
				'max_length' => 5000, // Allow long text, but pattern input cap at 1000.
			],
		] );

		$result = $this->validator->validate(
			[ 'code' => str_repeat( 'a', 1001 ) ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'code', $result['errors'] );
		$this->assertStringContainsString( '1000', $result['errors']['code'] );
	}

	/**
	 * @test
	 * @security-relevant SEC-VAL-10 — Catastrophic backtracking fails closed
	 */
	public function test_pattern_fails_closed_on_catastrophic_backtracking(): void {
		$field = $this->make_field( [
			'name'             => 'input',
			'field_type'       => 'text',
			'validation_rules' => [
				// Evil pattern: catastrophic backtracking with (a+)+.
				'pattern' => '/^(a+)+$/',
			],
		] );

		// 30 a's + 'b' triggers backtracking; with reduced limit (10000), preg_match returns false.
		$result = $this->validator->validate(
			[ 'input' => str_repeat( 'a', 30 ) . 'b' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'input', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Unknown field type
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_unknown_field_type_returns_error(): void {
		$field = $this->make_field( [
			'name'       => 'custom',
			'field_type' => 'unknown_type',
		] );

		$result = $this->validator->validate(
			[ 'custom' => 'value' ],
			[ $field ]
		);

		$this->assertArrayHasKey( 'custom', $result['errors'] );
	}

	// ──────────────────────────────────────────────────
	// Multiple fields
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_validates_multiple_fields_independently(): void {
		Functions\when( 'is_email' )->justReturn( true );

		$name_field  = $this->make_field( [
			'name'        => 'name',
			'field_type'  => 'text',
			'is_required' => true,
		] );
		$email_field = $this->make_field( [
			'name'        => 'email',
			'field_type'  => 'email',
			'is_required' => true,
		] );
		$phone_field = $this->make_field( [
			'name'        => 'phone',
			'field_type'  => 'tel',
			'is_required' => false,
		] );

		$result = $this->validator->validate(
			[
				'name'  => 'Max Mustermann',
				'email' => 'max@example.com',
				// phone omitted — optional.
			],
			[ $name_field, $email_field, $phone_field ]
		);

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( 'Max Mustermann', $result['sanitized']['name'] );
		$this->assertSame( 'max@example.com', $result['sanitized']['email'] );
		$this->assertSame( '', $result['sanitized']['phone'] );
	}
}
