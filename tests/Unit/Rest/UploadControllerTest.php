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
 *
 * @param string      $basedir      Temp directory standing in for the uploads basedir.
 * @param bool        $nonce_ok     What `wp_verify_nonce()` should return.
 * @param bool        $cap_ok       What `current_user_can()` should return for the resolved cap.
 * @param string|null $cap_override Value the capability filter should return, or null to leave the default.
 * @return void
 */
function wire_upload_stubs(
	string $basedir,
	bool $nonce_ok,
	bool $cap_ok,
	?string $cap_override = null
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

	// The placement template is expanded server-side (ADR-0014): the date comes
	// from the site timezone, the uploader from the current user's nicename. The
	// defaults — UTC and the `admin` nicename — keep every non-placement test
	// deterministic; a test that probes the timezone or the nicename overrides
	// these stubs locally.
	Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 1, 'admin' ) );

}

/**
 * The fixed upload instant every placement test expands against (UTC).
 *
 * 2024-06-16 12:00:00 UTC — far from a date boundary so the default UTC stub
 * yields a stable `2024/06/16` prefix, while the timezone test overrides the
 * timezone stub to push the calendar date across midnight.
 *
 * @var int
 */
const FIXED_UPLOAD_TS = 1_718_539_200;

/**
 * Builds an upload controller whose upload instant is pinned to FIXED_UPLOAD_TS.
 *
 * The real controller stamps each upload with `time()`; pinning it through the
 * overridable seam makes the expanded `%year%/%month%/%day%` prefix deterministic
 * so a placement assertion can name the exact folder. The nicename and timezone
 * still flow from the stubbed WordPress seams.
 *
 * @param Repository $repository The collection read/resolve side.
 * @return Upload_Controller A controller with a frozen upload clock.
 */
function fixed_clock_controller( Repository $repository ): Upload_Controller {
	return new class( $repository ) extends Upload_Controller {

		/**
		 * Returns the frozen upload instant instead of the wall clock.
		 *
		 * @return int The fixed Unix timestamp.
		 */
		protected function upload_timestamp(): int {
			return FIXED_UPLOAD_TS;
		}
	};
}

/**
 * Returns the expanded default-template prefix the fixed clock produces.
 *
 * The default template is `%year%/%month%/%day%/%uploader%`; with the frozen
 * UTC instant and the `admin` nicename stub it expands to `2024/06/16/admin`.
 * Placement assertions join this prefix with the client relative path.
 *
 * @param string $collection_path The collection root.
 * @param string $nicename        The expected uploader segment (default `admin`).
 * @return string The absolute directory the default template places uploads in.
 */
function placed_dir( string $collection_path, string $nicename = 'admin' ): string {
	return $collection_path . '/2024/06/16/' . $nicename;
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
	// converted to WebP server-side; the outcome reports the re-encode. The stored
	// main lands under the expanded default-template prefix (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 320, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'IMG_2024.jpg', rest_jpeg( 3000, 1500 ) ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data()['outcome'] )->toBe( 'reencoded' );
	expect( $response->get_data()['storedName'] )->toBe( 'IMG_2024.jpg.webp' );
	expect( is_file( placed_dir( $path ) . '/IMG_2024.jpg.webp' ) )->toBeTrue();
	expect( (int) getimagesize( placed_dir( $path ) . '/IMG_2024.jpg.webp' )[0] )->toBe( 1920 );

	rest_remove_tree( $basedir );
} );

test( 'an already-conforming WebP POSTed directly is stored as-is with a stored outcome', function (): void {

	// A WebP within the ceiling is accepted byte-for-byte (no second lossy pass),
	// so the outcome is stored, not reencoded, and the name is not doubled. The
	// stored bytes land under the expanded default-template prefix (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$source     = rest_webp( 800, 600 );
	$controller = fixed_clock_controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'sunset.webp', $source ) );

	expect( $response->get_data()['outcome'] )->toBe( 'stored' );
	expect( $response->get_data()['storedName'] )->toBe( 'sunset.webp' );
	expect( file_get_contents( placed_dir( $path ) . '/sunset.webp' ) )->toBe( $source );

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
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
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

	// A benign nested path recreates its sub-tree under the expanded prefix and the
	// realpath of the created directory stays inside the collection root.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'trip/day1/IMG.jpg', rest_jpeg( 1000, 800 ) ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( is_file( placed_dir( $path ) . '/trip/day1/IMG.jpg.webp' ) )->toBeTrue();
	expect( realpath( placed_dir( $path ) . '/trip/day1' ) )->toStartWith( realpath( $path ) );

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Placement — the pathComponents template is expanded server-side (ADR-0014)
// ---------------------------------------------------------------------------
//
// The descriptor's mutable pathComponents template is expanded at upload time
// and prepended ahead of each file's own source-relative path:
// %year%/%month%/%day% from the upload date in the site timezone, %uploader%
// from the authenticated uploader's nicename (server-derived, never
// client-named). The whole assembled path is then Path_Guard-confined, so the
// confinement below stays load-bearing.

test( 'an upload lands under the expanded default template and its own relative path', function (): void {

	// The default template expands to <year>/<month>/<day>/<nicename> and is
	// prepended ahead of the client relative path, so the dropped-folder hierarchy
	// is preserved after the prefix (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'trip/day-1/IMG.jpg', rest_jpeg( 800, 600 ) ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( is_file( placed_dir( $path ) . '/trip/day-1/IMG.jpg.webp' ) )->toBeTrue();

	rest_remove_tree( $basedir );
} );

test( 'an upload to a collection with no folder hierarchy still nests under the template', function (): void {

	// A bare filename (no client sub-path) lands directly under the expanded
	// prefix — there is no flat-at-root placement, every Drop Zone upload nests
	// under at least the template (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'loose.jpg', rest_jpeg( 800, 600 ) ) );

	expect( is_file( placed_dir( $path ) . '/loose.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/loose.jpg.webp' ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'the date segments use the site timezone, not UTC', function (): void {

	// %year%/%month%/%day% come from the site timezone (a human-facing folder),
	// not UTC. The frozen instant is 2024-06-16 12:00 UTC; in Pacific/Kiritimati
	// (UTC+14) that is already 2024-06-17, so a UTC expansion would place the file
	// under 06/16 and a correct site-timezone expansion under 06/17 (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Pacific/Kiritimati' ) );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, '%year%/%month%/%day%' );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'IMG.jpg', rest_jpeg( 800, 600 ) ) );

	// The site-timezone date (06/17) is used, never the UTC date (06/16).
	expect( is_file( $path . '/2024/06/17/IMG.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/2024/06/16/IMG.jpg.webp' ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'the uploader segment is the server-derived nicename, not a client value', function (): void {

	// %uploader% is the authenticated user's nicename read server-side, so a
	// client cannot spoof it. The current user's nicename is `field-photographer`;
	// the client also sends a `uploader` form field, which must be ignored
	// (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 7, 'field-photographer' ) );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, '%uploader%' );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$request = rest_request( 'photos', 'IMG.jpg', rest_jpeg( 800, 600 ) );
	$request->set_param( 'uploader', 'attacker' );
	$controller->upload( $request );

	// The file lands under the server nicename, never under a client-named segment.
	expect( is_file( $path . '/field-photographer/IMG.jpg.webp' ) )->toBeTrue();
	expect( is_file( $path . '/attacker/IMG.jpg.webp' ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'the uploader segment falls back to the user id when the nicename is empty', function (): void {

	// A user whose nicename is somehow empty still yields a non-empty, safe
	// segment: the numeric id, prefixed so it reads as a folder rather than a bare
	// number (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 42, '' ) );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, '%uploader%' );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'IMG.jpg', rest_jpeg( 800, 600 ) ) );

	expect( is_file( $path . '/user-42/IMG.jpg.webp' ) )->toBeTrue();

	rest_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Confinement — the assembled (prefix + client) path is Path_Guard-confined
// ---------------------------------------------------------------------------

test( 'a hostile relativePath is rejected and writes nothing', function ( string $hostile ): void {

	// A traversal payload that would climb out of the collection root is rejected
	// by Path_Guard once the expanded prefix and the client path are assembled,
	// with nothing written anywhere — the realpath confinement is unchanged.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$sanitize = capture_route_config( $controller )['args']['relativePath']['sanitize_callback'];
	$response = $controller->upload( rest_request( 'photos', $sanitize( $hostile ), rest_jpeg( 400, 300 ) ) );

	expect( $response->get_status() )->toBe( 422 );
	expect( $response->get_data()['outcome'] )->toBe( 'rejected' );
	expect( glob( $path . '/*' ) )->toBe( [ $path . '/collection.json' ] );

	rest_remove_tree( $basedir );
} )->with( [
	'climb out of the collection root' => [ '../escape.jpg' ],
	'deep traversal'                   => [ '../../../../escape.jpg' ],
	'climb out from the prefix tail'   => [ '../../../../../../escape.jpg' ],
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
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
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
	// (the default), so the bytes are unchanged and the outcome is skipped. The
	// fixed clock keeps both uploads landing on the same expanded prefix, so the
	// second genuinely collides with the first (ADR-0014).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 1000, 800 ) ) );
	$after_first = file_get_contents( placed_dir( $path ) . '/photo.jpg.webp' );
	$second      = $controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 1200, 900 ) ) );

	expect( $second->get_status() )->toBe( 200 );
	expect( $second->get_data()['outcome'] )->toBe( 'skipped' );
	expect( file_get_contents( placed_dir( $path ) . '/photo.jpg.webp' ) )->toBe( $after_first );

	rest_remove_tree( $basedir );
} );

test( 'the handler writes main plus derived renditions but never writes the index', function (): void {

	// One upload of a wide source writes the main plus the full and thumbnail
	// renditions under the hidden directory (each at its own width), but no
	// index.json — the index self-heals on the next gallery view (ADR-0006). With
	// upload 1920, full 1280, thumbnail 640 the 2000px source's main (1920) is
	// wider than the full (1280) and the full wider than the thumbnail (640), so
	// both derived tiers are written (ADR-0013).
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1280, 80, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$path       = seed_upload_collection( $basedir, 'photos', $descriptor );
	$controller = fixed_clock_controller( new Repository() );

	$response = $controller->upload( rest_request( 'photos', 'photo.jpg', rest_jpeg( 2000, 1200 ) ) );

	// The derived renditions and the (absent) index live in the hidden directory
	// beside the main, under the expanded prefix (ADR-0014).
	$thumbs = placed_dir( $path ) . '/' . Index::THUMBNAILS_DIRNAME;
	expect( $response->get_data()['thumbnails'] )->toBe( 2 );
	expect( is_file( $thumbs . '/1280/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $thumbs . '/640/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $thumbs . '/' . Index::FILENAME ) )->toBeFalse();

	rest_remove_tree( $basedir );
} );

test( 'every per-file response outcome is one of the four legal values', function (): void {

	// Drive all four outcomes through one collection — an already-conforming WebP
	// (stored), a JPEG re-encoded to the contract (reencoded), a re-POST of the
	// stored path (skipped), and a hostile path (rejected) — and assert each
	// reported outcome is drawn from exactly the closed set the design fixes.
	$basedir    = fresh_upload_basedir();
	wire_upload_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
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
	$descriptor = new Descriptor( 'Photos', 1920, 80, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
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
