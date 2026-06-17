<?php
/**
 * Integration tests for the simplified upload width/quality fields (#70).
 *
 * Drives the real `admin-post.php` create handler with a blank upload pair, then
 * imports a real JPEG, proving the blank-is-special rules end to end: a blank
 * upload width persists as "no max" (`uploadWidth = null`) and ingestion keeps
 * the source's own dimensions, while a blank upload quality persists as the
 * maximum (`uploadQuality = 100`). This is the create + ingestion coverage the
 * issue asks for, at the integration layer where a real admin POST and a real
 * encode meet — no as-is mode, full fidelity through the always-WebP contract
 * (ADR-0002).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection_via_admin;
use function Tests\Integration\delete_collection;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\remove_tree;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// One host-side fixture directory under the uploads bind mount serves every
// import in the file.
$fixtures = null;

beforeAll( function () use ( &$fixtures ): void {
	$fixtures = make_fixture_dir();
} );

afterAll( function () use ( &$fixtures ): void {
	if ( $fixtures !== null ) {
		remove_tree( $fixtures );
	}
} );

test( 'a blank upload pair keeps source dimensions at maximum quality', function () use ( &$fixtures ): void {

	// Create through the real admin form with both upload fields blank.
	$slug = unique_slug();
	try {

		create_collection_via_admin( $slug, 'none', '' );

		// The descriptor records the blank upload width as null ("no max") and the
		// blank upload quality as the maximum 100.
		$descriptor = read_descriptor( $slug );
		expect( $descriptor )->not->toBeNull();
		expect( $descriptor['uploadWidth'] )->toBeNull();
		expect( $descriptor['uploadQuality'] )->toBe( 100 );

		// Importing a real JPEG keeps its own dimensions — a null ceiling never
		// downscales, so the stored main is the source width, not a capped one.
		write_jpeg( "{$fixtures}/original.jpg", 1500, 1000 );
		$result = import_images( $slug, [ to_container_path( "{$fixtures}/original.jpg" ) ] );
		expect( $result['exit_code'] )->toBe( 0 );

		$main = collection_path( $slug ) . '/original.jpg.webp';
		expect( is_file( $main ) )->toBeTrue();
		$info = getimagesize( $main );
		expect( $info )->not->toBeFalse();
		expect( $info['mime'] )->toBe( 'image/webp' );
		expect( $info[0] )->toBe( 1500 );

	} finally {
		delete_collection( $slug );
	}

} );
