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
 * image). Each copy is stamped with its source identity (collection slug +
 * collection-relative path) as attachment post-meta, so a later add of the same
 * image is detected: a re-add is a 409 carrying the existing id (the gallery
 * raises an overwrite confirm), and a confirmed overwrite replaces that
 * attachment's file in place, keeping its id (ADR-0015 amendment).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.12.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;

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
 * @since 0.15.0
 */
class Media_Controller {

	// Shared request-reading and capability-resolution helpers.
	use Request_Gate;

	/**
	 * The REST namespace under which the media route is registered.
	 *
	 * Shared with the upload and list endpoints so the whole plugin surface lives
	 * under one versioned namespace.
	 *
	 * @since 0.15.0
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
	 * @since 0.15.0
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
	 * @since 0.15.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'upload_files';

	/**
	 * The JSON body field carrying the image's collection-relative path.
	 *
	 * @since 0.15.0
	 * @var string
	 */
	private const PATH_PARAM = 'path';

	/**
	 * Attachment post-meta key recording the source collection slug.
	 *
	 * Half of the source identity an add-to-media copy is stamped with, so a later
	 * add of the same image can be recognised and de-duplicated (ADR-0015 amendment).
	 *
	 * @since 0.15.0
	 * @var string
	 */
	private const META_COLLECTION = '_kntnt_photo_drop_collection';

	/**
	 * Attachment post-meta key recording the source collection-relative path.
	 *
	 * The other half of the stamped source identity (see META_COLLECTION).
	 *
	 * @since 0.15.0
	 * @var string
	 */
	private const META_PATH = '_kntnt_photo_drop_path';

	/**
	 * The JSON body flag asking to overwrite an existing copy in place.
	 *
	 * @since 0.15.0
	 * @var string
	 */
	private const OVERWRITE_PARAM = 'overwrite';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at a
	 * temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.15.0
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
	 * @since 0.15.0
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
					'slug'                => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					self::PATH_PARAM      => [
						'type'              => 'string',
						'required'          => true,
						// A type gate only, deliberately not sanitize_text_field:
						// that would strip %xx sequences before Path_Guard — the
						// real sanitiser, which rejects NUL/control/traversal/
						// schemes and realpath-confines the target — ever saw the
						// raw bytes.
						'sanitize_callback' => static fn ( mixed $value ): string => is_string( $value ) ? $value : '',
					],
					self::OVERWRITE_PARAM => [
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => static fn ( mixed $value ): bool => (bool) $value,
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
	 * @since 0.15.0
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
	 * escapes, is malformed, or carries traversal/scheme/NUL), resolves it to a real
	 * file on disk (404 when none is there), and verifies that file is a **main
	 * image** rather than a derived thumbnail, the descriptor, or a foreign
	 * in-collection file (404 when it is not — confinement alone would otherwise copy
	 * `collection.json` or a thumbnail).
	 *
	 * De-duplication (ADR-0015 amendment): a copy is detected by its stamped source
	 * identity (collection slug + collection-relative path). When this image is
	 * already in the Library and the caller has not asked to overwrite, the reply is
	 * `409` carrying the existing attachment id, with nothing copied — the gallery
	 * raises its overwrite confirm. When the caller confirms overwrite, the existing
	 * attachment's file is replaced in place (same id, so embeds keep working) and
	 * the reply is `200 { id }`. Otherwise the confined main is sideloaded into the
	 * Media Library as an ordinary, independent attachment with its own sub-sizes — a
	 * copy, never a link (ADR-0015) — and stamped with its source identity, the reply
	 * being `201 { id }`. A failed import or overwrite is a 500 rather than a phantom
	 * success.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error The created/replaced attachment, or a WP_Error for a failure.
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

		// The confined file must be a *main image*, not a derived artifact, the
		// descriptor, or a foreign in-collection file: confinement and is_file()
		// alone would copy collection.json or a kntnt-thumbnails/<width>/<name>.webp
		// thumbnail into the Library. ADR-0015 sideloads only the main, so a confined
		// non-main is a 404 with nothing copied.
		if ( ! $this->is_main_image( $main_path ) ) {
			$message = __( 'The requested image is not a gallery image.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_not_a_main_image', $message, [ 'status' => 404 ] );
		}

		// Compute the canonical source identity (collection slug + collection-relative
		// path) used both to detect a prior copy and to stamp a fresh one. The relative
		// path is taken against the guard's canonical (realpath) root, a guaranteed
		// prefix of the confined main — the descriptor-anchored collection path is not,
		// since realpath may rewrite symlinked path segments.
		$relative = ltrim( substr( $main_path, strlen( $guard->get_root() ) ), '/' );
		$existing = $this->find_existing( $slug, $relative );

		// A prior copy exists and the caller has not asked to overwrite: report 409
		// with the existing id so the gallery can raise its overwrite confirm. Nothing
		// is copied.
		if ( $existing !== null && ! $this->read_overwrite( $request ) ) {
			$message = __( 'This image is already in the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error(
				'kntnt_photo_drop_already_in_media',
				$message,
				[
					'status' => 409,
					'id'     => $existing,
				]
			);
		}

		// A prior copy exists and the caller confirmed overwrite: replace its file in
		// place (same id, so embeds keep working). A WP_Error is a 500.
		if ( $existing !== null ) {
			$replaced = $this->replace( $existing, $main_path );
			if ( $replaced instanceof \WP_Error ) {
				$reason = $replaced->get_error_message();
				Plugin::error( "Add-to-media overwrite failed for collection {$slug}: {$reason}" );
				$message = __( 'The image could not be overwritten in the Media Library.', 'kntnt-photo-drop' );
				return new \WP_Error( 'kntnt_photo_drop_overwrite_failed', $message, [ 'status' => 500 ] );
			}
			return new \WP_REST_Response( [ 'id' => $replaced ], 200 );
		}

		// No prior copy: sideload a fresh independent attachment, then stamp it with
		// its source identity so a future add recognises it. A failed import is a 500.
		$attachment_id = $this->sideload( $main_path );
		if ( $attachment_id instanceof \WP_Error ) {
			$reason = $attachment_id->get_error_message();
			Plugin::error( "Add-to-media sideload failed for collection {$slug}: {$reason}" );
			$message = __( 'The image could not be added to the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_sideload_failed', $message, [ 'status' => 500 ] );
		}
		update_post_meta( $attachment_id, self::META_COLLECTION, $slug );
		update_post_meta( $attachment_id, self::META_PATH, $relative );

		return new \WP_REST_Response( [ 'id' => $attachment_id ], 201 );

	}

	/**
	 * Reports whether a confined absolute path names a collection main image.
	 *
	 * The discrimination `Path_Guard` confinement and `is_file()` cannot make:
	 * both accept the descriptor and a derived thumbnail just as readily as a main,
	 * so this is what keeps the add-to-media copy to the main image (ADR-0015). A
	 * main is a file whose basename is a stored main name (the shared
	 * `Image_Name::is_stored_main()` rule the doctor's classification uses too — a
	 * `<original>.webp` ending in `.webp`), that does **not** live under any folder's
	 * hidden `kntnt-thumbnails/` artifacts directory (which would make it a derived
	 * thumbnail), and that is not the `collection.json` descriptor. The thumbnail and
	 * descriptor checks are redundant-by-construction defence in depth — a thumbnail
	 * shares the main's `<name>.webp` form, and the descriptor is excluded by the
	 * stored-main rule anyway — but both are stated explicitly so the gate reads as
	 * the full ADR-0015 contract rather than relying on a coincidence of the naming.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path The confined absolute path returned by `Path_Guard`.
	 * @return bool True when the path is a collection main image.
	 */
	private function is_main_image( string $path ): bool {

		// A path under any `kntnt-thumbnails/` segment is a derived thumbnail, not a
		// main — reject it before the cheaper basename checks.
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
	 * @since 0.15.0
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

		// Insert the collection main at its real, contract-bounded resolution:
		// WordPress otherwise downscales any image past big_image_size_threshold
		// (2560px) into a `…-scaled` master and attaches that. The main is already
		// the source of truth, so disable the threshold for this one import only,
		// then restore it so ordinary Media Library uploads keep WordPress's
		// default scaling. Sub-sizes still generate (ADR-0015).
		add_filter( 'big_image_size_threshold', '__return_false' );
		$attachment_id = media_handle_sideload( $file, 0 );
		remove_filter( 'big_image_size_threshold', '__return_false' );

		// media_handle_sideload removes the temp file on success; clean it up
		// ourselves on failure so a failed import leaves no orphan behind.
		if ( $attachment_id instanceof \WP_Error && file_exists( $temp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up the throwaway temp copy after a failed import, outside the Media Library.
			unlink( $temp );
		}

		return $attachment_id;

	}

	/**
	 * Finds an attachment previously copied from this exact collection image.
	 *
	 * Duplicate detection (ADR-0015 amendment): each add-to-media copy is stamped
	 * with its source collection slug and collection-relative path as post-meta, so
	 * a later add of the same image can be recognised. Returns the existing
	 * attachment id, or null when this image is not yet in the Library. A protected
	 * seam so a unit test can control the verdict without a real Media Library.
	 *
	 * @since 0.15.0
	 *
	 * @param string $slug     The collection slug.
	 * @param string $relative The collection-relative path of the main image.
	 * @return int|null The existing attachment id, or null when none.
	 */
	protected function find_existing( string $slug, string $relative ): ?int {

		// Query attachments stamped with this exact source identity; the newest
		// match (there should be at most one) is the existing copy.
		$matches = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The source-identity lookup is inherently a meta query (two stamped keys), bounded to one row and run only on a deliberate add-to-media write, never on a read path.
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'   => self::META_COLLECTION,
						'value' => $slug,
					],
					[
						'key'   => self::META_PATH,
						'value' => $relative,
					],
				],
			]
		);
		return isset( $matches[0] ) ? (int) $matches[0] : null;

	}

	/**
	 * Replaces an existing attachment's file in place from a collection main.
	 *
	 * Overwrite semantics (ADR-0015 amendment): the attachment id is preserved so
	 * any post already embedding it keeps working. The current attached file is
	 * overwritten with the confined main's bytes, stale sub-sizes are dropped, and
	 * metadata is regenerated. Big-image scaling is disabled for the regeneration
	 * so the replacement is full-resolution, not a `-scaled` master (see Plan 007).
	 * A protected seam so a unit test can record the call without a real Library.
	 *
	 * @since 0.15.0
	 *
	 * @param int    $attachment_id The existing attachment to overwrite.
	 * @param string $main_path     The absolute path to the confined main image.
	 * @return int|\WP_Error The same attachment id on success, or a WP_Error.
	 */
	protected function replace( int $attachment_id, string $main_path ): int|\WP_Error {

		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Resolve the attachment's current file; without it there is nothing to
		// replace in place.
		$current = get_attached_file( $attachment_id );
		if ( $current === false || $current === '' ) {
			return new \WP_Error( 'kntnt_photo_drop_replace_no_file', 'The attachment has no file to replace.' );
		}

		// Drop the existing generated sub-sizes so regeneration does not leave
		// orphaned intermediate files behind, then overwrite the master bytes.
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		$this->delete_subsizes( $current, is_array( $old_meta ) ? $old_meta : [] );
		if ( ! copy( $main_path, $current ) ) {
			return new \WP_Error( 'kntnt_photo_drop_replace_copy', 'Could not overwrite the attachment file.' );
		}

		// Regenerate metadata + sub-sizes for the new bytes at full resolution
		// (big-image scaling disabled, matching the fresh-add path).
		add_filter( 'big_image_size_threshold', '__return_false' );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $current );
		remove_filter( 'big_image_size_threshold', '__return_false' );
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		return $attachment_id;

	}

	/**
	 * Removes an attachment's generated sub-size files beside its master.
	 *
	 * @since 0.15.0
	 *
	 * @param string               $master_path The attachment's master file path.
	 * @param array<string, mixed> $meta        The attachment metadata (may be empty).
	 * @return void
	 */
	private function delete_subsizes( string $master_path, array $meta ): void {

		// Each sub-size lives beside the master; unlink any that still exist so a
		// regeneration cannot leave a stale intermediate behind.
		$dir   = dirname( $master_path );
		$sizes = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : [];
		foreach ( $sizes as $size ) {
			$file = is_array( $size ) ? ( $size['file'] ?? '' ) : '';
			if ( is_string( $file ) && $file !== '' && is_file( "{$dir}/{$file}" ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a stale sub-size beside the master before regeneration.
				unlink( "{$dir}/{$file}" );
			}
		}

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
	 * @since 0.15.0
	 *
	 * @return string The capability string to check.
	 */
	public static function required_capability(): string {
		return self::resolve_capability( 'kntnt_photo_drop_add_to_media_capability', self::DEFAULT_CAPABILITY );
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
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The raw relative path, or '' when absent.
	 */
	private function read_path( \WP_REST_Request $request ): string {
		$raw = $request->get_param( self::PATH_PARAM );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Reads the optional overwrite flag from the request.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool True when the caller asked to overwrite an existing copy.
	 */
	private function read_overwrite( \WP_REST_Request $request ): bool {
		return (bool) $request->get_param( self::OVERWRITE_PARAM );
	}

}
