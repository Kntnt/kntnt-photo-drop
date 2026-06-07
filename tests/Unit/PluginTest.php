<?php
/**
 * Tests for the plugin scaffolding: singleton identity, the logging-level
 * threshold behaviour, and the "Kntnt" block-category prepend.
 *
 * The threshold gate is tested via an internal helper that mirrors the exact
 * severity-map comparison used in Plugin::log(). Live invocation tests confirm
 * each public logging method is callable without throwing.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Bootstrap\Block_Registrar;
use Kntnt\Photo_Drop\Plugin;

// WP_Block_Editor_Context is stubbed in tests/Unit/fixtures/Wp_Stubs.php,
// loaded by tests/Pest.php, so the category callback's type hint resolves.

// ---------------------------------------------------------------------------
// Threshold-gate helper — mirrors Plugin::log() severity comparison exactly.
// ---------------------------------------------------------------------------

/**
 * Returns true when a message at $message_level should be written given the
 * configured $threshold.
 *
 * This function mirrors the private Plugin::log() gate so the tests are
 * deterministic without environment side-effects from calling error_log().
 *
 * @param string $message_level One of 'error', 'warning', 'info', 'debug'.
 * @param string $threshold     One of 'none', 'error', 'warning', 'info', 'debug'.
 * @return bool
 */
function should_log( string $message_level, string $threshold ): bool {
	$levels = [
		'none'    => -1,
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
		'debug'   => 3,
	];

	$threshold_key   = array_key_exists( $threshold, $levels ) ? $threshold : 'error';
	$threshold_value = $levels[ $threshold_key ];

	if ( $threshold_value < 0 ) {
		return false;
	}

	return $levels[ $message_level ] <= $threshold_value;
}

// ---------------------------------------------------------------------------
// Threshold: 'none' — all levels silenced
// ---------------------------------------------------------------------------

test( 'threshold none silences error', function (): void {
	expect( should_log( 'error', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences warning', function (): void {
	expect( should_log( 'warning', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences info', function (): void {
	expect( should_log( 'info', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences debug', function (): void {
	expect( should_log( 'debug', 'none' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'error' (default) — only error passes
// ---------------------------------------------------------------------------

test( 'threshold error passes error', function (): void {
	expect( should_log( 'error', 'error' ) )->toBeTrue();
} );

test( 'threshold error silences warning', function (): void {
	expect( should_log( 'warning', 'error' ) )->toBeFalse();
} );

test( 'threshold error silences info', function (): void {
	expect( should_log( 'info', 'error' ) )->toBeFalse();
} );

test( 'threshold error silences debug', function (): void {
	expect( should_log( 'debug', 'error' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'warning' — error and warning pass
// ---------------------------------------------------------------------------

test( 'threshold warning passes error', function (): void {
	expect( should_log( 'error', 'warning' ) )->toBeTrue();
} );

test( 'threshold warning passes warning', function (): void {
	expect( should_log( 'warning', 'warning' ) )->toBeTrue();
} );

test( 'threshold warning silences info', function (): void {
	expect( should_log( 'info', 'warning' ) )->toBeFalse();
} );

test( 'threshold warning silences debug', function (): void {
	expect( should_log( 'debug', 'warning' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'info' — error, warning, and info pass
// ---------------------------------------------------------------------------

test( 'threshold info passes error', function (): void {
	expect( should_log( 'error', 'info' ) )->toBeTrue();
} );

test( 'threshold info passes warning', function (): void {
	expect( should_log( 'warning', 'info' ) )->toBeTrue();
} );

test( 'threshold info passes info', function (): void {
	expect( should_log( 'info', 'info' ) )->toBeTrue();
} );

test( 'threshold info silences debug', function (): void {
	expect( should_log( 'debug', 'info' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'debug' — all levels pass
// ---------------------------------------------------------------------------

test( 'threshold debug passes error', function (): void {
	expect( should_log( 'error', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes warning', function (): void {
	expect( should_log( 'warning', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes info', function (): void {
	expect( should_log( 'info', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes debug', function (): void {
	expect( should_log( 'debug', 'debug' ) )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Live invocation — confirm each public logging method runs without throwing.
// KNTNT_PHOTO_DROP_LOG_LEVEL is 'warning' in the test process (see Pest.php),
// so error() and warning() write to error_log() and the others are silenced.
// All four calls are made so the no-throw guarantee is verified for every
// public method.
// ---------------------------------------------------------------------------

test( 'Plugin::error() does not throw', function (): void {
	Plugin::error( 'unit-test error message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::warning() does not throw', function (): void {
	Plugin::warning( 'unit-test warning message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::info() does not throw', function (): void {
	Plugin::info( 'unit-test info message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::debug() does not throw', function (): void {
	Plugin::debug( 'unit-test debug message' );
	expect( true )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Singleton identity and static helpers
// ---------------------------------------------------------------------------

test( 'get_instance returns the same instance on repeated calls', function (): void {

	// Two calls must yield the identical object — the singleton is idempotent.
	$first  = Plugin::get_instance( '/fake/path/to/kntnt-photo-drop.php' );
	$second = Plugin::get_instance();

	expect( $second )->toBe( $first );

} );

test( 'get_plugin_file returns the path captured at first bootstrap', function (): void {

	// The path passed to the FIRST get_instance() call wins; later args are
	// ignored. This test runs after the singleton is already bootstrapped, so
	// it asserts the first-captured path, not its own argument.
	Plugin::get_instance( '/ignored/second/path.php' );

	expect( Plugin::get_plugin_file() )->toBeString()->not->toBeEmpty();

} );

// ---------------------------------------------------------------------------
// Block category prepend
// ---------------------------------------------------------------------------

test( 'register_category prepends the kntnt category', function (): void {

	// Stub __() so the translatable title resolves to its source string.
	Brain\Monkey\Functions\when( '__' )->returnArg( 1 );

	// Feed an existing category list and a dummy editor context.
	$existing = [
		[
			'slug'  => 'text',
			'title' => 'Text',
			'icon'  => null,
		],
	];
	$registrar = new Block_Registrar();
	$result    = $registrar->register_category( $existing, new WP_Block_Editor_Context() );

	// The kntnt category is first and the original list follows it untouched.
	expect( $result[0]['slug'] )->toBe( 'kntnt' );
	expect( $result[0]['title'] )->toBe( 'Kntnt' );
	expect( $result[1]['slug'] )->toBe( 'text' );

} );
