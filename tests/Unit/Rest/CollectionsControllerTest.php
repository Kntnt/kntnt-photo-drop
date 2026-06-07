<?php
/**
 * Tests for the editor-only collection list endpoint.
 *
 * `Collections_Controller` backs the block editors' collection selectors. These
 * tests prove its permission gate (`edit_posts`, overridable via filter) and the
 * shape of its payload — one object per discovered collection carrying the slug,
 * display name, and the three contract fields the inspector shows read-only. The
 * `Repository` and `Descriptor` run against a real temp-dir collection; only the
 * WordPress seams are stubbed, the same harness pattern the upload tests use.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Rest\Collections_Controller;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, stubbed WP seams
// ---------------------------------------------------------------------------

/**
 * Wires the WordPress seams the controller and its collaborators reach for.
 *
 * The uploads basedir is a real temp directory so the `Repository` and
 * `Descriptor` exercise the real filesystem. The capability verdict is
 * parameterised so a test can request as a capable or an un-capable editor, and
 * `apply_filters` honours an optional capability override so the
 * `kntnt_photo_drop_list_capability` filter can be exercised.
 *
 * @param string      $basedir      Temp directory standing in for the uploads basedir.
 * @param bool        $cap_ok       What `current_user_can()` should return for the resolved cap.
 * @param string|null $cap_override Value the list-capability filter should return, or null.
 * @return void
 */
function wire_collections_stubs( string $basedir, bool $cap_ok, ?string $cap_override = null ): void {

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
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'current_user_can' )->justReturn( $cap_ok );
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $cap_override ): mixed {
			if ( $hook === 'kntnt_photo_drop_list_capability' && $cap_override !== null ) {
				return $cap_override;
			}
			return $value;
		}
	);

}

/**
 * Creates a real collection on disk under the temp uploads root.
 *
 * @param string     $basedir    The temp uploads basedir.
 * @param string     $slug       The collection slug.
 * @param Descriptor $descriptor The contract to persist.
 * @return string The absolute collection directory path.
 */
function seed_list_collection( string $basedir, string $slug, Descriptor $descriptor ): string {
	$path = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/' . $slug;
	mkdir( $path, 0700, true );
	$descriptor->write( $path );
	return $path;
}

/**
 * Allocates a fresh temp directory standing in for the uploads basedir.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_list_basedir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-list-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Recursively removes a temp directory tree.
 *
 * @param string $dir The directory to remove.
 * @return void
 */
function list_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		list_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// The permission gate — edit_posts, overridable via filter
// ---------------------------------------------------------------------------

test( 'the list is allowed for a user who can edit posts', function (): void {

	// A user holding the default edit_posts capability passes the gate.
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: true );
	$controller = new Collections_Controller( new Repository() );

	expect( $controller->check_permission() )->toBeTrue();

	list_remove_tree( $basedir );
} );

test( 'the list is refused for a user who cannot edit posts', function (): void {

	// A user lacking edit_posts is refused — the editor list is not public.
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: false );
	$controller = new Collections_Controller( new Repository() );

	expect( $controller->check_permission() )->toBeFalse();

	list_remove_tree( $basedir );
} );

test( 'the permission gate honours the list_capability filter', function (): void {

	// The filter narrows the required capability to manage_options. current_user_can
	// is stubbed false, so the gate must refuse — proving the controller checks the
	// filtered capability, not the hard default.
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: false, cap_override: 'manage_options' );
	$controller = new Collections_Controller( new Repository() );

	expect( $controller->check_permission() )->toBeFalse();

	list_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The payload shape — slug, name, and the three contract fields
// ---------------------------------------------------------------------------

test( 'the list returns one object per collection with the contract fields', function (): void {

	// Two seeded collections must come back as two objects, each carrying the
	// slug, display name, and the contract fields the inspector surfaces, sorted
	// by slug (the discovery scan's order).
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: true );
	seed_list_collection( $basedir, 'autumn', new Descriptor( 'Autumn Trip', 1600, 75, [ 320 ] ) );
	seed_list_collection( $basedir, 'spring', new Descriptor( 'Spring Walk', null, 80, [ 320, 640 ] ) );
	$controller = new Collections_Controller( new Repository() );

	$response = $controller->list_collections();
	$data     = $response->get_data();

	expect( $response->get_status() )->toBe( 200 );
	expect( $data )->toHaveCount( 2 );
	expect( $data[0] )->toBe(
		[
			'slug'            => 'autumn',
			'name'            => 'Autumn Trip',
			'maxWidth'        => 1600,
			'quality'         => 75,
			'thumbnailWidths' => [ 320 ],
		]
	);
	expect( $data[1]['slug'] )->toBe( 'spring' );
	expect( $data[1]['maxWidth'] )->toBeNull();
	expect( $data[1]['thumbnailWidths'] )->toBe( [ 320, 640 ] );

	list_remove_tree( $basedir );
} );

test( 'the list is empty when no collections are discovered', function (): void {

	// An empty uploads root yields an empty list, not an error.
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: true );
	$controller = new Collections_Controller( new Repository() );

	$response = $controller->list_collections();

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [] );

	list_remove_tree( $basedir );
} );

test( 'a collection with an unreadable descriptor is skipped, not fatal', function (): void {

	// One good collection and one whose descriptor is corrupt JSON: the list comes
	// back with only the good one rather than failing wholesale.
	$basedir = fresh_list_basedir();
	wire_collections_stubs( $basedir, cap_ok: true );
	seed_list_collection( $basedir, 'good', new Descriptor( 'Good', 1920, 80, [ 320 ] ) );
	$broken_path = $basedir . '/kntnt-photo-drop/broken';
	mkdir( $broken_path, 0700, true );
	file_put_contents( $broken_path . '/' . Descriptor::FILENAME, '{ not valid json' );
	$controller = new Collections_Controller( new Repository() );

	$data = $controller->list_collections()->get_data();

	expect( $data )->toHaveCount( 1 );
	expect( $data[0]['slug'] )->toBe( 'good' );

	list_remove_tree( $basedir );
} );
