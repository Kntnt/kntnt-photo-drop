<?php
/**
 * Unit tests for the Gallery's pure render helpers.
 *
 * The justified-row math, the srcset assembly, and the URL arithmetic are pure
 * helpers precisely so they can be proven in isolation, without a collection on
 * disk or a WordPress runtime (agents.d/testing.md). These tests pin each helper's
 * contract directly: the srcset keeps the main as a candidate and drops upscaled
 * thumbnails; the justified math derives basis and grow from the aspect ratio and
 * flags the last row; and URLs encode each path segment and splice the hidden
 * thumbnails directory in correctly. The breadcrumb overlay assembly is the
 * unified overlay framework's own pure core and lives in BreadcrumbBuilderTest.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Rendering\Image_Url;
use Kntnt\Photo_Drop\Rendering\Justified_Layout;
use Kntnt\Photo_Drop\Rendering\Srcset_Builder;

// ---------------------------------------------------------------------------
// Srcset_Builder — candidates are { thumbnail, full }; the full is the ceiling
// ---------------------------------------------------------------------------

test( 'srcset lists the thumbnail and the bounded full, never the wider main', function (): void {

	// A main (4000) wider than the full (1920) is download-only: the candidates are
	// the thumbnail (640) and the bounded full (1920), both served from the hidden
	// width directories, and the 4000px main never appears (ADR-0013).
	$candidates = Srcset_Builder::candidates(
		4000,
		1920,
		640,
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	expect( $candidates )->toBe( [
		[
			'url'   => 'https://x/t640.webp',
			'width' => 640,
		],
		[
			'url'   => 'https://x/t1920.webp',
			'width' => 1920,
		],
	] );

} );

test( 'a main no wider than the full is itself the full candidate, served from the main url', function (): void {

	// A main (1500) no wider than the full (1920) is the full rendition itself, so
	// it stays a candidate at its own width and is served from the main URL — nothing
	// is upscaled — alongside the smaller thumbnail (ADR-0013).
	$candidates = Srcset_Builder::candidates(
		1500,
		1920,
		640,
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	expect( $candidates )->toBe( [
		[
			'url'   => 'https://x/t640.webp',
			'width' => 640,
		],
		[
			'url'   => 'https://x/main.webp',
			'width' => 1500,
		],
	] );

} );

test( 'a main no wider than the thumbnail yields just the main candidate', function (): void {

	// A tiny main (500) no wider than either tier serves every role, so the only
	// candidate is the main itself at its own width, served from the main URL.
	$candidates = Srcset_Builder::candidates(
		500,
		1920,
		640,
		'https://x/main.webp',
		static fn ( int $w ): string => "https://x/t{$w}.webp",
	);

	expect( $candidates )->toBe( [
		[
			'url'   => 'https://x/main.webp',
			'width' => 500,
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
	expect( $url )->toBe( 'https://x/photos/morning/kntnt-thumbnails/320/sunrise.jpg.webp' );
} );

test( 'a root-level thumbnail URL puts the hidden directory directly under the root', function (): void {
	$url = Image_Url::thumbnail( 'https://x/photos', 'top.jpg.webp', 640 );
	expect( $url )->toBe( 'https://x/photos/kntnt-thumbnails/640/top.jpg.webp' );
} );
