<?php
/**
 * PHPUnit bootstrap file for wp-dsgvo-form.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load WordPress class stubs (WP_Error, WP_REST_Request, etc.).
require_once __DIR__ . '/stubs/wordpress.php';

// Define WordPress constants needed for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// WordPress database result type constants (wp-includes/wp-db.php).
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

// WordPress time constants (wp-includes/default-constants.php).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

// Define plugin constants.
if ( ! defined( 'WPDSGVO_VERSION' ) ) {
	define( 'WPDSGVO_VERSION', '1.0.0' );
}

if ( ! defined( 'WPDSGVO_PLUGIN_FILE' ) ) {
	define( 'WPDSGVO_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-dsgvo-form.php' );
}

if ( ! defined( 'WPDSGVO_PLUGIN_DIR' ) ) {
	define( 'WPDSGVO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WPDSGVO_PLUGIN_URL' ) ) {
	define( 'WPDSGVO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-dsgvo-form/' );
}

if ( ! defined( 'WPDSGVO_PLUGIN_BASENAME' ) ) {
	define( 'WPDSGVO_PLUGIN_BASENAME', 'wp-dsgvo-form/wp-dsgvo-form.php' );
}
