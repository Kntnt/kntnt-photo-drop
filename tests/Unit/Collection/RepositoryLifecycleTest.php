<?php
/**
 * Tests for the collection repository's write side: creating a collection's
 * directory and deleting a collection tree.
 *
 * WordPress functions (`wp_upload_dir`, `apply_filters`, `trailingslashit`,
 * `wp_mkdir_p`) are stubbed via Brain Monkey, but a real temp directory backs
 * the uploads basedir so the filesystem effects exercise the actual disk. Each
 * test seeds its own state and tears the tree down afterwards. The shared
 * helpers (`wire_repository_stubs`, `seed_collection`, `repo_remove_tree`,
 * `fresh_basedir`) live in RepositoryTest.php and are reused here.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Collection\Repository;

// ---------------------------------------------------------------------------
// create_collection — directory creation and refusals
// ---------------------------------------------------------------------------

test( 'create_collection makes the directory and returns its path', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );

	$path = ( new Repository() )->create_collection( 'spring-2024' );

	// The returned path is the slug under the root and the directory now exists,
	// but it is bare — the descriptor is the caller's separate step.
	expect( $path )->toBe( $root . 'spring-2024' );
	expect( is_dir( $root . 'spring-2024' ) )->toBeTrue();
	expect( is_file( $root . 'spring-2024/' . Repository::DESCRIPTOR_FILENAME ) )->toBeFalse();

	repo_remove_tree( $basedir );
} );

test( 'create_collection seeds a directory-listing guard in the new directory', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );

	$path = ( new Repository() )->create_collection( 'guarded' );

	// The new collection directory carries the same "Silence is golden"
	// index.php the root gets, so a server with autoindex enabled cannot
	// enumerate the images or the descriptor.
	expect( is_file( $path . '/index.php' ) )->toBeTrue();
	expect( file_get_contents( $path . '/index.php' ) )->toContain( 'Silence is golden' );

	repo_remove_tree( $basedir );
} );

test( 'create_collection refuses an existing directory', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// A directory already at the slug must not be clobbered; creation refuses.
	mkdir( $root . 'taken', 0700, true );
	file_put_contents( $root . 'taken/keep.txt', 'precious' );

	expect( ( new Repository() )->create_collection( 'taken' ) )->toBeNull();
	expect( is_file( $root . 'taken/keep.txt' ) )->toBeTrue();

	repo_remove_tree( $basedir );
} );

test( 'create_collection refuses a malformed slug before touching disk', function ( string $hostile ): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );

	// A hostile or malformed slug creates nothing.
	expect( ( new Repository() )->create_collection( $hostile ) )->toBeNull();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	repo_remove_tree( $basedir );
} )->with( [
	'traversal'      => [ '../escape' ],
	'separator'      => [ 'a/b' ],
	'uppercase'      => [ 'Spring' ],
	'leading hyphen' => [ '-bad' ],
	'empty'          => [ '' ],
] );

// ---------------------------------------------------------------------------
// delete_collection — removal and refusals
// ---------------------------------------------------------------------------

test( 'delete_collection removes the whole tree', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// A collection with a nested content folder, a thumbnail dir, and a descriptor
	// — the entire tree must be gone after deletion.
	seed_collection( $root, 'album' );
	mkdir( $root . 'album/2024/.kntnt-thumbnails/640', 0700, true );
	file_put_contents( $root . 'album/2024/photo.jpg.webp', 'main' );
	file_put_contents( $root . 'album/2024/.kntnt-thumbnails/640/photo.jpg.webp', 'thumb' );

	expect( ( new Repository() )->delete_collection( 'album' ) )->toBeTrue();
	expect( is_dir( $root . 'album' ) )->toBeFalse();

	repo_remove_tree( $basedir );
} );

test( 'delete_collection refuses an unknown slug', function (): void {
	$basedir = fresh_basedir();
	wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// Nothing to delete: the slug resolves to no collection.
	expect( ( new Repository() )->delete_collection( 'ghost' ) )->toBeFalse();

	repo_remove_tree( $basedir );
} );

test( 'delete_collection refuses a bare directory without a descriptor', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// A directory without a descriptor is not a collection and must be left alone.
	mkdir( $root . 'bare', 0700, true );
	file_put_contents( $root . 'bare/keep.txt', 'precious' );

	expect( ( new Repository() )->delete_collection( 'bare' ) )->toBeFalse();
	expect( is_file( $root . 'bare/keep.txt' ) )->toBeTrue();

	repo_remove_tree( $basedir );
} );

test( 'delete_collection does not follow a symlink out of the tree', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// An outside file the collection symlinks to must survive deletion: the leaf
	// handling unlinks the link, never recurses into its target.
	$outside = $basedir . '/outside.txt';
	file_put_contents( $outside, 'survivor' );
	seed_collection( $root, 'linky' );
	symlink( $outside, $root . 'linky/pointer.txt' );

	expect( ( new Repository() )->delete_collection( 'linky' ) )->toBeTrue();
	expect( is_dir( $root . 'linky' ) )->toBeFalse();
	expect( is_file( $outside ) )->toBeTrue();
	expect( file_get_contents( $outside ) )->toBe( 'survivor' );

	repo_remove_tree( $basedir );
} );
