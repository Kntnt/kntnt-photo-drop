<?php
/**
 * The single HTTP write path into a collection — the trust boundary.
 *
 * Backs the Drop Zone block's one-file-per-request upload. This is the only
 * REST endpoint that mutates a collection (the gallery is purely server-rendered
 * and needs no REST), so it is the security-critical door. It enforces two
 * independent gates in its `permission_callback` — a valid `wp_rest` nonce
 * (stops CSRF) *and* `current_user_can( upload_files )` (stops the wrong people,
 * e.g. a self-registered Subscriber who holds a valid nonce) — then hands the
 * uploaded bytes and the attacker-controlled `relativePath` to the shared
 * `Ingestor`, which `Path_Guard`-confines the path, re-enforces the output
 * contract through the `Optimizer`, writes the main plus thumbnails, and never
 * touches the index. The per-file response carries the `Ingest_Outcome`
 * (`stored | skipped | reencoded | rejected`) so one bad file is a per-file
 * rejection, never a batch abort (ADR-0006).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Ingestion\Ingest_Outcome;
use Kntnt\Photo_Drop\Ingestion\Ingest_Result;
use Kntnt\Photo_Drop\Ingestion\Ingestor;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Registers and serves the collection upload endpoint.
 *
 * Constructed once by `Plugin` and bound to `rest_api_init`. The only injected
 * state is the collection `Repository`, which resolves a slug to its on-disk
 * path; the descriptor and the per-collection `Ingestor` are built per request,
 * because each collection carries its own immutable contract. The external
 * interface is two callbacks — `check_permission()` and `upload()` — wired to
 * one route; everything between the trust boundary and the filesystem is hidden
 * behind the `Ingestor`'s single deep method.
 *
 * @since 0.4.0
 */
final class Upload_Controller {

	/**
	 * The REST namespace under which the upload route is registered.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-photo-drop/v1';

	/**
	 * The route pattern, capturing the collection slug as a named group.
	 *
	 * The slug character class is permissive at the router level
	 * (`[a-zA-Z0-9._-]+`); the real slug validation is the `Repository`'s strict
	 * lexical gate, applied when the slug is resolved to a path, so a syntactically
	 * matched-but-invalid slug simply fails to resolve and yields a 404.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const ROUTE = '/collections/(?P<slug>[a-zA-Z0-9._-]+)/images';

	/**
	 * The default capability required to upload, overridable via filter.
	 *
	 * Reused core capability rather than a bespoke one (ADR-0006): a user who can
	 * upload to the Media Library can upload to a collection. The
	 * `kntnt_photo_drop_upload_capability` filter lets a site narrow or widen it.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'upload_files';

	/**
	 * The multipart body field carrying the per-file relative target path.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const RELATIVE_PATH_PARAM = 'relativePath';

	/**
	 * The multipart file field the uploaded image arrives in.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const FILE_PARAM = 'file';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at
	 * a temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.4.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * Registers the `collections/<slug>/images` POST route.
	 *
	 * Hooked on `rest_api_init`. The route accepts a multipart POST carrying the
	 * file and a `relativePath` body param, runs the two-gate permission check,
	 * and dispatches to `upload()`.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'upload' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'slug'                    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					self::RELATIVE_PATH_PARAM => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

	}

	/**
	 * Enforces the two independent upload gates before the handler runs.
	 *
	 * Both gates defend different things and both must pass (ADR-0006). First the
	 * `wp_rest` nonce, read from the `X-WP-Nonce` header or the `_wpnonce`
	 * parameter, stops cross-site forgery: a missing or invalid nonce is a 401 no
	 * matter how privileged the session. Then the capability — `upload_files` by
	 * default, overridable via `kntnt_photo_drop_upload_capability` — stops the
	 * wrong people: a logged-in but un-capable user (a self-registered Subscriber
	 * holding a valid nonce) is a 403. Only when both pass does WordPress invoke
	 * `upload()`; a `WP_Error` here means the handler never runs.
	 *
	 * @since 0.4.0
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
		// is genuine; this proves the requester is allowed to write files at all.
		$capability = $this->required_capability();
		if ( ! current_user_can( $capability ) ) {
			$message = __( 'You are not allowed to upload images to this collection.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_forbidden', $message, [ 'status' => 403 ] );
		}

		return true;

	}

	/**
	 * Ingests one uploaded file into the addressed collection.
	 *
	 * Resolves the slug to a collection (404 when unknown), reads its descriptor
	 * to fix the output contract (500 when the descriptor is unreadable), reads
	 * the uploaded bytes (400 when the multipart file is missing or unreadable),
	 * and drives the shared `Ingestor`, which confines the `relativePath`,
	 * re-enforces the contract, writes the main plus thumbnails, and leaves the
	 * index untouched. The reply is a per-file JSON object whose `outcome` is
	 * exactly one of `stored | skipped | reencoded | rejected`; a `rejected`
	 * outcome (hostile path or undecodable source) is a 422 for this one file and
	 * never aborts a wider batch, which is one request per file in any case.
	 *
	 * @since 0.4.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error The per-file outcome, or a WP_Error for a request-level failure.
	 */
	public function upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Resolve the addressed collection; an unknown or malformed slug is a 404
		// (the Repository applies the strict slug gate, so a hostile slug that the
		// permissive route pattern let through simply fails to resolve here).
		$slug            = $this->read_slug( $request );
		$collection_path = $this->repository->resolve_slug( $slug );
		if ( $collection_path === null ) {
			$message = __( 'The requested collection does not exist.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unknown_collection', $message, [ 'status' => 404 ] );
		}

		// Read the descriptor to fix this collection's immutable contract; an
		// unreadable or corrupt descriptor is a server-side fault, not the client's.
		$descriptor = Descriptor::read( $collection_path );
		if ( $descriptor === null ) {
			Plugin::error( "Upload refused: unreadable descriptor for collection {$slug}." );
			$message = __( 'The collection could not be read.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unreadable_collection', $message, [ 'status' => 500 ] );
		}

		// Extract the uploaded bytes and the caller-supplied relative target; a
		// missing or unreadable multipart file is a malformed request (400).
		$source_bytes = $this->read_uploaded_bytes( $request );
		if ( $source_bytes === null ) {
			$message = __( 'No readable image was uploaded.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_no_file', $message, [ 'status' => 400 ] );
		}
		$relative_path = $this->read_relative_path( $request );

		// Drive the shared ingestion path: confine the relative target, re-enforce
		// the contract, write the main plus thumbnails, leave the index untouched.
		$ingestor = new Ingestor( $collection_path, $descriptor );
		$result   = $ingestor->ingest( $source_bytes, $relative_path );

		return $this->respond( $result );

	}

	/**
	 * Maps a per-file ingestion result to a REST response.
	 *
	 * A `rejected` result — a hostile path or an undecodable source, with nothing
	 * written — is surfaced as a 422 so the client can mark that one file failed;
	 * every other outcome is a 200 carrying the stored facts. The body always
	 * carries the backed `outcome` string, the original and stored filenames (the
	 * stored name reversed back to the original for display), the count of
	 * thumbnails written, and the collection-relative source label, which is
	 * exactly the per-file shape the Drop Zone reports against.
	 *
	 * @since 0.4.0
	 *
	 * @param Ingest_Result $result The single per-file ingestion result.
	 * @return \WP_REST_Response The per-file response, 200 for written/skipped, 422 for rejected.
	 */
	private function respond( Ingest_Result $result ): \WP_REST_Response {

		// Assemble the per-file body from the result; `stored_name` is null only for
		// a rejection, where the original name reflects the requested source label.
		$body = [
			'outcome'    => $result->outcome->value,
			'source'     => $result->source,
			'name'       => $result->stored_name !== null ? Image_Name::to_original( $result->stored_name ) : null,
			'storedName' => $result->stored_name,
			'thumbnails' => count( $result->thumbnails ),
		];

		// A rejection is a per-file failure (422); anything written or skipped is a
		// success (200). Either way the body shape is identical for the client.
		$status = $result->outcome === Ingest_Outcome::Rejected ? 422 : 200;

		return new \WP_REST_Response( $body, $status );

	}

	/**
	 * Resolves the capability required to upload, through the filter.
	 *
	 * Defaults to `upload_files` and is passed through
	 * `kntnt_photo_drop_upload_capability` so a site can require a different
	 * capability. A filter that returns a non-string or empty value is a misuse
	 * and falls back to the default rather than silently disabling the gate.
	 *
	 * @since 0.4.0
	 *
	 * @return string The capability string to check.
	 */
	private function required_capability(): string {

		// Apply the filter and harden its return: a non-string or empty result is
		// rejected back to the default, so a buggy filter can never open the gate.
		$filtered = apply_filters( 'kntnt_photo_drop_upload_capability', self::DEFAULT_CAPABILITY );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;

	}

	/**
	 * Reads the `wp_rest` nonce from the request, header first.
	 *
	 * Prefers the canonical `X-WP-Nonce` header that `wp.apiFetch` and FilePond's
	 * configured headers send, falling back to a `_wpnonce` parameter. The value
	 * is sanitised before it reaches `wp_verify_nonce()`.
	 *
	 * @since 0.4.0
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
	 * The slug comes from the matched route segment; it is sanitised here as
	 * defence in depth, though the `Repository` re-validates it strictly before
	 * any filesystem access.
	 *
	 * @since 0.4.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The sanitised slug, or '' when absent.
	 */
	private function read_slug( \WP_REST_Request $request ): string {
		$raw = $request->get_param( 'slug' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Reads the caller-supplied relative target path verbatim.
	 *
	 * The value is *not* path-sanitised here: hard sanitisation and `realpath`
	 * confinement are the `Path_Guard`'s job inside the `Ingestor`, and that guard
	 * must see the raw bytes (including any encoded traversal) to reject them. Any
	 * non-string is normalised to an empty string, which the guard resolves to the
	 * collection root and pairs with the uploaded file's own basename.
	 *
	 * @since 0.4.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The raw relative path, or '' when absent.
	 */
	private function read_relative_path( \WP_REST_Request $request ): string {
		$raw = $request->get_param( self::RELATIVE_PATH_PARAM );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Extracts the uploaded image bytes from the multipart request.
	 *
	 * Reads the `file` entry from the request's file params (the PHP `$_FILES`
	 * shape), then reads the bytes from the temporary upload path. Returns `null`
	 * when the field is missing, carries a PHP upload error, or its temp file
	 * cannot be read — each a malformed request the handler answers with a 400
	 * rather than treating as a content rejection.
	 *
	 * @since 0.4.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string|null The uploaded bytes, or null when no readable file was sent.
	 */
	private function read_uploaded_bytes( \WP_REST_Request $request ): ?string {

		// Locate the `file` entry in the multipart file params; its absence or a
		// non-array shape means no file was sent.
		$files = $request->get_file_params();
		$file  = $files[ self::FILE_PARAM ] ?? null;
		if ( ! is_array( $file ) ) {
			return null;
		}

		// Reject any PHP-reported upload error (size, partial, no file) before
		// touching the temp path; only UPLOAD_ERR_OK carries usable bytes.
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ( ! is_int( $error ) || $error !== UPLOAD_ERR_OK ) {
			return null;
		}

		// Read the bytes from the temporary upload path; a missing or unreadable
		// temp file is a malformed request, not a content rejection.
		$tmp_path = $file['tmp_name'] ?? '';
		if ( ! is_string( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the PHP-managed multipart temp file directly; WP_Filesystem is the Media-Library abstraction and is the wrong tool for a raw upload byte read at this boundary.
		$bytes = file_get_contents( $tmp_path );
		return $bytes === false ? null : $bytes;

	}

}
