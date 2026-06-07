<?php
/**
 * Minimal WP_CLI test double for unit-testing the CLI command's thin glue.
 *
 * WP-CLI's static `WP_CLI::*` methods are not Brain-Monkey-mockable the way
 * plain functions are, and the real `WP_CLI` class is absent from the unit-test
 * runtime. This double stands in for it: `error()` and a declined `confirm()`
 * raise a catchable `Cli_Halt` exception (mirroring WP-CLI's own halt-on-error
 * semantics) so a test can assert the error path was taken *and* that no
 * filesystem effect followed it, while `success()` / `log()` record their
 * messages for inspection. A static recorder lets a test read back what the
 * command reported.
 *
 * Loaded via tests/Pest.php and kept out of the PSR-4 path so PHPStan (which
 * already has the real WP_CLI via its stubs) never sees it.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- fixtures hold several minimal stubs.

if ( ! class_exists( 'Tests\Unit\Fixtures\Cli_Halt' ) ) {
	// Importing the namespaced double into the global namespace is impossible in
	// one declaration block, so the exception lives under the test namespace and
	// the global WP_CLI references it by its fully-qualified name.
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- a fixture file groups the double and its halt exception.
	require_once __DIR__ . '/Cli_Halt.php';
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Global stand-in for WP-CLI's `WP_CLI` facade, used only in unit tests.
	 *
	 * Records output and converts the two flow-terminating calls (`error()` and a
	 * declined `confirm()`) into a catchable exception so tests can assert both
	 * the message and the absence of any subsequent side effect.
	 *
	 * @since 0.2.0
	 */
	class WP_CLI {

		/**
		 * The recorded `success(...)` messages since the last reset.
		 *
		 * @since 0.2.0
		 * @var array<int,string>
		 */
		public static array $successes = [];

		/**
		 * The recorded `error(...)` messages since the last reset.
		 *
		 * @since 0.2.0
		 * @var array<int,string>
		 */
		public static array $errors = [];

		/**
		 * The recorded `log(...)` messages since the last reset.
		 *
		 * @since 0.2.0
		 * @var array<int,string>
		 */
		public static array $logs = [];

		/**
		 * When false, the next `confirm(...)` without `--yes` is declined (halts).
		 *
		 * @since 0.2.0
		 * @var bool
		 */
		public static bool $confirm_answer = true;

		/**
		 * Clears all recorded output and resets the confirm answer to "accept".
		 *
		 * @since 0.2.0
		 */
		public static function reset(): void {
			self::$successes      = [];
			self::$errors         = [];
			self::$logs           = [];
			self::$confirm_answer = true;
		}

		/**
		 * Records a success message.
		 *
		 * @since 0.2.0
		 *
		 * @param string $message The success text.
		 */
		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		/**
		 * Records a log message.
		 *
		 * @since 0.2.0
		 *
		 * @param string $message The log text.
		 */
		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		/**
		 * Records an error message and halts, mirroring WP-CLI's exit-on-error.
		 *
		 * @since 0.2.0
		 *
		 * @param string $message The error text.
		 * @throws \Tests\Unit\Fixtures\Cli_Halt Always, to terminate the command flow.
		 */
		public static function error( string $message ): void {
			self::$errors[] = $message;
			throw new \Tests\Unit\Fixtures\Cli_Halt( $message );
		}

		/**
		 * Skips the prompt when `--yes` is present or the answer is "accept",
		 * otherwise halts as a declined confirmation would.
		 *
		 * @since 0.2.0
		 *
		 * @param string               $question   The confirmation question (unused).
		 * @param array<string,string> $assoc_args The command's associative args.
		 * @throws \Tests\Unit\Fixtures\Cli_Halt When the prompt is declined.
		 */
		public static function confirm( string $question, array $assoc_args = [] ): void {
			if ( isset( $assoc_args['yes'] ) || self::$confirm_answer ) {
				return;
			}
			throw new \Tests\Unit\Fixtures\Cli_Halt( 'declined' );
		}
	}
}
