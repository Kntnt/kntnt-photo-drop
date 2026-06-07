<?php
/**
 * Tests for the collection repository: root resolution, discovery scan, and
 * slug → path resolution.
 *
 * WordPress functions (`wp_upload_dir`, `apply_filters`, `trailingslashit`,
 * `wp_mkdir_p`) are stubbed via Brain Monkey, but a real temp directory backs
 * the uploads basedir so the discovery scan and slug resolution exercise the
 * actual filesystem. Each test seeds its own collections on disk and tears the
 * tree down afterwards.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Collection\Repository;

/**
 * Wires the WordPress stubs the repository depends on, anchored at a temp dir.
 *
 * `wp_upload_dir()` is made to return the given basedir; `trailingslashit()`
 * and `wp_mkdir_p()` get real behaviour; `apply_filters()` for the root passes
 * the default through unless an override is supplied. Returns the canonical
 * trailing-slashed root the repository will compute, so tests can assert paths
 * against it.
 *
 * @param string      $basedir  Absolute temp directory standing in for the uploads basedir.
 * @param string|null $override Optional value the kntnt_photo_drop_root filter should return.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_repository_stubs( string $basedir, ?string $override = null ): string {

	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => $basedir,
			'error'   => false,
		]
	);

	Functions\when( 'trailingslashit' )->alias(
		static fn ( string $path ): string => rtrim( $path, '/\\' ) . '/'
	);

	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);

	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $override ): mixed {
			return $hook === 'kntnt_photo_drop_root' && $override !== null ? $override : $value;
		}
	);

	$default = $override ?? ( rtrim( $basedir, '/' ) . '/kntnt-photo-drop' );
	return rtrim( $default, '/' ) . '/';

}

/**
 * Creates a collection directory under a root by writing its descriptor.
 *
 * @param string $root The trailing-slashed collection root.
 * @param string $slug The collection slug (directory name).
 */
function seed_collection( string $root, string $slug ): void {
	$dir = $root . $slug;
	mkdir( $dir, 0700, true );
	file_put_contents( $dir . '/' . Repository::DESCRIPTOR_FILENAME, '{}' );
}

/**
 * Removes a directory tree, used to clean up the temp uploads basedir.
 *
 * @param string $dir The directory to remove.
 */
function repo_remove_tree( string $dir ): void {

	// Remove a symlink with unlink rather than recursing into its target.
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		repo_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Allocates a fresh temp basedir for one test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-repo-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

// ---------------------------------------------------------------------------
// Root resolution
// ---------------------------------------------------------------------------

test( 'get_root creates the root and seeds a listing guard', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );

	$resolved = ( new Repository() )->get_root();

	// The root is the basedir plus the plugin directory, created on demand, and
	// carries the "Silence is golden" index.php guard against listing.
	expect( $resolved )->toBe( $root );
	expect( is_dir( $root ) )->toBeTrue();
	expect( is_file( $root . 'index.php' ) )->toBeTrue();

	repo_remove_tree( $basedir );
} );

test( 'get_root honours the kntnt_photo_drop_root filter', function (): void {
	$basedir  = fresh_basedir();
	$override = $basedir . '/custom-root';
	$root     = wire_repository_stubs( $basedir, $override );

	expect( ( new Repository() )->get_root() )->toBe( $root );
	expect( is_dir( $override ) )->toBeTrue();

	repo_remove_tree( $basedir );
} );

test( 'get_root returns null when the uploads basedir is unavailable', function (): void {

	// A populated 'error' from wp_upload_dir means uploads are unavailable, so
	// there is no root to offer.
	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => '',
			'error'   => 'disk full',
		]
	);
	Functions\when( 'trailingslashit' )->returnArg();
	Functions\when( 'apply_filters' )->returnArg( 2 );

	expect( ( new Repository() )->get_root() )->toBeNull();

} );

// ---------------------------------------------------------------------------
// Discovery scan
// ---------------------------------------------------------------------------

test( 'discover returns exactly the directories holding a collection.json', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// Two real collections, one bare directory (no descriptor), and one stray
	// file — only the two collections must be discovered.
	seed_collection( $root, 'spring' );
	seed_collection( $root, 'winter' );
	mkdir( $root . 'not-a-collection', 0700, true );
	file_put_contents( $root . 'loose.txt', 'x' );

	$found = ( new Repository() )->discover();

	expect( array_keys( $found ) )->toBe( [ 'spring', 'winter' ] );
	expect( $found['spring'] )->toBe( $root . 'spring' );

	repo_remove_tree( $basedir );
} );

test( 'discover skips directories whose name is not a valid slug', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	// A descriptor inside an invalid-slug directory does not make it a
	// collection: the name must be a well-formed slug.
	$invalid = $root . 'Has Spaces';
	mkdir( $invalid, 0700, true );
	file_put_contents( $invalid . '/' . Repository::DESCRIPTOR_FILENAME, '{}' );
	seed_collection( $root, 'valid-one' );

	expect( array_keys( ( new Repository() )->discover() ) )->toBe( [ 'valid-one' ] );

	repo_remove_tree( $basedir );
} );

test( 'discover returns an empty map for a root with no collections', function (): void {
	$basedir = fresh_basedir();
	wire_repository_stubs( $basedir );

	expect( ( new Repository() )->discover() )->toBe( [] );

	repo_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Slug → path resolution
// ---------------------------------------------------------------------------

test( 'resolve_slug returns the path of an existing collection', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();
	seed_collection( $root, 'autumn' );

	expect( ( new Repository() )->resolve_slug( 'autumn' ) )->toBe( $root . 'autumn' );

	repo_remove_tree( $basedir );
} );

test( 'resolve_slug returns null for a directory without a descriptor', function (): void {
	$basedir = fresh_basedir();
	$root    = wire_repository_stubs( $basedir );
	( new Repository() )->get_root();
	mkdir( $root . 'bare', 0700, true );

	expect( ( new Repository() )->resolve_slug( 'bare' ) )->toBeNull();

	repo_remove_tree( $basedir );
} );

test( 'resolve_slug returns null for an unknown slug', function (): void {
	$basedir = fresh_basedir();
	wire_repository_stubs( $basedir );
	( new Repository() )->get_root();

	expect( ( new Repository() )->resolve_slug( 'nope' ) )->toBeNull();

	repo_remove_tree( $basedir );
} );

test( 'resolve_slug rejects a hostile slug before touching disk', function ( string $hostile ): void {
	$basedir = fresh_basedir();
	wire_repository_stubs( $basedir );

	expect( ( new Repository() )->resolve_slug( $hostile ) )->toBeNull();

	repo_remove_tree( $basedir );
} )->with( [
	'traversal'       => [ '../other' ],
	'separator'       => [ 'a/b' ],
	'absolute'        => [ '/etc' ],
	'uppercase'       => [ 'Spring' ],
	'leading hyphen'  => [ '-bad' ],
	'trailing hyphen' => [ 'bad-' ],
	'dot'             => [ 'a.b' ],
	'empty'           => [ '' ],
	'space'           => [ 'a b' ],
] );

// ---------------------------------------------------------------------------
// Slug validation
// ---------------------------------------------------------------------------

test( 'is_valid_slug accepts well-formed slugs', function ( string $slug ): void {
	expect( ( new Repository() )->is_valid_slug( $slug ) )->toBeTrue();
} )->with( [
	'single word' => [ 'spring' ],
	'with digits' => [ 'album2024' ],
	'hyphenated'  => [ 'spring-2024-trip' ],
	'all digits'  => [ '2024' ],
] );

test( 'is_valid_slug rejects malformed slugs', function ( string $slug ): void {
	expect( ( new Repository() )->is_valid_slug( $slug ) )->toBeFalse();
} )->with( [
	'empty'           => [ '' ],
	'uppercase'       => [ 'Spring' ],
	'underscore'      => [ 'spring_2024' ],
	'dot'             => [ 'spring.2024' ],
	'slash'           => [ 'spring/2024' ],
	'leading hyphen'  => [ '-spring' ],
	'trailing hyphen' => [ 'spring-' ],
	'double hyphen'   => [ 'spring--2024' ],
	'space'           => [ 'spring 2024' ],
	'traversal'       => [ '..' ],
] );
