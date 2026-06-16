<?php
/**
 * Integration tests for Drop Zone upload placement.
 *
 * The immutable `uploaderFolders` boolean these tests originally exercised was
 * retired by ADR-0014 in favour of the descriptor's mutable `pathComponents`
 * template, whose server-side expansion — the `%year%/%month%/%day%/%uploader%`
 * prefix prepended ahead of each upload's source-relative path — is a separate
 * concern (issue #48). Until #48 lands that expansion, the Upload_Controller
 * ingests the client path verbatim, so there is no per-uploader placement to
 * assert here. The scenarios below are kept, skipped, as the placement contract
 * #48 must satisfy: they should be rewritten against the pathComponents template
 * (e.g. an `%uploader%` segment resolving to the request user's nicename) once it
 * exists.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

use function Tests\Integration\admin_session;
use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\delete_collection;
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

test( 'an upload lands under the pathComponents-expanded prefix', function () use ( $fixture ): void {

	// Placeholder for #48: once the pathComponents template is expanded server-side
	// at upload time, an `%uploader%` segment must land the file under the request
	// user's nicename (here, the admin's), never at the bare root.
	$slug = unique_slug();
	try {

		create_collection( $slug, '1200', 70 );
		$session = admin_session();
		rest_upload( $slug, $fixture, 'photo.jpg', $session['jar'], $session['nonce'] );
		expect( is_file( collection_path( $slug ) . '/admin/photo.jpg.webp' ) )->toBeTrue();

	} finally {
		delete_collection( $slug );
	}

} )->skip( 'pathComponents expansion is issue #48; the uploaderFolders boolean is retired (ADR-0014).' );

test( 'editing pathComponents changes only future uploads', function (): void {

	// Placeholder for #48: the template is mutable (unlike the retired immutable
	// uploaderFolders boolean), so editing it must affect only later uploads.
	expect( true )->toBeTrue();

} )->skip( 'pathComponents editing is issue #48 (ADR-0014).' );
