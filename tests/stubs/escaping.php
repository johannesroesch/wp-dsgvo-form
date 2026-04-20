<?php
/**
 * WordPress escaping function stubs for separate-process tests.
 *
 * Required by production code (Task #260 ExceptionNotEscaped fixes) for tests
 * that don't use Brain\Monkey (e.g. EncryptionServiceTest, KeyManagerTest).
 *
 * This file is loaded explicitly by those test classes, NOT by the main
 * bootstrap, to avoid Patchwork "DefinedTooEarly" conflicts with Brain\Monkey.
 *
 * @package WpDsgvoForm\Tests\Stubs
 */

declare(strict_types=1);

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
