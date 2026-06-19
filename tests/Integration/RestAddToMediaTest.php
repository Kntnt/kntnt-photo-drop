<?php
/**
 * Integration tests for the gallery's add-to-media REST write-path (ADR-0015).
 *
 * The gallery is otherwise pure SSR; the add-to-media overlay is its first REST
 * write-path. These tests drive real JSON POSTs from the host against
 * `kntnt-photo-drop/v1/collections/<slug>/media` on the live wp-env instance and
 * assert the real effect: a confirmed copy sideloads the collection's **main
 * image** into the Media Library as an ordinary, independent attachment that
 * carries WordPress-generated sub-sizes — a copy, never a link (ADR-0015). The
 * two security gates are exercised the same way the upload endpoint's are (a
 * `wp_rest` nonce minted via `admin-ajax.php?action=rest-nonce` plus the
 * `add_to_media` capability), and a `path` escaping the collection root is
 * rejected with no attachment created. A re-add of an image already in the Library
 * is de-duplicated: without overwrite it is a 409 that copies nothing, and with
 * overwrite it replaces the existing attachment's file in place — same id, no new
 * attachment (ADR-0015 amendment). This is the load-bearing layer for #52: the
 * attachment is really created, the source-identity stamp and `get_posts` lookup
 * really detect a duplicate, the in-place replacement really runs, and `Path_Guard`
 * really confines, so the sideload is never mocked into a tautology.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.12.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\attachment_count;
use function Tests\Integration\attachment_file;
use function Tests\Integration\attachment_subsize_count;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_subscriber;
use function Tests\Integration\delete_collection;
use function Tests\Integration\delete_user;
use function Tests\Integration\import_images;
use function Tests\Integration\login_session;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\remove_tree;
use function Tests\Integration\rest_add_to_media;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This suite runs on the host with no WordPress loaded (esc_html() does not exist); the seeding-failure message surfaces only in the Pest console, never in HTML.

// Seed one collection holding one real main image for the whole file. The image
// is imported through the CLI so it is conforming on disk, exactly as a gallery
// would serve it; its collection-relative path is the add-to-media target.
$slug      = unique_slug();
$fixtures  = null;
$main_path = 'photo.jpg.webp';

beforeAll( function () use ( $slug, &$fixtures, $main_path ): void {
	create_collection( $slug, '1200', 70 );
	$fixtures = make_fixture_dir();
	write_jpeg( "{$fixtures}/photo.jpg", 1600, 900 );
	$result = import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );
	if ( $result['exit_code'] !== 0 ) {
		throw new \RuntimeException( "Cannot seed the add-to-media main image: {$result['output']}" );
	}
} );

afterAll( function () use ( $slug, &$fixtures ): void {
	delete_collection( $slug );
	if ( $fixtures !== null ) {
		remove_tree( $fixtures );
	}
} );

test(
	'a confirmed copy sideloads the main image as an independent attachment with sub-sizes',
	function () use ( $slug, $main_path ): void {

		// Count the Media Library before, then POST the confirmed copy with both gates
		// satisfied: the admin session cookie and a fresh wp_rest nonce, targeting the
		// collection-relative main-image path the gallery mirrors onto the anchor.
		$before   = attachment_count();
		$session  = admin_session();
		$response = rest_add_to_media( $slug, $main_path, $session['jar'], $session['nonce'] );

		// The endpoint reports the created attachment, and exactly one new attachment
		// now exists in the Media Library — a real copy, not a link.
		expect( $response['status'] )->toBe( 201 );
		expect( $response['body'] )->not->toBeNull();
		expect( $response['body']['id'] )->toBeInt();
		expect( $response['body']['id'] )->toBeGreaterThan( 0 );
		expect( attachment_count() )->toBe( $before + 1 );

		// WordPress generated its own sub-sizes for the copied image — the proof it is
		// an ordinary, independent attachment (ADR-0015), not a bare file reference.
		expect( attachment_subsize_count( $response['body']['id'] ) )->toBeGreaterThan( 0 );

	} );

test( 'a copy without a nonce is 401 even for a logged-in admin', function () use ( $slug, $main_path ): void {

	// The session cookie alone must not pass: the nonce is the forgery gate, so its
	// absence is a 401 and no attachment is created.
	$before   = attachment_count();
	$session  = admin_session();
	$response = rest_add_to_media( $slug, $main_path, $session['jar'], null );
	expect( $response['status'] )->toBe( 401 );
	expect( attachment_count() )->toBe( $before );

} );

test( 'a subscriber with a valid nonce is rejected with 403', function () use ( $slug, $main_path ): void {

	// A self-registered subscriber can mint a valid nonce but lacks the add_to_media
	// capability (default upload_files), so the gate must answer 403 and copy nothing.
	$before   = attachment_count();
	$username = str_replace( '-', '', unique_slug() );
	create_subscriber( $username, 'it-secret-password' );
	try {
		$session  = login_session( $username, 'it-secret-password' );
		$response = rest_add_to_media( $slug, $main_path, $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 403 );
		expect( attachment_count() )->toBe( $before );
	} finally {
		delete_user( $username );
	}

} );

test( 'a path escaping the collection root is rejected and copies nothing', function () use ( $slug ): void {

	// A traversal path that would resolve above the collection root must be rejected
	// by Path_Guard with no attachment created — the realpath confinement applies to
	// the add-to-media write-path exactly as it does to the upload endpoint.
	$before   = attachment_count();
	$session  = admin_session();
	$response = rest_add_to_media( $slug, '../../wp-config.php', $session['jar'], $session['nonce'] );
	expect( $response['status'] )->toBe( 422 );
	expect( attachment_count() )->toBe( $before );

} );

test( 'a confined path naming a non-main file copies nothing', function () use ( $slug ): void {

	// `collection.json` confines cleanly inside the root and is a real file, but it is
	// the descriptor, not a main image — so the add-to-media gate must reject it
	// (ADR-0015: the action sideloads the *main image*) with no attachment created.
	// This is the integration proof that only a main image, never a derived artifact
	// or a foreign in-collection file, can reach the Media Library.
	$before   = attachment_count();
	$session  = admin_session();
	$response = rest_add_to_media( $slug, 'collection.json', $session['jar'], $session['nonce'] );
	expect( $response['status'] )->toBe( 404 );
	expect( attachment_count() )->toBe( $before );

} );

test( 'a re-add without overwrite is a 409 that adds no attachment', function (): void {

	// A first confirmed copy creates one attachment and stamps it with its source
	// identity. A second copy of the same main, without overwrite, is detected as a
	// duplicate: the controller answers 409 carrying the existing id and copies
	// nothing, so the Media Library count is unchanged after the second call
	// (ADR-0015 amendment — single-click add de-duplicates instead of piling up copies).
	$slug     = unique_slug();
	$fixtures = make_fixture_dir();
	create_collection( $slug, '1200', 70 );
	write_jpeg( "{$fixtures}/dup.jpg", 1600, 900 );
	$import = import_images( $slug, [ to_container_path( "{$fixtures}/dup.jpg" ) ] );
	expect( $import['exit_code'] )->toBe( 0 );

	$session = admin_session();
	$first   = rest_add_to_media( $slug, 'dup.jpg.webp', $session['jar'], $session['nonce'] );
	expect( $first['status'] )->toBe( 201 );
	$after_first = attachment_count();

	$second = rest_add_to_media( $slug, 'dup.jpg.webp', $session['jar'], $session['nonce'] );
	expect( $second['status'] )->toBe( 409 );
	expect( $second['body']['data']['id'] )->toBe( $first['body']['id'] );
	expect( attachment_count() )->toBe( $after_first );

	delete_collection( $slug );
	remove_tree( $fixtures );

} );

test( 'a re-add with overwrite replaces in place — same id, no new attachment', function (): void {

	// After a first 201 (id N), an add of the same path with overwrite returns 200
	// with the same id N and adds no attachment: the existing attachment's file is
	// replaced in place, so any post already embedding it keeps working (ADR-0015
	// amendment).
	$slug     = unique_slug();
	$fixtures = make_fixture_dir();
	create_collection( $slug, '1200', 70 );
	write_jpeg( "{$fixtures}/over.jpg", 1600, 900 );
	$import = import_images( $slug, [ to_container_path( "{$fixtures}/over.jpg" ) ] );
	expect( $import['exit_code'] )->toBe( 0 );

	$session = admin_session();
	$first   = rest_add_to_media( $slug, 'over.jpg.webp', $session['jar'], $session['nonce'] );
	expect( $first['status'] )->toBe( 201 );
	$after_first = attachment_count();

	$overwrite = rest_add_to_media( $slug, 'over.jpg.webp', $session['jar'], $session['nonce'], true );
	expect( $overwrite['status'] )->toBe( 200 );
	expect( $overwrite['body']['id'] )->toBe( $first['body']['id'] );
	expect( attachment_count() )->toBe( $after_first );

	delete_collection( $slug );
	remove_tree( $fixtures );

} );

test( 'add-to-media inserts the main at full resolution without a -scaled master', function (): void {

	// Seed a collection whose main width (3000) exceeds WordPress's 2560px
	// big-image threshold, then import a source wide enough to keep the main
	// above it. Without the threshold suppression the sideload would store a
	// `…-scaled.webp` master at 2560px instead of the real main.
	$slug     = unique_slug();
	$fixtures = make_fixture_dir();
	create_collection( $slug, '3000', 70 );
	write_jpeg( "{$fixtures}/big.jpg", 3200, 1800 );
	$import = import_images( $slug, [ to_container_path( "{$fixtures}/big.jpg" ) ] );
	expect( $import['exit_code'] )->toBe( 0 );

	// Copy the main into the Media Library through the real REST write-path.
	$session  = admin_session();
	$response = rest_add_to_media( $slug, 'big.jpg.webp', $session['jar'], $session['nonce'] );
	expect( $response['status'] )->toBe( 201 );
	$attachment_id = $response['body']['id'];

	// The stored file must be the original main, never a `-scaled` master.
	expect( attachment_file( $attachment_id ) )->not->toContain( '-scaled' );

	delete_collection( $slug );
	remove_tree( $fixtures );

} );
