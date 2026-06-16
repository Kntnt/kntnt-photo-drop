<?php
/**
 * Adversarial tests for the gallery add-to-media controller — the second REST
 * write-path (ADR-0015).
 *
 * The gallery's read path is pure SSR; this controller is the only write surface
 * it exposes, so the suite is hostile by design. It drives the real controller
 * against a real temp-dir collection holding a real WebP main, stubbing only the
 * WordPress seams (`wp_verify_nonce`, `current_user_can`, `apply_filters`,
 * `sanitize_text_field`, `__`, and the uploads-root helpers) and the sideload
 * itself (a protected seam — the real `media_handle_sideload` is exercised by the
 * integration suite, never mocked into a tautology here). It proves the two
 * independent gates (nonce, capability) each reject on their own and read the
 * `add_to_media` capability through its filter, that a `path` escaping the
 * collection root is rejected with nothing sideloaded, that a `path` resolving to
 * no main file is a 404, and that a valid `path` resolves to the main image and
 * hands exactly that file to the sideload.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.12.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Rest\Media_Controller;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, real WebP mains, stubbed WP seams
// ---------------------------------------------------------------------------

/**
 * Wires every WordPress seam the media controller reaches for.
 *
 * The uploads basedir is a real temp directory so the `Repository` and
 * `Path_Guard` exercise the real filesystem. The two security seams are
 * parameterised: `$nonce_ok` decides `wp_verify_nonce()` and `$cap_ok`
 * `current_user_can()`, so a test can fail exactly one gate. `apply_filters()`
 * honours an optional capability override so the
 * `kntnt_photo_drop_add_to_media_capability` filter can be exercised; every
 * other filter passes its value through unchanged.
 *
 * @param string      $basedir      Temp directory standing in for the uploads basedir.
 * @param bool        $nonce_ok     What `wp_verify_nonce()` should return.
 * @param bool        $cap_ok       What `current_user_can()` should return for the resolved cap.
 * @param string|null $cap_override Value the capability filter should return, or null to leave the default.
 * @return void
 */
function wire_media_stubs(
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

	// The nonce verifier returns a truthy tick (1) or false, matching core.
	Functions\when( 'wp_verify_nonce' )->justReturn( $nonce_ok ? 1 : false );

	// The capability check returns the parameterised verdict for whatever cap the
	// controller resolves through the filter.
	Functions\when( 'current_user_can' )->justReturn( $cap_ok );

	// Pass filters through, except an optional capability override so the
	// kntnt_photo_drop_add_to_media_capability filter can be exercised.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $cap_override ): mixed {
			if ( $hook === 'kntnt_photo_drop_add_to_media_capability' && $cap_override !== null ) {
				return $cap_override;
			}
			return $value;
		}
	);

}

/**
 * Builds a media controller whose sideload is a recording stub, not a real copy.
 *
 * The real controller drives `media_handle_sideload`; the integration suite
 * exercises that for real. Here the protected `sideload()` seam is overridden to
 * record the absolute main-image path it was handed and return a fixed
 * attachment id, so a unit test can assert the controller resolves and confines
 * the path to the right main file without a Media Library — never mocking the
 * resolution-and-confinement under test into a no-op.
 *
 * @param Repository $repository The collection read/resolve side.
 * @param int        $id         The attachment id the stub returns (default 4242).
 * @return Media_Controller A controller recording the sideloaded path in `$GLOBALS`.
 */
function recording_media_controller( Repository $repository, int $id = 4242 ): Media_Controller {
	$GLOBALS['kntnt_sideloaded_path'] = null;
	return new class( $repository, $id ) extends Media_Controller {

		/**
		 * Constructs the recording controller.
		 *
		 * @param Repository $repository The collection read/resolve side.
		 * @param int        $id         The attachment id to return from the stub.
		 */
		public function __construct( Repository $repository, private int $id ) {
			parent::__construct( $repository );
		}

		/**
		 * Records the main-image path and returns a fixed attachment id.
		 *
		 * @param string $main_path The absolute path to the confined main image.
		 * @return int|\WP_Error The fixed attachment id.
		 */
		protected function sideload( string $main_path ): int|\WP_Error {
			$GLOBALS['kntnt_sideloaded_path'] = $main_path;
			return $this->id;
		}
	};
}

/**
 * Creates a real collection on disk under the temp uploads root.
 *
 * @param string     $basedir    The temp uploads basedir.
 * @param string     $slug       The collection slug.
 * @param Descriptor $descriptor The contract to persist.
 * @return string The absolute collection directory path.
 */
function seed_media_collection( string $basedir, string $slug, Descriptor $descriptor ): string {
	$path = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/' . $slug;
	mkdir( $path, 0700, true );
	$descriptor->write( $path );
	return $path;
}

/**
 * Writes a real WebP main image at a collection-relative path.
 *
 * @param string $collection_path The absolute collection root.
 * @param string $relative_path   The image path relative to the root.
 * @return string The absolute path of the written main.
 */
function write_media_main( string $collection_path, string $relative_path ): string {
	$absolute = rtrim( $collection_path, '/' ) . '/' . $relative_path;
	$dir      = dirname( $absolute );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	$image = imagecreatetruecolor( 80, 60 );
	imagewebp( $image, $absolute );
	return $absolute;
}

/**
 * Allocates a fresh temp directory standing in for the uploads basedir.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_media_basedir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-media-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Builds a REST request carrying a nonce header and a JSON `path` body param.
 *
 * Mirrors the JSON shape the gallery view module POSTs: the `X-WP-Nonce` header,
 * the slug route param, and the collection-relative `path` of the image to copy.
 *
 * @param string $slug  The collection slug route param.
 * @param string $path  The collection-relative path body param.
 * @param string $nonce The nonce to put in the X-WP-Nonce header.
 * @return WP_REST_Request The assembled request.
 */
function media_request( string $slug, string $path, string $nonce = 'valid-nonce' ): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_param( 'slug', $slug );
	$request->set_param( 'path', $path );
	return $request;
}

/**
 * Captures the route configuration that register_routes() registers.
 *
 * @param Media_Controller $controller The controller whose route to capture.
 * @return array<string, mixed> The captured route configuration.
 */
function capture_media_route_config( Media_Controller $controller ): array {

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
function media_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		media_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds the descriptor the media tests seed (the contract is not load-bearing here).
 *
 * @return Descriptor A typical three-rendition descriptor.
 */
function media_descriptor(): Descriptor {
	return new Descriptor( 'Photos', 1920, 80, 1280, 80, 320, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
}

// ---------------------------------------------------------------------------
// The nonce gate (CSRF) — independent of capability
// ---------------------------------------------------------------------------

test( 'a missing or invalid nonce is rejected even when the capability would pass', function (): void {

	// The capability gate would pass, but the nonce verifier says no: the request
	// must still be rejected as 401, proving the nonce is enforced on its own.
	$basedir = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: false, cap_ok: true );
	$controller = new Media_Controller( new Repository() );

	$verdict = $controller->check_permission( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 401 );

	media_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The capability gate (authorisation) — independent of nonce
// ---------------------------------------------------------------------------

test( 'a valid nonce without the add_to_media capability is rejected', function (): void {

	// The nonce is valid but the user lacks the capability — a subscriber with a
	// valid nonce — so the request is rejected as 403.
	$basedir = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: false );
	$controller = new Media_Controller( new Repository() );

	$verdict = $controller->check_permission( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	media_remove_tree( $basedir );
} );

test( 'both a valid nonce and the capability pass the permission gate', function (): void {

	// Both gates satisfied: the permission callback returns true, so WordPress
	// would invoke the handler.
	$basedir = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$controller = new Media_Controller( new Repository() );

	$verdict = $controller->check_permission( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $verdict )->toBeTrue();

	media_remove_tree( $basedir );
} );

test( 'the capability gate honours the add_to_media_capability filter', function (): void {

	// The filter narrows the required capability to manage_options. current_user_can
	// is stubbed false (the user is not a manager), so the gate must reject — proving
	// the controller checks the filtered capability, not the hard default.
	$basedir = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: false, cap_override: 'manage_options' );
	$controller = new Media_Controller( new Repository() );

	$verdict = $controller->check_permission( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	media_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Path confinement — the add-to-media target is Path_Guard-confined
// ---------------------------------------------------------------------------

test( 'a hostile path is rejected with nothing sideloaded', function ( string $hostile ): void {

	// Every hostile target is rejected as 422 with the sideload never reached — the
	// realpath confinement that guards the upload path guards this write-path too.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	write_media_main( $path, 'a.jpg.webp' );
	$controller = recording_media_controller( new Repository() );

	$response = $controller->add_to_media( media_request( 'photos', $hostile ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 422 );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

	media_remove_tree( $basedir );
} )->with( [
	'parent traversal'   => [ '../escape.webp' ],
	'deep traversal'     => [ '../../../../etc/passwd' ],
	'encoded traversal'  => [ '%2e%2e%2fescape.webp' ],
	'double-encoded'     => [ '%252e%252e%252fescape.webp' ],
	'absolute path'      => [ '/etc/passwd' ],
	'embedded traversal' => [ 'a/../../b.webp' ],
	'nul byte'           => [ "ok\x00/../escape.webp" ],
] );

// ---------------------------------------------------------------------------
// Target resolution — a path resolves to the main image, or 404
// ---------------------------------------------------------------------------

test( 'a path resolving to no file is a 404 with nothing sideloaded', function (): void {

	// A confined but non-existent path names no main image, so the controller answers
	// 404 and never sideloads — the target must be a real main on disk.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	seed_media_collection( $basedir, 'photos', media_descriptor() );
	$controller = recording_media_controller( new Repository() );

	$response = $controller->add_to_media( media_request( 'photos', 'ghost.jpg.webp' ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 404 );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

	media_remove_tree( $basedir );
} );

test( 'an unknown collection slug is a 404 with nothing sideloaded', function (): void {

	// No collection is seeded, so the slug does not resolve; the handler answers 404
	// and never sideloads.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$controller = recording_media_controller( new Repository() );

	$response = $controller->add_to_media( media_request( 'ghost', 'a.jpg.webp' ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 404 );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

	media_remove_tree( $basedir );
} );

test( 'a valid nested path resolves to the confined main image and hands it to the sideload', function (): void {

	// A benign nested path resolves to the real main under the collection root, and
	// the controller hands exactly that absolute file to the sideload, answering 201
	// with the created attachment id.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	$main       = write_media_main( $path, '2026/06/15/jane/IMG_2024.jpg.webp' );
	$controller = recording_media_controller( new Repository(), id: 777 );

	$response = $controller->add_to_media( media_request( 'photos', '2026/06/15/jane/IMG_2024.jpg.webp' ) );

	expect( $response->get_status() )->toBe( 201 );
	expect( $response->get_data()['id'] )->toBe( 777 );
	expect( realpath( $GLOBALS['kntnt_sideloaded_path'] ) )->toBe( realpath( $main ) );

	media_remove_tree( $basedir );
} );

test( 'the path body param sanitiser is a pure type gate that preserves raw bytes', function (): void {

	// The registered callback must pass strings through verbatim — %xx sequences and
	// NUL bytes intact, so Path_Guard (the real sanitiser) sees the raw bytes — and
	// normalise non-strings to the empty string. The slug keeps the strict
	// text-field sanitiser: it addresses a directory, never carries a filename.
	$basedir = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$config = capture_media_route_config( new Media_Controller( new Repository() ) );

	$sanitize = $config['args']['path']['sanitize_callback'];
	expect( $sanitize( '%2e%2e%2fescape.webp' ) )->toBe( '%2e%2e%2fescape.webp' );
	expect( $sanitize( "nul\x00byte.webp" ) )->toBe( "nul\x00byte.webp" );
	expect( $sanitize( [ 'not', 'a', 'string' ] ) )->toBe( '' );
	expect( $config['args']['slug']['sanitize_callback'] )->toBe( 'sanitize_text_field' );

	media_remove_tree( $basedir );
} );

test( 'a failed sideload surfaces as a 500 rather than a phantom success', function (): void {

	// When the Media Library import itself fails (a WP_Error from media_handle_sideload),
	// the controller answers 500 — never a 201 over a copy that did not happen.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	write_media_main( $path, 'a.jpg.webp' );
	$controller = new class( new Repository() ) extends Media_Controller {

		/**
		 * Returns the failure a broken Media Library import would.
		 *
		 * @param string $main_path Ignored.
		 * @return int|\WP_Error Always a WP_Error.
		 */
		protected function sideload( string $main_path ): int|\WP_Error {
			return new \WP_Error( 'sideload_failed', 'Boom.' );
		}
	};

	$response = $controller->add_to_media( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 500 );

	media_remove_tree( $basedir );
} );
