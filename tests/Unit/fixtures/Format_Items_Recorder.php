<?php
/**
 * Static recorder for the `format_items()` test double.
 *
 * The stubbed `WP_CLI\Utils\format_items()` writes the rows and fields it was
 * handed here, so an image-command test can read back exactly what the command
 * rendered without parsing console output. A test resets it before driving the
 * command, then inspects the captured rows afterwards.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Tests\Unit\Fixtures;

/**
 * Holds the last rows and fields passed to the `format_items()` stub.
 *
 * @since 0.3.0
 */
final class Format_Items_Recorder {

	/**
	 * The rows last rendered by the stub.
	 *
	 * @since 0.3.0
	 * @var array<int,array<string,scalar>>
	 */
	public static array $rows = [];

	/**
	 * The column order last rendered by the stub.
	 *
	 * @since 0.3.0
	 * @var array<int,string>
	 */
	public static array $fields = [];

	/**
	 * Clears the recorded rows and fields before a command run.
	 *
	 * @since 0.3.0
	 */
	public static function reset(): void {
		self::$rows   = [];
		self::$fields = [];
	}

}
