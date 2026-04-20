<?php
/**
 * Server-side field validation for form submissions.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Validation;

defined('ABSPATH') || exit;

use WpDsgvoForm\Models\Field;

/**
 * Server-side field validation for form submissions.
 *
 * Validates submitted field values against their configured type,
 * required status, and custom validation rules.
 *
 * Security requirements: SEC-VAL-01 through SEC-VAL-11.
 *
 * @security-critical All validation is server-side (SEC-VAL-01).
 *                    Client-side validation is UX only, never security.
 */
class FieldValidator {

	/**
	 * Hard limit for text/textarea field length (SEC-VAL-03).
	 */
	private const MAX_FIELD_LENGTH = 10000;

	/**
	 * Max input length for custom regex pattern matching (SEC-VAL-10).
	 *
	 * Prevents ReDoS by capping the subject length before matching
	 * against admin-configured patterns that may contain backtracking.
	 */
	private const MAX_PATTERN_INPUT_LENGTH = 1000;

	/**
	 * Phone number regex (SEC-VAL-05).
	 */
	private const PHONE_PATTERN = '/^\+?[0-9\s\-\(\)]{5,20}$/';

	/**
	 * Date format for validation (SEC-VAL-08).
	 */
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * Validates all submitted fields against their definitions.
	 *
	 * SEC-VAL-02: Whitelist approach — only configured fields accepted,
	 * unknown fields are silently discarded.
	 *
	 * @param array   $submitted_data Submitted field values (field_name => value).
	 * @param Field[] $fields         Configured field definitions from the database.
	 * @return array{sanitized: array<string, mixed>, errors: array<string, string>}
	 *         Sanitized values and any validation errors keyed by field name.
	 */
	public function validate( array $submitted_data, array $fields ): array {
		$sanitized = [];
		$errors    = [];

		foreach ( $fields as $field ) {
			// Skip static text blocks — they don't accept input.
			if ( $field->field_type === 'static' ) {
				continue;
			}

			$name  = $field->name;
			$value = $submitted_data[ $name ] ?? null;

			// SEC-VAL-01: Required field check.
			if ( $field->is_required && $this->is_empty_value( $value ) ) {
				$errors[ $name ] = sprintf(
					/* translators: %s: field label */
					__( 'Das Feld "%s" ist ein Pflichtfeld.', 'wp-dsgvo-form' ),
					$field->label
				);
				continue;
			}

			// Skip non-required empty fields.
			if ( $this->is_empty_value( $value ) ) {
				$sanitized[ $name ] = '';
				continue;
			}

			// Type-specific validation and sanitization.
			$result = $this->validate_field_type( $field, $value );

			if ( $result['error'] !== null ) {
				$errors[ $name ] = $result['error'];
			} else {
				$sanitized[ $name ] = $result['value'];
			}
		}

		return [
			'sanitized' => $sanitized,
			'errors'    => $errors,
		];
	}

	/**
	 * Validates and sanitizes a single field value based on its type.
	 *
	 * @param Field $field The field definition.
	 * @param mixed $value The submitted value.
	 * @return array{value: mixed, error: string|null} Sanitized value or error message.
	 */
	private function validate_field_type( Field $field, $value ): array {
		switch ( $field->field_type ) {
			case 'text':
				return $this->validate_text( $field, $value );

			case 'email':
				return $this->validate_email( $field, $value );

			case 'tel':
				return $this->validate_phone( $field, $value );

			case 'textarea':
				return $this->validate_textarea( $field, $value );

			case 'checkbox':
				return $this->validate_checkbox( $field, $value );

			case 'radio':
				return $this->validate_radio( $field, $value );

			case 'select':
				return $this->validate_select( $field, $value );

			case 'date':
				return $this->validate_date( $field, $value );

			case 'file':
				// File validation is handled separately by FileHandler.
				return [ 'value' => $value, 'error' => null ];

			default:
				return [
					'value' => null,
					'error' => __( 'Unbekannter Feldtyp.', 'wp-dsgvo-form' ),
				];
		}
	}

	/**
	 * Validates a text field (SEC-VAL-06).
	 */
	private function validate_text( Field $field, $value ): array {
		$value = (string) $value;

		// SEC-VAL-03: Enforce max length.
		$max_length = $this->get_max_length( $field );
		if ( mb_strlen( $value ) > $max_length ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: 1: field label, 2: max length */
					__( '"%1$s" darf maximal %2$d Zeichen lang sein.', 'wp-dsgvo-form' ),
					$field->label,
					$max_length
				),
			];
		}

		// Custom pattern validation.
		$pattern_error = $this->validate_pattern( $field, $value );
		if ( $pattern_error !== null ) {
			return [ 'value' => null, 'error' => $pattern_error ];
		}

		return [ 'value' => sanitize_text_field( $value ), 'error' => null ];
	}

	/**
	 * Validates an email field (SEC-VAL-04).
	 */
	private function validate_email( Field $field, $value ): array {
		$value     = (string) $value;
		$sanitized = sanitize_email( $value );

		if ( ! is_email( $sanitized ) ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: %s: field label */
					__( 'Bitte geben Sie eine gueltige E-Mail-Adresse fuer "%s" ein.', 'wp-dsgvo-form' ),
					$field->label
				),
			];
		}

		return [ 'value' => $sanitized, 'error' => null ];
	}

	/**
	 * Validates a phone field (SEC-VAL-05).
	 */
	private function validate_phone( Field $field, $value ): array {
		$value = (string) $value;

		if ( ! preg_match( self::PHONE_PATTERN, $value ) ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: %s: field label */
					__( 'Bitte geben Sie eine gueltige Telefonnummer fuer "%s" ein.', 'wp-dsgvo-form' ),
					$field->label
				),
			];
		}

		return [ 'value' => sanitize_text_field( $value ), 'error' => null ];
	}

	/**
	 * Validates a textarea field (SEC-VAL-06).
	 */
	private function validate_textarea( Field $field, $value ): array {
		$value = (string) $value;

		// SEC-VAL-03: Enforce max length.
		$max_length = $this->get_max_length( $field );
		if ( mb_strlen( $value ) > $max_length ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: 1: field label, 2: max length */
					__( '"%1$s" darf maximal %2$d Zeichen lang sein.', 'wp-dsgvo-form' ),
					$field->label,
					$max_length
				),
			];
		}

		return [ 'value' => sanitize_textarea_field( $value ), 'error' => null ];
	}

	/**
	 * Validates a checkbox field (SEC-VAL-07).
	 *
	 * Checkbox accepts an array of selected values, validated against allowed options.
	 */
	private function validate_checkbox( Field $field, $value ): array {
		$allowed = $field->get_options();

		if ( ! is_array( $value ) ) {
			$value = [ (string) $value ];
		}

		$value = array_map( 'strval', $value );

		// SEC-VAL-07: Only whitelisted values.
		$invalid = array_diff( $value, $allowed );

		if ( ! empty( $invalid ) ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: %s: field label */
					__( 'Ungueltige Auswahl fuer "%s".', 'wp-dsgvo-form' ),
					$field->label
				),
			];
		}

		return [ 'value' => $value, 'error' => null ];
	}

	/**
	 * Validates a radio field (SEC-VAL-07).
	 */
	private function validate_radio( Field $field, $value ): array {
		$value   = (string) $value;
		$allowed = $field->get_options();

		if ( ! in_array( $value, $allowed, true ) ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: %s: field label */
					__( 'Ungueltige Auswahl fuer "%s".', 'wp-dsgvo-form' ),
					$field->label
				),
			];
		}

		return [ 'value' => $value, 'error' => null ];
	}

	/**
	 * Validates a select field (SEC-VAL-07).
	 */
	private function validate_select( Field $field, $value ): array {
		// Select uses the same logic as radio.
		return $this->validate_radio( $field, $value );
	}

	/**
	 * Validates a date field (SEC-VAL-08).
	 */
	private function validate_date( Field $field, $value ): array {
		$value = (string) $value;
		$rules = $field->get_validation_rules();
		$format = $rules['date_format'] ?? self::DATE_FORMAT;

		$date = \DateTime::createFromFormat( $format, $value );

		if ( ! $date || $date->format( $format ) !== $value ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: 1: field label, 2: expected format */
					__( 'Bitte geben Sie ein gueltiges Datum fuer "%1$s" ein (Format: %2$s).', 'wp-dsgvo-form' ),
					$field->label,
					$format
				),
			];
		}

		return [ 'value' => $value, 'error' => null ];
	}

	/**
	 * Returns the effective max length for a field.
	 *
	 * SEC-VAL-03: Configurable per field, hard limit 10,000.
	 */
	private function get_max_length( Field $field ): int {
		$rules      = $field->get_validation_rules();
		$configured = (int) ( $rules['max_length'] ?? self::MAX_FIELD_LENGTH );

		return min( max( $configured, 1 ), self::MAX_FIELD_LENGTH );
	}

	/**
	 * Validates a value against a configured regex pattern.
	 *
	 * SEC-VAL-10: ReDoS protection — input is capped at MAX_PATTERN_INPUT_LENGTH
	 * before matching, and pcre.backtrack_limit is reduced for the match to
	 * prevent catastrophic backtracking with admin-configured patterns.
	 *
	 * @return string|null Error message if pattern does not match, null on success.
	 */
	private function validate_pattern( Field $field, string $value ): ?string {
		$rules = $field->get_validation_rules();

		if ( empty( $rules['pattern'] ) ) {
			return null;
		}

		// SEC-VAL-10: Cap input length to prevent ReDoS with long subjects.
		if ( mb_strlen( $value ) > self::MAX_PATTERN_INPUT_LENGTH ) {
			return sprintf(
				/* translators: 1: field label, 2: max length */
				__( '"%1$s" darf maximal %2$d Zeichen fuer die Formatpruefung enthalten.', 'wp-dsgvo-form' ),
				$field->label,
				self::MAX_PATTERN_INPUT_LENGTH
			);
		}

		$pattern = $rules['pattern'];

		// Ensure the pattern is wrapped in delimiters.
		if ( @preg_match( $pattern, '' ) === false ) {
			$pattern = '/' . $pattern . '/';
		}

		// SEC-VAL-10: Reduce backtrack limit for admin-configured patterns.
		$prev_limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '10000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- SEC-VAL-10: ReDoS mitigation for admin-configured patterns; no WP API alternative exists. Restored immediately after preg_match.

		$match = @preg_match( $pattern, $value );

		ini_set( 'pcre.backtrack_limit', $prev_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring original value after SEC-VAL-10 pattern check.

		// PREG_BACKTRACK_LIMIT_ERROR or other PCRE error — fail closed.
		if ( $match === false ) {
			return sprintf(
				/* translators: %s: field label */
				__( 'Die Formatpruefung fuer "%s" konnte nicht ausgefuehrt werden.', 'wp-dsgvo-form' ),
				$field->label
			);
		}

		if ( $match === 0 ) {
			$message = $rules['pattern_message']
				?? sprintf(
					/* translators: %s: field label */
					__( 'Der Wert fuer "%s" hat ein ungueltiges Format.', 'wp-dsgvo-form' ),
					$field->label
				);

			return $message;
		}

		return null;
	}

	/**
	 * Checks if a value is empty (null, empty string, empty array).
	 */
	private function is_empty_value( $value ): bool {
		if ( $value === null ) {
			return true;
		}

		if ( is_string( $value ) && trim( $value ) === '' ) {
			return true;
		}

		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}

		return false;
	}
}
