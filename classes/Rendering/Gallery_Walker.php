<?php
/**
 * Walks a collection's tree into one flattened, naturally-ordered image list.
 *
 * The Gallery renders all images under a start path as one flattened set, with
 * no in-gallery folder navigation (ADR-0005, ordering amended by ADR-0015). This
 * walker is that flattening: starting at a folder inside the collection, it
 * visits that folder (and, when recursive, every sub-folder beneath it), reading
 * each folder's self-healing `index.json` through `Index_Store::get_or_rebuild`
 * — so dimensions are taken from the stored index, never re-measured here — and
 * collects every main image as a `Gallery_Item` carrying its
 * collection-root-relative directory. The result follows a **pre-order tree
 * traversal**: a folder's own images first (natural sort within the level, so
 * `img2` precedes `img10`), then each subfolder in natural-sorted name order,
 * each subfolder fully explored before the next is visited — so a top-level
 * image precedes every subfolder's contents instead of interleaving by string
 * comparison. The start path is the editor-set attribute, already validated once
 * against the root by the caller; there is no per-request path input, so the
 * walk has no traversal surface.
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
 * and the pre-order traversal ordering behind one call returning the flat list.
 * The walker holds no per-walk state — one instance serves any number of walks.
 *
 * @since 0.6.0
 */
final class Gallery_Walker {

	/**
	 * The ascending-order token: natural sort within each level, smallest first.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const ORDER_ASC = 'asc';

	/**
	 * The descending-order token: the within-level natural sort reversed.
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
	 * collection root. Ordering is a pre-order tree traversal performed during the
	 * walk: at each level a folder's own images come first (natural sort, so
	 * `img2` precedes `img10`), then its subfolders in natural-sorted name order,
	 * each subfolder fully explored before the next. `ORDER_DESC` reverses the
	 * sort within each level — both the own-images order and the subfolder order —
	 * while preserving the own-images-before-subfolders structure, so date-based
	 * path components naturally surface the newest folders first.
	 *
	 * @since 0.6.0
	 *
	 * @param string $collection_root The absolute collection root directory.
	 * @param string $start_path      The validated start path relative to the root; `''` for the root.
	 * @param bool   $recursive       Whether to descend into sub-folders.
	 * @param string $order           ORDER_ASC or ORDER_DESC.
	 * @return array<int,Gallery_Item> The flattened, pre-order-traversed images.
	 */
	public function walk( string $collection_root, string $start_path, bool $recursive, string $order ): array {

		// Collect every image under the start path in pre-order — the recursion
		// itself imposes the order, so no post-hoc sort of the flat list is needed.
		$items = [];
		$this->collect( rtrim( $collection_root, '/' ), trim( $start_path, '/' ), $recursive, $order, $items );

		return $items;

	}

	/**
	 * Pre-order-collects a folder's images, appending to the accumulator.
	 *
	 * Reads the folder's index once; a missing folder (the start path no longer
	 * exists) simply contributes nothing. The folder's own main images are
	 * natural-sorted within the level and appended first, each tagged with the
	 * relative directory; then, when recursive, its sub-folders are natural-sorted
	 * by name and visited in turn, each fully explored before the next — the
	 * pre-order shape that puts a folder's images ahead of any subfolder's. The
	 * index sorts both lists lexically (`strcmp`/`sort`), so the natural sort is
	 * re-applied here rather than trusted from the index. `ORDER_DESC` reverses
	 * each within-level sort while the structure stays own-images-first; the
	 * index's subdirs already exclude the hidden thumbnails directory, so no
	 * derived folder is ever walked as content.
	 *
	 * @since 0.6.0
	 *
	 * @param string                  $collection_root The absolute collection root, without a trailing slash.
	 * @param string                  $relative_dir    The current directory relative to the root; `''` at the root.
	 * @param bool                    $recursive       Whether to descend into sub-folders.
	 * @param string                  $order           ORDER_ASC or ORDER_DESC.
	 * @param array<int,Gallery_Item> $items           The accumulator, appended in place.
	 * @return void
	 */
	private function collect(
		string $collection_root,
		string $relative_dir,
		bool $recursive,
		string $order,
		array &$items,
	): void {

		// Resolve the folder's absolute path and read its self-healing index; an
		// absent folder yields no index and so contributes no images.
		$absolute = $relative_dir === '' ? $collection_root : $collection_root . '/' . $relative_dir;
		$index    = $this->index_store->get_or_rebuild( $absolute );
		if ( $index === null ) {
			return;
		}

		// Append this folder's own images first, natural-sorted by filename within
		// the level (reversed for descending) so they precede every subfolder's.
		$images = $index->images;
		usort( $images, static fn ( $a, $b ): int => strnatcmp( $a->file, $b->file ) );
		if ( $order === self::ORDER_DESC ) {
			$images = array_reverse( $images );
		}
		foreach ( $images as $image ) {
			$items[] = new Gallery_Item( $relative_dir, $image->file, $image->width, $image->height );
		}

		// Then descend into each sub-folder in natural-sorted name order (reversed
		// for descending), each fully explored before the next — the pre-order step
		// that keeps a folder's images ahead of its subfolders' contents.
		if ( $recursive ) {
			$subdirs = $index->subdirs;
			usort( $subdirs, static fn ( string $a, string $b ): int => strnatcmp( $a, $b ) );
			if ( $order === self::ORDER_DESC ) {
				$subdirs = array_reverse( $subdirs );
			}
			foreach ( $subdirs as $subdir ) {
				$child = $relative_dir === '' ? $subdir : $relative_dir . '/' . $subdir;
				$this->collect( $collection_root, $child, $recursive, $order, $items );
			}
		}

	}

}
