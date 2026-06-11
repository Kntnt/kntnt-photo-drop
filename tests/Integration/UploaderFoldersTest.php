<?php
/**
 * Integration tests for the create-time uploader-folders choice (ADR-0008).
 *
 * The placement rule is fixed when a collection is established — through the
 * admin create form or the CLI `collection create` flag — and then governs
 * where every Drop Zone upload lands: under a folder named for the uploader
 * when on, at the collection root when off. These tests establish a collection
 * each way, with the option both on and off, then drive a real authenticated
 * REST upload and assert the file's placement on disk, closing the loop from
 * the create-time surface to the server-side enforcement landed in #38.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_collection_via_admin;
use function Tests\Integration\delete_collection;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\rest_upload;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// One host-side JPEG serves every upload in the file; it is POSTed over HTTP,
// so it can live in the host temp dir.
$fixture = sys_get_temp_dir() . '/it-uf-' . bin2hex( random_bytes( 4 ) ) . '.jpg';

beforeAll( function () use ( $fixture ): void {
	write_jpeg( $fixture, 800, 600 );
} );

afterAll( function () use ( $fixture ): void {
	@unlink( $fixture );
} );

test( 'a CLI collection with folders on lands uploads under the uploader', function () use ( $fixture ): void {

	$slug = unique_slug();
	try {

		// Establish via the CLI with the default placement rule, upload one JPEG,
		// and assert the descriptor recorded the choice and the file landed under
		// the admin's nicename rather than at the bare root.
		create_collection( $slug, '1200', 70 );
		expect( read_descriptor( $slug )['uploaderFolders'] )->toBeTrue();
		$session = admin_session();
		rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/photo.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'a CLI collection with folders off lands uploads at the root', function () use ( $fixture ): void {

	$slug = unique_slug();
	try {

		// Establish via the CLI with --no-uploader-folders; the descriptor records
		// the rule off and the upload lands at the collection root with no prefix.
		create_collection( $slug, '1200', 70, null, false );
		expect( read_descriptor( $slug )['uploaderFolders'] )->toBeFalse();
		$session = admin_session();
		rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/photo.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'an admin-form collection with the box ticked namespaces uploads', function () use ( $fixture ): void {

	$slug = unique_slug();
	try {

		// Establish through the real admin create form with the checkbox ticked;
		// the descriptor records the rule on and the upload is namespaced.
		create_collection_via_admin( $slug, '1200', 70, true );
		expect( read_descriptor( $slug )['uploaderFolders'] )->toBeTrue();
		$session = admin_session();
		rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/photo.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'an admin-form collection with the box unticked lands at the root', function () use ( $fixture ): void {

	$slug = unique_slug();
	try {

		// Establish through the real admin create form with the checkbox absent (an
		// unchecked box submits nothing); the descriptor records the rule off and
		// the upload lands at the collection root.
		create_collection_via_admin( $slug, '1200', 70, false );
		expect( read_descriptor( $slug )['uploaderFolders'] )->toBeFalse();
		$session = admin_session();
		rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/photo.jpg.webp' ) )->toBeTrue();
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeFalse();

	} finally {
		delete_collection( $slug );
	}

} );

test( 'collection update cannot change the immutable uploader-folders rule', function (): void {

	$slug = unique_slug();
	try {

		// The placement rule is fixed at establishment; passing the flag to update
		// must fail hard and leave the descriptor byte-identical.
		create_collection( $slug, '1200', 70 );
		$before = read_descriptor( $slug );
		$result = \Tests\Integration\run_cli(
			[ 'kntnt-photo-drop', 'collection', 'update', $slug, '--name=X', '--no-uploader-folders' ],
		);
		expect( $result['exit_code'] )->not->toBe( 0 );
		expect( read_descriptor( $slug ) )->toBe( $before );

	} finally {
		delete_collection( $slug );
	}

} );
