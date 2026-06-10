<?php
/**
 * Tests for thumbnail derivation — the `.kntnt-thumbnails/<width>/<name>.webp`
 * convention and the "no thumbnail at or above the main's width" rule.
 *
 * Each test writes a real WebP main into a temp folder and drives the real GD
 * codec, then asserts which thumbnail files appear and at what width. It covers
 * the per-width path convention, the rule that an image at or below a width gets
 * no separate thumbnail there, the empty-widths short-circuit, and that the main
 * folder's content is untouched apart from the hidden artifacts directory.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Storage\Index;

// The thumbnailer reaches for wp_mkdir_p() when it is defined; once Brain Monkey
// has seen the function in any test it stays defined, so every test here wires it
// to a real recursive mkdir against the temp tree.
beforeEach( function (): void {
	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);
} );

/**
 * Allocates a fresh temp folder standing in for a collection content folder.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_thumb_dir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-thumb-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a real WebP main image of given dimensions into a folder.
 *
 * @param string $folder   The folder to write into.
 * @param string $filename The stored main filename (ends in `.webp`).
 * @param int    $width    The image width in pixels.
 * @param int    $height   The image height in pixels.
 * @return string The absolute path of the written main.
 */
function write_main_image( string $folder, string $filename, int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 40, 90, 160 ) );
	$path = rtrim( $folder, '/' ) . '/' . $filename;
	imagewebp( $image, $path, 80 );
	return $path;
}

/**
 * Returns the width of a WebP file on disk.
 *
 * @param string $path Absolute path to the WebP file.
 * @return int The pixel width.
 */
function webp_width( string $path ): int {
	$info = getimagesize( $path );
	return is_array( $info ) ? (int) $info[0] : 0;
}

/**
 * Removes a directory tree used as a temp content folder.
 *
 * @param string $dir The directory to remove.
 */
function thumb_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		thumb_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds a thumbnailer bound to the real GD codec.
 *
 * @return Thumbnailer The thumbnailer under test.
 */
function gd_thumbnailer(): Thumbnailer {
	return new Thumbnailer( new Gd_Webp_Codec() );
}

// ---------------------------------------------------------------------------
// The per-width path convention and the at-or-above-width skip rule
// ---------------------------------------------------------------------------

test( 'a thumbnail is written per configured width below the main width', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 2000, 1200 );

	$written = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', [ 320, 640 ], 80 );

	// Both widths are below the main's 2000px, so both thumbnails appear at the
	// conventional path and at exactly their configured widths.
	$small = $folder . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp';
	$large = $folder . '/' . Index::THUMBNAILS_DIRNAME . '/640/photo.jpg.webp';
	expect( $written )->toHaveCount( 2 );
	expect( is_file( $small ) )->toBeTrue();
	expect( is_file( $large ) )->toBeTrue();
	expect( webp_width( $small ) )->toBe( 320 );
	expect( webp_width( $large ) )->toBe( 640 );

	thumb_remove_tree( $folder );
} );

test( 'an image at or below a width gets no separate thumbnail at that width', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'small.jpg.webp', 500, 400 );

	$written = gd_thumbnailer()->generate( $main, 'small.jpg.webp', [ 320, 640, 500 ], 80 );

	// 320 is below the main width so it is derived; 640 (above) and 500 (equal to
	// the main width) are not — the main serves both of those roles itself.
	expect( $written )->toHaveCount( 1 );
	expect( is_file( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/320/small.jpg.webp' ) )->toBeTrue();
	expect( is_file( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/640/small.jpg.webp' ) )->toBeFalse();
	expect( is_file( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/500/small.jpg.webp' ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

test( 'an empty widths list writes no thumbnails at all', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'none.jpg.webp', 2000, 1200 );

	$written = gd_thumbnailer()->generate( $main, 'none.jpg.webp', [], 80 );

	// The empty list short-circuits before any read, so the hidden directory is
	// never even created by the thumbnailer.
	expect( $written )->toBe( [] );
	expect( is_dir( $folder . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

test( 'thumbnails carry the same stored name as the main', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'a.b.c.jpg.webp', 1500, 1000 );

	gd_thumbnailer()->generate( $main, 'a.b.c.jpg.webp', [ 320 ], 80 );

	// The multi-dot stored name is reproduced verbatim inside the width directory.
	expect( is_file( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/320/a.b.c.jpg.webp' ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

test( 'an unreadable main yields no thumbnails rather than an error', function (): void {
	$folder = fresh_thumb_dir();

	$written = gd_thumbnailer()->generate( $folder . '/missing.webp', 'missing.webp', [ 320 ], 80 );

	expect( $written )->toBe( [] );

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// The static path helper matches what generate() writes
// ---------------------------------------------------------------------------

test( 'the static thumbnail_path helper matches the written location', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 2000, 1200 );

	$written  = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', [ 640 ], 80 );
	$computed = Thumbnailer::thumbnail_path( $folder, 'photo.jpg.webp', 640 );

	// The path the caller would compute to locate or remove a thumbnail is exactly
	// the one generate() wrote, keeping the convention in one place.
	expect( $written )->toBe( [ $computed ] );
	expect( is_file( $computed ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// The megapixel input ceiling guards the thumbnailer's decode too
// ---------------------------------------------------------------------------

test( 'a main over the megapixel input ceiling is refused before decode', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'big.jpg.webp', 200, 200 );

	// Filter the ceiling down to 0.01 MP (10 000 px) so the 40 000 px main is
	// over it: the thumbnailer must refuse to decode — a foreign or tampered
	// main this large would OOM the worker — and derive nothing.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ): mixed {
			return $hook === 'kntnt_photo_drop_max_input_megapixels' ? 0.01 : $value;
		}
	);

	$written = gd_thumbnailer()->generate( $main, 'big.jpg.webp', [ 100 ], 80 );

	expect( $written )->toBe( [] );
	expect( is_dir( $folder . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// Thumbnails are published atomically
// ---------------------------------------------------------------------------

test( 'thumbnail writes leave no staging files behind', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 2000, 1200 );

	$written = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', [ 320, 640 ], 80 );

	// The atomic writer stages under `<target>.tmp-<random>` beside the target;
	// a clean run publishes both thumbnails and removes every staging file.
	expect( $written )->toHaveCount( 2 );
	expect( glob( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/*/*.tmp-*' ) )->toBe( [] );

	thumb_remove_tree( $folder );
} );
