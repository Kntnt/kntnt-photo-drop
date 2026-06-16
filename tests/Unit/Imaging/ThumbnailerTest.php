<?php
/**
 * Tests for derived-rendition generation — the full and thumbnail files written
 * under `.kntnt-thumbnails/<width>/<name>.webp` (ADR-0013).
 *
 * Each test writes a real WebP main into a temp folder and drives the real GD
 * codec, then asserts which derived files appear and at what width. It covers the
 * per-width path convention, the tier-skip rules realised on disk (a separate
 * full only when the main is wider than the full width; a thumbnail only when the
 * full rendition is wider than the thumbnail width), the degenerate-collapse case,
 * the input-ceiling guard, and atomic publication.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Storage\Index;
use Tests\Unit\Fixtures\Encode_Failing_Codec;

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
 * Returns the absolute derived-file path for a width in a folder.
 *
 * @param string $folder The content folder.
 * @param string $name   The stored main filename.
 * @param int    $width  The rendition width.
 * @return string The absolute derived file path.
 */
function derived_path( string $folder, string $name, int $width ): string {
	return $folder . '/' . Index::THUMBNAILS_DIRNAME . '/' . $width . '/' . $name;
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
// A wide main writes both a full and a thumbnail
// ---------------------------------------------------------------------------

test( 'a main wider than both tiers writes a full and a thumbnail at their widths', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 4000, 2400 );

	// Full 1920/85, thumbnail 640/75: the main (4000) is wider than both, so both
	// derived files are written, each at exactly its configured width.
	$written = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 1920, 85, 640, 75 );

	$full  = derived_path( $folder, 'photo.jpg.webp', 1920 );
	$thumb = derived_path( $folder, 'photo.jpg.webp', 640 );
	expect( $written )->toHaveCount( 2 );
	expect( is_file( $full ) )->toBeTrue();
	expect( is_file( $thumb ) )->toBeTrue();
	expect( webp_width( $full ) )->toBe( 1920 );
	expect( webp_width( $thumb ) )->toBe( 640 );

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// A main no wider than the full skips the full tier
// ---------------------------------------------------------------------------

test( 'a main no wider than the full width writes only the thumbnail', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'mid.jpg.webp', 1500, 1000 );

	// The main (1500) is the full rendition itself (no separate full file), but it
	// is still wider than the 640 thumbnail, so only the thumbnail is written.
	$written = gd_thumbnailer()->generate( $main, 'mid.jpg.webp', 1920, 85, 640, 75 );

	expect( $written )->toHaveCount( 1 );
	expect( is_file( derived_path( $folder, 'mid.jpg.webp', 1920 ) ) )->toBeFalse();
	expect( is_file( derived_path( $folder, 'mid.jpg.webp', 640 ) ) )->toBeTrue();
	expect( webp_width( derived_path( $folder, 'mid.jpg.webp', 640 ) ) )->toBe( 640 );

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// A small main collapses every tier into itself
// ---------------------------------------------------------------------------

test( 'a main no wider than the thumbnail writes nothing', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'small.jpg.webp', 500, 400 );

	// The main (500) is no wider than either tier, so it serves every role and no
	// derived file is written — the hidden directory is never even created.
	$written = gd_thumbnailer()->generate( $main, 'small.jpg.webp', 1920, 85, 640, 75 );

	expect( $written )->toBe( [] );
	expect( is_dir( $folder . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// Equal full and thumbnail widths collapse to one file at the full quality
// ---------------------------------------------------------------------------

test( 'equal full and thumbnail widths write one file at the full quality', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 2000, 1200 );

	// Full and thumbnail both at 800: only one file lives at .kntnt-thumbnails/800/,
	// and the contrasting qualities (90 vs 40) must collapse to the larger tier's
	// — the full — so the single file is the higher-quality one (ADR-0013).
	$collapsed = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 800, 90, 800, 40 );
	$at_collapse = filesize( derived_path( $folder, 'photo.jpg.webp', 800 ) );
	expect( $collapsed )->toHaveCount( 1 );

	// Re-derive the same main with the *thumbnail* quality raised to the full's: a
	// genuine quality-40 thumbnail would be far smaller, so proving the file matches
	// the high-quality encode proves the collapse used the full quality, not 40.
	$folder2 = fresh_thumb_dir();
	$main2   = write_main_image( $folder2, 'photo.jpg.webp', 2000, 1200 );
	gd_thumbnailer()->generate( $main2, 'photo.jpg.webp', 800, 90, 800, 90 );
	$at_explicit_full = filesize( derived_path( $folder2, 'photo.jpg.webp', 800 ) );

	expect( $at_collapse )->toBe( $at_explicit_full );

	thumb_remove_tree( $folder );
	thumb_remove_tree( $folder2 );
} );

// ---------------------------------------------------------------------------
// The full and thumbnail carry the main's stored name
// ---------------------------------------------------------------------------

test( 'derived files carry the same stored name as the main', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'a.b.c.jpg.webp', 3000, 2000 );

	gd_thumbnailer()->generate( $main, 'a.b.c.jpg.webp', 1920, 85, 640, 75 );

	// The multi-dot stored name is reproduced verbatim inside both width directories.
	expect( is_file( derived_path( $folder, 'a.b.c.jpg.webp', 1920 ) ) )->toBeTrue();
	expect( is_file( derived_path( $folder, 'a.b.c.jpg.webp', 640 ) ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// An unreadable main is healed later, not an error now
// ---------------------------------------------------------------------------

test( 'an unreadable main yields no derived files rather than an error', function (): void {
	$folder = fresh_thumb_dir();

	$written = gd_thumbnailer()->generate( $folder . '/missing.webp', 'missing.webp', 1920, 85, 640, 75 );

	expect( $written )->toBe( [] );

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// The static path helper matches what generate() writes
// ---------------------------------------------------------------------------

test( 'the static thumbnail_path helper matches the written location', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 4000, 2400 );

	$written  = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 1920, 85, 640, 75 );
	$computed = Thumbnailer::thumbnail_path( $folder, 'photo.jpg.webp', 640 );

	// The path the caller would compute to locate or remove a derived file is one of
	// the paths generate() wrote, keeping the convention in one place.
	expect( $written )->toContain( $computed );
	expect( is_file( $computed ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// The megapixel input ceiling guards the deriver's decode too
// ---------------------------------------------------------------------------

test( 'a main over the megapixel input ceiling is refused before decode', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'big.jpg.webp', 200, 200 );

	// Filter the ceiling down to 0.01 MP (10 000 px) so the 40 000 px main is over
	// it: the deriver must refuse to decode — a foreign or tampered main this large
	// would OOM the worker — and derive nothing.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ): mixed {
			return $hook === 'kntnt_photo_drop_max_input_megapixels' ? 0.01 : $value;
		}
	);

	$written = gd_thumbnailer()->generate( $main, 'big.jpg.webp', 1920, 85, 640, 75 );

	expect( $written )->toBe( [] );
	expect( is_dir( $folder . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// Derived files are published atomically
// ---------------------------------------------------------------------------

test( 'derived writes leave no staging files behind', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 4000, 2400 );

	$written = gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 1920, 85, 640, 75 );

	// The atomic writer stages under `<target>.tmp-<random>` beside the target; a
	// clean run publishes both derived files and removes every staging file.
	expect( $written )->toHaveCount( 2 );
	expect( glob( $folder . '/' . Index::THUMBNAILS_DIRNAME . '/*/*.tmp-*' ) )->toBe( [] );

	thumb_remove_tree( $folder );
} );

// ---------------------------------------------------------------------------
// renditions_present — the completeness disambiguator behind verify-then-flip
// ---------------------------------------------------------------------------

test( 'renditions_present is true once a main has every expected rendition on disk', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 4000, 2400 );

	// Derive the full set for real (a 1920 full and a 640 thumbnail), then ask whether
	// they are present under the same settings — the post-derive complete state.
	gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 1920, 85, 640, 75 );

	expect( gd_thumbnailer()->renditions_present( $main, 'photo.jpg.webp', 1920, 85, 640, 75 ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

test( 'renditions_present is false when an expected rendition is missing on disk', function (): void {
	$folder = fresh_thumb_dir();
	$main   = write_main_image( $folder, 'photo.jpg.webp', 4000, 2400 );

	// Derive only the old-width set, then check completeness against *different* target
	// widths whose files were never written: the plan expects them, none are on disk.
	gd_thumbnailer()->generate( $main, 'photo.jpg.webp', 1920, 85, 640, 75 );

	expect( gd_thumbnailer()->renditions_present( $main, 'photo.jpg.webp', 800, 85, 300, 75 ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );

test( 'renditions_present distinguishes a failed re-derive from a tier-collapse', function (): void {
	$folder = fresh_thumb_dir();

	// A wide main whose deriver encodes to null wrote nothing though its plan is
	// non-empty: that is a failure, not a collapse, so completeness must read false —
	// the very ambiguity generate()'s empty list cannot resolve.
	$wide = write_main_image( $folder, 'wide.jpg.webp', 4000, 2400 );
	$failing = new Thumbnailer( new Encode_Failing_Codec() );
	$failing->generate( $wide, 'wide.jpg.webp', 1920, 85, 640, 75 );
	expect( $failing->renditions_present( $wide, 'wide.jpg.webp', 1920, 85, 640, 75 ) )->toBeFalse();

	// A genuinely small main has an empty plan — it serves every role itself — so it is
	// vacuously complete even though no derived file exists.
	$small = write_main_image( $folder, 'small.jpg.webp', 400, 300 );
	expect( gd_thumbnailer()->renditions_present( $small, 'small.jpg.webp', 1920, 85, 640, 75 ) )->toBeTrue();

	thumb_remove_tree( $folder );
} );

test( 'renditions_present is false when the main cannot be decoded', function (): void {
	$folder = fresh_thumb_dir();

	// A main whose bytes are not a decodable image yields no plan to verify against; an
	// unverifiable main is never treated as complete, so the flip can never proceed onto
	// it.
	$path = $folder . '/corrupt.jpg.webp';
	file_put_contents( $path, 'this is not an image at all' );

	expect( gd_thumbnailer()->renditions_present( $path, 'corrupt.jpg.webp', 1920, 85, 640, 75 ) )->toBeFalse();

	thumb_remove_tree( $folder );
} );
