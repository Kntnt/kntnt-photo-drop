<?php
/**
 * Re-derives a collection's full and thumbnail renditions at new widths — the
 * browser-driven half of regenerate-then-flip (ADR-0013).
 *
 * Editing a collection's re-derivable settings (the full and thumbnail
 * width/quality pairs) cannot take effect by simply rewriting the descriptor: the
 * new-width files do not exist yet, so the gallery's `srcset` would point at
 * missing renditions. Instead the change is rolled out by **regenerate-then-flip**
 * — a browser-driven batch re-derives every main at the *new* widths while the
 * gallery keeps serving the *old* ones, and only once every main is done is the
 * descriptor flipped to the new widths and the old buckets pruned. This service is
 * the engine that batch drives: it enumerates the collection's mains so a REST
 * controller can re-derive them one per request (mirroring the Drop Zone's
 * one-file-per-request model), realises each main's new renditions through the
 * shared `Thumbnailer`, and finalises by flipping the descriptor and pruning the
 * width buckets the flip retires.
 *
 * The safety property is structural: the descriptor — the gallery's source of
 * truth for which widths to serve — is never touched until `finalise()`, so an
 * interrupted run leaves the old renditions live and a re-run simply re-derives
 * and finalises again. The derived files are losslessly re-derivable from the
 * main, so re-deriving an already-present rendition is harmless.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Drives the regenerate-then-flip re-derive of one collection.
 *
 * Constructed per request with the collection root, its current descriptor, and a
 * `Thumbnailer` (the production GD-backed deriver by default; a test injects its
 * own). The external interface is deliberately small and batch-shaped:
 * `main_count()` and `regenerate_main()` let a caller re-derive one main per
 * request at the target widths, and `finalise()` performs the flip and prune once
 * every main is done. The pure tier decision behind the prune — which old width
 * buckets a flip retires — is the static `stale_widths()`, kept dependency-free so
 * it is unit-testable in isolation.
 *
 * @since 0.11.0
 */
final class Rendition_Regenerator {

	/**
	 * The deriver that re-writes each main's full and thumbnail renditions.
	 *
	 * @since 0.11.0
	 * @var Thumbnailer
	 */
	private readonly Thumbnailer $thumbnailer;

	/**
	 * The self-healing index store the completeness sweep reads main widths from.
	 *
	 * @since 0.11.0
	 * @var Index_Store
	 */
	private readonly Index_Store $index_store;

	/**
	 * Constructs a regenerator anchored at one collection.
	 *
	 * The root and descriptor fix the collection being re-derived and its immutable
	 * upload contract (which the flip carries over). The thumbnailer and index store
	 * both default to their production implementations, so the REST controller
	 * constructs the regenerator with just the root and descriptor; a test injects its
	 * own deriver to exercise the mechanics without real pixel work, or its own index
	 * store to pin the completeness sweep's width source.
	 *
	 * @since 0.11.0
	 *
	 * @param string           $root        Absolute path to the collection root directory.
	 * @param Descriptor       $descriptor  The collection's current descriptor.
	 * @param Thumbnailer|null $thumbnailer The deriver to re-write renditions with, or null for GD.
	 * @param Index_Store|null $index_store The index store the sweep reads widths from, or null for the default.
	 */
	public function __construct(
		private readonly string $root,
		private readonly Descriptor $descriptor,
		?Thumbnailer $thumbnailer = null,
		?Index_Store $index_store = null,
	) {
		$this->thumbnailer = $thumbnailer ?? new Thumbnailer();
		$this->index_store = $index_store ?? new Index_Store();
	}

	/**
	 * Returns the old width buckets a flip to the new widths retires.
	 *
	 * The pure decision the prune rests on: a width bucket survives a flip only when
	 * the new configuration still uses it, so the stale set is every old width not
	 * present among the new ones. Keeping it a total function of two integer lists —
	 * no descriptor, no filesystem — makes the safety-critical tier decision
	 * (deleting a live bucket would break the gallery; leaking one grows the disk)
	 * directly unit-testable. A degenerate flip whose new full width equals an old
	 * thumbnail width keeps that bucket, because it is configured after the flip.
	 *
	 * @since 0.11.0
	 *
	 * @param array<int,int> $old_widths The derived widths before the flip.
	 * @param array<int,int> $new_widths The derived widths after the flip.
	 * @return array<int,int> The old widths no longer configured, in input order.
	 */
	public static function stale_widths( array $old_widths, array $new_widths ): array {
		return array_values( array_filter(
			$old_widths,
			static fn ( int $width ): bool => ! in_array( $width, $new_widths, true ),
		) );
	}

	/**
	 * Returns the number of conforming mains the collection holds.
	 *
	 * The batch cursor's upper bound: the controller re-derives mains `0 …
	 * main_count() - 1`, one per request. Counts every `*.webp` main across the
	 * collection's content folders (a symlinked directory is never descended into, so
	 * the count never reaches outside the collection). The list is recomputed from
	 * disk per request, so a main added or removed mid-run shifts the count rather
	 * than relying on a stale snapshot.
	 *
	 * @since 0.11.0
	 *
	 * @return int The number of stored mains.
	 */
	public function main_count(): int {
		return count( $this->mains() );
	}

	/**
	 * Re-derives the main at the given cursor index at the target widths.
	 *
	 * Realises one main's new full and thumbnail renditions through the shared
	 * `Thumbnailer`, which computes the tier-skip plan from the main's own width and
	 * refuses any symlinked write itself, so the new-width files land in their
	 * `kntnt-thumbnails/<width>/` buckets *alongside* the live old-width ones — the
	 * gallery keeps serving the old renditions throughout. An index past the end of
	 * the current main list (the list shrank between requests) is a no-op returning
	 * zero, never an error. Returns the count of derived files written for that main.
	 *
	 * @since 0.11.0
	 *
	 * @param int $index             The zero-based cursor into the collection's mains.
	 * @param int $full_width        The target full-image width.
	 * @param int $full_quality      The target full-image quality.
	 * @param int $thumbnail_width   The target thumbnail width.
	 * @param int $thumbnail_quality The target thumbnail quality.
	 * @return int The number of derived files written for the addressed main.
	 */
	public function regenerate_main(
		int $index,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): int {

		// An out-of-range cursor — the main list shrank since the count was read — is a
		// harmless no-op rather than an error, mirroring the upload path's resilience.
		$mains = $this->mains();
		if ( ! isset( $mains[ $index ] ) ) {
			return 0;
		}

		// Re-derive that main at the *target* widths; the deriver writes the new
		// buckets beside the old ones, so the descriptor (still on the old widths) keeps
		// the gallery serving until the flip.
		$main_path = $mains[ $index ];
		return count( $this->thumbnailer->generate(
			$main_path,
			basename( $main_path ),
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		) );

	}

	/**
	 * Reports whether the main at the cursor has all its new renditions on disk.
	 *
	 * The per-batch failure signal the controller propagates: after `regenerate_main()`
	 * re-derives the addressed main, this confirms — against `Rendition_Plan` for that
	 * main's own width — that every rendition the target widths call for actually landed
	 * on disk. A `generate()` that silently wrote fewer renditions than the main needed
	 * (an undecodable main, an over-ceiling main, an encode failure) is exactly what the
	 * count-only return could not distinguish from a legitimate tier-collapse; this does,
	 * so a shortfall surfaces as a real error and the browser driver stops before the
	 * finalise. An out-of-range cursor (the main list shrank since the count was read) is
	 * vacuously complete — there is nothing to write at that index — mirroring
	 * `regenerate_main()`'s no-op.
	 *
	 * @since 0.11.0
	 *
	 * @param int $index             The zero-based cursor into the collection's mains.
	 * @param int $full_width        The target full-image width.
	 * @param int $full_quality      The target full-image quality.
	 * @param int $thumbnail_width   The target thumbnail width.
	 * @param int $thumbnail_quality The target thumbnail quality.
	 * @return bool True when the addressed main's expected new renditions are all present.
	 */
	public function main_complete(
		int $index,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): bool {

		// An out-of-range cursor has nothing to write, so it is complete by definition.
		$mains = $this->mains();
		if ( ! isset( $mains[ $index ] ) ) {
			return true;
		}

		// Defer the disambiguation to the deriver: it owns the decode, the tier-skip
		// plan, and the symlink stance, so the completeness check matches what it writes.
		$main_path = $mains[ $index ];
		return $this->thumbnailer->renditions_present(
			$main_path,
			basename( $main_path ),
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);

	}

	/**
	 * Flips the descriptor to the target widths and prunes the retired buckets.
	 *
	 * The "flip" half of regenerate-then-flip, run only after every main has been
	 * re-derived at the target widths: it rewrites `collection.json` with the new
	 * full and thumbnail pairs (the immutable upload contract carried over via
	 * `Descriptor::with_renditions()`), which is the single instant the gallery
	 * switches to the new renditions, then prunes every old `kntnt-thumbnails/<width>/`
	 * bucket the new configuration no longer uses so the collection does not grow
	 * forever. The flip is ordered before the prune so a crash between them leaves
	 * extra (harmless) buckets, never a missing one. Returns the number of stale
	 * thumbnail files pruned, or `false` when the descriptor could not be rewritten
	 * (in which case nothing is pruned and the old renditions stay live).
	 *
	 * Before anything is touched it runs a completeness sweep: every main's expected
	 * new-width renditions must already be present on disk (verify-then-flip). If any
	 * main is missing even one rendition — because its re-derive silently wrote fewer
	 * files than its width required — the flip is aborted, **nothing is pruned**, and a
	 * failure is returned, so the gallery is never flipped onto renditions that were
	 * never written and the old fallback is never deleted (ADR-0013, "flip only on
	 * success"). Only once the whole collection is verified complete is the descriptor
	 * rewritten and the retired buckets pruned.
	 *
	 * The four width/quality arguments are the *effective* targets the verify-sweep
	 * and the prune compare against — they must already be resolved, so an unset
	 * (collapsed) tier arrives as its unbounded effective width (`PHP_INT_MAX`), which
	 * simply matches no real on-disk bucket (ADR-0013/#71). What the descriptor flips
	 * *to* is a separate question: when `$flip_to` is given, that raw target descriptor
	 * is written verbatim, so an unset re-derivable field persists as JSON null rather
	 * than being frozen to a concrete value; when it is omitted (the REST batch path,
	 * which only ever flips to concrete widths) the descriptor flips via
	 * `with_renditions()` from the four arguments, as before.
	 *
	 * @since 0.11.0
	 *
	 * @param int             $full_width        The effective target full-image width.
	 * @param int             $full_quality      The effective target full-image quality.
	 * @param int             $thumbnail_width   The effective target thumbnail width.
	 * @param int             $thumbnail_quality The effective target thumbnail quality.
	 * @param Descriptor|null $flip_to           The raw target descriptor to write, or null to flip from the arguments.
	 * @return int|false The number of pruned thumbnail files, or false when verification or the flip failed.
	 */
	public function finalise(
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
		?Descriptor $flip_to = null,
	): int|false {

		// Verify the whole collection before touching anything: a single main missing a
		// new-width rendition aborts the flip and prunes nothing, so the descriptor never
		// points the gallery at files that were never written.
		if ( ! $this->all_renditions_present( $full_width, $full_quality, $thumbnail_width, $thumbnail_quality ) ) {
			Plugin::error( "Refused to flip {$this->root}: a main is missing its new-width renditions on disk." );
			return false;
		}

		// Compute which old buckets the flip retires before the descriptor changes,
		// comparing the still-current effective widths against the effective targets so a
		// collapsed (unbounded) tier never lists a real bucket as stale.
		$old_renditions = $this->descriptor->effective_renditions();
		$old_widths     = [ $old_renditions['full_width'], $old_renditions['thumbnail_width'] ];
		$new_widths     = [ $full_width, $thumbnail_width ];
		$stale          = self::stale_widths( $old_widths, $new_widths );

		// Flip the descriptor — the one moment the gallery starts serving the new
		// renditions. The raw target descriptor wins when supplied (so an unset field
		// persists as null); otherwise the flip is built from the effective arguments. A
		// failed write leaves the old descriptor (and old renditions) live and prunes
		// nothing, so the collection stays consistent.
		$flipped = $flip_to ?? $this->descriptor->with_renditions(
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);
		if ( ! $flipped->write( $this->root ) ) {
			Plugin::error( "Failed to flip the descriptor for {$this->root}; old renditions stay live." );
			return false;
		}

		// Prune the retired buckets across every content folder now that the descriptor
		// no longer references them; the count feeds the controller's final reply.
		return $this->prune_width_buckets( $stale );

	}

	/**
	 * Reports whether every main's expected new-width renditions are on disk.
	 *
	 * The completeness sweep behind verify-then-flip: it walks every stored main and
	 * confirms that each main's renditions for the target widths are present on disk.
	 * The first incomplete main short-circuits the sweep to `false`, which aborts the
	 * flip. An empty collection (no mains) is vacuously complete, since a no-image
	 * collection is a valid flip target.
	 *
	 * To learn each main's width — all the completeness check needs — it reads the
	 * per-folder index through the self-healing `Index_Store` rather than decoding the
	 * main, sparing a full pixel-buffer allocation per main across the whole collection
	 * (a real cost at hundreds or thousands of images). Sourcing the width from the
	 * index is safe precisely because the store is mtime-validated: a main rewritten at
	 * a different width bumps its folder's mtime, which forces a rebuild before this
	 * sweep reads it, so the width is always current — never a stale cache. A main the
	 * index cannot vouch for (absent because the folder has no index yet and could not
	 * be rebuilt, a symlinked main the index skips by design, or one whose dimensions
	 * could not be measured) falls back to the deriver's decode-based
	 * `renditions_present()`, which reads the width by decoding and treats an
	 * undecodable main as incomplete. Either way the safety guarantee holds: the flip
	 * proceeds only when every expected rendition is verified present on disk.
	 *
	 * @since 0.11.0
	 *
	 * @param int $full_width        The target full-image width.
	 * @param int $full_quality      The target full-image quality.
	 * @param int $thumbnail_width   The target thumbnail width.
	 * @param int $thumbnail_quality The target thumbnail quality.
	 * @return bool True when every main is complete on disk at the target widths.
	 */
	private function all_renditions_present(
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): bool {

		// Sweep folder by folder so each folder's index — the width source — is read at
		// most once; the first main missing a rendition aborts the whole sweep, so the
		// flip happens only when the collection is whole.
		foreach ( $this->content_folders() as $folder ) {

			// Read this folder's main widths from the self-healing index once; a main it
			// covers is checked from its stored width with no decode, a main it does not
			// cover falls back to the decode-based check.
			$widths = $this->folder_main_widths( $folder );
			foreach ( $this->folder_mains( $folder ) as $name ) {
				$main_path = $folder . '/' . $name;
				$complete  = isset( $widths[ $name ] )
					? $this->thumbnailer->renditions_present_for_width(
						$widths[ $name ],
						$main_path,
						$name,
						$full_width,
						$full_quality,
						$thumbnail_width,
						$thumbnail_quality,
					)
					: $this->thumbnailer->renditions_present(
						$main_path,
						$name,
						$full_width,
						$full_quality,
						$thumbnail_width,
						$thumbnail_quality,
					);
				if ( ! $complete ) {
					return false;
				}
			}
		}

		return true;

	}

	/**
	 * Maps a folder's main filenames to their pixel widths via the self-healing index.
	 *
	 * The width source for the completeness sweep: it resolves the folder to a current
	 * `Index` through `Index_Store::get_or_rebuild()` — a cache hit measures no image,
	 * and a stale or missing index is rebuilt (reading dimensions once via the cheap
	 * header probe, never a full decode) — then returns each stored main's `file =>
	 * width`. A folder with no index (it could not be rebuilt, e.g. it has vanished)
	 * yields an empty map, so every main falls back to the decode-based check.
	 *
	 * @since 0.11.0
	 *
	 * @param string $folder Absolute path to a content folder.
	 * @return array<string,int> Each main filename mapped to its pixel width.
	 */
	private function folder_main_widths( string $folder ): array {

		// A folder with no resolvable index leaves every main to the decode fallback.
		$index = $this->index_store->get_or_rebuild( $folder );
		if ( $index === null ) {
			return [];
		}

		// Project the index's image entries to a filename => width lookup the sweep
		// indexes by each main's stored name.
		$widths = [];
		foreach ( $index->images as $entry ) {
			$widths[ $entry->file ] = $entry->width;
		}

		return $widths;

	}

	/**
	 * Removes the given width buckets from every content folder, symlink-safe.
	 *
	 * Walks each content folder's hidden corral and deletes every `<width>/` bucket
	 * whose width is in the stale set — the files first, then the now-empty directory.
	 * Every symlink guard the rest of the plugin enforces applies: a symlinked corral
	 * or bucket is never followed, a symlink inside a bucket is never unlinked, and a
	 * bucket that does not empty out is left in place with a warning, so a planted
	 * link can never route a delete outside the collection. Returns the number of
	 * thumbnail files removed.
	 *
	 * @since 0.11.0
	 *
	 * @param array<int,int> $stale The width buckets to remove.
	 * @return int The number of thumbnail files removed.
	 */
	private function prune_width_buckets( array $stale ): int {

		// Nothing stale means nothing to prune; the common same-width-only flip (a
		// quality-only change) takes this fast path.
		if ( $stale === [] ) {
			return 0;
		}

		// Visit each folder's corral and remove the stale numeric buckets; a symlinked
		// or absent corral is skipped so the prune never reaches outside the collection.
		$pruned = 0;
		foreach ( $this->content_folders() as $folder ) {
			$corral = $folder . '/' . Index::THUMBNAILS_DIRNAME;
			if ( is_link( $corral ) || ! is_dir( $corral ) ) {
				continue;
			}
			foreach ( $stale as $width ) {
				$pruned += $this->remove_bucket( $corral . '/' . $width );
			}
		}

		return $pruned;

	}

	/**
	 * Empties and removes one width bucket, returning the files removed.
	 *
	 * Only plain files are unlinked — a symlink or a sub-directory is not a thumbnail
	 * the plugin wrote, so it is left in place and the bucket then survives (with a
	 * warning) rather than being forced away. A symlinked or missing bucket is left
	 * untouched. Returns the number of thumbnail files removed.
	 *
	 * @since 0.11.0
	 *
	 * @param string $bucket Absolute path to the `<width>/` bucket to remove.
	 * @return int The number of thumbnail files removed.
	 */
	private function remove_bucket( string $bucket ): int {

		// A missing or symlinked bucket is nothing of ours to remove.
		if ( is_link( $bucket ) || ! is_dir( $bucket ) ) {
			return 0;
		}

		// Unlink each plain file in the bucket; a symlink or sub-directory is refused so
		// nothing outside the collection can be touched through a planted link.
		$removed = 0;
		$entries = scandir( $bucket );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $bucket . '/' . $entry;
			if ( ! is_link( $path ) && is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin owns this directory tree on disk directly (ADR-0001); wp_delete_file is for Media-Library attachments, not files written outside it.
				if ( unlink( $path ) ) {
					++$removed;
				}
			}
		}

		// Remove the bucket itself only once it is verifiably empty; anything that
		// survived the unlink pass (a symlink, a sub-directory) keeps it alive.
		$remaining = scandir( $bucket );
		if ( $remaining !== false && count( $remaining ) === 2 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin owns this directory tree on disk directly (ADR-0001); WP_Filesystem is the wrong abstraction for artifacts written outside the Media Library.
			rmdir( $bucket );
		} else {
			Plugin::warning( "Left the stale rendition bucket at {$bucket} in place; it did not empty out." );
		}

		return $removed;

	}

	/**
	 * Returns the absolute paths of every stored main in the collection.
	 *
	 * Walks the collection's content folders depth-first and collects each top-level
	 * `*.webp` main, sorted for a deterministic cursor order so the same index names
	 * the same main across requests (as long as the set is unchanged). The hidden
	 * corral is never treated as content and a symlinked directory is never descended
	 * into, mirroring the doctor's and the delete path's symlink stance.
	 *
	 * @since 0.11.0
	 *
	 * @return array<int,string> The absolute main paths, in stable order.
	 */
	private function mains(): array {

		// Collect each folder's mains in folder order, then sort the flat list so the
		// batch cursor is stable and independent of directory-entry order.
		$mains = [];
		foreach ( $this->content_folders() as $folder ) {
			foreach ( $this->folder_mains( $folder ) as $main ) {
				$mains[] = $folder . '/' . $main;
			}
		}
		sort( $mains );

		return $mains;

	}

	/**
	 * Returns the stored main filenames present directly in a folder.
	 *
	 * A main is a top-level `*.webp` file; thumbnails live under the hidden corral, so
	 * the extension alone classifies a main here.
	 *
	 * @since 0.11.0
	 *
	 * @param string $folder Absolute path to a content folder.
	 * @return array<int,string> The folder's main `*.webp` filenames.
	 */
	private function folder_mains( string $folder ): array {

		// Keep only top-level `*.webp` entries; the corral and sub-directories are not
		// mains.
		$mains   = [];
		$entries = scandir( $folder );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}
			$path = $folder . '/' . $entry;
			if ( ! is_dir( $path ) && str_ends_with( strtolower( $entry ), '.webp' ) ) {
				$mains[] = $entry;
			}
		}

		return $mains;

	}

	/**
	 * Returns the absolute paths of every content folder in the collection.
	 *
	 * The root counts as a content folder; the walk descends into real
	 * sub-directories but never into the hidden corral (which holds artifacts, not
	 * content) and never through a symlink (which would let the regenerate reach
	 * outside the collection). The list is depth-first and stable.
	 *
	 * @since 0.11.0
	 *
	 * @return array<int,string> The absolute content-folder paths, root first.
	 */
	private function content_folders(): array {

		// Seed at the root and accumulate every content sub-directory depth-first.
		$folders = [ $this->root ];
		$this->collect_subfolders( $this->root, $folders );

		return $folders;

	}

	/**
	 * Appends every content sub-directory of a folder, recursing depth-first.
	 *
	 * The hidden corral is skipped so the walk stays on content, and a symlinked
	 * directory is never descended into — following one would let the regenerate write
	 * and prune outside the collection, breaking the symlink confinement the rest of
	 * the plugin enforces.
	 *
	 * @since 0.11.0
	 *
	 * @param string            $folder   The folder to scan.
	 * @param array<int,string> &$folders The accumulator the sub-folders are appended to.
	 */
	private function collect_subfolders( string $folder, array &$folders ): void {

		// Record each content sub-directory then recurse, leaving the corral alone and
		// never following a symlink out of the collection.
		$entries = scandir( $folder );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}
			$path = $folder . '/' . $entry;
			if ( ! is_link( $path ) && is_dir( $path ) ) {
				$folders[] = $path;
				$this->collect_subfolders( $path, $folders );
			}
		}

	}

}
