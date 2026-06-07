<?php
/**
 * Tests for the image command's pure source/path helper.
 *
 * The helper decides, without touching WP-CLI, what relative target a source
 * maps to under a collection root: a relative source keeps its sub-directories,
 * an absolute source collapses to its basename. It also reads a source file's
 * bytes. These pure rules are covered here so the command's tests can focus on
 * the WP-CLI glue.
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
