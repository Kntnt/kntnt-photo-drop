<?php
/**
 * Unit tests for the Gallery's pure render helpers.
 *
 * The justified-row math, the srcset assembly, the caption assembly, and the URL
 * arithmetic are pure helpers precisely so they can be proven in isolation,
 * without a collection on disk or a WordPress runtime (docs/testing.md). These
 * tests pin each helper's contract directly: the srcset keeps the main as a
 * candidate and drops upscaled thumbnails; captions assemble across every
 * content/humanise/separator combination; the justified math derives basis and
 * grow from the aspect ratio and flags the last row; and URLs encode each path
 * segment and splice the hidden thumbnails directory in correctly.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Rendering\Caption_Builder;
use Kntnt\Photo_Drop\Rendering\Image_Url;
use Kntnt\Photo_Drop\Rendering\Justified_Layout;
use Kntnt\Photo_Drop\Rendering\Srcset_Builder;

// ---------------------------------------------------------------------------
// Srcset_Builder — the main is always a candidate; upscales are dropped
// ---------------------------------------------------------------------------

test( 'srcset candidates list each smaller thumbnail width plus the main, ascending', function (): void {

	$candidates = Srcset_Builder::candidates(
		1200,
		[ 320, 640 ],
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	// Three candidates ascending: the two thumbnails below the main, then the main.
	$widths = array_column( $candidates, 'width' );
	expect( $widths )->toBe( [ 320, 640, 1200 ] );
	expect( $candidates[2]['url'] )->toBe( 'https://x/main.webp' );

} );

test( 'a thumbnail width at or above the main width is dropped from the candidates', function (): void {

	$candidates = Srcset_Builder::candidates(
		500,
		[ 320, 500, 800 ],
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	// 500 equals the main (the main candidate wins that width) and 800 is an
	// upscale, so only 320 and the 500-wide main remain.
	$widths = array_column( $candidates, 'width' );
	expect( $widths )->toBe( [ 320, 500 ] );
	expect( $candidates[1]['url'] )->toBe( 'https://x/main.webp' );

} );

test( 'a collection with no thumbnail widths yields just the main candidate', function (): void {

	$candidates = Srcset_Builder::candidates(
		900,
		[],
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	expect( $candidates )->toBe( [
		[
			'url'   => 'https://x/main.webp',
			'width' => 900,
		],
	] );

} );

test( 'the srcset attribute joins candidates as <url> <width>w', function (): void {

	$attribute = Srcset_Builder::to_attribute(
		[
			[
				'url'   => 'https://x/t320.webp',
				'width' => 320,
			],
			[
				'url'   => 'https://x/main.webp',
				'width' => 900,
			],
		],
	);

	expect( $attribute )->toBe( 'https://x/t320.webp 320w, https://x/main.webp 900w' );

} );

// ---------------------------------------------------------------------------
// Caption_Builder — content / humanise / breadcrumb assembly
// ---------------------------------------------------------------------------

test( 'the none content yields an empty caption', function (): void {
	expect( Caption_Builder::build( 'a/b.jpg.webp', 'none', true, false, '›', 'C' ) )->toBe( '' );
} );

test( 'a humanised filename caption strips the stored webp and the extension', function (): void {

	// The stored name is the original plus .webp; humanising recovers the original
	// and drops its own extension and separators.
	$caption = Caption_Builder::build( 'morning/sun_rise-01.jpg.webp', 'filename', true, false, '›', 'C' );
	expect( $caption )->toBe( 'sun rise 01' );

} );

test( 'a non-humanised filename caption keeps the original name with its extension', function (): void {
	$caption = Caption_Builder::build( 'a/IMG_2024.jpg.webp', 'filename', false, false, '›', 'C' );
	expect( $caption )->toBe( 'IMG_2024.jpg' );
} );

test( 'an already-webp original is not stripped to an extensionless name', function (): void {

	// sunset.webp was an already-webp original stored verbatim; humanising must not
	// invent an extensionless "sunset" by stripping a non-existent appended suffix.
	$caption = Caption_Builder::build( 'sunset.webp', 'filename', false, false, '›', 'C' );
	expect( $caption )->toBe( 'sunset.webp' );

} );

test( 'a path breadcrumb joins humanised segments with the separator', function (): void {
	$caption = Caption_Builder::build( '2024_summer/day-one/IMG_5.jpg.webp', 'path', true, false, '›', 'Trip' );
	expect( $caption )->toBe( '2024 summer › day one › IMG 5' );
} );

test( 'a path breadcrumb prefixes the collection name when asked', function (): void {
	$caption = Caption_Builder::build( 'day-one/IMG_5.jpg.webp', 'path', true, true, '›', 'Trip' );
	expect( $caption )->toBe( 'Trip › day one › IMG 5' );
} );

test( 'a root-level path breadcrumb is just the filename', function (): void {
	$caption = Caption_Builder::build( 'lonely.jpg.webp', 'path', true, false, '›', 'Trip' );
	expect( $caption )->toBe( 'lonely' );
} );

test( 'an unrecognised content value falls back to no caption', function (): void {
	expect( Caption_Builder::build( 'a.jpg.webp', 'nonsense', true, false, '›', 'C' ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// Justified_Layout — basis from natural width, grow from ratio, last-row flag
// ---------------------------------------------------------------------------

test( 'the justified basis is the natural width at the target height and grow is the ratio', function (): void {

	// A 3:2 image at a 200px row height is 300px wide naturally.
	$flex = Justified_Layout::compute( [
		[
			'width'  => 300,
			'height' => 200,
		],
	], 200, 10 );
	expect( $flex[0]['basis'] )->toEqualWithDelta( 300.0, 0.001 );
	expect( $flex[0]['grow'] )->toEqualWithDelta( 1.5, 0.001 );

} );

test( 'a single-row gallery flags every image as the last row', function (): void {

	$flex = Justified_Layout::compute(
		[
			[
				'width'  => 100,
				'height' => 100,
			],
			[
				'width'  => 100,
				'height' => 100,
			],
		],
		100,
		10,
		960,
	);
	expect( $flex[0]['last_row'] )->toBeTrue();
	expect( $flex[1]['last_row'] )->toBeTrue();

} );

test( 'only the final row is flagged when images overflow into multiple rows', function (): void {

	// Six 240px-wide images in a 600px container pack two per row, so the first
	// four are not the last row and the final two are.
	$images = array_fill( 0, 6, [
		'width'  => 240,
		'height' => 240,
	] );
	$flex   = Justified_Layout::compute( $images, 240, 10, 600 );
	$flags  = array_column( $flex, 'last_row' );
	expect( array_slice( $flags, 0, 4 ) )->toBe( [ false, false, false, false ] );
	expect( array_slice( $flags, 4 ) )->toBe( [ true, true ] );

} );

test( 'a corrupt zero dimension falls back to a square ratio', function (): void {
	$flex = Justified_Layout::compute( [
		[
			'width'  => 0,
			'height' => 0,
		],
	], 200, 10 );
	expect( $flex[0]['grow'] )->toEqualWithDelta( 1.0, 0.001 );
} );

// ---------------------------------------------------------------------------
// Image_Url — segment encoding and the thumbnails-directory splice
// ---------------------------------------------------------------------------

test( 'the main URL appends the relative path with each segment encoded', function (): void {
	$url = Image_Url::main( 'https://x/photos', 'a folder/sun rise.jpg.webp' );
	expect( $url )->toBe( 'https://x/photos/a%20folder/sun%20rise.jpg.webp' );
} );

test( 'a nested thumbnail URL splices the hidden directory and width before the file', function (): void {
	$url = Image_Url::thumbnail( 'https://x/photos', 'morning/sunrise.jpg.webp', 320 );
	expect( $url )->toBe( 'https://x/photos/morning/.kntnt-thumbnails/320/sunrise.jpg.webp' );
} );

test( 'a root-level thumbnail URL puts the hidden directory directly under the root', function (): void {
	$url = Image_Url::thumbnail( 'https://x/photos', 'top.jpg.webp', 640 );
	expect( $url )->toBe( 'https://x/photos/.kntnt-thumbnails/640/top.jpg.webp' );
} );
