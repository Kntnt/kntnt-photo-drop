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

	// Record the source-identity stamp the 201 path writes so a test can assert it,
	// and keep get_posts harmless by default (the real find_existing is overridden
	// by the recording controller; this guards any unmocked call).
	$GLOBALS['kntnt_stamped_meta'] = [];
	Functions\when( 'update_post_meta' )->alias(
		static function ( int $id, string $key, mixed $value ): bool {
			$GLOBALS['kntnt_stamped_meta'][ $key ] = $value;
			return true;
		}
	);
	Functions\when( 'get_posts' )->justReturn( [] );

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
 * Builds a media controller whose sideload and dedup seams are recording stubs.
 *
 * The real controller drives `media_handle_sideload`, `get_posts`, and the
 * in-place file replacement; the integration suite exercises those for real.
 * Here the protected seams are overridden so a unit test can assert the
 * controller's resolution, confinement, and dedup branching without a Media
 * Library — never mocking the logic under test into a no-op. `sideload()` records
 * the absolute main-image path it was handed and returns a fixed id; `find_existing()`
 * returns a configurable id-or-null so a test can simulate "no duplicate" vs
 * "duplicate exists"; `replace()` records the call and returns the same id.
 *
 * @param Repository $repository The collection read/resolve side.
 * @param int        $id         The attachment id the sideload stub returns (default 4242).
 * @param int|null   $existing   The verdict find_existing() returns (default null — no duplicate).
 * @return Media_Controller A controller recording its seam calls in `$GLOBALS`.
 */
function recording_media_controller(
	Repository $repository,
	int $id = 4242,
	?int $existing = null
): Media_Controller {
	$GLOBALS['kntnt_sideloaded_path'] = null;
	$GLOBALS['kntnt_replaced']        = null;
	return new class( $repository, $id, $existing ) extends Media_Controller {

		/**
		 * Constructs the recording controller.
		 *
		 * @param Repository $repository The collection read/resolve side.
		 * @param int        $id         The attachment id to return from the sideload stub.
		 * @param int|null   $existing   The verdict find_existing() returns.
		 */
		public function __construct(
			Repository $repository,
			private int $id,
			private ?int $existing
		) {
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

		/**
		 * Returns the configured dedup verdict without touching a Media Library.
		 *
		 * @param string $slug     Ignored.
		 * @param string $relative Ignored.
		 * @return int|null The configured existing-attachment id, or null.
		 */
		protected function find_existing( string $slug, string $relative ): ?int {
			return $this->existing;
		}

		/**
		 * Records the overwrite call and returns the same attachment id.
		 *
		 * @param int    $attachment_id The existing attachment to overwrite.
		 * @param string $main_path     The absolute path to the confined main image.
		 * @return int|\WP_Error The same attachment id.
		 */
		protected function replace( int $attachment_id, string $main_path ): int|\WP_Error {
			$GLOBALS['kntnt_replaced'] = [ $attachment_id, $main_path ];
			return $attachment_id;
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
 * @param string $slug      The collection slug route param.
 * @param string $path      The collection-relative path body param.
 * @param string $nonce     The nonce to put in the X-WP-Nonce header.
 * @param bool   $overwrite Whether to set the overwrite body param.
 * @return WP_REST_Request The assembled request.
 */
function media_request(
	string $slug,
	string $path,
	string $nonce = 'valid-nonce',
	bool $overwrite = false
): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_header( 'X-WP-Nonce', $nonce );
	$request->set_param( 'slug', $slug );
	$request->set_param( 'path', $path );
	if ( $overwrite ) {
		$request->set_param( 'overwrite', true );
	}
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

test(
	'a confined path naming a thumbnail is rejected as not-a-main with nothing sideloaded',
	function (): void {

		// A thumbnail lives under the hidden `kntnt-thumbnails/<width>/` tree and is a
		// derived artifact, not the main image (ADR-0013/0015). Its path confines fine
		// and its basename ends in `.webp`, so only the thumbnails-segment guard stops
		// it: the controller must reject it as 404 and never sideload a derived file.
		$basedir    = fresh_media_basedir();
		wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
		$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
		write_media_main( $path, 'a.jpg.webp' );
		write_media_main( $path, 'kntnt-thumbnails/320/a.jpg.webp' );
		$controller = recording_media_controller( new Repository() );

		$response = $controller->add_to_media( media_request( 'photos', 'kntnt-thumbnails/320/a.jpg.webp' ) );

		expect( $response )->toBeInstanceOf( WP_Error::class );
		expect( $response->get_error_data()['status'] )->toBe( 404 );
		expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

		media_remove_tree( $basedir );
	} );

test(
	'a confined path naming the collection descriptor is rejected as not-a-main with nothing sideloaded',
	function (): void {

		// `collection.json` is the visible descriptor (ADR-0003), the one irreplaceable
		// plugin file — never an image. It confines fine and is_file() accepts it, so
		// without a main-image gate the controller would copy the descriptor into the
		// Media Library. It must reject it as 404 and sideload nothing.
		$basedir    = fresh_media_basedir();
		wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
		$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
		write_media_main( $path, 'a.jpg.webp' );
		$controller = recording_media_controller( new Repository() );

		$response = $controller->add_to_media( media_request( 'photos', 'collection.json' ) );

		expect( $response )->toBeInstanceOf( WP_Error::class );
		expect( $response->get_error_data()['status'] )->toBe( 404 );
		expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

		media_remove_tree( $basedir );
	} );

test(
	'a confined path naming a foreign non-webp file is rejected as not-a-main with nothing sideloaded',
	function (): void {

		// A foreign file dropped inside the collection root (here a plain `.txt`) is not
		// a conforming main image. It confines fine and is_file() accepts it, so the
		// main-image gate is what must stop it: reject as 404 and sideload nothing,
		// so an in-collection foreign file can never reach the Media Library.
		$basedir    = fresh_media_basedir();
		wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
		$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
		write_media_main( $path, 'a.jpg.webp' );
		file_put_contents( rtrim( $path, '/' ) . '/notes.txt', 'not an image' );
		$controller = recording_media_controller( new Repository() );

		$response = $controller->add_to_media( media_request( 'photos', 'notes.txt' ) );

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

// ---------------------------------------------------------------------------
// De-duplication and replace-in-place overwrite (ADR-0015 amendment)
// ---------------------------------------------------------------------------

test( 'a fresh add stamps the source identity and returns 201', function (): void {

	// With no prior copy (find_existing → null), the controller sideloads and stamps
	// the attachment with its source identity (collection slug + collection-relative
	// path) so a future add of the same image can be recognised.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	write_media_main( $path, '2026/06/15/jane/IMG.jpg.webp' );
	$controller = recording_media_controller( new Repository(), id: 555, existing: null );

	$response = $controller->add_to_media( media_request( 'photos', '2026/06/15/jane/IMG.jpg.webp' ) );

	expect( $response->get_status() )->toBe( 201 );
	expect( $response->get_data()['id'] )->toBe( 555 );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->not->toBeNull();
	expect( $GLOBALS['kntnt_stamped_meta']['_kntnt_photo_drop_collection'] )->toBe( 'photos' );
	expect( $GLOBALS['kntnt_stamped_meta']['_kntnt_photo_drop_path'] )->toBe( '2026/06/15/jane/IMG.jpg.webp' );

	media_remove_tree( $basedir );
} );

test( 'a duplicate without overwrite is a 409 carrying the existing id, nothing sideloaded', function (): void {

	// A prior copy exists (find_existing → 99) and the caller did not ask to
	// overwrite: the controller answers 409 with the existing id in the error data
	// so the gallery can raise its overwrite confirm, and copies nothing.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	write_media_main( $path, 'a.jpg.webp' );
	$controller = recording_media_controller( new Repository(), existing: 99 );

	$response = $controller->add_to_media( media_request( 'photos', 'a.jpg.webp' ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 409 );
	expect( $response->get_error_data()['id'] )->toBe( 99 );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();
	expect( $GLOBALS['kntnt_replaced'] )->toBeNull();

	media_remove_tree( $basedir );
} );

test( 'a duplicate with overwrite replaces in place and returns 200 with the same id', function (): void {

	// A prior copy exists (find_existing → 99) and the caller confirmed overwrite:
	// the controller replaces the existing attachment in place — same id — and
	// answers 200, never sideloading a fresh copy.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	$main       = write_media_main( $path, 'a.jpg.webp' );
	$controller = recording_media_controller( new Repository(), existing: 99 );

	$response = $controller->add_to_media( media_request( 'photos', 'a.jpg.webp', overwrite: true ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data()['id'] )->toBe( 99 );
	expect( $GLOBALS['kntnt_replaced'][0] )->toBe( 99 );
	expect( realpath( $GLOBALS['kntnt_replaced'][1] ) )->toBe( realpath( $main ) );
	expect( $GLOBALS['kntnt_sideloaded_path'] )->toBeNull();

	media_remove_tree( $basedir );
} );

test( 'a failed overwrite surfaces as a 500', function (): void {

	// When the in-place replacement itself fails (a WP_Error from replace()), the
	// controller answers 500 — never a 200 over an overwrite that did not happen.
	$basedir    = fresh_media_basedir();
	wire_media_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path       = seed_media_collection( $basedir, 'photos', media_descriptor() );
	write_media_main( $path, 'a.jpg.webp' );
	$controller = new class( new Repository() ) extends Media_Controller {

		/**
		 * Reports a prior copy so the overwrite branch is taken.
		 *
		 * @param string $slug     Ignored.
		 * @param string $relative Ignored.
		 * @return int|null A fixed existing id.
		 */
		protected function find_existing( string $slug, string $relative ): ?int {
			return 99;
		}

		/**
		 * Returns the failure a broken in-place replacement would.
		 *
		 * @param int    $attachment_id Ignored.
		 * @param string $main_path     Ignored.
		 * @return int|\WP_Error Always a WP_Error.
		 */
		protected function replace( int $attachment_id, string $main_path ): int|\WP_Error {
			return new \WP_Error( 'replace_failed', 'Boom.' );
		}
	};

	$response = $controller->add_to_media( media_request( 'photos', 'a.jpg.webp', overwrite: true ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 500 );

	media_remove_tree( $basedir );
} );
