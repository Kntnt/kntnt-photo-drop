<?php
/**
 * Tests for the shared image-deletion service — the routine behind both the CLI
 * `image delete` verb and the gallery's trash REST write-path (ADR-0015).
 *
 * `Image_Deleter` is the one place a collection image is removed: it confines a
 * caller-supplied relative path to the collection root, resolves it to a stored
 * `<original>.webp` main, and — when that main really exists — removes it and
 * every derived artifact (the full rendition and the thumbnail, both living
 * under the hidden `.kntnt-thumbnails/<width>/` corral), leaving the per-folder
 * index to self-heal on the next render. The suite drives the real service
 * against a real temp-dir collection so the confinement and the on-disk removal
 * are exercised for real, never mocked: it proves a confined main and all its
 * derived files are removed, a hostile path resolves to no main (nothing
 * removed), a foreign non-`.webp` file resolves to no main, and the original
 * filename resolves to its stored main.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Collection\Image_Deleter;
use Kntnt\Photo_Drop\Storage\Index;

/**
 * Allocates a fresh temp directory standing in for a collection root.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_deleter_root(): string {
	$dir = sys_get_temp_dir() . '/kntnt-deleter-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a real WebP file at a collection-relative path, recreating directories.
 *
 * @param string $root     The absolute collection root.
 * @param string $relative The path relative to the root.
 * @return string The absolute path of the written file.
 */
function write_deleter_webp( string $root, string $relative ): string {
	$absolute = rtrim( $root, '/' ) . '/' . $relative;
	$dir      = dirname( $absolute );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	$image = imagecreatetruecolor( 40, 30 );
	imagewebp( $image, $absolute );
	return $absolute;
}

/**
 * Recursively removes a temp directory tree.
 *
 * @param string $dir The directory to remove.
 * @return void
 */
function deleter_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		deleter_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// resolve_main — confine + map to a stored main, or null
// ---------------------------------------------------------------------------

test( 'resolve_main maps a stored-name path to the existing main', function (): void {

	// A path naming the stored `<original>.webp` form resolves to that absolute
	// main when it exists on disk.
	$root = fresh_deleter_root();
	$main = write_deleter_webp( $root, 'photo.jpg.webp' );

	$resolved = ( new Image_Deleter() )->resolve_main( $root, 'photo.jpg.webp' );

	expect( $resolved )->not->toBeNull();
	expect( realpath( $resolved ) )->toBe( realpath( $main ) );

	deleter_remove_tree( $root );
} );

test( 'resolve_main accepts the original filename and finds the stored main', function (): void {

	// Passing the original name (without the appended `.webp`) resolves the stored
	// `<original>.webp` main — the same convenience the CLI `image delete` offers.
	$root = fresh_deleter_root();
	$main = write_deleter_webp( $root, 'IMG.jpg.webp' );

	$resolved = ( new Image_Deleter() )->resolve_main( $root, 'IMG.jpg' );

	expect( realpath( $resolved ) )->toBe( realpath( $main ) );

	deleter_remove_tree( $root );
} );

test( 'resolve_main returns null for a foreign non-webp file', function (): void {

	// A foreign file the plugin never created has no `<original>.webp` form on disk,
	// so it resolves to no main — a delete can therefore never remove it.
	$root = fresh_deleter_root();
	file_put_contents( rtrim( $root, '/' ) . '/notes.txt', 'keep me' );

	$resolved = ( new Image_Deleter() )->resolve_main( $root, 'notes.txt' );

	expect( $resolved )->toBeNull();

	deleter_remove_tree( $root );
} );

test( 'resolve_main rejects a hostile traversal path with no resolution', function ( string $hostile ): void {

	// Every traversal/scheme/absolute path is confined away to null by Path_Guard,
	// so nothing outside the collection root can ever resolve to a main.
	$root = fresh_deleter_root();
	write_deleter_webp( $root, 'photo.jpg.webp' );

	$resolved = ( new Image_Deleter() )->resolve_main( $root, $hostile );

	expect( $resolved )->toBeNull();

	deleter_remove_tree( $root );
} )->with( [
	'parent traversal'  => [ '../escape.webp' ],
	'deep traversal'    => [ '../../../../etc/passwd' ],
	'encoded traversal' => [ '%2e%2e%2fescape.webp' ],
	'absolute path'     => [ '/etc/passwd' ],
	'nul byte'          => [ "ok\x00/../escape.webp" ],
] );

// ---------------------------------------------------------------------------
// delete — removes the main and every derived artifact
// ---------------------------------------------------------------------------

test( 'delete removes the main, the full rendition, and the thumbnail', function (): void {

	// A main with both a full rendition and a thumbnail under the hidden corral:
	// deleting the main removes the main and every derived width, since both the
	// full and the thumbnail live under `.kntnt-thumbnails/<width>/` (ADR-0013).
	$root  = fresh_deleter_root();
	$main  = write_deleter_webp( $root, 'photo.jpg.webp' );
	$full  = write_deleter_webp( $root, Index::THUMBNAILS_DIRNAME . '/1920/photo.jpg.webp' );
	$thumb = write_deleter_webp( $root, Index::THUMBNAILS_DIRNAME . '/640/photo.jpg.webp' );

	$removed = ( new Image_Deleter() )->delete( $main );

	expect( $removed )->toBeTrue();
	expect( is_file( $main ) )->toBeFalse();
	expect( is_file( $full ) )->toBeFalse();
	expect( is_file( $thumb ) )->toBeFalse();

	deleter_remove_tree( $root );
} );

test( 'delete leaves a sibling main and its derived artifacts untouched', function (): void {

	// Deleting one main removes only its own derived files; a sibling main and the
	// sibling's thumbnail in the same width directory survive, so the service never
	// over-deletes.
	$root         = fresh_deleter_root();
	$main         = write_deleter_webp( $root, 'a.jpg.webp' );
	$sibling      = write_deleter_webp( $root, 'b.jpg.webp' );
	$sibling_thumb = write_deleter_webp( $root, Index::THUMBNAILS_DIRNAME . '/640/b.jpg.webp' );
	write_deleter_webp( $root, Index::THUMBNAILS_DIRNAME . '/640/a.jpg.webp' );

	( new Image_Deleter() )->delete( $main );

	expect( is_file( $main ) )->toBeFalse();
	expect( is_file( $sibling ) )->toBeTrue();
	expect( is_file( $sibling_thumb ) )->toBeTrue();

	deleter_remove_tree( $root );
} );

test( 'delete reports false when the main cannot be removed', function (): void {

	// A main path that names no file cannot be removed; the service reports false so
	// a caller can surface the failure rather than claim a phantom success.
	$root = fresh_deleter_root();

	$removed = ( new Image_Deleter() )->delete( rtrim( $root, '/' ) . '/ghost.jpg.webp' );

	expect( $removed )->toBeFalse();

	deleter_remove_tree( $root );
} );
