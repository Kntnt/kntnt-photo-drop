<?php
/**
 * Walks a collection's tree into one flattened, naturally-ordered image list.
 *
 * The Gallery renders all images under a start path as one flattened set, with
 * no in-gallery folder navigation (ADR-0005). This walker is that flattening:
 * starting at a folder inside the collection, it visits that folder (and, when
 * recursive, every sub-folder beneath it), reading each folder's self-healing
 * `index.json` through `Index_Store::get_or_rebuild` — so dimensions are taken
 * from the stored index, never re-measured here — and collects every main image
 * as a `Gallery_Item` carrying its collection-root-relative directory. The
 * result is ordered by full relative path with a natural sort, so each folder's
 * images stay contiguous and numbered names sort 2 before 10. The start path is
 * the editor-set attribute, already validated once against the root by the
 * caller; there is no per-request path input, so the walk has no traversal
 * surface.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Flattens a collection sub-tree to an ordered `Gallery_Item` list.
 *
 * Constructed with an `Index_Store` (injected so a test can drive a stubbed
 * dimension reader and so the self-heal path is shared with production). The
 * single deep method `walk()` hides the recursion, the per-folder index reads,
 * and the natural-sort ordering behind one call returning the flat list. The
 * walker holds no per-walk state — one instance serves any number of walks.
 *
 * @since 0.6.0
 */
final class Gallery_Walker {

	/**
	 * The ascending-order token: natural sort, smallest path first.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const ORDER_ASC = 'asc';

	/**
	 * The descending-order token: natural sort, largest path first.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const ORDER_DESC = 'desc';

	/**
	 * Constructs the walker with the index store it reads folders through.
	 *
	 * The store is held `readonly`; production passes the default GD-backed one,
	 * a test passes one wired to a counting or stub reader so the walk can be
	 * proven to read dimensions from the index rather than re-measuring files.
	 *
	 * @since 0.6.0
	 *
	 * @param Index_Store $index_store The self-healing per-folder index engine.
	 */
	public function __construct( private readonly Index_Store $index_store ) {}

	/**
	 * Walks the sub-tree at a start path into an ordered, flattened image list.
	 *
	 * Visits the start folder, and — when `recursive` — every sub-folder beneath
	 * it, reading each folder's index once. Every main image becomes a
	 * `Gallery_Item` stamped with the directory it lives in relative to the
	 * collection root. The flat result is then ordered by full relative path using
	 * a natural sort (so `img2` precedes `img10` and a folder's images stay
	 * contiguous), ascending or descending per `$order`.
	 *
	 * @since 0.6.0
	 *
	 * @param string $collection_root The absolute collection root directory.
	 * @param string $start_path      The validated start path relative to the root; `''` for the root.
	 * @param bool   $recursive       Whether to descend into sub-folders.
	 * @param string $order           ORDER_ASC or ORDER_DESC.
	 * @return array<int,Gallery_Item> The flattened, ordered images.
	 */
	public function walk( string $collection_root, string $start_path, bool $recursive, string $order ): array {

		// Collect every image under the start path (this folder only, or the whole
		// sub-tree when recursive), each tagged with its root-relative directory.
		$items = [];
		$this->collect( rtrim( $collection_root, '/' ), trim( $start_path, '/' ), $recursive, $items );

		// Order the flat list by full relative path with a natural sort, so numbers
		// sort numerically and each folder's images remain contiguous; reverse for
		// descending. The comparison is stable enough for gallery display.
		usort(
			$items,
			static fn ( Gallery_Item $a, Gallery_Item $b ): int => strnatcmp(
				$a->relative_path(),
				$b->relative_path(),
			),
		);
		if ( $order === self::ORDER_DESC ) {
			$items = array_reverse( $items );
		}

		return $items;

	}

	/**
	 * Recursively collects a folder's images, appending to the accumulator.
	 *
	 * Reads the folder's index once; a missing folder (the start path no longer
	 * exists) simply contributes nothing. Each main image is appended as a
	 * `Gallery_Item` carrying the relative directory. When recursive, the index's
	 * listed sub-directories are visited in turn — the index already excludes the
	 * hidden thumbnails directory, so the walk never descends into derived
	 * artifacts.
	 *
	 * @since 0.6.0
	 *
	 * @param string                  $collection_root The absolute collection root, without a trailing slash.
	 * @param string                  $relative_dir    The current directory relative to the root; `''` at the root.
	 * @param bool                    $recursive       Whether to descend into sub-folders.
	 * @param array<int,Gallery_Item> $items           The accumulator, appended in place.
	 * @return void
	 */
	private function collect( string $collection_root, string $relative_dir, bool $recursive, array &$items ): void {

		// Resolve the folder's absolute path and read its self-healing index; an
		// absent folder yields no index and so contributes no images.
		$absolute = $relative_dir === '' ? $collection_root : $collection_root . '/' . $relative_dir;
		$index    = $this->index_store->get_or_rebuild( $absolute );
		if ( $index === null ) {
			return;
		}

		// Append this folder's main images, each tagged with the directory it lives
		// in so the caller can build its URL, order it, and caption its path.
		foreach ( $index->images as $image ) {
			$items[] = new Gallery_Item( $relative_dir, $image->file, $image->width, $image->height );
		}

		// Descend into each listed sub-folder when recursive; the index's subdirs
		// already exclude our hidden artifact directory, so no derived folder is
		// ever walked as content.
		if ( $recursive ) {
			foreach ( $index->subdirs as $subdir ) {
				$child = $relative_dir === '' ? $subdir : $relative_dir . '/' . $subdir;
				$this->collect( $collection_root, $child, $recursive, $items );
			}
		}

	}

}
