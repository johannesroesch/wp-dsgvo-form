<?php
/**
 * Plugin Name:       WP DSGVO Form
 * Plugin URI:        https://github.com/johannesroesch/wp-dsgvo-form
 * Description:       DSGVO-konformes Formular-Plugin mit AES-256 verschluesselter Speicherung.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Johannes Roesch
 * Author URI:        https://github.com/johannesroesch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-dsgvo-form
 * Domain Path:       /languages
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'WPDSGVO_VERSION', '1.1.0' );
define( 'WPDSGVO_CAPTCHA_SRI', 'sha384-vdnF+DWZHDg9l97VaOzx4lwxRcInsl09kH0DrPLCp2HQSrq8wLLjVx4zQ+mjwgZU' );
define( 'WPDSGVO_FORM_HANDLER_SRI', 'sha384-drWkcndfUjtCDNZCdSRfErBO9Jg2R8opDNlU7S/dD0kl1O0lzJ59bXwUo5xb5CTp' );
define( 'WPDSGVO_CAPTCHA_URL', 'https://captcha.repaircafe-bruchsal.de' );
define( 'WPDSGVO_PLUGIN_FILE', __FILE__ );
define( 'WPDSGVO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPDSGVO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPDSGVO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader: Composer (dev/local) falls back to custom PSR-4 loader for production ZIPs.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'WpDsgvoForm\\';
			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Activation / Deactivation hooks.
register_activation_hook( __FILE__, array( \WpDsgvoForm\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WpDsgvoForm\Deactivator::class, 'deactivate' ) );

// Initialize plugin on plugins_loaded.
add_action(
	'plugins_loaded',
	function () {
		\WpDsgvoForm\Plugin::instance()->init();
	}
);
