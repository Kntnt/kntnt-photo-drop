<?php
/**
 * Integration tests for the regenerate-then-flip re-derive flow (ADR-0013).
 *
 * Drives the real `kntnt-photo-drop/v1/collections/<slug>/regenerate` endpoint
 * from the host against the live wp-env instance, with a real on-disk collection
 * holding a real imported image. This is the load-bearing layer for #49 because
 * the behaviour is genuinely filesystem-plus-REST and cannot be faked into a unit
 * assertion: the new-width renditions are written while the gallery keeps serving
 * the old ones; the descriptor's active widths flip **only on a successful
 * finalise**; the old-width buckets are pruned afterwards; and an interrupted run
 * (no finalise) leaves the old descriptor active and is safely re-runnable.
 * Authentication mirrors a browser — a `wp-login.php` cookie session plus a
 * `wp_rest` nonce — since the endpoint is gated by the manage capability.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\delete_collection;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\remove_tree;
use function Tests\Integration\rest_regenerate;
use function Tests\Integration\run_cli;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\width_buckets;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This suite runs on the host with no WordPress loaded (esc_html() does not exist); a seed-failure message surfaces only in the Pest console, never in HTML.

// Seed one collection whose upload ceiling (1500) leaves room for a separate full
// (1200) and thumbnail (600) bucket, then import one wide image so the derived
// buckets actually exist on disk. The full/thumbnail widths are fixed at create
// time (the CLI's create flags), since this issue drives the *re-derive* through
// the admin REST endpoint, not the CLI. The fixture must be container-readable for
// the import, so it lives under the uploads bind mount.
$slug        = unique_slug();
$fixture_dir = null;

beforeAll( function () use ( $slug, &$fixture_dir ): void {

	// Establish the collection with the 1200/600 derived pair the flip will later
	// retire, then import a 1600px source so the main lands at the 1500 ceiling and
	// both derived buckets are written.
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
	$fixture_dir = make_fixture_dir();
	write_jpeg( $fixture_dir . '/wide.jpg', 1600, 900 );
	import_images( $slug, [ to_container_path( $fixture_dir . '/wide.jpg' ) ] );
} );

afterAll( function () use ( $slug, &$fixture_dir ): void {
	delete_collection( $slug );
	if ( $fixture_dir !== null ) {
		remove_tree( $fixture_dir );
	}
} );

test( 'the seed collection starts with the old-width buckets on disk', function () use ( $slug ): void {

	// Precondition the flip tests rely on: the import produced a 1200 full bucket and
	// a 600 thumbnail bucket, and the descriptor records those widths.
	expect( width_buckets( $slug ) )->toEqualCanonicalizing( [ 600, 1200 ] );
	$descriptor = read_descriptor( $slug );
	expect( $descriptor['fullWidth'] )->toBe( 1200 );
	expect( $descriptor['thumbnailWidth'] )->toBe( 600 );
} );

test( 'an interrupted regenerate leaves the old descriptor and buckets serving', function () use ( $slug ): void {

	// Regenerate every main at the new widths (full 800 / thumbnail 300) but stop
	// before finalising — exactly an interrupted run. The new buckets are written
	// alongside the old ones, but because the descriptor was never flipped the gallery
	// keeps reading 1200/600.
	$session = admin_session();
	$target  = [
		'fullWidth'        => 800,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 300,
		'thumbnailQuality' => 75,
	];
	$batch   = rest_regenerate( $slug, $target + [ 'index' => 0 ], $session['jar'], $session['nonce'] );
	expect( $batch['status'] )->toBe( 200 );

	// The descriptor still records the old widths — the interruption is safe.
	$descriptor = read_descriptor( $slug );
	expect( $descriptor['fullWidth'] )->toBe( 1200 );
	expect( $descriptor['thumbnailWidth'] )->toBe( 600 );

	// The old buckets are still on disk, so the gallery's srcset keeps resolving while
	// the new buckets sit ready but unreferenced.
	expect( width_buckets( $slug ) )->toContain( 1200 );
	expect( width_buckets( $slug ) )->toContain( 600 );
} );

test( 'a full regenerate then finalise flips the descriptor and prunes old buckets', function () use ( $slug ): void {

	// Run the whole flow to completion: regenerate the one main at the new widths, then
	// finalise. Only on the successful finalise does the descriptor flip.
	$session = admin_session();
	$target  = [
		'fullWidth'        => 800,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 300,
		'thumbnailQuality' => 75,
	];

	// Walk the batch cursor until the endpoint reports the collection is done, mirroring
	// the browser's one-per-request loop.
	$index = 0;
	do {
		$batch = rest_regenerate( $slug, $target + [ 'index' => $index ], $session['jar'], $session['nonce'] );
		expect( $batch['status'] )->toBe( 200 );
		++$index;
	} while ( $batch['body']['done'] === false && $index < 50 );

	// Finalise: this is the moment the descriptor's active widths flip.
	$final = rest_regenerate( $slug, $target + [ 'finalize' => true ], $session['jar'], $session['nonce'] );
	expect( $final['status'] )->toBe( 200 );

	// The descriptor now records the new widths; the immutable upload contract is
	// untouched (still 1500/80).
	$descriptor = read_descriptor( $slug );
	expect( $descriptor['fullWidth'] )->toBe( 800 );
	expect( $descriptor['thumbnailWidth'] )->toBe( 300 );
	expect( $descriptor['uploadWidth'] )->toBe( 1500 );
	expect( $descriptor['uploadQuality'] )->toBe( 80 );

	// On disk the new buckets exist and the old ones are pruned, so nothing unreachable
	// is left to grow the collection forever.
	$buckets = width_buckets( $slug );
	expect( $buckets )->toContain( 800 );
	expect( $buckets )->toContain( 300 );
	expect( $buckets )->not->toContain( 1200 );
	expect( $buckets )->not->toContain( 600 );

	// The flipped renditions are real WebP files at the new widths.
	$full = collection_path( $slug ) . '/.kntnt-thumbnails/800/wide.jpg.webp';
	expect( is_file( $full ) )->toBeTrue();
	$info = getimagesize( $full );
	expect( $info['mime'] )->toBe( 'image/webp' );
	expect( $info[0] )->toBe( 800 );
} );

test( 'a regenerate without a nonce is rejected and the descriptor is untouched', function () use ( $slug ): void {

	// The session cookie alone must not pass the forgery gate: omit the nonce and the
	// endpoint answers 401, flipping nothing.
	$session    = admin_session();
	$descriptor = read_descriptor( $slug );
	$response   = rest_regenerate(
		$slug,
		[
			'fullWidth'        => 999,
			'fullQuality'      => 85,
			'thumbnailWidth'   => 222,
			'thumbnailQuality' => 75,
			'finalize'         => true,
		],
		$session['jar'],
		null,
	);

	expect( $response['status'] )->toBe( 401 );
	expect( read_descriptor( $slug )['fullWidth'] )->toBe( $descriptor['fullWidth'] );
} );
