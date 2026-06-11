<?php
/**
 * Adversarial tests for the REST upload controller — the trust boundary.
 *
 * This is the only HTTP write path into a collection, so the suite is hostile by
 * design. It drives the real controller against a real temp-dir collection with
 * real GD images and the production ingestion engine, stubbing only the
 * WordPress seams (`wp_verify_nonce`, `current_user_can`, `apply_filters`,
 * `sanitize_text_field`, `__`, and the uploads-root helpers). It proves the two
 * independent gates (nonce, capability) each reject on their own, that the
 * server re-enforces the output contract on a file POSTed directly, that a
 * hostile `relativePath` is rejected with nothing written, and that the per-file
 * response is exactly one of `stored | skipped | reencoded | rejected` while the
 * index is never written.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Ingestion\Ingestor;
use Kntnt\Photo_Drop\Rest\Upload_Controller;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, real GD images, stubbed WP seams
// ---------------------------------------------------------------------------

/**
 * Wires every WordPress seam the controller and its collaborators reach for.
 *
 * The uploads basedir is a real temp directory so the `Repository`, `Ingestor`,
 * and `Path_Guard` exercise the real filesystem. The two security seams are
 * parameterised: `$nonce_ok` decides what `wp_verify_nonce()` returns and
 * `$cap_ok` what `current_user_can()` returns, so a test can fail exactly one
 * gate. `apply_filters()` honours an optional capability override so the
 * `kntnt_photo_drop_upload_capability` filter can be exercised; every other
 * filter (the root, the thumbnail width) passes its value through unchanged.
 * The current user resolves to a fixed `user_nicename`, and `sanitize_title()`
 * reduces it to a single safe segment, so the uploader-folders placement path
 * has a deterministic prefix to prepend.
 *
 * @param string      $basedir      Temp directory standing in for the uploads basedir.
 * @param bool        $nonce_ok     What `wp_verify_nonce()` should return.
 * @param bool        $cap_ok       What `current_user_can()` should return for the resolved cap.
 * @param string|null $cap_override Value the capability filter should return, or null to leave the default.
 * @param string      $nicename     The `user_nicename` the current user resolves to.
 * @return void
 */
function wire_upload_stubs(
	string $basedir,
	bool $nonce_ok,
	bool $cap_ok,
	?string $cap_override = null,
	string $nicename = 'anders'
): void {

	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => $basedir,
			'error'   => false,
		]
	);
	Functions\when( 'trailingslashit' )->alias(
		static fn ( string $path ): string => rtrim( $path, '/\\' ) . '/'
	);
	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);
	Functions\when( 'sanitize_text_field' )->alias(
		static fn ( string $value ): string => trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' )
	);
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);
	Functions\when( 'wp_raise_memory_limit' )->justReturn( true );

	// The nonce verifier returns a truthy tick (1) or false, matching core.
	Functions\when( 'wp_verify_nonce' )->justReturn( $nonce_ok ? 1 : false );

	// The capability check returns the parameterised verdict for whatever cap the
	// controller resolves through the filter.
	Functions\when( 'current_user_can' )->justReturn( $cap_ok );

	// Pass filters through, except an optional capability override so the
	// kntnt_photo_drop_upload_capability filter can be exercised in one test.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $cap_override ): mixed {
			if ( $hook === 'kntnt_photo_drop_upload_capability' && $cap_override !== null ) {
				return $cap_override;
			}
			return $value;
		}
	);

	// Resolve the request user to a fixed nicename so the uploader-folders prefix
	// is deterministic, and reduce a nicename to one safe segment exactly as the
	// real sanitize_title() would (lowercase, alphanumerics and hyphens only).
	Functions\when( 'wp_get_current_user' )->justReturn(
		(object) [
			'ID'            => 7,
			'user_nicename' => $nicename,
		]
	);
	Functions\when( 'sanitize_title' )->alias(
		static function ( string $title ): string {
			$lower = strtolower( $title );
			$clean = preg_replace( '/[^a-z0-9]+/', '-', $lower ) ?? '';
			return trim( $clean, '-' );
		}
	);

}

/**
 * Creates a real collection on disk under the temp uploads root.
 *
 * Builds the `<root>/kntnt-photo-drop/<slug>/` directory and writes a real
 * `collection.json` via the descriptor codec, so `Repository::resolve_slug()`
 * resolves it and `Descriptor::read()` returns the contract under test.
 *
 * @param string     $basedir    The temp uploads basedir.
 * @param string     $slug       The collection slug.
 * @param Descriptor $descriptor The contract to persist.
 * @return string The absolute collection directory path.
 */
function seed_upload_collection( string $basedir, string $slug, Descriptor $descriptor ): string {
	$path = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/' . $slug;
	mkdir( $path, 0700, true );
	$descriptor->write( $path );
	return $path;
}

/**
 * Allocates a fresh temp directory standing in for the uploads basedir.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_upload_basedir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-rest-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Encodes a solid-colour true-colour image to JPEG bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The JPEG bytes.
 */
function rest_jpeg( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 70, 130, 90 ) );
	ob_start();
	imagejpeg( $image, null, 90 );
	return (string) ob_get_clean();
}

/**
 * Encodes a true-colour image to WebP bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The WebP bytes.
 */
function rest_webp( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 50, 110, 190 ) );
	ob_start();
	imagewebp( $image, null, 80 );
	return (string) ob_get_clean();
}

/**
 * Writes bytes to a temp file standing in for a PHP multipart upload.
 *
 * @param string $bytes The image bytes to stage.
 * @return string The absolute temp file path.
 */
function stage_upload( string $bytes ): string {
	$tmp = sys_get_temp_dir() . '/kntnt-up-' . bin2hex( random_bytes( 6 ) );
	file_put_contents( $tmp, $bytes );
	return $tmp;
}

/**
 * Builds a REST request carrying a nonce header, a relativePath, and a file.
 *
 * Mirrors the multipart shape the Drop Zone POSTs: the `X-WP-Nonce` header, the
 * slug and `relativePath` params, and a `$_FILES`-shaped `file` entry pointing
 * at a staged temp file. A `null` `$bytes` omits the file entirely so the
 * missing-file path can be tested.
 *
 * @param string      $slug          The collection slug route param.
 * @param string      $relative_path The caller-supplied relative target.
 * @param string|null $bytes         The uploaded bytes, or null to omit the file.
 * @param string      $nonce         The nonce to put in the X-WP-Nonce header.
 * @return WP_REST_Request The assembled request.
 */
function rest_request(
	string $slug,
	string $relative_path,
	?string $bytes,
	string $nonce = 'valid-nonce'
): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_param( 'slug', $slug );
	$request->set_param( 'relativePath', $relative_path );
	if ( $bytes !== null ) {
		$request->set_file_params(
			[
				'file' => [
					'name'     => basename( $relative_path ),
					'tmp_name' => stage_upload( $bytes ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $bytes ),
				],
			]
		);
	}
	return $request;
}

/**
 * Captures the route configuration that register_routes() registers.
 *
 * Stubs `register_rest_route()` to record its configuration array — exactly
 * what production WordPress receives — so tests can assert the registered
 * argument schema and drive request parameters through the same
 * `sanitize_callback` production applies before the handler runs.
 *
 * @param Upload_Controller $controller The controller whose route to capture.
 * @return array<string, mixed> The captured route configuration.
 */
function capture_route_config( Upload_Controller $controller ): array {

	// Record the configuration the controller hands to WordPress.
	$captured = [];
	Functions\when( 'register_rest_route' )->alias(
		static function ( string $route_namespace, string $route, array $config ) use ( &$captured ): bool {
			$captured = $config;
			return true;
		}
	);
	$controller->register_routes();

	return $captured;

}

/**
 * Recursively removes a temp directory tree.
 *
 * @param string $dir The directory to remove.
 * @return void
 */
function rest_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		rest_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// The nonce gate (CSRF) — independent of capability
// ---------------------------------------------------------------------------

test( 'a missing or invalid nonce is rejected even when the capability would pass', function (): void {

	// The capability gate would pass, but the nonce verifier says no: the request
	// must still be rejected as 401, proving the nonce is enforced on its own.
	$basedir = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: false, cap_ok: true );
	$controller = new Upload_Controller( new Repository() );

	$verdict = $controller->check_permission( rest_request( 'photos', 'IMG.jpg', null ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_code() )->toBe( 'kntnt_photo_drop_invalid_nonce' );
	expect( $verdict->get_error_data()['status'] )->toBe( 401 );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The capability gate (authorisation) — independent of nonce
// ---------------------------------------------------------------------------

test( 'a valid nonce without upload_files is rejected', function (): void {

	// The nonce is valid but the user lacks the capability — exactly the
	// self-registered Subscriber case — so the request is rejected as 403.
	$basedir = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: false );
	$controller = new Upload_Controller( new Repository() );

	$verdict = $controller->check_permission( rest_request( 'photos', 'IMG.jpg', null ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_code() )->toBe( 'kntnt_photo_drop_forbidden' );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	rest_remove_tree( $basedir );
} );

test( 'both a valid nonce and the capability pass the permission gate', function (): void {

	// Both gates satisfied: the permission callback returns true, so WordPress
	// would invoke the handler.
	$basedir = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$controller = new Upload_Controller( new Repository() );

	$verdict = $controller->check_permission( rest_request( 'photos', 'IMG.jpg', null ) );

	expect( $verdict )->toBeTrue();

	rest_remove_tree( $basedir );
} );

test( 'the capability gate honours the upload_capability filter', function (): void {

	// The filter narrows the required capability to manage_options. current_user_can
	// is stubbed to false (the user is *not* a manager), so the gate must reject —
	// proving the controller checks the filtered capability, not the hard default.
	$basedir = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: false, cap_override: 'manage_options' );
	$controller = new Upload_Controller( new Repository() );

	$verdict = $controller->check_permission( rest_request( 'photos', 'IMG.jpg', null ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Server-side contract re-enforcement (the client optimisation is not the boundary)
// ---------------------------------------------------------------------------

test( 'an over-ceiling JPEG POSTed directly is stored conforming as a downscaled WebP', function (): void {

	// A 3000px JPEG bypassing the browser is downscaled to the 1920 ceiling and
	// converted to WebP server-side; the outcome reports the re-encode.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'IMG_2024.jpg', rest_jpeg( 3000, 1500 ) ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data()['outcome'] )->toBe( 'reencoded' );
	expect( $response->get_data()['storedName'] )->toBe( 'IMG_2024.jpg.webp' );
	expect( is_file( $path . '/IMG_2024.jpg.webp' ) )->toBeTrue();
	expect( (int) getimagesize( $path . '/IMG_2024.jpg.webp' )[0] )->toBe( 1920 );

	rest_remove_tree( $basedir );
} );

test( 'an already-conforming WebP POSTed directly is stored as-is with a stored outcome', function (): void {

	// A WebP within the ceiling is accepted byte-for-byte (no second lossy pass),
	// so the outcome is stored, not reencoded, and the name is not doubled.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$source     = rest_webp( 800, 600 );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'sunset.webp', $source ) );

	expect( $response->get_data()['outcome'] )->toBe( 'stored' );
	expect( $response->get_data()['storedName'] )->toBe( 'sunset.webp' );
	expect( file_get_contents( $path . '/sunset.webp' ) )->toBe( $source );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Path traversal and realpath confinement
// ---------------------------------------------------------------------------

test( 'the relativePath sanitiser is a pure type gate that preserves raw bytes', function (): void {

	// The registered callback must pass strings through verbatim — %xx
	// sequences, doubled spaces, even NUL bytes intact, so Path_Guard (the real
	// sanitiser) sees the raw bytes — and normalise non-strings to the empty
	// string. The slug keeps the strict text-field sanitiser: it addresses a
	// directory, never carries a filename.
	$basedir = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$config = capture_route_config( new Upload_Controller( new Repository() ) );

	$sanitize = $config['args']['relativePath']['sanitize_callback'];
	expect( $sanitize( '%2e%2e%2fescape.jpg' ) )->toBe( '%2e%2e%2fescape.jpg' );
	expect( $sanitize( 'with  double  spaces.jpg' ) )->toBe( 'with  double  spaces.jpg' );
	expect( $sanitize( "nul\x00byte.jpg" ) )->toBe( "nul\x00byte.jpg" );
	expect( $sanitize( [ 'not', 'a', 'string' ] ) )->toBe( '' );
	expect( $sanitize( null ) )->toBe( '' );
	expect( $config['args']['slug']['sanitize_callback'] )->toBe( 'sanitize_text_field' );

	rest_remove_tree( $basedir );
} );

test( 'a hostile relativePath is rejected with nothing written outside the root', function ( string $hostile ): void {

	// Every hostile target is rejected as 422 with no file written; the collection
	// holds only the descriptor it was seeded with — nothing escaped above it.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	// Drive the hostile value through the exact sanitize_callback production
	// registers, so upload() sees the same bytes WordPress would deliver —
	// keeping this adversarial dataset meaningful end to end (the previous
	// sanitize_text_field would have mangled the encoded entries before the
	// guard ever saw them).
	$sanitize = capture_route_config( $controller )['args']['relativePath']['sanitize_callback'];
	$response = $controller->upload( rest_request( 'photos', $sanitize( $hostile ), rest_jpeg( 400, 300 ) ) );

	expect( $response->get_status() )->toBe( 422 );
	expect( $response->get_data()['outcome'] )->toBe( 'rejected' );
	expect( $response->get_data()['storedName'] )->toBeNull();
	$contents = glob( $path . '/*' );
	expect( $contents )->toBe( [ $path . '/collection.json' ] );

	rest_remove_tree( $basedir );
} )->with( [
	'parent traversal'   => [ '../escape.jpg' ],
	'deep traversal'     => [ '../../../../etc/passwd.jpg' ],
	'encoded traversal'  => [ '%2e%2e%2fescape.jpg' ],
	'double-encoded'     => [ '%252e%252e%252fescape.jpg' ],
	'absolute path'      => [ '/etc/passwd.jpg' ],
	'embedded traversal' => [ 'a/../../b.jpg' ],
	'nul byte'           => [ "ok\x00/../escape.jpg" ],
] );

test( 'an accepted nested relativePath is confined inside the collection root', function (): void {

	// A benign nested path recreates its sub-tree under the root and the realpath
	// of the created directory stays inside the collection root.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'trip/day1/IMG.jpg', rest_jpeg( 1000, 800 ) ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( is_file( $path . '/trip/day1/IMG.jpg.webp' ) )->toBeTrue();
	expect( realpath( $path . '/trip/day1' ) )->toStartWith( realpath( $path ) );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Per-uploader placement — the uploaderFolders namespace (ADR-0008)
// ---------------------------------------------------------------------------

test( 'an upload to an uploader-folders collection lands under the request user nicename', function (): void {

	// With uploaderFolders on, the server prepends a first segment derived from
	// the request user's nicename ahead of the client path; the file lands under
	// <nicename>/<relative path>, never at the bare root.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true, nicename: 'anders' );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: true );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'trip/IMG.jpg', rest_jpeg( 800, 600 ) ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( is_file( $path . '/anders/trip/IMG.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/trip/IMG.jpg.webp' ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'the uploader folder is server-derived, ignoring any client-named segment', function (): void {

	// The nicename comes from the request user, never the client: a client that
	// names its own "victim" first segment still lands under the server's
	// "anders" folder, so the prefix cannot be spoofed.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true, nicename: 'anders' );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: true );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'victim/IMG.jpg', rest_jpeg( 800, 600 ) ) );

	expect( is_file( $path . '/anders/victim/IMG.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/victim/IMG.jpg.webp' ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'a hostile relativePath is still confined after prefixing', function ( string $hostile ): void {

	// Prepending the nicename must not weaken confinement: a traversal payload
	// that would climb out of the uploader folder (and the collection root) is
	// still rejected by Path_Guard, with nothing written anywhere.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true, nicename: 'anders' );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: true );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$sanitize = capture_route_config( $controller )['args']['relativePath']['sanitize_callback'];
	$response = $controller->upload( rest_request( 'photos', $sanitize( $hostile ), rest_jpeg( 400, 300 ) ) );

	expect( $response->get_status() )->toBe( 422 );
	expect( $response->get_data()['outcome'] )->toBe( 'rejected' );
	expect( glob( $path . '/*' ) )->toBe( [ $path . '/collection.json' ] );

	rest_remove_tree( $basedir );
} )->with( [
	'climb out of the uploader folder' => [ '../escape.jpg' ],
	'climb out of the collection root' => [ '../../escape.jpg' ],
	'deep traversal'                   => [ '../../../../etc/passwd.jpg' ],
	'encoded traversal'                => [ '%2e%2e%2f%2e%2e%2fescape.jpg' ],
	'double-encoded'                   => [ '%252e%252e%252fescape.jpg' ],
] );

// ---------------------------------------------------------------------------
// Unknown collection, missing file, and per-file outcomes
// ---------------------------------------------------------------------------

test( 'an unknown collection slug is a 404', function (): void {

	// No collection is seeded, so the slug does not resolve; the handler answers
	// 404 and writes nothing.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'ghost', 'IMG.jpg', rest_jpeg( 400, 300 ) ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'kntnt_photo_drop_unknown_collection' );
	expect( $response->get_error_data()['status'] )->toBe( 404 );

	rest_remove_tree( $basedir );
} );

test( 'a request with no uploaded file is a 400', function (): void {

	// The collection resolves but no multipart file was sent; the handler answers
	// 400 rather than treating the absence as a content rejection.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'IMG.jpg', null ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'kntnt_photo_drop_no_file' );
	expect( $response->get_error_data()['status'] )->toBe( 400 );

	rest_remove_tree( $basedir );
} );

test( 'an existing path skips by default and reports the skipped outcome', function (): void {

	// A first upload stores the main; a second to the same path skips it untouched
	// (the default), so the bytes are unchanged and the outcome is skipped.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 1000, 800 ) ) );
	$after_first = file_get_contents( $path . '/photo.jpg.webp' );
	$second      = $controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 1200, 900 ) ) );

	expect( $second->get_status() )->toBe( 200 );
	expect( $second->get_data()['outcome'] )->toBe( 'skipped' );
	expect( file_get_contents( $path . '/photo.jpg.webp' ) )->toBe( $after_first );

	rest_remove_tree( $basedir );
} );

test( 'the handler writes main plus thumbnails but never writes the index', function (): void {

	// One upload writes the main and both thumbnails under the hidden directory,
	// but no index.json — the index self-heals on the next gallery view (ADR-0006).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320, 640 ], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 2000, 1200 ) ) );

	expect( $response->get_data()['thumbnails'] )->toBe( 2 );
	expect( is_file( $path . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/' . Index::THUMBNAILS_DIRNAME . '/640/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'every per-file response outcome is one of the four legal values', function (): void {

	// Drive all four outcomes through one collection — an already-conforming WebP
	// (stored), a JPEG re-encoded to the contract (reencoded), a re-POST of the
	// stored path (skipped), and a hostile path (rejected) — and assert each
	// reported outcome is drawn from exactly the closed set the design fixes.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new Upload_Controller( new Repository() );

	$stored    = $controller->upload( rest_request( 'photos', 'conform.webp', rest_webp( 600, 400 ) ) );
	$reencoded = $controller->upload( rest_request( 'photos', 'a.jpg', rest_jpeg( 800, 600 ) ) );
	$skipped   = $controller->upload( rest_request( 'photos', 'conform.webp', rest_webp( 600, 400 ) ) );
	$rejected  = $controller->upload( rest_request( 'photos', '../x.jpg', rest_jpeg( 800, 600 ) ) );

	$legal = [ 'stored', 'skipped', 'reencoded', 'rejected' ];
	foreach ( [ $stored, $reencoded, $skipped, $rejected ] as $response ) {
		expect( $legal )->toContain( $response->get_data()['outcome'] );
	}
	expect( $stored->get_data()['outcome'] )->toBe( 'stored' );
	expect( $reencoded->get_data()['outcome'] )->toBe( 'reencoded' );
	expect( $skipped->get_data()['outcome'] )->toBe( 'skipped' );
	expect( $rejected->get_data()['outcome'] )->toBe( 'rejected' );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// A host without a WebP codec is a clean 500, never an uncaught throw
// ---------------------------------------------------------------------------

test( 'a missing WebP codec yields an actionable 500 instead of an uncaught throw', function (): void {

	// A test subclass whose ingestor factory throws the optimiser's
	// construction error stands in for a PHP without GD/Imagick WebP support:
	// the handler must answer with a translated, actionable 500 — and write
	// nothing — rather than letting the RuntimeException escape as an opaque
	// server error.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, [], uploader_folders: false );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = new class( new Repository() ) extends Upload_Controller {

		/**
		 * Throws the codec-missing construction error the real Ingestor raises.
		 *
		 * @param string     $collection_path Ignored.
		 * @param Descriptor $descriptor      Ignored.
		 * @throws \RuntimeException Always.
		 */
		protected function create_ingestor( string $collection_path, Descriptor $descriptor ): Ingestor {
			throw new \RuntimeException( 'No image codec on this host can encode WebP.' );
		}

	};

	$response = $controller->upload( rest_request( 'photos', 'IMG.jpg', rest_jpeg( 400, 300 ) ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'kntnt_photo_drop_no_codec' );
	expect( $response->get_error_data()['status'] )->toBe( 500 );
	expect( $response->get_error_message() )->toContain( 'WebP' );
	expect( glob( $path . '/*' ) )->toBe( [ $path . '/collection.json' ] );

	rest_remove_tree( $basedir );
} );
