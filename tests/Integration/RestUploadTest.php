<?php
/**
 * Integration tests for the REST upload round-trip and its two security gates.
 *
 * Drives real multipart POSTs from the host against
 * `kntnt-photo-drop/v1/collections/<slug>/images` on the live wp-env
 * instance: an authenticated admin upload (nonce + `upload_files`), the
 * missing-nonce 401, the capable-of-nothing subscriber 403, and the hostile
 * `relativePath` that must be rejected with nothing written outside the
 * collection root. Authentication mirrors a browser: a `wp-login.php` cookie
 * session plus a `wp_rest` nonce minted via `admin-ajax.php?action=rest-nonce`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_subscriber;
use function Tests\Integration\delete_collection;
use function Tests\Integration\delete_user;
use function Tests\Integration\login_session;
use function Tests\Integration\rest_upload;
use function Tests\Integration\unique_slug;
use function Tests\Integration\uploads_root;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// Seed one collection and one host-side JPEG for the whole file. The fixture
// is uploaded over HTTP, so it can live in the host temp dir — the container
// never needs to read it.
$slug    = unique_slug();
$fixture = sys_get_temp_dir() . '/it-rest-' . bin2hex( random_bytes( 4 ) ) . '.jpg';

beforeAll( function () use ( $slug, $fixture ): void {
	create_collection( $slug, '1200', 70 );
	write_jpeg( $fixture, 800, 600 );
} );

afterAll( function () use ( $slug, $fixture ): void {
	delete_collection( $slug );
	@unlink( $fixture );
} );

test( 'an authenticated upload stores a conforming re-encoded file', function () use ( $slug, $fixture ): void {

	// POST the JPEG with both gates satisfied: the admin session cookie and a
	// fresh wp_rest nonce, targeting a nested relative path.
	$session  = admin_session();
	$response = rest_upload( $slug, $fixture, 'field/day-1/photo.jpg', $session['jar'], $session['nonce'] );

	// The per-file reply reports the re-encode and the stored name; the JPEG
	// was not WebP, so a re-encode (not a verbatim store) is the contract.
	expect( $response['status'] )->toBe( 200 );
	expect( $response['body'] )->not->toBeNull();
	expect( $response['body']['outcome'] )->toBe( 'reencoded' );
	expect( $response['body']['storedName'] )->toBe( 'photo.jpg.webp' );

	// The file on disk conforms: WebP bytes, under the relative path the
	// client named, never upscaled past its own 800px width.
	$main = collection_path( $slug ) . '/field/day-1/photo.jpg.webp';
	expect( is_file( $main ) )->toBeTrue();
	$info = getimagesize( $main );
	expect( $info['mime'] )->toBe( 'image/webp' );
	expect( $info[0] )->toBe( 800 );

} );

test( 'an upload without a nonce is 401 even for a logged-in admin', function () use ( $slug, $fixture ): void {

	// The session cookie alone must not pass: the nonce is the forgery gate,
	// so its absence is a 401 regardless of how privileged the session is.
	$session  = admin_session();
	$response = rest_upload( $slug, $fixture, 'forged.jpg', $session['jar'], null );
	expect( $response['status'] )->toBe( 401 );
	expect( is_file( collection_path( $slug ) . '/forged.jpg.webp' ) )->toBeFalse();

} );

test( 'a subscriber with a valid nonce is rejected with 403', function () use ( $slug, $fixture ): void {

	// A self-registered subscriber can mint a perfectly valid nonce but lacks
	// upload_files; the capability gate must answer 403, not write a byte.
	$username = str_replace( '-', '', unique_slug() );
	create_subscriber( $username, 'it-secret-password' );
	try {
		$session  = login_session( $username, 'it-secret-password' );
		$response = rest_upload( $slug, $fixture, 'subscriber.jpg', $session['jar'], $session['nonce'] );
		expect( $response['status'] )->toBe( 403 );
		expect( is_file( collection_path( $slug ) . '/subscriber.jpg.webp' ) )->toBeFalse();
	} finally {
		delete_user( $username );
	}

} );

test( 'a hostile relativePath is rejected and writes nothing outside', function () use ( $slug, $fixture ): void {

	// POST a traversal path that would resolve two levels above the
	// collection root — straight into the uploads directory.
	$session  = admin_session();
	$response = rest_upload( $slug, $fixture, '../../escape.jpg', $session['jar'], $session['nonce'] );

	// The reply is a per-file rejection with nothing stored.
	expect( $response['status'] )->toBe( 422 );
	expect( $response['body']['outcome'] )->toBe( 'rejected' );
	expect( $response['body']['storedName'] )->toBeNull();

	// Nothing landed at the escape location (or anywhere else the traversal
	// could lexically point), under either the raw or the stored name.
	$escapes = [
		uploads_root() . '/escape.jpg',
		uploads_root() . '/escape.jpg.webp',
		uploads_root() . '/kntnt-photo-drop/escape.jpg',
		uploads_root() . '/kntnt-photo-drop/escape.jpg.webp',
		collection_path( $slug ) . '/escape.jpg',
		collection_path( $slug ) . '/escape.jpg.webp',
	];
	foreach ( $escapes as $escape ) {
		expect( file_exists( $escape ) )->toBeFalse();
	}

} );
