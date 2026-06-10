<?php
/**
 * Plugin Name:       Kntnt Photo Drop
 * Plugin URI:        https://github.com/Kntnt/kntnt-photo-drop
 * Description:       Gutenberg blocks: a front-end bulk photo uploader and a server-rendered gallery with a lightbox.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.4
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-photo-drop
 * Domain Path:       /languages
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running on PHP versions older than 8.4.
 *
 * When the requirement is not met, an admin notice is displayed and the plugin
 * deactivates itself so it does not produce fatal errors. Returns true when
 * the environment is acceptable, false otherwise.
 *
 * @since 0.1.0
 *
 * @return bool True when PHP >= 8.4, false when the guard fires.
 */
function kntnt_photo_drop_php_version_check(): bool {

	// Nothing to do when the runtime meets the requirement.
	if ( version_compare( PHP_VERSION, '8.4', '>=' ) ) {
		return true;
	}

	// Show a dismissible admin notice and deactivate the plugin gracefully.
	add_action(
		'admin_notices',
		static function (): void {
			/* translators: 1: required PHP version, 2: current server PHP version */
			$tpl     = esc_html__( 'Kntnt Photo Drop needs PHP %1$s+. Server runs %2$s.', 'kntnt-photo-drop' );
			$message = sprintf( $tpl, '8.4', PHP_VERSION );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_html__() above.
			echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
		}
	);

	// Deactivate the plugin so WordPress does not try to load it again.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);

	return false;
}

// Abort the rest of the bootstrap when the PHP version guard fires.
if ( ! kntnt_photo_drop_php_version_check() ) {
	return;
}

// Load the PSR-4 autoloader (delegates to vendor/autoload.php).
require_once __DIR__ . '/autoloader.php';

// Register the activation handler so the uploads root exists before the first
// collection write. The handler is kept out of the autoloaded class graph
// because it must run in isolation at activation time.
require_once __DIR__ . '/install.php';
register_activation_hook( __FILE__, '\Kntnt\Photo_Drop\activate' );

// Bootstrap the plugin singleton, passing this file's path so it can expose
// get_plugin_file() and get_plugin_data() to consumers.
\Kntnt\Photo_Drop\Plugin::get_instance( __FILE__ );
