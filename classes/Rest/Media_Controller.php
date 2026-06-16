<?php
/**
 * The gallery's add-to-media REST write-path — a copy into the Media Library.
 *
 * The gallery's read path is pure SSR; the add-to-media overlay is the first
 * surface that mutates anything, so this is the gallery's one REST write-path
 * (ADR-0015). It is gated the same way the Drop Zone upload is: a valid `wp_rest`
 * nonce (stops CSRF) *and* the `add_to_media` capability (`upload_files` by
 * default, filter `kntnt_photo_drop_add_to_media_capability`). The request body
 * carries the image's **collection-relative `path`** (the gallery mirrors it onto
 * the anchor as `data-kntnt-photo-drop-path`); the server hard-sanitises and
 * `Path_Guard`-confines that path to the collection root, then resolves it to the
 * **main image** on disk. A path escaping the root, or one naming no main, is
 * rejected with nothing copied.
 *
 * On success the main image is **sideloaded** into the Media Library as an
 * ordinary, independent attachment — WordPress generates its own sub-sizes — a
 * **copy, never a link** (ADR-0015 rejects the Media-Library-backed mode that
 * would reintroduce a DB row plus an out-of-collection file backing a collection
 * image). Each confirmed click adds another copy; there is no dedup.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.12.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Plugin;

/**
 * Registers and serves the collection add-to-media endpoint.
 *
 * Constructed once by `Plugin` and bound to `rest_api_init`. The only injected
 * state is the collection `Repository`, which resolves a slug to its on-disk
 * path; the per-request work — confining the path, resolving the main, copying
 * it into the Library — is hidden behind two callbacks (`check_permission()` and
 * `add_to_media()`) wired to one route. Deliberately not `final`: the protected
 * `sideload()` factory is the seam a unit test overrides to exercise the
 * resolution-and-confinement logic without a real Media Library (the real
 * sideload is covered by the integration suite).
 *
 * @since 0.12.0
 */
class Media_Controller {

	/**
	 * The REST namespace under which the media route is registered.
	 *
	 * Shared with the upload and list endpoints so the whole plugin surface lives
	 * under one versioned namespace.
	 *
	 * @since 0.12.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-photo-drop/v1';

	/**
	 * The route pattern, capturing the collection slug as a named group.
	 *
	 * The slug character class is permissive at the router level; the real slug
	 * validation is the `Repository`'s strict lexical gate, applied when the slug
	 * is resolved to a path, so a syntactically matched-but-invalid slug simply
	 * fails to resolve and yields a 404.
	 *
	 * @since 0.12.0
	 * @var string
	 */
	private const ROUTE = '/collections/(?P<slug>[a-zA-Z0-9._-]+)/media';

	/**
	 * The default capability required to copy into the Media Library.
	 *
	 * Reuses `upload_files` (ADR-0015): a user who can add to the Media Library may
	 * copy a collection image into it. The
	 * `kntnt_photo_drop_add_to_media_capability` filter lets a site narrow or widen
	 * it.
	 *
	 * @since 0.12.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'upload_files';

	/**
	 * The JSON body field carrying the image's collection-relative path.
	 *
	 * @since 0.12.0
	 * @var string
	 */
	private const PATH_PARAM = 'path';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at a
	 * temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.12.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * Registers the `collections/<slug>/media` POST route.
	 *
	 * Hooked on `rest_api_init`. The route accepts a JSON POST carrying the
	 * collection-relative `path` of the image to copy, runs the two-gate permission
	 * check, and dispatches to `add_to_media()`.
	 *
	 * @since 0.12.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_media' ],
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
	 * `_wpnonce` parameter, stops cross-site forgery: a missing or invalid nonce is
	 * a 401 no matter how privileged the session. Then the capability —
	 * `upload_files` by default, overridable via
	 * `kntnt_photo_drop_add_to_media_capability` — stops the wrong people: a
	 * logged-in but un-capable user (a self-registered Subscriber holding a valid
	 * nonce) is a 403. Only when both pass does WordPress invoke `add_to_media()`.
	 *
	 * @since 0.12.0
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
		// is genuine; this proves the requester is allowed to copy into the Library.
		if ( ! current_user_can( self::required_capability() ) ) {
			$message = __( 'You are not allowed to copy images into the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_forbidden', $message, [ 'status' => 403 ] );
		}

		return true;

	}

	/**
	 * Copies one collection main image into the Media Library as an attachment.
	 *
	 * Resolves the slug to a collection (404 when unknown), confines the
	 * caller-supplied `path` to the collection root with `Path_Guard` (422 when it
	 * escapes, is malformed, or carries traversal/scheme/NUL), and resolves it to a
	 * real main image on disk (404 when no file is there). The confined main is then
	 * sideloaded into the Media Library as an ordinary, independent attachment with
	 * its own sub-sizes — a copy, never a link (ADR-0015). A failed sideload is a 500
	 * rather than a phantom success. The reply is `201 { id }` carrying the created
	 * attachment id.
	 *
	 * @since 0.12.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error The created attachment, or a WP_Error for a failure.
	 */
	public function add_to_media( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

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
		// with nothing copied, the same realpath confinement the upload path uses.
		$client_path = $this->read_path( $request );
		$guard       = new Path_Guard( $collection_path );
		$main_path   = $guard->resolve( $client_path );
		if ( $main_path === null ) {
			Plugin::warning( "Rejected an unsafe add-to-media path for collection {$slug}: {$client_path}." );
			$message = __( 'The requested image path is not allowed.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_rejected_path', $message, [ 'status' => 422 ] );
		}

		// The confined path must name a real main image on disk; a confined-but-absent
		// path (a deleted image, a directory) is a 404 with nothing copied.
		if ( ! is_file( $main_path ) ) {
			$message = __( 'The requested image does not exist.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unknown_image', $message, [ 'status' => 404 ] );
		}

		// Sideload the confined main into the Media Library as an independent copy;
		// a WP_Error means the Library import itself failed, which is a 500 rather
		// than a 201 over a copy that did not happen.
		$attachment_id = $this->sideload( $main_path );
		if ( $attachment_id instanceof \WP_Error ) {
			$reason = $attachment_id->get_error_message();
			Plugin::error( "Add-to-media sideload failed for collection {$slug}: {$reason}" );
			$message = __( 'The image could not be added to the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_sideload_failed', $message, [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( [ 'id' => $attachment_id ], 201 );

	}

	/**
	 * Sideloads a main image into the Media Library — the test seam.
	 *
	 * Copies the confined main to a temporary file (since `media_handle_sideload`
	 * *moves* the file it is given, which must never be the collection's own main),
	 * then hands that copy to `media_handle_sideload`, which inserts the attachment
	 * and generates its sub-sizes. Returns the new attachment id or a `WP_Error`
	 * from the Library import. Protected so a unit test can substitute a recording
	 * stub and exercise the path resolution without a real Media Library; production
	 * always runs the real import (covered end-to-end by the integration suite).
	 *
	 * @since 0.12.0
	 *
	 * @param string $main_path The absolute path to the confined main image.
	 * @return int|\WP_Error The created attachment id, or a WP_Error on failure.
	 */
	protected function sideload( string $main_path ): int|\WP_Error {

		// Load the Media Library helpers; they are admin-only includes not present on
		// a front-end REST request, so pull them in before the sideload runs.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Copy the main to a temp file media_handle_sideload can consume and move;
		// the original collection main must stay untouched (it is the source of
		// truth), so the Library gets a throwaway copy under the same basename.
		$temp = wp_tempnam( wp_basename( $main_path ) );
		if ( $temp === '' || ! copy( $main_path, $temp ) ) {
			if ( $temp !== '' ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A throwaway temp copy this method created, outside the Media Library; wp_delete_file is for attachments.
				unlink( $temp );
			}
			return new \WP_Error( 'kntnt_photo_drop_temp_copy', 'Could not stage the image for sideload.' );
		}

		// Hand the temp copy to the Library as an independent attachment with its own
		// sub-sizes; pass the original basename so the stored filename reads naturally.
		$file = [
			'name'     => wp_basename( $main_path ),
			'tmp_name' => $temp,
		];
		$attachment_id = media_handle_sideload( $file, 0 );

		// media_handle_sideload removes the temp file on success; clean it up
		// ourselves on failure so a failed import leaves no orphan behind.
		if ( $attachment_id instanceof \WP_Error && file_exists( $temp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up the throwaway temp copy after a failed import, outside the Media Library.
			unlink( $temp );
		}

		return $attachment_id;

	}

	/**
	 * Resolves the capability required to copy into the Library, through the filter.
	 *
	 * Defaults to `upload_files` and is passed through
	 * `kntnt_photo_drop_add_to_media_capability` so a site can require a different
	 * capability (ADR-0015). A filter that returns a non-string or empty value is a
	 * misuse and falls back to the default rather than silently disabling the gate.
	 * Static so both the controller's gate and the gallery's capability-gated render
	 * resolve the identical value through one place.
	 *
	 * @since 0.12.0
	 *
	 * @return string The capability string to check.
	 */
	public static function required_capability(): string {

		// Apply the filter and harden its return: a non-string or empty result is
		// rejected back to the default, so a buggy filter can never open the gate.
		$filtered = apply_filters( 'kntnt_photo_drop_add_to_media_capability', self::DEFAULT_CAPABILITY );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;

	}

	/**
	 * Reads the `wp_rest` nonce from the request, header first.
	 *
	 * Prefers the canonical `X-WP-Nonce` header that `wp.apiFetch` and the gallery's
	 * view module send, falling back to a `_wpnonce` parameter. The value is
	 * sanitised before it reaches `wp_verify_nonce()`.
	 *
	 * @since 0.12.0
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
	 * in depth, though the `Repository` re-validates it strictly before any
	 * filesystem access.
	 *
	 * @since 0.12.0
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
	 * through untouched, because hard sanitisation and `realpath` confinement are
	 * the `Path_Guard`'s job, and that guard must see the raw bytes (including any
	 * encoded traversal) to reject them.
	 *
	 * @since 0.12.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The raw relative path, or '' when absent.
	 */
	private function read_path( \WP_REST_Request $request ): string {
		$raw = $request->get_param( self::PATH_PARAM );
		return is_string( $raw ) ? $raw : '';
	}

}
