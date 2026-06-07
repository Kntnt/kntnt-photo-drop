<?php
/**
 * Pest bootstrap file.
 *
 * Configures the test suite: binds the unit-test base case and sets the
 * plugin's log threshold for the test process.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

/*
 * Unit tests run with Brain Monkey so that WordPress functions are available
 * as mocks without a full WordPress install.
 */
uses( Tests\Unit\TestCase::class )->in( 'Unit' );

// Pull in unit-test-only fixtures (minimal WordPress class stand-ins) that
// tests type-hint against, but that PHPStan must not see — its WordPress stubs
// already declare the real classes.
require_once __DIR__ . '/Unit/fixtures/Wp_Stubs.php';

// Lift the plugin's log threshold to `warning` for the test process so that
// log-behaviour invariants can be inspected. The constant is defined exactly
// once per process; if a test eventually wants `debug` it can override at the
// invocation point (define()-before-include cannot be undone, so this global
// choice is the right scope).
if ( ! defined( 'KNTNT_PHOTO_DROP_LOG_LEVEL' ) ) {
	define( 'KNTNT_PHOTO_DROP_LOG_LEVEL', 'warning' );
}
