<?php
/**
 * The gallery's trash REST write-path — a permanent delete of a collection image.
 *
 * The gallery's read path is pure SSR; the trash overlay is its only destructive
 * surface, so this is the gallery's one *destructive* REST write-path (ADR-0015).
 * It is gated the same way the Drop Zone upload and the add-to-media copy are: a
 * valid `wp_rest` nonce (stops CSRF) *and* the `delete` capability
 * (`delete_others_posts` by default — the closest core analog to "can delete
 * others' media in the Library" — filter `kntnt_photo_drop_delete_capability`).
 * The request body carries the image's **collection-relative `path`** (the gallery
 * mirrors it onto the anchor as `data-kntnt-photo-drop-path`, shared with the
 * add-to-media write-path); the server hard-sanitises and `Path_Guard`-confines
 * that path to the collection root, then resolves it to the **main image** on
 * disk. A path escaping the root, or one naming no main, is rejected with nothing
 * deleted.
 *
 * On success the main image **and every derived artifact** slaved to it (the full
 * rendition, the thumbnail) are permanently removed, reusing the shared
 * `Image_Deleter` — the same deletion routine behind the CLI `image delete` verb,
 * so the two never drift. There is no recycle bin (ADR-0015): collection images
 * are plain files, not posts, so the inline-popover confirm in the view module is
 * the only safety. The per-folder index self-heals on the next gallery render
 * (unlinking the main bumps the folder mtime). The reply is `200 { deleted: true }`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Image_Deleter;
use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;

/**
 * Registers and serves the collection image-delete endpoint.
 *
 * Constructed once by `Plugin` and bound to `rest_api_init`. The only injected
 * state is the collection `Repository`, which resolves a slug to its on-disk
 * path; the per-request work — confining the path, resolving the main, removing
 * it and its derived artifacts — is hidden behind two callbacks
 * (`check_permission()` and `delete_image()`) wired to one DELETE route.
 * Deliberately not `final`: the protected `delete_main()` factory is the seam a
 * unit test overrides to exercise the resolution-and-confinement logic without
 * touching disk (the real removal is covered by the `Image_Deleter` unit suite
 * and the integration suite). Mirrors `Media_Controller`'s shape — the two
 * gallery write-paths read the same anchor `path`, confine it the same way, and
 * apply the same main-image gate, differing only in capability and effect.
 *
 * @since 0.13.0
 */
class Images_Controller {

	/**
	 * The REST namespace under which the images route is registered.
	 *
	 * Shared with the upload, list, and media endpoints so the whole plugin surface
	 * lives under one versioned namespace.
	 *
	 * @since 0.13.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-photo-drop/v1';

	/**
	 * The route pattern, capturing the collection slug as a named group.
	 *
	 * `/images` is the same segment the Drop Zone upload POSTs to; the HTTP method
	 * disambiguates — POST uploads, DELETE removes — so the gallery's destructive
	 * write-path and the Drop Zone's ingest share one resource path. The slug
	 * character class is permissive at the router level; the real slug validation is
	 * the `Repository`'s strict lexical gate, applied when the slug is resolved to a
	 * path, so a syntactically matched-but-invalid slug simply fails to resolve and
	 * yields a 404.
	 *
	 * @since 0.13.0
	 * @var string
	 */
	private const ROUTE = '/collections/(?P<slug>[a-zA-Z0-9._-]+)/images';

	/**
	 * The default capability required to permanently delete a collection image.
	 *
	 * `delete_others_posts` (ADR-0015): the closest core analog to "can delete
	 * others' media in the Library". The `kntnt_photo_drop_delete_capability` filter
	 * lets a site narrow or widen it.
	 *
	 * @since 0.13.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'delete_others_posts';

	/**
	 * The JSON body field carrying the image's collection-relative path.
	 *
	 * @since 0.13.0
	 * @var string
	 */
	private const PATH_PARAM = 'path';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at a
	 * temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.13.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * Registers the `collections/<slug>/images` DELETE route.
	 *
	 * Hooked on `rest_api_init`. The route accepts a JSON DELETE carrying the
	 * collection-relative `path` of the image to remove, runs the two-gate permission
	 * check, and dispatches to `delete_image()`. It shares the `/images` resource path
	 * with the Drop Zone upload (POST), distinguished by the HTTP method.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_image' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'slug'           => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					self::PATH_PARAM => [
						'type'              => 'string',
						'required'          => true,
						// A type gate only, deliberately not sanitize_text_field:
						// that would strip %xx sequences before Path_Guard — the
						// real sanitiser, which rejects NUL/control/traversal/
						// schemes and realpath-confines the target — ever saw the
						// raw bytes.
						'sanitize_callback' => static fn ( mixed $value ): string => is_string( $value ) ? $value : '',
					],
				],
			]
		);

	}

	/**
	 * Enforces the two independent gates before the handler runs.
	 *
	 * Both gates defend different things and both must pass (ADR-0015, mirroring
	 * ADR-0006). First the `wp_rest` nonce, read from the `X-WP-Nonce` header or the
	 * `_wpnonce` parameter, stops cross-site forgery: a missing or invalid nonce is a
	 * 401 no matter how privileged the session — a vital guard for a destructive
	 * action. Then the capability — `delete_others_posts` by default, overridable via
	 * `kntnt_photo_drop_delete_capability` — stops the wrong people: a logged-in but
	 * un-capable user (an uploader who can add but not delete others' media) is a 403.
	 * Only when both pass does WordPress invoke `delete_image()`.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return true|\WP_Error True when both gates pass, or a WP_Error carrying 401/403.
	 */
	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {

		// Verify the anti-forgery nonce first; a forged or absent nonce is rejected
		// as 401 regardless of who the session belongs to. The nonce may ride in the
		// canonical `X-WP-Nonce` header or, as a fallback, the `_wpnonce` param.
		$nonce = $this->read_nonce( $request );
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			$message = __( 'Your session could not be verified. Please reload and try again.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_invalid_nonce', $message, [ 'status' => 401 ] );
		}

		// Then the authorisation gate: resolve the required capability through the
		// filter and reject anyone who lacks it as 403. The nonce proves the request
		// is genuine; this proves the requester is allowed to delete a gallery image.
		if ( ! current_user_can( self::required_capability() ) ) {
			$message = __( 'You are not allowed to delete images from this collection.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_forbidden', $message, [ 'status' => 403 ] );
		}

		return true;

	}

	/**
	 * Permanently deletes one collection main image and its derived artifacts.
	 *
	 * Resolves the slug to a collection (404 when unknown), confines the
	 * caller-supplied `path` to the collection root with `Path_Guard` (422 when it
	 * escapes, is malformed, or carries traversal/scheme/NUL), resolves it to a real
	 * file on disk (404 when none is there), and verifies that file is a **main
	 * image** rather than a derived thumbnail, the descriptor, or a foreign
	 * in-collection file (404 when it is not — confinement alone would otherwise let
	 * a `kntnt-thumbnails/<width>/…` thumbnail or `collection.json` be deleted). Only
	 * then is the confined main and every derived artifact slaved to it removed via
	 * the shared `Image_Deleter` (the same routine the CLI `image delete` uses); the
	 * per-folder index self-heals on the next render. A failed removal is a 500 rather
	 * than a phantom success. The reply is `200 { deleted: true }`.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error The deletion outcome, or a WP_Error for a failure.
	 */
	public function delete_image( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Resolve the addressed collection; an unknown or malformed slug is a 404
		// (the Repository applies the strict slug gate, so a hostile slug that the
		// permissive route pattern let through simply fails to resolve here).
		$slug            = $this->read_slug( $request );
		$collection_path = $this->repository->resolve_slug( $slug );
		if ( $collection_path === null ) {
			$message = __( 'The requested collection does not exist.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unknown_collection', $message, [ 'status' => 404 ] );
		}

		// Confine the caller-supplied path to the collection root; a null means the
		// path was hostile (traversal, scheme, NUL, symlink escape) — reject as 422
		// with nothing deleted, the same realpath confinement the upload and
		// add-to-media paths use.
		$client_path = $this->read_path( $request );
		$guard       = new Path_Guard( $collection_path );
		$main_path   = $guard->resolve( $client_path );
		if ( $main_path === null ) {
			Plugin::warning( "Rejected an unsafe delete path for collection {$slug}: {$client_path}." );
			$message = __( 'The requested image path is not allowed.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_rejected_path', $message, [ 'status' => 422 ] );
		}

		// The confined path must name a real main image on disk; a confined-but-absent
		// path (an already-deleted image, a directory) is a 404 with nothing deleted.
		if ( ! is_file( $main_path ) ) {
			$message = __( 'The requested image does not exist.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unknown_image', $message, [ 'status' => 404 ] );
		}

		// The confined file must be a *main image*, not a derived artifact, the
		// descriptor, or a foreign in-collection file: confinement and is_file() alone
		// would let collection.json or a kntnt-thumbnails/<width>/<name>.webp thumbnail
		// be deleted directly. ADR-0015 deletes only the main (its derived artifacts
		// follow from it), so a confined non-main is a 404 with nothing deleted.
		if ( ! $this->is_main_image( $main_path ) ) {
			$message = __( 'The requested image is not a gallery image.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_not_a_main_image', $message, [ 'status' => 404 ] );
		}

		// Permanently remove the confined main and every derived artifact slaved to
		// it; a false verdict means the on-disk removal failed, which is a 500 rather
		// than a 200 over a delete that did not happen.
		if ( ! $this->delete_main( $main_path ) ) {
			Plugin::error( "Trash delete failed for collection {$slug}: {$client_path}" );
			$message = __( 'The image could not be deleted.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_delete_failed', $message, [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );

	}

	/**
	 * Reports whether a confined absolute path names a collection main image.
	 *
	 * The discrimination `Path_Guard` confinement and `is_file()` cannot make: both
	 * accept the descriptor and a derived thumbnail just as readily as a main, so this
	 * is what keeps the destructive delete to the main image (ADR-0015) — the same
	 * gate `Media_Controller` applies to the add-to-media copy, kept local to each
	 * controller so the two write-paths stay decoupled while the *authoritative*
	 * naming rule (`Image_Name::is_stored_main()`) lives in one place. A main is a file
	 * whose basename is a stored main name (a `<original>.webp` ending in `.webp`),
	 * that does **not** live under any folder's hidden `kntnt-thumbnails/` artifacts
	 * directory (which would make it a derived thumbnail or full rendition), and that
	 * is not the `collection.json` descriptor. The thumbnail and descriptor checks are
	 * redundant-by-construction defence in depth — a derived artifact shares the main's
	 * `<name>.webp` form, and the descriptor is excluded by the stored-main rule anyway
	 * — but both are stated so the gate reads as the full ADR-0015 contract.
	 *
	 * @since 0.13.0
	 *
	 * @param string $path The confined absolute path returned by `Path_Guard`.
	 * @return bool True when the path is a collection main image.
	 */
	private function is_main_image( string $path ): bool {

		// A path under any `kntnt-thumbnails/` segment is a derived artifact (a
		// thumbnail or the full rendition), not a main — reject it before the cheaper
		// basename checks.
		if ( str_contains( $path, '/' . Index::THUMBNAILS_DIRNAME . '/' ) ) {
			return false;
		}

		// The basename must be a stored main name and must not be the descriptor; the
		// descriptor fails the stored-main rule already, but the explicit exclusion
		// keeps the intent legible. The path is a confined absolute one (Path_Guard
		// rejects backslashes outright), so native basename() is exact here.
		$basename = basename( $path );
		return $basename !== Descriptor::FILENAME && Image_Name::is_stored_main( $basename );

	}

	/**
	 * Removes a main image and its derived artifacts — the test seam.
	 *
	 * Delegates to the shared `Image_Deleter`, which removes the main and every
	 * derived artifact slaved to it (the full rendition and the thumbnail) and leaves
	 * the index to self-heal. Returns whether the main was removed. Protected so a
	 * unit test can substitute a recording stub and exercise the path resolution
	 * without touching disk; production always runs the real removal (covered by the
	 * `Image_Deleter` unit suite and the integration suite).
	 *
	 * @since 0.13.0
	 *
	 * @param string $main_path The absolute path to the confined main image.
	 * @return bool True when the main image was removed.
	 */
	protected function delete_main( string $main_path ): bool {
		return ( new Image_Deleter() )->delete( $main_path );
	}

	/**
	 * Resolves the capability required to delete an image, through the filter.
	 *
	 * Defaults to `delete_others_posts` and is passed through
	 * `kntnt_photo_drop_delete_capability` so a site can require a different
	 * capability (ADR-0015). A filter that returns a non-string or empty value is a
	 * misuse and falls back to the default rather than silently disabling the gate.
	 * Static so both the controller's gate and the gallery's capability-gated render
	 * resolve the identical value through one place.
	 *
	 * @since 0.13.0
	 *
	 * @return string The capability string to check.
	 */
	public static function required_capability(): string {

		// Apply the filter and harden its return: a non-string or empty result is
		// rejected back to the default, so a buggy filter can never open the gate.
		$filtered = apply_filters( 'kntnt_photo_drop_delete_capability', self::DEFAULT_CAPABILITY );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;

	}

	/**
	 * Reads the `wp_rest` nonce from the request, header first.
	 *
	 * Prefers the canonical `X-WP-Nonce` header that `wp.apiFetch` and the gallery's
	 * view module send, falling back to a `_wpnonce` parameter. The value is sanitised
	 * before it reaches `wp_verify_nonce()`.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The nonce string, or '' when none was supplied.
	 */
	private function read_nonce( \WP_REST_Request $request ): string {

		// Take the header value first, then the parameter fallback; sanitise either
		// way so only a clean token string reaches the verifier.
		$header = $request->get_header( 'X-WP-Nonce' );
		$raw    = is_string( $header ) && $header !== '' ? $header : $request->get_param( '_wpnonce' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';

	}

	/**
	 * Reads and sanitises the addressed collection slug.
	 *
	 * The slug comes from the matched route segment; it is sanitised here as defence
	 * in depth, though the `Repository` re-validates it strictly before any filesystem
	 * access.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The sanitised slug, or '' when absent.
	 */
	private function read_slug( \WP_REST_Request $request ): string {
		$raw = $request->get_param( 'slug' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Reads the caller-supplied collection-relative path verbatim.
	 *
	 * The value is *not* path-sanitised on the request path: the registered
	 * `sanitize_callback` is a pure type gate and this reader passes the string
	 * through untouched, because hard sanitisation and `realpath` confinement are the
	 * `Path_Guard`'s job, and that guard must see the raw bytes (including any encoded
	 * traversal) to reject them.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The raw relative path, or '' when absent.
	 */
	private function read_path( \WP_REST_Request $request ): string {
		$raw = $request->get_param( self::PATH_PARAM );
		return is_string( $raw ) ? $raw : '';
	}

}
