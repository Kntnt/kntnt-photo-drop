<?php
/**
 * The editor-only read endpoint that lists discovered collections.
 *
 * Backs the block editors: the Drop Zone's collection selector (this issue) and,
 * later, the Gallery's. It exposes one GET route — `collections` — that runs the
 * filesystem discovery scan and returns each collection's slug, display name, and
 * contract fields (max width, quality, thumbnail widths) so the inspector can list
 * collections by name and show the selected contract read-only. It is a pure read:
 * it creates nothing, configures nothing, and never touches a main image. The
 * write path is the separate `Upload_Controller`; this controller is gated by
 * `edit_posts` because only someone editing a page needs the list, and the list
 * leaks no secret (the descriptors already sit as visible JSON beside the images).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Registers and serves the editor-only collection list endpoint.
 *
 * Constructed once by `Plugin` and bound to `rest_api_init`. The only injected
 * state is the collection `Repository`, which both discovers collections and
 * resolves a slug to its path; each descriptor is read per request, because the
 * filesystem is the source of truth and a list cached across requests could
 * drift from a collection copied in or deleted out of band. The external
 * interface is two callbacks — `check_permission()` and `list_collections()` —
 * wired to one read-only route.
 *
 * @since 0.5.0
 */
final class Collections_Controller {

	/**
	 * The REST namespace under which the list route is registered.
	 *
	 * Shared with the upload endpoint so the whole plugin surface lives under one
	 * versioned namespace.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-photo-drop/v1';

	/**
	 * The route that returns the discovered-collection list.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const ROUTE = '/collections';

	/**
	 * The capability required to read the list, overridable via filter.
	 *
	 * The list is needed by anyone placing a block, so it gates on `edit_posts`
	 * rather than the upload capability — a contributor configuring a Drop Zone
	 * must see the collections even if a separate policy forbids them uploading.
	 * The `kntnt_photo_drop_list_capability` filter lets a site narrow or widen
	 * it.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'edit_posts';

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * The repository is held `readonly` so a test can substitute one anchored at
	 * a temp root at construction and production code cannot swap it afterwards.
	 *
	 * @since 0.5.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * Registers the `collections` GET route.
	 *
	 * Hooked on `rest_api_init`. The route takes no arguments, runs the
	 * `edit_posts` permission check, and dispatches to `list_collections()`.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_collections' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

	}

	/**
	 * Allows the request only for users who can edit posts.
	 *
	 * The list drives the block editors' collection selectors, so it gates on the
	 * editing capability rather than the upload one (the two defend different
	 * surfaces). The capability is resolved through
	 * `kntnt_photo_drop_list_capability` so a site can change it; a filter that
	 * returns a non-string or empty value is a misuse and falls back to the
	 * default rather than silently opening the gate.
	 *
	 * @since 0.5.0
	 *
	 * @return bool True when the current user holds the required capability.
	 */
	public function check_permission(): bool {

		// Resolve the required capability through the filter, hardening its
		// return so a buggy filter can never disable the gate.
		$filtered   = apply_filters( 'kntnt_photo_drop_list_capability', self::DEFAULT_CAPABILITY );
		$capability = is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;

		return current_user_can( $capability );

	}

	/**
	 * Returns the discovered collections as an editor-friendly list.
	 *
	 * Runs the discovery scan and reads each collection's descriptor, emitting one
	 * object per collection with its `slug`, display `name`, and the three contract
	 * fields the inspector surfaces: `maxWidth` (an int, or `null` for no limit),
	 * `quality`, and the `thumbnailWidths` list. A descriptor that cannot be read
	 * (corrupt or mid-deletion) is skipped rather than failing the whole list, so
	 * one bad collection never blanks the selector. The list is already slug-sorted
	 * by the discovery scan, so the editor renders a stable order.
	 *
	 * @since 0.5.0
	 *
	 * @return \WP_REST_Response The list of `{ slug, name, maxWidth, quality, thumbnailWidths }` objects.
	 */
	public function list_collections(): \WP_REST_Response {

		// Walk the discovered slug→path map, reading each descriptor; a collection
		// whose descriptor is unreadable is omitted so a single corrupt file never
		// blanks the editor's selector.
		$collections = [];
		foreach ( $this->repository->discover() as $slug => $path ) {
			$descriptor = Descriptor::read( $path );
			if ( $descriptor === null ) {
				continue;
			}
			$collections[] = [
				'slug'            => $slug,
				'name'            => $descriptor->name,
				'maxWidth'        => $descriptor->max_width,
				'quality'         => $descriptor->quality,
				'thumbnailWidths' => $descriptor->thumbnail_widths,
			];
		}

		return new \WP_REST_Response( $collections, 200 );

	}

}
