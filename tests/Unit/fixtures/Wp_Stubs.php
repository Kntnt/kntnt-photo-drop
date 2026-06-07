<?php
/**
 * Minimal WordPress class stand-ins for unit tests.
 *
 * The real WordPress classes live in core source files that are unavailable in
 * the unit-test runtime. The stubs below define the bare surface the tests
 * need so that type hints resolve and tests run without a WordPress install.
 *
 * Loaded via tests/Pest.php — kept out of the PSR-4 autoload path so PHPStan
 * (which already has the real classes via its WordPress stubs) never sees them.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- fixtures hold several minimal stubs.

if ( ! class_exists( 'WP_Block_Editor_Context' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Block_Editor_Context class.
	 *
	 * Defined only when the real class is not loaded (i.e. during unit tests).
	 * The block-category callback type-hints against it but reads nothing from
	 * it, so an empty shell suffices.
	 *
	 * @since 0.1.0
	 */
	class WP_Block_Editor_Context {}
}
