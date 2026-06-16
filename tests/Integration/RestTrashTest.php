<?php
/**
 * Integration tests for the gallery's trash REST write-path (ADR-0015).
 *
 * The trash overlay is the gallery's second REST write-path, and the only
 * destructive one: a confirmed click permanently deletes a collection's **main
 * image and all its derived artifacts** (the full rendition, the thumbnail, and
 * the index entry), reusing the `image delete` deletion routine — there is no
 * recycle bin (ADR-0015). These tests drive real JSON `DELETE`s from the host
 * against `kntnt-photo-drop/v1/collections/<slug>/images` on the live wp-env
 * instance and assert the real effect on disk: the main, the full, and the
 * thumbnail are gone and the index entry no longer names the deleted image. The
 * two security gates are exercised the same way the add-to-media endpoint's are
 * (a `wp_rest` nonce minted via `admin-ajax.php?action=rest-nonce` plus the
 * `delete` capability, default `delete_others_posts`), a `path` escaping the
 * collection root is rejected with nothing deleted, and a confined-but-non-main
 * path (the descriptor, a thumbnail) is rejected with nothing deleted. This is
 * the load-bearing layer for #53: the artifacts are really removed and
 * `Path_Guard` really confines, so the deletion is never mocked into a tautology.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_gallery_page;
use function Tests\Integration\create_subscriber;
use function Tests\Integration\delete_collection;
use function Tests\Integration\delete_page;
use function Tests\Integration\delete_user;
use function Tests\Integration\http_get;
use function Tests\Integration\import_images;
use function Tests\Integration\login_session;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_index;
use function Tests\Integration\remove_tree;
use function Tests\Integration\rest_delete_image;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This suite runs on the host with no WordPress loaded (esc_html() does not exist); the seeding-failure message surfaces only in the Pest console, never in HTML.

// The hidden artifacts directory and the two derived widths a seeded import
// produces: the full rendition (the upload-width 2400 main is wider than the
// default 1920 full ceiling) and the thumbnail (default 640). Both are
// `.kntnt-thumbnails/<width>/<stored-name>.webp` alongside the index.
const THUMBS_DIR  = '.kntnt-thumbnails';
const FULL_WIDTH  = 1920;
const THUMB_WIDTH = 640;
const STORED_NAME = 'photo.jpg.webp';

/**
 * Seeds one collection holding one real main image, returning the slug.
 *
 * The upload width is 2400 so the imported 2400×1350 source yields all three
 * renditions on disk — a 2400 main, a 1920 full, and a 640 thumbnail — plus the
 * per-folder index entry. That is the full set the trash action must remove.
 *
 * @param string $fixtures The fixture directory to write the source into.
 * @return string The seeded collection slug.
 * @throws \RuntimeException When the source cannot be imported.
 */
function seed_trash_collection( string $fixtures ): string {

	// Establish a collection wide enough that the main exceeds the full ceiling,
	// then import one large source so every rendition tier is materialised.
	$slug = unique_slug();
	create_collection( $slug, '2400', 80 );
	write_jpeg( "{$fixtures}/photo.jpg", 2400, 1350 );
	$result = import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );
	if ( $result['exit_code'] !== 0 ) {
		throw new \RuntimeException( "Cannot seed the trash main image: {$result['output']}" );
	}

	return $slug;

}

/**
 * Absolute host path of a collection-relative path under the collection root.
 *
 * @param string $slug     The collection slug.
 * @param string $relative The path relative to the collection root.
 * @return string The absolute host path.
 */
function trash_path( string $slug, string $relative ): string {
	return collection_path( $slug ) . '/' . $relative;
}

test(
	'a confirmed delete removes the main, the full, the thumbnail, and the index entry',
	function (): void {

		// Seed a fresh collection holding one image with all three renditions, then
		// render its gallery once so the per-folder index is built and lists the
		// image — the index is written on the first view, never by import (ADR-0003).
		$fixtures = make_fixture_dir();
		$slug     = seed_trash_collection( $fixtures );
		$page     = create_gallery_page( $slug );
		try {
			$main  = trash_path( $slug, STORED_NAME );
			$full  = trash_path( $slug, THUMBS_DIR . '/' . FULL_WIDTH . '/' . STORED_NAME );
			$thumb = trash_path( $slug, THUMBS_DIR . '/' . THUMB_WIDTH . '/' . STORED_NAME );
			expect( is_file( $main ) )->toBeTrue();
			expect( is_file( $full ) )->toBeTrue();
			expect( is_file( $thumb ) )->toBeTrue();
			$first = http_get( $page['url'] );
			expect( $first['status'] )->toBe( 200 );
			$index = read_index( $slug );
			expect( $index )->not->toBeNull();
			expect( array_column( $index['images'] ?? [], 'file' ) )->toContain( STORED_NAME );

			// Wait past the index's stamped second so the rebuilt index after the delete
			// is actually persisted (the same-second persist guard of ADR-0003), then
			// DELETE the main by its collection-relative path with both gates satisfied:
			// the admin session cookie and a fresh wp_rest nonce. The endpoint reports a
			// 200 success.
			usleep( 1100000 );
			$session  = admin_session();
			$response = rest_delete_image( $slug, STORED_NAME, $session['jar'], $session['nonce'] );
			expect( $response['status'] )->toBe( 200 );

			// The main and both derived artifacts are gone from disk — the proof the
			// trash action removes the whole rendition family, not just the main.
			expect( is_file( $main ) )->toBeFalse();
			expect( is_file( $full ) )->toBeFalse();
			expect( is_file( $thumb ) )->toBeFalse();

			// Wait past the second the delete bumped the folder mtime into, so the next
			// render's rebuilt index is actually persisted rather than held in memory
			// (the same-second persist guard of ADR-0003), then render again. That render
			// rebuilds the index off the now-empty folder, so the rebuilt index no longer
			// lists the deleted image — the index self-heals on the next server render
			// (ADR-0015). The rewritten index reaches the host through the bind mount with
			// a short writeback delay, so poll briefly rather than read once.
			usleep( 1100000 );
			$second = http_get( $page['url'] );
			expect( $second['status'] )->toBe( 200 );
			$deadline = microtime( true ) + 5.0;
			$after    = read_index( $slug );
			while ( microtime( true ) < $deadline ) {
				$files = $after === null ? [] : array_column( $after['images'] ?? [], 'file' );
				if ( ! in_array( STORED_NAME, $files, true ) ) {
					break;
				}
				usleep( 200000 );
				$after = read_index( $slug );
			}
			expect( array_column( $after['images'] ?? [], 'file' ) )->not->toContain( STORED_NAME );
		} finally {
			delete_page( $page['id'] );
			delete_collection( $slug );
			remove_tree( $fixtures );
		}

	} );

test( 'a delete without a nonce is 401 and removes nothing', function (): void {

	// The session cookie alone must not pass: the nonce is the forgery gate, so its
	// absence is a 401 and the main image stays on disk.
	$fixtures = make_fixture_dir();
	$slug     = seed_trash_collection( $fixtures );
	try {
		$main     = trash_path( $slug, STORED_NAME );
		$session  = admin_session();
		$response = rest_delete_image( $slug, STORED_NAME, $session['jar'], null );
		expect( $response['status'] )->toBe( 401 );
		expect( is_file( $main ) )->toBeTrue();
	} finally {
		delete_collection( $slug );
		remove_tree( $fixtures );
	}

} );

test( 'a subscriber with a valid nonce is rejected with 403 and removes nothing', function (): void {

	// A self-registered subscriber can mint a valid nonce but lacks the delete
	// capability (default delete_others_posts), so the gate must answer 403 and
	// delete nothing.
	$fixtures = make_fixture_dir();
	$slug     = seed_trash_collection( $fixtures );
	$username = str_replace( '-', '', unique_slug() );
	create_subscriber( $username, 'it-secret-password' );
	try {
		$main     = trash_path( $slug, STORED_NAME );
		$session  = login_session( $username, 'it-secret-password' );
		$response = rest_delete_image( $slug, STORED_NAME, $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 403 );
		expect( is_file( $main ) )->toBeTrue();
	} finally {
		delete_user( $username );
		delete_collection( $slug );
		remove_tree( $fixtures );
	}

} );

test( 'a path escaping the collection root is rejected and deletes nothing', function (): void {

	// A traversal path that would resolve above the collection root must be rejected
	// by Path_Guard with nothing deleted — the realpath confinement applies to the
	// trash write-path exactly as it does to the add-to-media and upload endpoints.
	// wp-config.php is a real file just above the install; it must survive.
	$fixtures = make_fixture_dir();
	$slug     = seed_trash_collection( $fixtures );
	try {
		$main     = trash_path( $slug, STORED_NAME );
		$session  = admin_session();
		$response = rest_delete_image( $slug, '../../wp-config.php', $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 422 );
		expect( is_file( $main ) )->toBeTrue();
	} finally {
		delete_collection( $slug );
		remove_tree( $fixtures );
	}

} );

test( 'a confined path naming a non-main file is rejected and deletes nothing', function (): void {

	// `collection.json` confines cleanly inside the root and is a real file, but it
	// is the descriptor, not a main image — so the trash gate must reject it
	// (ADR-0015: the action deletes the *main image*) with nothing deleted. This is
	// the integration proof that only a main image, never a derived artifact, the
	// descriptor, or a foreign in-collection file, can be deleted through the
	// endpoint — the bug class #52 fixed for add-to-media, guarded here for trash.
	$fixtures = make_fixture_dir();
	$slug     = seed_trash_collection( $fixtures );
	try {
		$descriptor = trash_path( $slug, 'collection.json' );
		$session    = admin_session();
		$response   = rest_delete_image( $slug, 'collection.json', $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 404 );
		expect( is_file( $descriptor ) )->toBeTrue();
	} finally {
		delete_collection( $slug );
		remove_tree( $fixtures );
	}

} );

test( 'a confined path naming a thumbnail is rejected and deletes nothing', function (): void {

	// A thumbnail under the hidden `.kntnt-thumbnails/<width>/` tree is a derived
	// artifact, not the main image. Its path confines fine and ends in `.webp`, so
	// only the thumbnails-segment guard stops it: the endpoint must reject it as 404
	// with the thumbnail (and the main) untouched, so a confined-but-non-main path
	// can never be deleted directly.
	$fixtures = make_fixture_dir();
	$slug     = seed_trash_collection( $fixtures );
	try {
		$thumb_rel = THUMBS_DIR . '/' . THUMB_WIDTH . '/' . STORED_NAME;
		$thumb     = trash_path( $slug, $thumb_rel );
		$main      = trash_path( $slug, STORED_NAME );
		$session   = admin_session();
		$response  = rest_delete_image( $slug, $thumb_rel, $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 404 );
		expect( is_file( $thumb ) )->toBeTrue();
		expect( is_file( $main ) )->toBeTrue();
	} finally {
		delete_collection( $slug );
		remove_tree( $fixtures );
	}

} );

test( 'an unknown collection slug is a 404 with nothing deleted', function (): void {

	// A slug that resolves to no collection is a 404; there is nothing to delete and
	// no other collection is touched.
	$session  = admin_session();
	$response = rest_delete_image( 'it-does-not-exist', STORED_NAME, $session['jar'], $session['nonce'] );
	expect( $response['status'] )->toBe( 404 );

} );
