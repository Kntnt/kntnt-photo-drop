<?php
/**
 * Integration tests for `collection update`'s re-derivable flags (ADR-0013).
 *
 * Drives the real `wp kntnt-photo-drop collection update` command against the live
 * wp-env instance with a real on-disk collection. The CLI is a trusted, synchronous
 * context, so the whole regenerate-then-flip batch runs in-process: when a full or
 * thumbnail width/quality flag changes a value, every main is re-derived at the new
 * widths and the descriptor flips — but only after every image's new-width
 * renditions exist on disk (verify-then-flip). The two load-bearing cases live here
 * because the behaviour is genuinely filesystem-plus-CLI and cannot be faked into a
 * unit assertion: a real width change really regenerates and flips, and a main that
 * cannot be re-derived aborts the flip (mirroring the #49 regenerate-then-flip
 * safety case). The pure flag parsing/validation and the descriptor mutation are
 * unit-tested in CollectionCommandTest.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\delete_collection;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\remove_tree;
use function Tests\Integration\run_cli;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\width_buckets;
use function Tests\Integration\write_corrupt_image;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This suite runs on the host with no WordPress loaded (esc_html() does not exist); a seed-failure message surfaces only in the Pest console, never in HTML.

/**
 * Seeds a collection with the 1500/1200/600 contract and imports the given mains.
 *
 * @param string            $slug    The collection slug to create.
 * @param string            $fixture A host fixture directory under the uploads bind mount.
 * @param array<int,string> $names   The JPEG basenames to write and import as wide mains.
 * @throws \RuntimeException When the collection cannot be created.
 */
function seed_update_collection( string $slug, string $fixture, array $names ): void {

	// Establish the collection with a 1200 full and 600 thumbnail over a 1500 ceiling,
	// so an imported wide image lands at the ceiling and both derived buckets exist.
	$created = run_cli( [
		'kntnt-photo-drop',
		'collection',
		'create',
		$slug,
		'--upload-width=1500',
		'--upload-quality=80',
		'--full-width=1200',
		'--thumbnail-width=600',
	] );
	if ( $created['exit_code'] !== 0 ) {
		throw new \RuntimeException( "Cannot seed collection '{$slug}': {$created['output']}" );
	}

	// Write each wide source and import it through the trusted CLI ingestion path.
	$sources = [];
	foreach ( $names as $name ) {
		write_jpeg( $fixture . '/' . $name, 1600, 900 );
		$sources[] = to_container_path( $fixture . '/' . $name );
	}
	import_images( $slug, $sources );

}

test( 'collection update --thumbnail-width regenerates the renditions and flips the descriptor', function (): void {
	$slug    = unique_slug();
	$fixture = make_fixture_dir();

	try {

		// Seed one wide main so both the 1200 and 600 buckets exist before the change.
		seed_update_collection( $slug, $fixture, [ 'wide.jpg' ] );
		expect( width_buckets( $slug ) )->toEqualCanonicalizing( [ 600, 1200 ] );

		// Change only the thumbnail width: the CLI must regenerate in-process and flip.
		$updated = run_cli( [ 'kntnt-photo-drop', 'collection', 'update', $slug, '--thumbnail-width=300' ] );
		expect( $updated['exit_code'] )->toBe( 0 );

		// The descriptor flipped to the new thumbnail width; the full pair and the
		// immutable upload contract are untouched.
		$descriptor = read_descriptor( $slug );
		expect( $descriptor['thumbnailWidth'] )->toBe( 300 );
		expect( $descriptor['fullWidth'] )->toBe( 1200 );
		expect( $descriptor['uploadWidth'] )->toBe( 1500 );
		expect( $descriptor['uploadQuality'] )->toBe( 80 );

		// On disk the new 300 bucket exists, the old 600 bucket is pruned, and the
		// still-configured 1200 full bucket survives.
		$buckets = width_buckets( $slug );
		expect( $buckets )->toContain( 300 );
		expect( $buckets )->toContain( 1200 );
		expect( $buckets )->not->toContain( 600 );

		// The flipped thumbnail is a real WebP at the new width.
		$thumb = collection_path( $slug ) . '/.kntnt-thumbnails/300/wide.jpg.webp';
		expect( is_file( $thumb ) )->toBeTrue();
		$info = getimagesize( $thumb );
		expect( $info['mime'] )->toBe( 'image/webp' );
		expect( $info[0] )->toBe( 300 );

	} finally {
		delete_collection( $slug );
		remove_tree( $fixture );
	}
} );

test( 'collection update does not flip when a main cannot be re-derived', function (): void {
	$slug    = unique_slug();
	$fixture = make_fixture_dir();

	try {

		// Seed two wide mains so both derived buckets exist, then corrupt one stored main
		// out-of-band so its decode fails — exactly a per-image re-derive failure that
		// must abort the flip rather than be swallowed (ADR-0013, the silent-failure case).
		seed_update_collection( $slug, $fixture, [ 'keep.jpg', 'broken.jpg' ] );
		expect( width_buckets( $slug ) )->toEqualCanonicalizing( [ 600, 1200 ] );
		write_corrupt_image( collection_path( $slug ) . '/broken.jpg.webp' );

		// Change the thumbnail width: the in-process regenerate hits the corrupt main, so
		// the command must exit non-zero and flip nothing.
		$updated = run_cli( [ 'kntnt-photo-drop', 'collection', 'update', $slug, '--thumbnail-width=300' ] );
		expect( $updated['exit_code'] )->not->toBe( 0 );

		// The descriptor still records the OLD widths — no flip happened.
		$descriptor = read_descriptor( $slug );
		expect( $descriptor['thumbnailWidth'] )->toBe( 600 );
		expect( $descriptor['fullWidth'] )->toBe( 1200 );

		// And the OLD buckets are still on disk — nothing was pruned, so the gallery keeps
		// serving the renditions that exist rather than 404ing on deleted files.
		$buckets = width_buckets( $slug );
		expect( $buckets )->toContain( 1200 );
		expect( $buckets )->toContain( 600 );

	} finally {
		delete_collection( $slug );
		remove_tree( $fixture );
	}
} );
