<?php
/**
 * Plugin singleton — entry point and logging API.
 *
 * Wires all components, exposes the static helpers other classes rely on, and
 * provides the four logging methods that every class in the plugin uses
 * instead of calling error_log() directly.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop;

use Kntnt\Photo_Drop\Bootstrap\Block_Registrar;
use Kntnt\Photo_Drop\Cli\Collection_Command;
use Kntnt\Photo_Drop\Collection\Repository;

/**
 * Singleton entry point for the kntnt-photo-drop plugin.
 *
 * Holds the absolute path to the main plugin file, caches the parsed plugin
 * header, and gates all log output through a single, filterable level check.
 *
 * Usage from outside:
 *   Plugin::get_instance()    — bootstrap; idempotent after first call.
 *   Plugin::get_plugin_file() — absolute path to kntnt-photo-drop.php.
 *   Plugin::get_plugin_data() — parsed plugin header (array).
 *   Plugin::error( $msg )     — log at ERROR level.
 *   Plugin::warning( $msg )   — log at WARNING level.
 *   Plugin::info( $msg )      — log at INFO level.
 *   Plugin::debug( $msg )     — log at DEBUG level.
 *
 * @package Kntnt\Photo_Drop
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Log-level hierarchy: maps each level name to its numeric severity.
	 *
	 * Lower numbers are more severe. A message is written when its severity
	 * is less than or equal to the configured threshold.
	 *
	 * @since 0.1.0
	 * @var array<string,int>
	 */
	private const LOG_LEVELS = [
		'none'    => -1,
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
		'debug'   => 3,
	];

	/**
	 * The sole instance of this class.
	 *
	 * @since 0.1.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the main plugin file (kntnt-photo-drop.php).
	 *
	 * Set once during bootstrap and used by get_plugin_file() and
	 * get_plugin_data().
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Cached return value of get_file_data() / get_plugin_data().
	 *
	 * Populated lazily on the first call to get_plugin_data(). WordPress's
	 * get_plugin_data() returns a typed shape (mostly strings, with Network as
	 * bool); get_file_data() returns string[]. Both are accepted as array<mixed>.
	 *
	 * @since 0.1.0
	 * @var array<mixed>|null
	 */
	private static ?array $plugin_data = null;

	/**
	 * Returns (and on first call, creates) the singleton instance.
	 *
	 * Stores the path to the main plugin file so that get_plugin_file() and
	 * get_plugin_data() can work without globals. Calling this method a second
	 * time is a no-op and returns the existing instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Absolute path to kntnt-photo-drop.php.
	 *                            Ignored on subsequent calls.
	 * @return self
	 */
	public static function get_instance( string $plugin_file = '' ): self {

		// Return early when already bootstrapped.
		if ( self::$instance !== null ) {
			return self::$instance;
		}

		// Capture the plugin file path and initialise the singleton.
		self::$plugin_file = $plugin_file;
		self::$instance    = new self();

		return self::$instance;

	}

	/**
	 * Returns the absolute path to the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path, e.g. /var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Returns the parsed plugin header, cached after the first call.
	 *
	 * The array keys match what get_file_data() / get_plugin_data() return:
	 * 'Name', 'Version', 'PluginURI', 'Description', 'Author', 'AuthorURI',
	 * 'TextDomain', 'DomainPath', 'Network', 'RequiresWP', 'RequiresPHP'.
	 *
	 * @since 0.1.0
	 *
	 * @return array<mixed>
	 */
	public static function get_plugin_data(): array {

		// Return the cached result to avoid repeated file reads.
		if ( self::$plugin_data !== null ) {
			return self::$plugin_data;
		}

		// Parse the plugin header from the main plugin file.
		$default_headers = [
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'Network'     => 'Network',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		];

		// Prefer the WordPress function when available; fall back to get_file_data()
		// for contexts where the full plugin API isn't loaded yet.
		if ( function_exists( 'get_plugin_data' ) ) {
			self::$plugin_data = get_plugin_data( self::$plugin_file, false, false );
		} else {
			self::$plugin_data = get_file_data( self::$plugin_file, $default_headers );
		}

		return self::$plugin_data;

	}

	/**
	 * Logs a message at ERROR level.
	 *
	 * Always writes when the configured log level is 'error' (the default)
	 * or more verbose. Silenced only by 'none'.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable log message. No stack trace appended.
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Logs a message at WARNING level.
	 *
	 * Written when the configured level is 'warning', 'info', or 'debug'.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function warning( string $message ): void {
		self::log( 'warning', $message );
	}

	/**
	 * Logs a message at INFO level.
	 *
	 * Written when the configured level is 'info' or 'debug'.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function info( string $message ): void {
		self::log( 'info', $message );
	}

	/**
	 * Logs a message at DEBUG level.
	 *
	 * Written only when the configured level is 'debug'. Should not be left
	 * on in production — the debug stream contains operational detail
	 * (collection slugs, file paths, timings).
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function debug( string $message ): void {
		self::log( 'debug', $message );
	}

	/**
	 * Writes a log line to PHP's error_log() when the message's level passes
	 * the configured threshold.
	 *
	 * Format: [kntnt-photo-drop] [LEVEL] message
	 *
	 * The threshold is read from the KNTNT_PHOTO_DROP_LOG_LEVEL constant.
	 * If undefined, it defaults to 'error'. The value 'none' suppresses all
	 * output.
	 *
	 * @since 0.1.0
	 *
	 * @param string $level   One of 'error', 'warning', 'info', 'debug'.
	 * @param string $message The text to log.
	 */
	private static function log( string $level, string $message ): void {

		// Resolve the configured threshold, defaulting to 'error'.
		// constant() returns mixed; we use is_string() to safely narrow the type.
		$constant_value = defined( 'KNTNT_PHOTO_DROP_LOG_LEVEL' ) ? constant( 'KNTNT_PHOTO_DROP_LOG_LEVEL' ) : null;
		$raw_threshold  = is_string( $constant_value ) ? $constant_value : 'error';
		$threshold_key  = array_key_exists( $raw_threshold, self::LOG_LEVELS ) ? $raw_threshold : 'error';

		// Bail when the threshold is 'none' or the message level is too verbose.
		$threshold_value = self::LOG_LEVELS[ $threshold_key ];
		if ( $threshold_value < 0 || self::LOG_LEVELS[ $level ] > $threshold_value ) {
			return;
		}

		// Write the formatted line to the PHP error log.
		error_log( '[kntnt-photo-drop] [' . strtoupper( $level ) . '] ' . $message );

	}

	/**
	 * Wires all plugin components and registers their WordPress hooks.
	 *
	 * Instantiated once by get_instance(). Components are created in dependency
	 * order; each registers its own actions and filters here so the constructor
	 * remains the single authoritative place to trace the hook graph.
	 *
	 * Component instances are local variables. The global `$wp_filter` array
	 * holds the bound array callable `[$object, 'method']`, which keeps the
	 * object alive for the lifetime of the request.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Bootstrap block registration and the custom "Kntnt" block category.
		$block_registrar = new Block_Registrar();
		add_action( 'init', [ $block_registrar, 'register' ] );
		add_filter( 'block_categories_all', [ $block_registrar, 'register_category' ], 10, 2 );

		// Register the WP-CLI lifecycle commands only when running under WP_CLI,
		// so the command class is never loaded on a web request. The CLI is the
		// trusted place a collection is established, renamed, and removed.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kntnt-photo-drop collection', new Collection_Command( new Repository() ) );
		}

	}

}
