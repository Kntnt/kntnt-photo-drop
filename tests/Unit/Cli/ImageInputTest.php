<?php
/**
 * Tests for the image command's pure source/path helper.
 *
 * The helper decides, without touching WP-CLI, what relative target a source
 * maps to under a collection root: a relative source keeps its sub-directories,
 * an absolute source collapses to its basename. It also reads a source file's
 * bytes and expands a source into the concrete import units it stands for — a
 * file is itself, a directory is walked recursively for every image under it,
 * skipping hidden noise and RAW/video siblings. These rules are covered here so
 * the command's tests can focus on the WP-CLI glue.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Cli\Image_Input;

// ---------------------------------------------------------------------------
// relative_target — relative keeps the tree, absolute collapses to basename
// ---------------------------------------------------------------------------

test( 'a relative source keeps its sub-directory structure', function (): void {
	expect( ( new Image_Input() )->relative_target( 'photos/2024/IMG.jpg' ) )->toBe( 'photos/2024/IMG.jpg' );
} );

test( 'a bare relative filename maps to itself', function (): void {
	expect( ( new Image_Input() )->relative_target( 'photo.jpg' ) )->toBe( 'photo.jpg' );
} );

test( 'an absolute source collapses to its basename', function ( string $source, string $expected ): void {
	expect( ( new Image_Input() )->relative_target( $source ) )->toBe( $expected );
} )->with( [
	'unix absolute' => [ '/home/user/photos/IMG.jpg', 'IMG.jpg' ],
	'windows drive' => [ 'C:\\Users\\me\\IMG.jpg', 'IMG.jpg' ],
	'windows unc'   => [ '\\\\server\\share\\IMG.jpg', 'IMG.jpg' ],
] );

// ---------------------------------------------------------------------------
// is_absolute — classification used to decide tree-vs-basename
// ---------------------------------------------------------------------------

test( 'is_absolute classifies paths correctly', function ( string $path, bool $expected ): void {
	expect( ( new Image_Input() )->is_absolute( $path ) )->toBe( $expected );
} )->with( [
	'unix root'     => [ '/etc/passwd', true ],
	'windows drive' => [ 'C:\\Windows', true ],
	'windows unc'   => [ '\\\\srv\\x', true ],
	'relative file' => [ 'photo.jpg', false ],
	'relative tree' => [ 'a/b/c.jpg', false ],
	'dot-relative'  => [ './photo.jpg', false ],
] );

// ---------------------------------------------------------------------------
// read_source — reads bytes, or null when missing
// ---------------------------------------------------------------------------

test( 'read_source returns the file bytes for an existing file', function (): void {
	$file = sys_get_temp_dir() . '/kntnt-src-' . bin2hex( random_bytes( 6 ) ) . '.bin';
	file_put_contents( $file, 'hello bytes' );

	expect( ( new Image_Input() )->read_source( $file ) )->toBe( 'hello bytes' );

	@unlink( $file );
} );

test( 'read_source returns null for a missing file', function (): void {
	$missing = sys_get_temp_dir() . '/kntnt-missing-' . bin2hex( random_bytes( 6 ) );

	expect( ( new Image_Input() )->read_source( $missing ) )->toBeNull();
} );

// ---------------------------------------------------------------------------
// expand_source — a file is one unit; a directory fans out into its images
// ---------------------------------------------------------------------------

test( 'expand_source on a file returns a single unit reading and labelling the given path', function (): void {
	$file = sys_get_temp_dir() . '/kntnt-one-' . bin2hex( random_bytes( 6 ) ) . '.jpg';
	file_put_contents( $file, 'x' );

	// An absolute file collapses to its basename for the target, but reads from
	// the given path and labels itself by that path — exactly as before.
	expect( ( new Image_Input() )->expand_source( $file ) )->toBe( [
		[
			'read'     => $file,
			'relative' => basename( $file ),
			'label'    => $file,
		],
	] );

	@unlink( $file );
} );

test( 'expand_source on a non-existent path stays a single unit for downstream rejection', function (): void {
	$missing = 'kntnt-missing-' . bin2hex( random_bytes( 6 ) ) . '.jpg';

	// A relative path that is neither a file nor a directory flows on as one unit
	// so the command reports it as a rejection — the pre-directory behaviour.
	expect( ( new Image_Input() )->expand_source( $missing ) )->toBe( [
		[
			'read'     => $missing,
			'relative' => $missing,
			'label'    => $missing,
		],
	] );
} );

test( 'expand_source walks a relative directory, keeping its name as the prefix', function (): void {
	$root = input_make_dir();
	input_build_tree( $root, [ 'a.jpg', 'sub/b.jpg', 'sub/deep/c.jpg' ] );
	$base = basename( $root );

	// A relative directory keeps its own name as the top-level prefix; the units
	// are sorted by relative target, so the order is deterministic.
	$units = input_in_dir( dirname( $root ), fn (): array => ( new Image_Input() )->expand_source( $base ) );

	expect( array_column( $units, 'relative' ) )->toBe( [
		"{$base}/a.jpg",
		"{$base}/sub/b.jpg",
		"{$base}/sub/deep/c.jpg",
	] );
	// The read path and the label match the relative target for a relative walk.
	expect( $units[0] )->toBe( [
		'read'     => "{$base}/a.jpg",
		'relative' => "{$base}/a.jpg",
		'label'    => "{$base}/a.jpg",
	] );

	input_remove_dir( $root );
} );

test( 'an absolute directory collapses to its basename as the prefix', function (): void {
	$root = input_make_dir();
	input_build_tree( $root, [ 'a.jpg', 'sub/b.jpg' ] );
	$base = basename( $root );

	$units = ( new Image_Input() )->expand_source( $root );

	expect( array_column( $units, 'relative' ) )->toBe( [ "{$base}/a.jpg", "{$base}/sub/b.jpg" ] );
	// The read path stays the absolute on-disk file even as the target collapses.
	expect( $units[0]['read'] )->toBe( "{$root}/a.jpg" );

	input_remove_dir( $root );
} );

test( 'expand_source skips hidden files and the contents of hidden directories', function (): void {
	$root = input_make_dir();
	input_build_tree(
		$root,
		[ 'a.jpg', '.DS_Store', '._a.jpg', '.git/config', '.thumbnails/x.jpg', 'sub/b.jpg' ],
	);
	$base = basename( $root );

	expect( array_column( ( new Image_Input() )->expand_source( $root ), 'relative' ) )
		->toBe( [ "{$base}/a.jpg", "{$base}/sub/b.jpg" ] );

	input_remove_dir( $root );
} );

test( 'expand_source skips RAW and video siblings when walking a directory', function (): void {
	$root = input_make_dir();
	input_build_tree( $root, [ 'photo.jpg', 'IMG.CR2', 'clip.MOV' ] );
	$base = basename( $root );

	expect( array_column( ( new Image_Input() )->expand_source( $root ), 'relative' ) )
		->toBe( [ "{$base}/photo.jpg" ] );

	input_remove_dir( $root );
} );

test( 'a directory source with a trailing slash yields no double slash', function (): void {
	$root = input_make_dir();
	input_build_tree( $root, [ 'a.jpg' ] );
	$base = basename( $root );

	$units = input_in_dir( dirname( $root ), fn (): array => ( new Image_Input() )->expand_source( "{$base}/" ) );

	expect( $units[0]['relative'] )->toBe( "{$base}/a.jpg" );

	input_remove_dir( $root );
} );

// ---------------------------------------------------------------------------
// Local helpers — build and tear down a small directory tree on disk
// ---------------------------------------------------------------------------

/**
 * Creates a fresh, uniquely named temp directory and returns its absolute path.
 *
 * @return string The new directory's absolute path.
 */
function input_make_dir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-input-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a placeholder file for each relative path, creating parents as needed.
 *
 * `expand_source` never reads the bytes, so a one-byte placeholder is enough to
 * exercise the directory walk and the path math.
 *
 * @param string            $root      The directory to build the tree under.
 * @param array<int,string> $relatives Forward-slash paths relative to the root.
 */
function input_build_tree( string $root, array $relatives ): void {
	foreach ( $relatives as $relative ) {
		$path = $root . '/' . $relative;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( $path, 'x' );
	}
}

/**
 * Runs a callback with the working directory temporarily changed, then restores it.
 *
 * Lets a test exercise a relative directory source without leaking a changed
 * working directory into the rest of the suite.
 *
 * @param string           $dir      The directory to run inside.
 * @param callable():mixed $callback The work to run while inside `$dir`.
 * @return mixed The callback's return value.
 */
function input_in_dir( string $dir, callable $callback ): mixed {
	$previous = getcwd();
	chdir( $dir );
	try {
		return $callback();
	} finally {
		if ( $previous !== false ) {
			chdir( $previous );
		}
	}
}

/**
 * Recursively removes a directory tree created for one test.
 *
 * @param string $dir The directory to remove.
 */
function input_remove_dir( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		input_remove_dir( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}
