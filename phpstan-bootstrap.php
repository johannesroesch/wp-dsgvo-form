<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are normally set in the main plugin file
 * but are not visible to PHPStan's static analysis.
 *
 * @phpstan-ignore-file
 */

// Plugin constants defined in wp-dsgvo-form.php.
define( 'WPDSGVO_VERSION', '1.0.0' );
define( 'WPDSGVO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-dsgvo-form/' );
define( 'WPDSGVO_CAPTCHA_URL', 'https://captcha.example.com' );
define( 'WPDSGVO_CAPTCHA_SRI', 'sha384-placeholder' );
