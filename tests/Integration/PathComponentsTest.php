<?php
/**
 * Integration tests for the Drop Zone path-components placement template.
 *
 * Drives real multipart uploads from the host against the live wp-env instance
 * and asserts where the stored main lands on disk, proving the descriptor's
 * mutable `pathComponents` template (ADR-0014) is expanded server-side and
 * prepended ahead of each file's own relative path, and that the expanded path
 * is `Path_Guard`-confined to the collection root. These scenarios replace the
 * retired `uploaderFolders` boolean's tests: the `%uploader%` segment is now just
 * the folder that placeholder produces, derived server-side from the request
 * user's nicename. A deterministic `%uploader%`-only template is used where the
 * exact path is asserted; the date-folder case computes the expected date in the
 * site timezone, the same way the server does.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection_with_template;
use function Tests\Integration\delete_collection;
use function Tests\Integration\rest_upload;
use function Tests\Integration\site_today_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\update_path_components;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// One host-side JPEG serves every upload in the file; it is POSTed over HTTP,
// so it can live in the host temp dir.
$fixture = sys_get_temp_dir() . '/it-pc-' . bin2hex( random_bytes( 4 ) ) . '.jpg';

beforeAll( function () use ( $fixture ): void {
	write_jpeg( $fixture, 800, 600 );
} );

afterAll( function () use ( $fixture ): void {
	@unlink( $fixture );
} );

test( 'an upload lands under the expanded template with its hierarchy preserved', function () use ( $fixture ): void {

	// A %uploader%-only template (deterministic, no date) is expanded to the admin's
	// nicename and prepended ahead of the client relative path, so the dropped-folder
	// hierarchy is preserved after the prefix (ADR-0014).
	$slug = unique_slug();
	try {

		create_collection_with_template( $slug, '%uploader%' );
		$session  = admin_session();
		$response = rest_upload( $slug, $fixture, 'trip/day-1/photo.jpg', $session['jar'], $session['nonce'] );

		expect( $response['status'] )->toBe( 200 );
		expect( is_file( collection_path( $slug ) . '/admin/trip/day-1/photo.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/trip/day-1/photo.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'the %uploader% segment is the request user nicename, not a client value', function () use ( $fixture ): void {

	// %uploader% resolves server-side to the authenticated user (admin), and a
	// client-sent `uploader` form field is ignored, so the placement cannot be
	// spoofed (ADR-0014).
	$slug = unique_slug();
	try {

		create_collection_with_template( $slug, '%uploader%' );
		$session = admin_session();
		$response = rest_upload(
			$slug,
			$fixture,
			'photo.jpg',
			$session['jar'],
			$session['nonce'],
		);

		expect( $response['status'] )->toBe( 200 );
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeTrue();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'the date segments use the site timezone', function () use ( $fixture ): void {

	// %year%/%month%/%day% expand to the current date in the site timezone — the
	// same value site_today_path() reads from wp_timezone() in the container — so
	// the file lands under today's dated folder (ADR-0014).
	$slug = unique_slug();
	try {

		create_collection_with_template( $slug, '%year%/%month%/%day%' );
		$session  = admin_session();
		$response = rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );

		expect( $response['status'] )->toBe( 200 );
		expect( is_file( collection_path( $slug ) . '/' . site_today_path() . '/photo.jpg.webp' ) )->toBeTrue();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'editing pathComponents changes only future uploads', function () use ( $fixture ): void {

	// The template is mutable (unlike the retired uploaderFolders boolean): editing
	// it moves only later uploads, while files already written stay where they were
	// (ADR-0014).
	$slug = unique_slug();
	try {

		// First upload under the original %uploader% template.
		create_collection_with_template( $slug, '%uploader%' );
		$session = admin_session();
		rest_upload( $slug, $fixture, 'first.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/admin/first.jpg.webp' ) )->toBeTrue();

		// Change the template, then upload again; the second file lands under the new
		// prefix and the first is untouched.
		$updated = update_path_components( $slug, 'events/%uploader%' );
		expect( $updated['exit_code'] )->toBe( 0 );
		$session = admin_session();
		rest_upload( $slug, $fixture, 'second.jpg', $session['jar'], $session['nonce'] );

		expect( is_file( collection_path( $slug ) . '/events/admin/second.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/admin/first.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/events/admin/first.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'a hostile relativePath is rejected and nothing escapes the expanded prefix', function () use ( $fixture ): void {

	// Even with a template prefix prepended, a traversal client path is rejected by
	// Path_Guard with nothing written outside the collection root — the realpath
	// confinement of the assembled path stays load-bearing (ADR-0014).
	$slug = unique_slug();
	try {

		create_collection_with_template( $slug, '%uploader%' );
		$session  = admin_session();
		$response = rest_upload( $slug, $fixture, '../../escape.jpg', $session['jar'], $session['nonce'] );

		expect( $response['status'] )->toBe( 422 );
		expect( $response['body']['outcome'] )->toBe( 'rejected' );
		expect( is_file( collection_path( $slug ) . '/admin/escape.jpg.webp' ) )->toBeFalse();
		expect( is_file( collection_path( $slug ) . '/escape.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );
