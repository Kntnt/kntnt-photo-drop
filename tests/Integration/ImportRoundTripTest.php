<?php
/**
 * Integration tests for the CLI import round-trip: a real JPEG goes in, a
 * conforming `<original>.jpg.webp` main plus its thumbnail come out.
 *
 * One uniquely-slugged collection (ceiling 1200px, quality 70) is seeded for
 * the whole file; each test imports its own fixture files so no test depends
 * on another's writes. Fixtures are generated with GD on the host into a
 * directory under the uploads bind mount, which the `cli` container sees at
 * the mapped `/var/www/html/wp-content/uploads/…` path.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\delete_collection;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\remove_tree;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_corrupt_image;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// Seed one collection and one fixture directory for the whole file; the slug
// and paths are fixed at load time so every closure can capture them.
$slug     = unique_slug();
$fixtures = null;

beforeAll( function () use ( $slug, &$fixtures ): void {
	create_collection( $slug, '1200', 70 );
	$fixtures = make_fixture_dir();
} );

afterAll( function () use ( $slug, &$fixtures ): void {
	delete_collection( $slug );
	if ( $fixtures !== null ) {
		remove_tree( $fixtures );
	}
} );

test( 'import stores a JPEG as a conforming <name>.jpg.webp main', function () use ( $slug, &$fixtures ): void {

	// Import a 1600px JPEG into the 1200px-ceiling collection.
	write_jpeg( "{$fixtures}/photo.jpg", 1600, 900 );
	$result = import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );
	expect( $result['exit_code'] )->toBe( 0 );
	expect( $result['output'] )->toContain( 'reencoded' );

	// The stored main carries the original name with .webp appended, really
	// is WebP, and was downscaled to the contract ceiling.
	$main = collection_path( $slug ) . '/photo.jpg.webp';
	expect( is_file( $main ) )->toBeTrue();
	$info = getimagesize( $main );
	expect( $info )->not->toBeFalse();
	expect( $info['mime'] )->toBe( 'image/webp' );
	expect( $info[0] )->toBeLessThanOrEqual( 1200 );

} );

test( 'import generates the thumbnail under .kntnt-thumbnails/<width>/', function () use ( $slug, &$fixtures ): void {

	// Import a fresh fixture, then demand one thumbnail per width recorded in
	// the descriptor (the widths are filter-derived, so read, not assumed).
	write_jpeg( "{$fixtures}/thumbed.jpg", 1600, 900 );
	$result = import_images( $slug, [ to_container_path( "{$fixtures}/thumbed.jpg" ) ] );
	expect( $result['exit_code'] )->toBe( 0 );
	$widths = read_descriptor( $slug )['thumbnailWidths'];
	expect( $widths )->not->toBeEmpty();
	foreach ( $widths as $width ) {
		$thumbnail = collection_path( $slug ) . "/.kntnt-thumbnails/{$width}/thumbed.jpg.webp";
		expect( is_file( $thumbnail ) )->toBeTrue();
		expect( getimagesize( $thumbnail )[0] )->toBe( $width );
	}

} );

test( 'a second import of the same source is skipped', function () use ( $slug, &$fixtures ): void {

	// Import the same file twice: the first pass writes, the second is the
	// idempotent skip, and both runs exit zero.
	write_jpeg( "{$fixtures}/twice.jpg", 1600, 900 );
	$source = to_container_path( "{$fixtures}/twice.jpg" );
	$first  = import_images( $slug, [ $source ] );
	expect( $first['exit_code'] )->toBe( 0 );
	$second = import_images( $slug, [ $source ] );
	expect( $second['exit_code'] )->toBe( 0 );
	expect( $second['output'] )->toContain( 'skipped' );

} );

test( 'a corrupt source is rejected without aborting the batch', function () use ( $slug, &$fixtures ): void {

	// One undecodable file and one good file in a single call: the run exits
	// zero, the corrupt source is reported rejected, the good one is stored.
	write_corrupt_image( "{$fixtures}/corrupt.jpg" );
	write_jpeg( "{$fixtures}/good.jpg", 1600, 900 );
	$result = import_images(
		$slug,
		[ to_container_path( "{$fixtures}/corrupt.jpg" ), to_container_path( "{$fixtures}/good.jpg" ) ],
	);
	expect( $result['exit_code'] )->toBe( 0 );
	expect( $result['output'] )->toContain( 'rejected' );

	// The good main landed; nothing was written for the corrupt source.
	expect( is_file( collection_path( $slug ) . '/good.jpg.webp' ) )->toBeTrue();
	expect( is_file( collection_path( $slug ) . '/corrupt.jpg.webp' ) )->toBeFalse();

} );
