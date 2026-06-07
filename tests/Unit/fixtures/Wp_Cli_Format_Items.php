<?php
/**
 * Test double for `WP_CLI\Utils\format_items()`, used by the image command tests.
 *
 * The real function lives in the `WP_CLI\Utils` namespace and prints a formatted
 * table to stdout. The unit-test runtime has no WP-CLI, so this stub stands in:
 * it records the last-rendered rows and fields in a static recorder so a test
 * can assert what the command reported, without parsing console output. Kept out
 * of the PSR-4 path and loaded explicitly from the WP_CLI double, so PHPStan
 * (which has the real function via its stubs) never sees it.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace WP_CLI\Utils;

/**
 * Records the rows a command formats, standing in for the real table printer.
 *
 * @since 0.3.0
 *
 * @param string                    $format The output format (ignored by the stub).
 * @param array<int,array<string,scalar>> $items  The rows to render.
 * @param array<int,string>         $fields The column order.
 */
function format_items( string $format, array $items, array $fields ): void {
	\Tests\Unit\Fixtures\Format_Items_Recorder::$rows   = $items;
	\Tests\Unit\Fixtures\Format_Items_Recorder::$fields = $fields;
}
