<?php
/**
 * Tests for the doctor's reconciliation of derived artifacts to main images.
 *
 * Each test builds a *real* on-disk collection in a temp directory with *real*
 * GD WebP images and drives the real `Doctor` service end to end (real codec,
 * real thumbnailer, real index store). It covers the design's Doctor contract:
 * report-only lists every drift and changes nothing; `--repair` creates missing
 * thumbnails, refreshes the index, and removes orphans; `--repair --force`
 * re-derives everything after a thumbnail-width change; an image below a width is
 * not flagged; a contract-violating main is warned but never processed or
 * deleted; foreign files honour the ignore list, `--ignore`, and `--show-ignored`
 * (and a `.thumbnails` dir is foreign); and mains are never altered, foreign
 * files never deleted (hashed before/after).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Doctor\Doctor;
use Kntnt\Photo_Drop\Doctor\Finding_Kind;
use Kntnt\Photo_Drop\Doctor\Ignore_Matcher;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Kntnt\Photo_Drop\Storage\Index_Store;

// The doctor's collaborators reach for wp_mkdir_p() and wp_json_encode() when
// defined; once Brain Monkey has seen a function in any test it stays defined, so
// every test here wires them to real behaviour against the temp tree.
beforeEach( function (): void {
	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);
} );

/**
 * Allocates a fresh temp directory standing in for a collection root.
 *
 * @return string The absolute path of the new collection root.
 */
function doctor_fresh_root(): string {
	$dir = sys_get_temp_dir() . '/kntnt-doctor-' . bin2hex( random_bytes( 6 ) );
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
function write_doctor_main( string $folder, string $filename, int $width, int $height = 0 ): string {
	$height = $height === 0 ? (int) round( $width * 0.6 ) : $height;
	$image  = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 30, 110, 180 ) );
	$path = rtrim( $folder, '/' ) . '/' . $filename;
	imagewebp( $image, $path, 80 );
	return $path;
}

/**
 * Writes a real JPEG (a non-WebP file) at a path, simulating an out-of-band copy.
 *
 * @param string $path  The absolute path to write to.
 * @param int    $width The image width in pixels.
 * @return string The absolute path written.
 */
function write_doctor_jpeg( string $path, int $width = 800 ): string {
	$height = (int) round( $width * 0.6 );
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 200, 90, 40 ) );
	imagejpeg( $image, $path, 85 );
	return $path;
}

/**
 * Returns the pixel width of a WebP file on disk.
 *
 * @param string $path Absolute path to the WebP file.
 * @return int The pixel width.
 */
function doctor_webp_width( string $path ): int {
	$info = getimagesize( $path );
	return is_array( $info ) ? (int) $info[0] : 0;
}

/**
 * Removes a directory tree used as a temp collection root.
 *
 * @param string $dir The directory to remove.
 */
function doctor_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		doctor_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds a descriptor with a fixed contract and given thumbnail widths.
 *
 * Constructed directly (not via the filter) so each test pins the contract and
 * the widths it needs without wiring apply_filters.
 *
 * @param array<int,int> $widths    The thumbnail widths.
 * @param int|null       $max_width The contract ceiling, or null for no limit.
 * @return Descriptor The descriptor under test.
 */
function doctor_descriptor( array $widths, ?int $max_width = 1920 ): Descriptor {
	return new Descriptor( 'Test', $max_width, 80, $widths );
}

/**
 * Builds a doctor for a root and descriptor with no extra ignore globs.
 *
 * Uses the production GD-backed engine end to end (default codec, thumbnailer,
 * index store), so the tests exercise real pixel work and a real index rebuild.
 *
 * @param string      $root         The collection root.
 * @param Descriptor  $descriptor   The collection's contract and widths.
 * @param string|null $ignore_globs The raw --ignore value, or null.
 * @return Doctor The doctor under test.
 */
function make_doctor( string $root, Descriptor $descriptor, ?string $ignore_globs = null ): Doctor {
	return new Doctor( $root, $descriptor, new Ignore_Matcher( $ignore_globs ) );
}

/**
 * Returns the relative paths of one finding kind from a report.
 *
 * @param \Kntnt\Photo_Drop\Doctor\Doctor_Report $report The report to read.
 * @param Finding_Kind                           $kind   The kind to extract.
 * @return array<int,string> The relative paths, sorted for stable comparison.
 */
function finding_paths( \Kntnt\Photo_Drop\Doctor\Doctor_Report $report, Finding_Kind $kind ): array {
	$paths = array_map( static fn ( $f ): string => $f->path, $report->of_kind( $kind ) );
	sort( $paths );
	return $paths;
}

// ---------------------------------------------------------------------------
// Report-only: lists every drift and changes nothing on disk
// ---------------------------------------------------------------------------

test( 'report-only lists missing thumbnails for a present main and changes nothing', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	$main = write_doctor_main( $root, 'photo.jpg.webp', 1600 );

	// A 2000px main with no thumbnails: the 320 thumbnail is missing-derived, and the
	// index entry is missing too (no index has been built yet).
	$before = md5_file( $main );
	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	$missing = finding_paths( $report, Finding_Kind::Missing_Derived );
	expect( $missing )->toContain( '.kntnt-thumbnails/320/photo.jpg.webp' );
	expect( $missing )->toContain( 'photo.jpg.webp' );
	expect( $report->repaired )->toBeFalse();

	// The report is the dry run: the main is untouched and no thumbnail was written.
	expect( md5_file( $main ) )->toBe( $before );
	expect( is_dir( $root . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	doctor_remove_tree( $root );
} );

test( 'report-only lists an orphan thumbnail whose main is gone', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );

	// Plant a thumbnail with no corresponding main — an orphan derived artifact.
	$orphan_dir = $root . '/' . Index::THUMBNAILS_DIRNAME . '/320';
	mkdir( $orphan_dir, 0700, true );
	write_doctor_main( $orphan_dir, 'ghost.jpg.webp', 320 );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	expect( finding_paths( $report, Finding_Kind::Orphan_Derived ) )
		->toBe( [ '.kntnt-thumbnails/320/ghost.jpg.webp' ] );
	expect( is_file( $orphan_dir . '/ghost.jpg.webp' ) )->toBeTrue();

	doctor_remove_tree( $root );
} );

test( 'report-only lists a contract-violating main and a foreign file', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ], 1000 )->write( $root );

	// An over-ceiling WebP main (1600 > 1000) and a non-WebP main (JPEG) both arrived
	// out of band; a loose text file is foreign.
	write_doctor_main( $root, 'too-wide.jpg.webp', 1600 );
	write_doctor_jpeg( $root . '/raw.webp', 800 );
	file_put_contents( $root . '/notes.txt', 'hello' );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ], 1000 ) )->run( false, false );

	expect( finding_paths( $report, Finding_Kind::Contract_Violation ) )->toBe( [ 'raw.webp', 'too-wide.jpg.webp' ] );
	expect( finding_paths( $report, Finding_Kind::Foreign ) )->toBe( [ 'notes.txt' ] );

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// --repair: creates missing thumbnails, refreshes the index, removes orphans
// ---------------------------------------------------------------------------

test( 'repair creates the missing thumbnail and refreshes the index', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	$main = write_doctor_main( $root, 'photo.jpg.webp', 1600 );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( true, false );

	// The thumbnail now exists at the conventional path and at exactly 320px, and the
	// index records the main.
	$thumb = $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp';
	expect( is_file( $thumb ) )->toBeTrue();
	expect( doctor_webp_width( $thumb ) )->toBe( 320 );
	expect( $report->created )->toBeGreaterThanOrEqual( 1 );
	expect( $report->repaired )->toBeTrue();

	$index = ( new Index_Store() )->read( $root );
	expect( $index )->not->toBeNull();
	expect( array_map( static fn ( $e ): string => $e->file, $index->images ) )->toContain( 'photo.jpg.webp' );

	doctor_remove_tree( $root );
} );

test( 'repair removes an orphan thumbnail', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	$orphan_dir = $root . '/' . Index::THUMBNAILS_DIRNAME . '/320';
	mkdir( $orphan_dir, 0700, true );
	$orphan = write_doctor_main( $orphan_dir, 'ghost.jpg.webp', 320 );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( true, false );

	expect( is_file( $orphan ) )->toBeFalse();
	expect( $report->removed )->toBe( 1 );

	doctor_remove_tree( $root );
} );

test( 'a second repair run finds nothing left to do', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	write_doctor_main( $root, 'photo.jpg.webp', 1600 );

	make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( true, false );
	$second = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	// After a clean repair there is no missing or orphan derived artifact left.
	expect( $second->of_kind( Finding_Kind::Missing_Derived ) )->toBe( [] );
	expect( $second->of_kind( Finding_Kind::Orphan_Derived ) )->toBe( [] );

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// --repair --force: re-derives everything after a thumbnail-width change
// ---------------------------------------------------------------------------

test( 'force regenerates all thumbnails after a thumbnail-width change', function (): void {
	$root = doctor_fresh_root();

	// Establish at width 320 and repair so the 320 thumbnail exists.
	doctor_descriptor( [ 320 ] )->write( $root );
	write_doctor_main( $root, 'photo.jpg.webp', 1600 );
	make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( true, false );

	// Now the width filter has changed to [320, 640]; a forced repair re-derives the
	// full set, so the new 640 thumbnail appears (and 320 is regenerated).
	$wider  = doctor_descriptor( [ 320, 640 ] );
	$wider->write( $root );
	$report = make_doctor( $root, $wider )->run( true, true );

	$thumb_320 = $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp';
	$thumb_640 = $root . '/' . Index::THUMBNAILS_DIRNAME . '/640/photo.jpg.webp';
	expect( doctor_webp_width( $thumb_320 ) )->toBe( 320 );
	expect( doctor_webp_width( $thumb_640 ) )->toBe( 640 );
	expect( $report->created )->toBe( 2 );

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// An image smaller than the thumbnail width is not flagged
// ---------------------------------------------------------------------------

test( 'an image at or below a thumbnail width is not flagged', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320, 640 ] )->write( $root );

	// A 500px main: 320 is below it (a thumbnail is wanted), but 640 (above) and any
	// width equal to its own need no separate thumbnail — the main serves those roles.
	write_doctor_main( $root, 'small.jpg.webp', 500 );

	$report = make_doctor( $root, doctor_descriptor( [ 320, 640 ] ) )->run( false, false );

	$missing = finding_paths( $report, Finding_Kind::Missing_Derived );
	expect( $missing )->toContain( '.kntnt-thumbnails/320/small.jpg.webp' );
	expect( $missing )->not->toContain( '.kntnt-thumbnails/640/small.jpg.webp' );

	doctor_remove_tree( $root );
} );

test( 'force never derives a thumbnail at or above the main width', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320, 640 ] )->write( $root );
	write_doctor_main( $root, 'small.jpg.webp', 500 );

	make_doctor( $root, doctor_descriptor( [ 320, 640 ] ) )->run( true, true );

	// Only the 320 thumbnail (below 500) exists; the 640 width directory has no
	// thumbnail for this main.
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/small.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/640/small.jpg.webp' ) )->toBeFalse();

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// A contract-violating main is warned, never processed, never deleted
// ---------------------------------------------------------------------------

test( 'a contract-violating main is never processed in place or deleted, even with repair', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ], 1000 )->write( $root );

	// An over-ceiling WebP main and a non-WebP main, both placed out of band.
	$too_wide = write_doctor_main( $root, 'too-wide.jpg.webp', 1600 );
	$non_webp = write_doctor_jpeg( $root . '/raw.webp', 800 );
	$wide_hash = md5_file( $too_wide );
	$jpeg_hash = md5_file( $non_webp );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ], 1000 ) )->run( true, true );

	// Both are reported as violations, both remain on disk, both byte-identical, and
	// neither got a thumbnail derived (a violating main is never processed in place).
	expect( finding_paths( $report, Finding_Kind::Contract_Violation ) )->toBe( [ 'raw.webp', 'too-wide.jpg.webp' ] );
	expect( is_file( $too_wide ) )->toBeTrue();
	expect( is_file( $non_webp ) )->toBeTrue();
	expect( md5_file( $too_wide ) )->toBe( $wide_hash );
	expect( md5_file( $non_webp ) )->toBe( $jpeg_hash );
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/too-wide.jpg.webp' ) )->toBeFalse();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/raw.webp' ) )->toBeFalse();

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Foreign files: built-in ignore list, --ignore, --show-ignored, .thumbnails
// ---------------------------------------------------------------------------

test( 'foreign warnings honour the built-in ignore list', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );

	// OS junk on the built-in list is not warned about; an ordinary loose file is.
	file_put_contents( $root . '/.DS_Store', 'junk' );
	file_put_contents( $root . '/Thumbs.db', 'junk' );
	file_put_contents( $root . '/._photo.jpg.webp', 'junk' );
	file_put_contents( $root . '/notes.txt', 'real' );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	expect( finding_paths( $report, Finding_Kind::Foreign ) )->toBe( [ 'notes.txt' ] );
	expect( finding_paths( $report, Finding_Kind::Ignored ) )
		->toBe( [ '.DS_Store', '._photo.jpg.webp', 'Thumbs.db' ] );

	doctor_remove_tree( $root );
} );

test( 'a --ignore glob extends the ignore list', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	file_put_contents( $root . '/scratch.tmp', 'x' );
	file_put_contents( $root . '/keep.txt', 'x' );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ), '*.tmp' )->run( false, false );

	// The .tmp file is now ignored; the .txt remains foreign.
	expect( finding_paths( $report, Finding_Kind::Foreign ) )->toBe( [ 'keep.txt' ] );
	expect( finding_paths( $report, Finding_Kind::Ignored ) )->toBe( [ 'scratch.tmp' ] );

	doctor_remove_tree( $root );
} );

test( "a user's own .thumbnails directory is treated as foreign", function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );

	// A bare `.thumbnails` (not our namespaced `.kntnt-thumbnails`) is a foreign
	// directory's content, warned about, not skipped.
	mkdir( $root . '/.thumbnails', 0700, true );
	file_put_contents( $root . '/.thumbnails/cache.dat', 'x' );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	expect( finding_paths( $report, Finding_Kind::Foreign ) )->toContain( '.thumbnails/cache.dat' );

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Doctor never alters mains and never deletes foreign files
// ---------------------------------------------------------------------------

test( 'repair never alters a main image and never deletes a foreign file', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320, 640 ] )->write( $root );

	// A conforming main (which will get thumbnails), plus a foreign file.
	$main = write_doctor_main( $root, 'photo.jpg.webp', 1600 );
	file_put_contents( $root . '/notes.txt', 'keep me' );
	$main_hash    = md5_file( $main );
	$foreign_hash = md5_file( $root . '/notes.txt' );

	make_doctor( $root, doctor_descriptor( [ 320, 640 ] ) )->run( true, true );

	// The main is byte-identical (only derived artifacts were written) and the
	// foreign file still exists, byte-identical — even after a forced repair.
	expect( md5_file( $main ) )->toBe( $main_hash );
	expect( is_file( $root . '/notes.txt' ) )->toBeTrue();
	expect( md5_file( $root . '/notes.txt' ) )->toBe( $foreign_hash );

	doctor_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Sub-folders are reconciled too
// ---------------------------------------------------------------------------

test( 'mains in a sub-folder are reconciled like the root', function (): void {
	$root = doctor_fresh_root();
	doctor_descriptor( [ 320 ] )->write( $root );
	mkdir( $root . '/2024', 0700, true );
	write_doctor_main( $root . '/2024', 'trip.jpg.webp', 1500 );

	$report = make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( false, false );

	expect( finding_paths( $report, Finding_Kind::Missing_Derived ) )
		->toContain( '2024/.kntnt-thumbnails/320/trip.jpg.webp' );

	// And a repair derives the sub-folder thumbnail at its conventional path.
	make_doctor( $root, doctor_descriptor( [ 320 ] ) )->run( true, false );
	expect( is_file( $root . '/2024/.kntnt-thumbnails/320/trip.jpg.webp' ) )->toBeTrue();

	doctor_remove_tree( $root );
} );
