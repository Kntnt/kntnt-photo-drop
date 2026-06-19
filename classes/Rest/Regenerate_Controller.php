<?php
/**
 * The manage-gated REST write path that re-derives a collection's renditions —
 * the browser-driven half of regenerate-then-flip (ADR-0013).
 *
 * Editing a collection's re-derivable settings (the full and thumbnail
 * width/quality pairs) on the admin Edit page takes effect by regenerate-then-flip:
 * the browser drives this endpoint to re-derive every main at the new widths while
 * the gallery keeps serving the old ones, then a final call flips the descriptor
 * and prunes the old buckets. Like the upload endpoint this mutates a collection,
 * so it enforces two independent gates in its `permission_callback` — a valid
 * `wp_rest` nonce (forgery) *and* the manage capability
 * (`kntnt_photo_drop_manage_capability`, default `manage_options`; authorisation),
 * the same gate the admin page itself uses. The actual re-derive, flip, and prune
 * live behind the `Rendition_Regenerator`'s deep interface; this controller is the
 * HTTP boundary that validates the request and dispatches one batch (or the
 * finalise) per call. The endpoint **never** reads the slug or the upload contract
 * from the request body: the immutable upload pair comes only from the stored
 * descriptor, so the re-derive can never reconfigure the contract or rename a
 * collection.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Imaging\Rendition_Regenerator;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Registers and serves the collection regenerate endpoint.
 *
 * Constructed once by `Plugin` and bound to `rest_api_init`. The only injected
 * state is the collection `Repository`, which resolves a slug to its path; the
 * descriptor and the per-collection `Rendition_Regenerator` are built per request.
 * The external interface is two callbacks — `check_permission()` and
 * `regenerate()` — wired to one route; everything between the trust boundary and
 * the filesystem is hidden behind the regenerator. Deliberately not `final`: the
 * protected `create_regenerator()` factory is the seam a test subclass overrides
 * to drive a faked deriver.
 *
 * @since 0.11.0
 */
class Regenerate_Controller {

	// Shared request-reading and capability-resolution helpers.
	use Request_Gate;

	/**
	 * The REST namespace under which the regenerate route is registered.
	 *
	 * Shared with the upload and list endpoints so the whole plugin surface lives
	 * under one versioned namespace.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-photo-drop/v1';

	/**
	 * The route pattern, capturing the collection slug as a named group.
	 *
	 * The slug character class is permissive at the router level; the real slug
	 * validation is the `Repository`'s strict lexical gate, applied when the slug is
	 * resolved to a path, so a syntactically matched-but-invalid slug simply fails to
	 * resolve and yields a 404.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	private const ROUTE = '/collections/(?P<slug>[a-zA-Z0-9._-]+)/regenerate';

	/**
	 * The default capability required to regenerate, overridable via filter.
	 *
	 * The re-derive is a collection-lifecycle operation, so it gates on the same
	 * `manage_options` capability the admin lifecycle page uses, resolved through
	 * `kntnt_photo_drop_manage_capability`. An editor who can merely place a block
	 * must not be able to re-derive a collection.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at a
	 * temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.11.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * Registers the `collections/<slug>/regenerate` POST route.
	 *
	 * Hooked on `rest_api_init`. The route accepts a JSON POST carrying the target
	 * full/thumbnail widths plus a batch `index` (or a `finalize` flag), runs the
	 * two-gate permission check, and dispatches to `regenerate()`.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'regenerate' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'slug' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

	}

	/**
	 * Enforces the two independent gates before the handler runs.
	 *
	 * Both gates defend different things and both must pass. First the `wp_rest`
	 * nonce, read from the `X-WP-Nonce` header or the `_wpnonce` parameter, stops
	 * cross-site forgery: a missing or invalid nonce is a 401 no matter how
	 * privileged the session. Then the manage capability — `manage_options` by
	 * default, overridable via `kntnt_photo_drop_manage_capability` — stops the wrong
	 * people: a logged-in but un-capable user (an editor who can place a block) is a
	 * 403. Only when both pass does WordPress invoke `regenerate()`.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return true|\WP_Error True when both gates pass, or a WP_Error carrying 401/403.
	 */
	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {

		// Verify the anti-forgery nonce first; a forged or absent nonce is a 401
		// regardless of who the session belongs to.
		$nonce = $this->read_nonce( $request );
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			$message = __( 'Your session could not be verified. Please reload and try again.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_invalid_nonce', $message, [ 'status' => 401 ] );
		}

		// Then the authorisation gate: resolve the manage capability through the filter
		// and reject anyone who lacks it as 403.
		$capability = $this->required_capability();
		if ( ! current_user_can( $capability ) ) {
			$message = __( 'You are not allowed to regenerate this collection.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_forbidden', $message, [ 'status' => 403 ] );
		}

		return true;

	}

	/**
	 * Re-derives one batch of the addressed collection, or finalises the flip.
	 *
	 * Resolves the slug to a collection (404 when unknown), reads its descriptor to
	 * fix the immutable upload contract the flip preserves (500 when unreadable), and
	 * validates the four target rendition values (400 when any is malformed — so a bad
	 * request can never flip the collection to a degenerate width). It then dispatches
	 * by the request's intent: a `finalize` request flips the descriptor to the target
	 * widths and prunes the retired buckets (the gallery switches to the new
	 * renditions only here); any other request re-derives the single main at the given
	 * `index` at the target widths, writing the new buckets beside the live old ones.
	 * The slug and the upload contract are **never** read from the request, so the
	 * endpoint can neither rename a collection nor change its irreversible pair.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error The batch/finalise result, or a WP_Error for a request-level failure.
	 */
	public function regenerate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Resolve the addressed collection; an unknown or malformed slug is a 404 (the
		// Repository applies the strict slug gate the permissive route pattern let by).
		$slug            = $this->read_slug( $request );
		$collection_path = $this->repository->resolve_slug( $slug );
		if ( $collection_path === null ) {
			$message = __( 'The requested collection does not exist.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unknown_collection', $message, [ 'status' => 404 ] );
		}

		// Read the descriptor to fix the immutable upload contract the flip carries
		// over; an unreadable or corrupt descriptor is a server-side fault.
		$descriptor = Descriptor::read( $collection_path );
		if ( $descriptor === null ) {
			Plugin::error( "Regenerate refused: unreadable descriptor for collection {$slug}." );
			$message = __( 'The collection could not be read.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_unreadable_collection', $message, [ 'status' => 500 ] );
		}

		// Validate the target into a raw target descriptor that carries the upload
		// contract over and the re-derivable trio possibly *unset* (collapse-to-parent,
		// ADR-0013/#71); a malformed width or quality is a 400 with nothing written, so a
		// bad request never reaches the deriver or flips the descriptor to a degenerate
		// width. A present-but-empty re-derivable field arrives as JSON null (unset),
		// distinct from a non-numeric value, which is malformed.
		$target = $this->read_target( $request, $descriptor );
		if ( $target === null ) {
			$message = __( 'The full and thumbnail width must be positive and the quality 0–100.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_invalid_target', $message, [ 'status' => 400 ] );
		}

		// Dispatch by intent: a finalise flips and prunes; anything else re-derives one
		// batch. The regenerator reads the upload contract from the descriptor alone.
		$regenerator = $this->create_regenerator( $collection_path, $descriptor );
		return $this->is_finalize( $request )
			? $this->finalize( $regenerator, $target )
			: $this->regenerate_batch( $request, $regenerator, $target );

	}

	/**
	 * Re-derives the single main at the request's cursor index.
	 *
	 * Reads the zero-based `index` from the request and asks the regenerator to
	 * re-derive that main at the target widths, returning the running progress — the
	 * cursor, the total main count, whether the cursor has reached the end, and the
	 * count of derived files written — so the browser can drive the next batch or move
	 * on to the finalise.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request      $request     The incoming request.
	 * @param Rendition_Regenerator $regenerator The per-collection regenerator.
	 * @param Descriptor            $target      The raw target descriptor (re-derivable trio possibly unset).
	 * @return \WP_REST_Response|\WP_Error The batch progress, or a 500 when the main's renditions are incomplete.
	 */
	private function regenerate_batch(
		\WP_REST_Request $request,
		Rendition_Regenerator $regenerator,
		Descriptor $target,
	): \WP_REST_Response|\WP_Error {

		// Resolve the raw target's re-derivable knobs to the concrete widths the deriver
		// and the completeness check act on; an unset tier collapses to its effective
		// width (an unbounded ceiling that matches no real bucket, ADR-0013/#71).
		$effective = $target->effective_renditions();

		// Re-derive the addressed main at the effective target widths; the new buckets
		// land beside the live old ones, so the gallery keeps serving until the flip.
		$index   = $this->read_index( $request );
		$written = $regenerator->regenerate_main(
			$index,
			$effective['full_width'],
			$effective['full_quality'],
			$effective['thumbnail_width'],
			$effective['thumbnail_quality'],
		);

		// Confirm the main's expected new renditions actually landed on disk; a shortfall
		// (an undecodable, over-ceiling, or un-encodable main) is a real failure, not a
		// silent 200, so the browser driver stops here and never reaches the finalise.
		$complete = $regenerator->main_complete(
			$index,
			$effective['full_width'],
			$effective['full_quality'],
			$effective['thumbnail_width'],
			$effective['thumbnail_quality'],
		);
		if ( ! $complete ) {
			$message = __( 'An image could not be regenerated. Nothing was changed.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_regenerate_incomplete', $message, [ 'status' => 500 ] );
		}

		// Report progress so the browser can advance the cursor; `done` is true once the
		// cursor reaches the last main, at which point the client finalises.
		$total = $regenerator->main_count();
		return new \WP_REST_Response(
			[
				'index'   => $index,
				'total'   => $total,
				'written' => $written,
				'done'    => $index + 1 >= $total,
			],
			200,
		);

	}

	/**
	 * Flips the descriptor to the target and prunes the retired buckets.
	 *
	 * The finalise step: it is reached only after every main has been re-derived, and
	 * it is the one instant the gallery switches to the new renditions. The raw target
	 * descriptor is what the flip *writes* — so an unset re-derivable field persists as
	 * JSON null (collapse-to-parent), never frozen to a concrete value (ADR-0013/#71) —
	 * while its `effective_renditions()` are what the verify-then-flip sweep and the
	 * prune compare against, mirroring the CLI's `regenerate_and_flip`. A failed flip
	 * (the descriptor write failed, or a main is incomplete) is a 500 with nothing
	 * pruned, so the old renditions stay live and the collection consistent; a success
	 * reports the effective new widths and the count of pruned files.
	 *
	 * @since 0.11.0
	 *
	 * @param Rendition_Regenerator $regenerator The per-collection regenerator.
	 * @param Descriptor            $target      The raw target descriptor (re-derivable trio possibly unset).
	 * @return \WP_REST_Response|\WP_Error The finalise result, or a 500 when the flip failed.
	 */
	private function finalize( Rendition_Regenerator $regenerator, Descriptor $target ): \WP_REST_Response|\WP_Error {

		// Resolve the target's effective widths for the verify/prune comparison, then
		// flip to the *raw* target so an unset field stays null; a false return means the
		// completeness sweep or the descriptor write failed, leaving the old renditions
		// serving.
		$effective = $target->effective_renditions();
		$pruned    = $regenerator->finalise(
			$effective['full_width'],
			$effective['full_quality'],
			$effective['thumbnail_width'],
			$effective['thumbnail_quality'],
			$target,
		);
		if ( $pruned === false ) {
			// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
			$message = __( 'The collection could not be finalised; the previous renditions are kept.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_flip_failed', $message, [ 'status' => 500 ] );
		}

		// Report the flipped (effective) widths and the prune count so the browser can
		// confirm the change landed.
		return new \WP_REST_Response(
			[
				'finalized'      => true,
				'fullWidth'      => $effective['full_width'],
				'thumbnailWidth' => $effective['thumbnail_width'],
				'pruned'         => $pruned,
			],
			200,
		);

	}

	/**
	 * Builds the regenerator for one resolved collection — the test seam.
	 *
	 * Protected so a test subclass can substitute a faked deriver; production always
	 * binds the real `Rendition_Regenerator` with its default GD-backed deriver.
	 *
	 * @since 0.11.0
	 *
	 * @param string     $collection_path Absolute path to the resolved collection root.
	 * @param Descriptor $descriptor      The collection's current descriptor.
	 * @return Rendition_Regenerator The per-collection regenerator.
	 */
	protected function create_regenerator( string $collection_path, Descriptor $descriptor ): Rendition_Regenerator {
		return new Rendition_Regenerator( $collection_path, $descriptor );
	}

	/**
	 * Reads and validates the target into a raw target descriptor.
	 *
	 * The target is the new full and thumbnail width/quality pair the re-derive aims at,
	 * applied onto the stored descriptor via `with_renditions()` so the immutable upload
	 * contract, the display name, and the placement template carry over untouched — the
	 * endpoint can never reconfigure the contract or rename a collection. The three
	 * re-derivable fields (full width, full quality, thumbnail width) may each be left
	 * **unset** — sent over the wire as JSON null (an emptied Edit-page field) — to
	 * collapse to the tier above (ADR-0013/#71); the raw target carries those nulls so
	 * the flip persists "unset" distinct from a concrete value. A *present* width must be
	 * a strictly-positive integer and a *present* quality an integer in 0–100, while the
	 * always-concrete thumbnail quality must be a valid quality; any malformed value (a
	 * non-numeric string, a non-positive width, an out-of-range quality) yields `null`,
	 * which the caller answers with a 400 — distinct from a legitimate unset.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request    The incoming request.
	 * @param Descriptor       $descriptor The stored descriptor the target is applied onto.
	 * @return Descriptor|null The raw target descriptor, or null when any field is malformed.
	 */
	private function read_target( \WP_REST_Request $request, Descriptor $descriptor ): ?Descriptor {

		// Read the three re-derivable fields three-state: a concrete int, null (unset —
		// collapse to the tier above), or the malformed sentinel. A malformed width or
		// quality aborts the whole target, so a bad request never flips the descriptor.
		$full_width      = $this->read_optional_width( $request, 'fullWidth' );
		$full_quality    = $this->read_optional_quality( $request, 'fullQuality' );
		$thumbnail_width = $this->read_optional_width( $request, 'thumbnailWidth' );
		if ( $full_width === false || $full_quality === false || $thumbnail_width === false ) {
			return null;
		}

		// The thumbnail quality is always concrete in the descriptor; an absent or
		// out-of-range value is malformed, never unset.
		$thumbnail_quality = $this->read_int( $request, 'thumbnailQuality' );
		if ( ! $this->is_quality( $thumbnail_quality ) ) {
			return null;
		}

		// Apply the validated target onto the stored descriptor so only the re-derivable
		// trio changes; nulls persist as "unset" through the flip.
		return $descriptor->with_renditions(
			$full_width,
			$full_quality,
			$thumbnail_width,
			(int) $thumbnail_quality,
		);

	}

	/**
	 * Reads a re-derivable width three-state: concrete, unset, or malformed.
	 *
	 * The wire carries an emptied Edit-page width as JSON null (or an absent key), which
	 * means **unset** — collapse to the tier above (ADR-0013/#71) — and returns `null`.
	 * A present value must be a strictly-positive integer; a non-positive or non-numeric
	 * value is malformed and returns `false`, which the caller turns into a 400. The
	 * `false` sentinel is what distinguishes garbage from a legitimate unset.
	 *
	 * @since 0.14.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @param string           $key     The width parameter name.
	 * @return int|null|false The positive width, null for unset, or false when malformed.
	 */
	private function read_optional_width( \WP_REST_Request $request, string $key ): int|null|false {

		// An absent or JSON-null field is unset; a present one must parse to a positive
		// integer, otherwise it is malformed.
		if ( $this->is_unset_param( $request, $key ) ) {
			return null;
		}
		$width = $this->read_int( $request, $key );
		return $width !== null && $width > 0 ? $width : false;

	}

	/**
	 * Reads a re-derivable quality three-state: concrete, unset, or malformed.
	 *
	 * The wire carries an emptied Edit-page quality as JSON null (or an absent key),
	 * which means **unset** — follow the upload quality (ADR-0013/#71) — and returns
	 * `null`. A present value must be an integer in 0–100; anything else is malformed and
	 * returns `false`, which the caller turns into a 400.
	 *
	 * @since 0.14.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @param string           $key     The quality parameter name.
	 * @return int|null|false The 0–100 quality, null for unset, or false when malformed.
	 */
	private function read_optional_quality( \WP_REST_Request $request, string $key ): int|null|false {

		// An absent or JSON-null field is unset; a present one must be a valid 0–100
		// quality, otherwise it is malformed.
		if ( $this->is_unset_param( $request, $key ) ) {
			return null;
		}
		$quality = $this->read_int( $request, $key );
		return $this->is_quality( $quality ) ? $quality : false;

	}

	/**
	 * Reports whether a parameter is absent or explicitly JSON null — i.e. unset.
	 *
	 * The Edit page sends an emptied re-derivable field as JSON null; an absent key is
	 * treated the same way. Either is the collapse-to-parent "unset" state, distinct from
	 * a present-but-garbage value, which the optional readers reject as malformed.
	 *
	 * @since 0.14.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @param string           $key     The parameter name.
	 * @return bool True when the parameter is absent or explicitly null.
	 */
	private function is_unset_param( \WP_REST_Request $request, string $key ): bool {
		return $request->get_param( $key ) === null;
	}

	/**
	 * Reports whether a value is a valid 0–100 quality.
	 *
	 * @since 0.11.0
	 *
	 * @param int|null $quality The coerced quality value.
	 * @return bool True when the value is an integer in 0–100.
	 */
	private function is_quality( ?int $quality ): bool {
		return $quality !== null && $quality >= 0 && $quality <= 100;
	}

	/**
	 * Reports whether the request asks to finalise the flip rather than re-derive.
	 *
	 * A truthy `finalize` parameter switches the handler from re-deriving one batch to
	 * flipping the descriptor and pruning. The value is read loosely (the JS sends a
	 * JSON boolean) so `true`, `"1"`, and `1` all count.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return bool True when the request is a finalise.
	 */
	private function is_finalize( \WP_REST_Request $request ): bool {

		// Treat any truthy finalize value as the flip request; an absent or falsey one
		// is a re-derive batch.
		$raw = $request->get_param( 'finalize' );
		return $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';

	}

	/**
	 * Reads the zero-based batch cursor index from the request, clamped to ≥ 0.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return int The cursor index, never negative.
	 */
	private function read_index( \WP_REST_Request $request ): int {
		$index = $this->read_int( $request, 'index' );
		return $index !== null && $index > 0 ? $index : 0;
	}

	/**
	 * Reads an integer parameter, or null when absent or not integer-valued.
	 *
	 * Accepts an int or an all-digit string (the shape a JSON body or a query string
	 * yields), so the controller treats the wire's loose typing uniformly. A
	 * non-integer, float, or empty value reads as `null`.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @param string           $key     The parameter name.
	 * @return int|null The integer value, or null when absent or not integer-valued.
	 */
	private function read_int( \WP_REST_Request $request, string $key ): ?int {

		// An int passes straight through; a string is accepted only when it is all
		// digits (optionally signed), so a stray float or word reads as absent.
		$raw = $request->get_param( $key );
		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && preg_match( '/^-?\d+$/', $raw ) === 1 ) {
			return (int) $raw;
		}

		return null;

	}

	/**
	 * Resolves the capability required to regenerate, through the filter.
	 *
	 * Defaults to `manage_options` and is passed through
	 * `kntnt_photo_drop_manage_capability` — the same filter the admin lifecycle page
	 * uses — so the two surfaces always agree on who may reconfigure a collection. A
	 * filter that returns a non-string or empty value is a misuse and falls back to
	 * the default rather than silently disabling the gate.
	 *
	 * @since 0.11.0
	 *
	 * @return string The capability string to check.
	 */
	private function required_capability(): string {
		return self::resolve_capability( 'kntnt_photo_drop_manage_capability', self::DEFAULT_CAPABILITY );
	}

}
