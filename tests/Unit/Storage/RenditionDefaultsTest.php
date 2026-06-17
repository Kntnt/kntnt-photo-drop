<?php
/**
 * Tests for the six per-field rendition defaults that pre-fill a create form
 * and supply the CLI's flag defaults (ADR-0013).
 *
 * Each default reads one `kntnt_photo_drop_default_{upload,full,thumbnail}_{width,quality}`
 * filter and hardens its return so a buggy filter can never seed a degenerate
 * collection. `apply_filters` is stubbed via Brain Monkey; nothing touches disk.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Storage\Rendition_Defaults;

/**
 * Stubs `apply_filters` to return a per-hook override, else pass the value through.
 *
 * @param array<string,mixed> $overrides Hook name → forced return value.
 */
function wire_default_filters( array $overrides = [] ): void {
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $overrides ): mixed {
			return array_key_exists( $hook, $overrides ) ? $overrides[ $hook ] : $value;
		}
	);
}

// ---------------------------------------------------------------------------
// The documented defaults when no filter overrides them
// ---------------------------------------------------------------------------

test( 'the unfiltered defaults match the documented per-field values', function (): void {
	wire_default_filters();

	// The defaults are: upload width unset (the source's own dimensions), upload
	// quality 95, full 1920/85, thumbnail 640/75 (ADR-0013, design.md).
	expect( Rendition_Defaults::upload_width() )->toBeNull();
	expect( Rendition_Defaults::upload_quality() )->toBe( 95 );
	expect( Rendition_Defaults::full_width() )->toBe( 1920 );
	expect( Rendition_Defaults::full_quality() )->toBe( 85 );
	expect( Rendition_Defaults::thumbnail_width() )->toBe( 640 );
	expect( Rendition_Defaults::thumbnail_quality() )->toBe( 75 );

} );

// ---------------------------------------------------------------------------
// Each default reads its own filter
// ---------------------------------------------------------------------------

test( 'each default honours a valid filter override', function (): void {
	wire_default_filters( [
		'kntnt_photo_drop_default_upload_width'      => 4000,
		'kntnt_photo_drop_default_upload_quality'    => 90,
		'kntnt_photo_drop_default_full_width'        => 1600,
		'kntnt_photo_drop_default_full_quality'      => 80,
		'kntnt_photo_drop_default_thumbnail_width'   => 480,
		'kntnt_photo_drop_default_thumbnail_quality' => 60,
	] );

	expect( Rendition_Defaults::upload_width() )->toBe( 4000 );
	expect( Rendition_Defaults::upload_quality() )->toBe( 90 );
	expect( Rendition_Defaults::full_width() )->toBe( 1600 );
	expect( Rendition_Defaults::full_quality() )->toBe( 80 );
	expect( Rendition_Defaults::thumbnail_width() )->toBe( 480 );
	expect( Rendition_Defaults::thumbnail_quality() )->toBe( 60 );

} );

// ---------------------------------------------------------------------------
// The upload width is the nullable "original dimensions" default
// ---------------------------------------------------------------------------

test( 'a null or non-positive upload-width filter return means the source dimensions', function ( mixed $bogus ): void {
	wire_default_filters( [ 'kntnt_photo_drop_default_upload_width' => $bogus ] );

	// The upload width is the only nullable rendition field: null is the documented
	// "original dimensions" default, and a non-positive or non-integer filter
	// return collapses to that same null rather than a degenerate ceiling.
	expect( Rendition_Defaults::upload_width() )->toBeNull();

} )->with( [
	'explicit null' => [ null ],
	'zero'          => [ 0 ],
	'negative'      => [ -10 ],
	'non-integer'   => [ '4000' ],
] );

// ---------------------------------------------------------------------------
// A bogus filter return falls back to the documented default
// ---------------------------------------------------------------------------

test( 'an invalid width filter return falls back to the documented default', function ( mixed $bogus ): void {
	wire_default_filters( [ 'kntnt_photo_drop_default_full_width' => $bogus ] );

	// A non-positive or non-integer width is unusable, so the default 1920 stands.
	expect( Rendition_Defaults::full_width() )->toBe( 1920 );

} )->with( [
	'zero'     => [ 0 ],
	'negative' => [ -1 ],
	'string'   => [ '1600' ],
	'null'     => [ null ],
] );

test( 'an out-of-range quality filter return falls back to the documented default', function ( mixed $bogus ): void {
	wire_default_filters( [ 'kntnt_photo_drop_default_thumbnail_quality' => $bogus ] );

	// Quality is bounded to 0–100; anything outside that (or non-integer) falls back
	// to the documented thumbnail default 75.
	expect( Rendition_Defaults::thumbnail_quality() )->toBe( 75 );

} )->with( [
	'over 100'    => [ 101 ],
	'negative'    => [ -1 ],
	'non-integer' => [ '70' ],
] );
