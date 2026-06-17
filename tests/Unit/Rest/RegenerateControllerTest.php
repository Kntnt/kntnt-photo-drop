<?php
/**
 * Adversarial tests for the REST regenerate controller — the manage-gated
 * re-derive write path (ADR-0013).
 *
 * The regenerate endpoint is the browser-driven half of regenerate-then-flip: it
 * re-derives a collection's full and thumbnail renditions at new widths, one
 * batch per request, then flips the descriptor and prunes the old buckets on a
 * final call. It mutates derived artifacts and the descriptor, so — like the
 * upload endpoint — it defends two independent gates: a `wp_rest` nonce (forgery)
 * and the manage capability (authorisation). The full regenerate/flip/prune
 * round-trip on a real collection is the integration suite's job against wp-env;
 * what is unit-checked here is the two gates each rejecting on their own, the
 * `manage` capability filter being honoured, and the load-bearing safety rule
 * that the endpoint refuses to touch the immutable upload contract or the slug —
 * a request carrying those is ignored, never honoured. The WordPress seams
 * (`wp_verify_nonce`, `current_user_can`, `apply_filters`, `__`, the uploads
 * helpers) are stubbed; the collection lives in a real temp directory.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Rest\Regenerate_Controller;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, stubbed WP seams, parameterised gates
// ---------------------------------------------------------------------------

/**
 * Wires every WordPress seam the regenerate controller reaches for.
 *
 * The uploads basedir is a real temp directory so the `Repository` exercises the
 * real filesystem. The two security seams are parameterised: `$nonce_ok` decides
 * `wp_verify_nonce()`, `$cap_ok` decides `current_user_can()`, so a test can fail
 * exactly one gate. `apply_filters()` honours an optional manage-capability
 * override so the `kntnt_photo_drop_manage_capability` filter can be exercised;
 * every other filter passes its value through unchanged.
 *
 * @param string      $basedir      Temp directory standing in for the uploads basedir.
 * @param bool        $nonce_ok     What `wp_verify_nonce()` should return.
 * @param bool        $cap_ok       What `current_user_can()` should return.
 * @param string|null $cap_override Value the manage-capability filter should return, or null.
 * @return void
 */
function wire_regenerate_stubs(
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
		static fn ( string $value ): string => trim( $value )
	);
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	// The nonce verifier returns a truthy tick (1) or false, matching core.
	Functions\when( 'wp_verify_nonce' )->justReturn( $nonce_ok ? 1 : false );

	// The capability check returns the parameterised verdict for whatever cap the
	// controller resolves through the filter.
	Functions\when( 'current_user_can' )->justReturn( $cap_ok );

	// Pass filters through, except an optional manage-capability override so the
	// kntnt_photo_drop_manage_capability filter can be exercised in one test.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $cap_override ): mixed {
			if ( $hook === 'kntnt_photo_drop_manage_capability' && $cap_override !== null ) {
				return $cap_override;
			}
			return $value;
		}
	);

}

/**
 * Allocates a fresh temp basedir for one regenerate-controller test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_regenerate_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-regen-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

/**
 * Removes a directory tree, used to clean up the temp uploads basedir.
 *
 * @param string $dir The directory to remove.
 */
function regenerate_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		regenerate_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Seeds a real collection on disk so a regenerate request resolves its slug.
 *
 * @param string $root The trailing-slashed collection root.
 * @param string $slug The collection slug.
 * @return string The absolute collection path.
 */
function seed_regenerate_collection( string $root, string $slug ): string {
	$path       = (string) ( new Repository() )->create_collection( $slug );
	$descriptor = new Descriptor( 'Trip', 4000, 92, 1920, 85, 640, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$descriptor->write( $path );
	return $path;
}

/**
 * Builds a regenerate request carrying a slug and the target rendition params.
 *
 * @param string              $slug    The target collection slug.
 * @param array<string,mixed> $params  Extra body params (target widths, index, finalize, …).
 * @param string|null         $nonce   A `wp_rest` nonce header, or null to omit it.
 * @return WP_REST_Request The constructed request.
 */
function regenerate_request( string $slug, array $params = [], ?string $nonce = 'tok' ): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_param( 'slug', $slug );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( $nonce !== null ) {
		$request->set_header( 'X-WP-Nonce', $nonce );
	}
	return $request;
}

// ---------------------------------------------------------------------------
// Permission gate — nonce and manage capability, each enforced alone
// ---------------------------------------------------------------------------

test( 'a missing or invalid nonce is rejected even when the capability would pass', function (): void {
	$basedir = fresh_regenerate_basedir();

	// The capability would pass, but the nonce is invalid: the forgery gate must
	// reject on its own with a 401, no matter how privileged the session is.
	wire_regenerate_stubs( $basedir, nonce_ok: false, cap_ok: true );
	$controller = new Regenerate_Controller( new Repository() );

	$verdict = $controller->check_permission( regenerate_request( 'photos', [], null ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 401 );

	regenerate_remove_tree( $basedir );
} );

test( 'a valid nonce without the manage capability is rejected', function (): void {
	$basedir = fresh_regenerate_basedir();

	// The nonce is valid but the user lacks manage_options — an editor who can place
	// a block must not be able to re-derive a collection — so the request is a 403.
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: false );
	$controller = new Regenerate_Controller( new Repository() );

	$verdict = $controller->check_permission( regenerate_request( 'photos' ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	regenerate_remove_tree( $basedir );
} );

test( 'both a valid nonce and the manage capability pass the permission gate', function (): void {
	$basedir = fresh_regenerate_basedir();

	// With both gates satisfied the permission callback returns true and WordPress
	// would invoke the handler.
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$controller = new Regenerate_Controller( new Repository() );

	$verdict = $controller->check_permission( regenerate_request( 'photos' ) );

	expect( $verdict )->toBeTrue();

	regenerate_remove_tree( $basedir );
} );

test( 'the permission gate honours the manage_capability filter', function (): void {
	$basedir = fresh_regenerate_basedir();

	// The filter narrows the required capability to a bespoke one the user lacks;
	// current_user_can() is stubbed false, so the resolved capability is rejected.
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: false, cap_override: 'manage_kntnt_photo_drop' );
	$controller = new Regenerate_Controller( new Repository() );

	$verdict = $controller->check_permission( regenerate_request( 'photos' ) );

	expect( $verdict )->toBeInstanceOf( WP_Error::class );
	expect( $verdict->get_error_data()['status'] )->toBe( 403 );

	regenerate_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Slug resolution — an unknown collection is a 404
// ---------------------------------------------------------------------------

test( 'an unknown collection slug is a 404', function (): void {
	$basedir = fresh_regenerate_basedir();
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	( new Repository() )->get_root();

	// A slug that resolves to no collection is a 404 — the handler never reaches the
	// regenerate engine.
	$controller = new Regenerate_Controller( new Repository() );
	$response   = $controller->regenerate( regenerate_request( 'ghost', [ 'index' => 0 ] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 404 );

	regenerate_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Contract immutability — the endpoint never honours an upload or slug change
// ---------------------------------------------------------------------------

test( 'the flip never alters the immutable upload contract even when the request carries it', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	$path    = seed_regenerate_collection( $root, 'frozen' );

	// A tampered request smuggles uploadWidth/uploadQuality and a different slug
	// alongside the legitimate new full/thumbnail widths, then finalises. The flip
	// must rewrite only the re-derivable pairs; the upload contract carries over from
	// the stored descriptor untouched (ADR-0013), and the slug is never a writeable
	// field — proving the endpoint cannot reconfigure the contract.
	$controller = new Regenerate_Controller( new Repository() );
	$controller->regenerate( regenerate_request( 'frozen', [
		'fullWidth'        => 1280,
		'fullQuality'      => 80,
		'thumbnailWidth'   => 320,
		'thumbnailQuality' => 60,
		'uploadWidth'      => 100,
		'uploadQuality'    => 10,
		'slug'             => 'frozen',
		'newSlug'          => 'hijacked',
		'finalize'         => true,
	] ) );

	// The descriptor on disk: upload pair preserved at 4000/92, full/thumbnail flipped
	// to the new widths, and no collection renamed.
	$descriptor = Descriptor::read( $path );
	expect( $descriptor->upload_width )->toBe( 4000 );
	expect( $descriptor->upload_quality )->toBe( 92 );
	expect( $descriptor->full_width )->toBe( 1280 );
	expect( $descriptor->thumbnail_width )->toBe( 320 );
	expect( is_dir( $root . 'hijacked' ) )->toBeFalse();

	regenerate_remove_tree( $basedir );
} );

test( 'a malformed target width is rejected as a 400 and the descriptor is untouched', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path   = seed_regenerate_collection( $root, 'bad-target' );
	$before = file_get_contents( $path . '/' . Descriptor::FILENAME );

	// A non-positive full width is not a re-derivable target; the finalise is refused
	// with a 400 and the descriptor is left byte-identical, so a bad request can never
	// flip the collection to a degenerate width.
	$controller = new Regenerate_Controller( new Repository() );
	$response   = $controller->regenerate( regenerate_request( 'bad-target', [
		'fullWidth'        => 0,
		'fullQuality'      => 80,
		'thumbnailWidth'   => 320,
		'thumbnailQuality' => 60,
		'finalize'         => true,
	] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
	expect( file_get_contents( $path . '/' . Descriptor::FILENAME ) )->toBe( $before );

	regenerate_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Collapse-to-parent — an unset re-derivable field flips to JSON null (#71)
// ---------------------------------------------------------------------------

test( 'a finalise with an unset full width flips the descriptor to null, not a concrete width', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path = seed_regenerate_collection( $root, 'unset-full' );

	// An emptied Full width arrives over the wire as JSON null (the Edit page's
	// collapse-to-parent state, ADR-0013/#71). The empty collection finalises
	// vacuously; the flip must persist fullWidth as null — "no separate full, the main
	// serves" — never freeze it to a concrete value.
	$controller = new Regenerate_Controller( new Repository() );
	$controller->regenerate( regenerate_request( 'unset-full', [
		'fullWidth'        => null,
		'fullQuality'      => 80,
		'thumbnailWidth'   => 320,
		'thumbnailQuality' => 60,
		'finalize'         => true,
	] ) );

	$descriptor = Descriptor::read( $path );
	expect( $descriptor->full_width )->toBeNull();
	expect( $descriptor->full_quality )->toBe( 80 );
	expect( $descriptor->thumbnail_width )->toBe( 320 );

	regenerate_remove_tree( $basedir );
} );

test( 'a finalise with an unset full quality flips to null, not the degenerate concrete 0', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path = seed_regenerate_collection( $root, 'unset-fq' );

	// An emptied Full quality must persist as null (follow the upload quality,
	// ADR-0013/#71), never as a concrete 0 that would freeze a degenerate quality on
	// disk — the precise failure the nullable-field reader warns against.
	$controller = new Regenerate_Controller( new Repository() );
	$controller->regenerate( regenerate_request( 'unset-fq', [
		'fullWidth'        => 1280,
		'fullQuality'      => null,
		'thumbnailWidth'   => 320,
		'thumbnailQuality' => 60,
		'finalize'         => true,
	] ) );

	$descriptor = Descriptor::read( $path );
	expect( $descriptor->full_quality )->toBeNull();
	expect( $descriptor->full_width )->toBe( 1280 );

	regenerate_remove_tree( $basedir );
} );

test( 'a finalise with an unset thumbnail width flips it to null (follows the full width)', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path = seed_regenerate_collection( $root, 'unset-tw' );

	// An emptied Thumbnail width must persist as null (follow the effective full width,
	// ADR-0013/#71), not a concrete value.
	$controller = new Regenerate_Controller( new Repository() );
	$controller->regenerate( regenerate_request( 'unset-tw', [
		'fullWidth'        => 1280,
		'fullQuality'      => 80,
		'thumbnailWidth'   => null,
		'thumbnailQuality' => 60,
		'finalize'         => true,
	] ) );

	$descriptor = Descriptor::read( $path );
	expect( $descriptor->thumbnail_width )->toBeNull();
	expect( $descriptor->full_width )->toBe( 1280 );

	regenerate_remove_tree( $basedir );
} );

test( 'all three re-derivable fields unset together flip to null in one finalise', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path = seed_regenerate_collection( $root, 'all-unset' );

	// Clearing Full width, Full quality, and Thumbnail width together (the maximal
	// collapse) must persist all three as null while the always-concrete thumbnail
	// quality and the immutable upload pair carry over.
	$controller = new Regenerate_Controller( new Repository() );
	$controller->regenerate( regenerate_request( 'all-unset', [
		'fullWidth'        => null,
		'fullQuality'      => null,
		'thumbnailWidth'   => null,
		'thumbnailQuality' => 70,
		'finalize'         => true,
	] ) );

	$descriptor = Descriptor::read( $path );
	expect( $descriptor->full_width )->toBeNull();
	expect( $descriptor->full_quality )->toBeNull();
	expect( $descriptor->thumbnail_width )->toBeNull();
	expect( $descriptor->thumbnail_quality )->toBe( 70 );
	expect( $descriptor->upload_width )->toBe( 4000 );
	expect( $descriptor->upload_quality )->toBe( 92 );

	regenerate_remove_tree( $basedir );
} );

test( 'a non-null malformed width is still rejected as a 400, distinct from an unset null', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path   = seed_regenerate_collection( $root, 'still-malformed' );
	$before = file_get_contents( $path . '/' . Descriptor::FILENAME );

	// A present-but-garbage width (a non-numeric string) is malformed, not "unset": it
	// must still be a 400 with the descriptor byte-identical, so accepting null as unset
	// does not open the door to a degenerate flip.
	$controller = new Regenerate_Controller( new Repository() );
	$response   = $controller->regenerate( regenerate_request( 'still-malformed', [
		'fullWidth'        => 'wide',
		'fullQuality'      => 80,
		'thumbnailWidth'   => 320,
		'thumbnailQuality' => 60,
		'finalize'         => true,
	] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
	expect( file_get_contents( $path . '/' . Descriptor::FILENAME ) )->toBe( $before );

	regenerate_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Per-image failure propagation — a shortfall is a 500, never a silent 200
// ---------------------------------------------------------------------------

test( 'a batch whose main cannot produce its renditions is a 500, not a silent 200', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path = seed_regenerate_collection( $root, 'shortfall' );

	// Plant a stored main that cannot be decoded, so the re-derive writes none of the
	// renditions the target widths require — exactly the per-image failure the count-only
	// reply used to swallow as a 200.
	file_put_contents( $path . '/broken.jpg.webp', 'this is not an image at all' );

	// A batch re-derive of that main must surface a 500 (an image could not be
	// regenerated), so the browser driver stops before the finalise rather than flipping.
	$controller = new Regenerate_Controller( new Repository() );
	$response   = $controller->regenerate( regenerate_request( 'shortfall', [
		'fullWidth'        => 800,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 300,
		'thumbnailQuality' => 75,
		'index'            => 0,
	] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 500 );

	regenerate_remove_tree( $basedir );
} );

test( 'a finalise refuses to flip when a main is incomplete on disk', function (): void {
	$basedir = fresh_regenerate_basedir();
	$root    = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';
	wire_regenerate_stubs( $basedir, nonce_ok: true, cap_ok: true );
	$path   = seed_regenerate_collection( $root, 'incomplete' );
	$before = file_get_contents( $path . '/' . Descriptor::FILENAME );

	// An undecodable main means its expected new renditions can never be on disk; a
	// direct finalise (defence in depth, bypassing the per-batch check) must be refused
	// with a 500 and leave the descriptor byte-identical — the flip is gated on the whole
	// collection being complete (ADR-0013, "flip only on success").
	file_put_contents( $path . '/broken.jpg.webp', 'this is not an image at all' );

	$controller = new Regenerate_Controller( new Repository() );
	$response   = $controller->regenerate( regenerate_request( 'incomplete', [
		'fullWidth'        => 800,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 300,
		'thumbnailQuality' => 75,
		'finalize'         => true,
	] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 500 );
	expect( file_get_contents( $path . '/' . Descriptor::FILENAME ) )->toBe( $before );

	regenerate_remove_tree( $basedir );
} );
