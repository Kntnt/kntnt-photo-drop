<?php
/**
 * Integration test for the per-folder index self-heal on a real directory
 * mtime bump (ADR-0003).
 *
 * A gallery view writes `index.json` inside `.kntnt-thumbnails/`; a file that
 * arrives out-of-band (copied straight onto the filesystem, bypassing every
 * plugin ingestion path) bumps the content folder's mtime, so the next view
 * must distrust the cache, rescan the folder, and rewrite the index — making
 * the new image appear in the rendered markup with no plugin involvement in
 * the copy. The copy itself goes through the container (`cp`, not the
 * plugin), because a host-side write would leave the bind mount's cached
 * directory mtime stale inside the VM and never trip the cache validation.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\copy_in_container;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_gallery_page;
use function Tests\Integration\delete_collection;
use function Tests\Integration\delete_page;
use function Tests\Integration\http_get;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_index;
use function Tests\Integration\remove_tree;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;
use function Tests\Integration\write_webp;

require_once __DIR__ . '/helpers.php';

// Seed one collection with one imported image and one published gallery page
// for the whole file.
$slug     = unique_slug();
$fixtures = null;
$page_id  = null;
$page_url = null;

beforeAll( function () use ( $slug, &$fixtures, &$page_id, &$page_url ): void {
	create_collection( $slug, '1200', 70 );
	$fixtures = make_fixture_dir();
	write_jpeg( "{$fixtures}/photo.jpg", 1600, 900 );
	import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );
	$gallery_page = create_gallery_page( $slug );
	$page_id      = $gallery_page['id'];
	$page_url     = $gallery_page['url'];
} );

afterAll( function () use ( $slug, &$fixtures, &$page_id ): void {
	if ( $page_id !== null ) {
		delete_page( $page_id );
	}
	delete_collection( $slug );
	if ( $fixtures !== null ) {
		remove_tree( $fixtures );
	}
} );

test( 'an out-of-band image self-heals index and gallery', function () use ( $slug, &$fixtures, &$page_url ): void {

	// A first render builds the index: it lists the imported image and stamps
	// the folder mtime it reflects.
	$first = http_get( $page_url );
	expect( $first['status'] )->toBe( 200 );
	expect( $first['body'] )->toContain( 'photo.jpg.webp' );
	$index_before = read_index( $slug );
	expect( $index_before )->not->toBeNull();
	expect( array_column( $index_before['images'], 'file' ) )->toContain( 'photo.jpg.webp' );

	// Copy a conforming WebP straight into the content folder, bypassing
	// every plugin ingestion path; only the directory mtime betrays it. The
	// fixture is staged on the host and copied via the container so the mtime
	// bump is real on the VM side of the bind mount.
	write_webp( "{$fixtures}/added-out-of-band.webp", 600, 400 );
	copy_in_container( "{$fixtures}/added-out-of-band.webp", collection_path( $slug ) . '/added-out-of-band.webp' );

	// Wait past the second boundary before the next view: the store persists
	// a rebuilt index only once the folder's stamped mtime second has fully
	// passed (the same-second persist guard of ADR-0003), so a render inside
	// the copy's own second would rebuild in memory but write nothing back.
	usleep( 1100000 );

	// The next view distrusts the stale index, rescans, and rewrites: the new
	// image appears in the markup.
	$second = http_get( $page_url );
	expect( $second['status'] )->toBe( 200 );
	expect( $second['body'] )->toContain( 'added-out-of-band.webp' );

	// The rewritten index reaches the host through the bind mount with a
	// short writeback delay, so poll briefly rather than read once.
	$deadline    = microtime( true ) + 5.0;
	$index_after = read_index( $slug );
	while ( microtime( true ) < $deadline ) {
		$files = $index_after === null ? [] : array_column( $index_after['images'], 'file' );
		if ( in_array( 'added-out-of-band.webp', $files, true ) ) {
			break;
		}
		usleep( 200000 );
		$index_after = read_index( $slug );
	}

	// The persisted index now lists the out-of-band image and stamps a later
	// directory mtime than the one it replaced — the rewrite really happened.
	expect( array_column( $index_after['images'], 'file' ) )->toContain( 'added-out-of-band.webp' );
	expect( $index_after['dirMtime'] )->toBeGreaterThan( $index_before['dirMtime'] );

} );
