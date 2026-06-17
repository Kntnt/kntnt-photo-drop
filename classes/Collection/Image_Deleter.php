<?php
/**
 * The shared collection-image deletion routine.
 *
 * Removing a collection image is one piece of knowledge with two callers — the
 * CLI `image delete` verb and the gallery's trash REST write-path (ADR-0015) —
 * so it lives here once rather than in either. The main image is the unit of
 * truth: deleting it removes the main and every derived artifact slaved to it
 * (the full rendition and the thumbnail, both living under the hidden
 * `.kntnt-thumbnails/<width>/` corral), and the per-folder index is left to
 * self-heal on the next gallery render — unlinking the main bumps the folder
 * mtime, which is exactly the signal the mtime-validated index distrusts
 * (ADR-0003). There is no recycle bin; collection images are plain files, not
 * posts (ADR-0001).
 *
 * Target resolution is the trust boundary: a caller-supplied relative path is
 * `Path_Guard`-confined to the collection root, then mapped to its stored
 * `<original>.webp` form, and accepted only when that exact `.webp` file exists.
 * A foreign non-`.webp` file therefore never resolves to a main, so a delete can
 * never remove it; a path escaping the root resolves to nothing at all.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

use Kntnt\Photo_Drop\Storage\Index;

/**
 * Resolves and removes a collection's main image and its derived artifacts.
 *
 * A stateless service: every method is a pure function of its arguments and the
 * filesystem, so it holds no construction state and can be instantiated freely by
 * either caller. The boundary is deliberately narrow — `resolve_main()` to turn a
 * relative path into a confined absolute main, and `delete()` to remove that main
 * and its derived family — because both callers must name and remove files
 * identically for "derived artifacts are slaved to the main" to hold uniformly.
 *
 * @since 0.13.0
 */
final class Image_Deleter {

	/**
	 * Resolves a relative path to an existing main image inside the collection.
	 *
	 * Confines the path with `Path_Guard` first, so nothing outside the collection
	 * can be named. Only a stored main — a `<original>.webp` file — can ever
	 * resolve: the `Image_Name::to_stored()` form of the confined path is computed
	 * (a no-op for a path already ending in `.webp`, otherwise `<original>.webp`)
	 * and accepted only when that exact `.webp` file exists. A foreign file like
	 * `notes.txt` therefore never resolves to a main, so a delete can never remove
	 * it. Returns the absolute main path, or `null` when nothing resolves.
	 *
	 * Note this resolves to the *stored main name only*. A caller that must also
	 * reject a confined-but-non-main path which happens to be a `.webp` (a derived
	 * thumbnail under the hidden corral, the descriptor) adds that classification on
	 * top — the trash controller does, via the shared `Image_Name::is_stored_main()`
	 * rule plus the thumbnails-segment check (ADR-0015).
	 *
	 * @since 0.13.0
	 *
	 * @param string $collection_path The absolute collection root.
	 * @param string $relative        The caller-supplied relative path.
	 * @return string|null The absolute main path, or null when no main matches.
	 */
	public function resolve_main( string $collection_path, string $relative ): ?string {

		// Confine the path to the collection; a rejected path resolves to no main.
		$guard    = new Path_Guard( $collection_path );
		$resolved = $guard->resolve( $relative );
		if ( $resolved === null ) {
			return null;
		}

		// Map the confined path to its stored main name — a no-op when it already
		// ends in `.webp`, otherwise `<original>.webp` — so only a real main is ever
		// targeted and a foreign non-`.webp` file can never be deleted.
		$main = \dirname( $resolved ) . '/' . Image_Name::to_stored( basename( $resolved ) );

		return is_file( $main ) ? $main : null;

	}

	/**
	 * Deletes a main image and every derived artifact slaved to it.
	 *
	 * Removes the main first, then every derived file the main produced (the full
	 * rendition and the thumbnail, each at `.kntnt-thumbnails/<width>/<name>.webp`).
	 * The per-folder index is deliberately not touched: unlinking the main bumps the
	 * folder mtime, so the mtime-validated index regenerates on the next gallery
	 * render (ADR-0003) without this routine writing it. Returns whether the main
	 * itself was removed — the derived removal is best-effort (the doctor heals a
	 * stray), so a present-but-unremovable derived file does not fail the delete, but
	 * a main that could not be removed reports `false` so the caller can surface it.
	 *
	 * @since 0.13.0
	 *
	 * @param string $main_path The absolute path to the main image to remove.
	 * @return bool True when the main image was removed.
	 */
	public function delete( string $main_path ): bool {

		// Remove the main first — it is the unit of truth; a failed main removal is
		// the only outcome that fails the whole delete.
		if ( ! $this->unlink_file( $main_path ) ) {
			return false;
		}

		// Remove every derived artifact the main produced (full + thumbnail); the
		// index self-heals on the next render off the now-bumped folder mtime.
		$this->remove_derived( $main_path );

		return true;

	}

	/**
	 * Removes every derived artifact derived from a main, returning how many were removed.
	 *
	 * The full rendition and the thumbnail both live at
	 * `.kntnt-thumbnails/<width>/<name>.webp`; the configured widths may have changed
	 * since the main was imported, so this scans every width sub-directory present and
	 * removes the one named for this main rather than trusting the current descriptor.
	 * It never recurses or deletes anything but this main's own derived files, so a
	 * sibling main's artifacts and any foreign file are never touched.
	 *
	 * @since 0.13.0
	 *
	 * @param string $main_path Absolute path to the main image being deleted.
	 * @return int The number of derived files removed.
	 */
	private function remove_derived( string $main_path ): int {

		// The artifacts root sits beside the main, inside the content folder.
		$folder      = \dirname( $main_path );
		$stored_name = basename( $main_path );
		$thumbs_root = $folder . '/' . Index::THUMBNAILS_DIRNAME;
		if ( ! is_dir( $thumbs_root ) ) {
			return 0;
		}

		// Walk each width sub-directory and remove this main's artifact there; only
		// the file named exactly for this main is touched, never a sibling's.
		$removed = 0;
		$entries = scandir( $thumbs_root );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$candidate = $thumbs_root . '/' . $entry . '/' . $stored_name;
			if ( is_file( $candidate ) && $this->unlink_file( $candidate ) ) {
				++$removed;
			}
		}

		return $removed;

	}

	/**
	 * Unlinks a single file, returning whether it was removed.
	 *
	 * The plugin owns this directory tree on disk directly (ADR-0001), so it unlinks
	 * the file rather than routing through `wp_delete_file`, which is for
	 * Media-Library attachments, not files written outside it. A path that is not a
	 * file (already gone) reports `false` without attempting the unlink, so a
	 * best-effort derived-artifact sweep over a partially-removed set raises no
	 * warning on the missing entries.
	 *
	 * @since 0.13.0
	 *
	 * @param string $path Absolute path to the file to remove.
	 * @return bool True when the file was removed.
	 */
	private function unlink_file( string $path ): bool {

		// A path that is not a file cannot be removed; report false without the unlink
		// so a missing entry raises no warning.
		if ( ! is_file( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin owns this directory tree on disk directly (ADR-0001); wp_delete_file is for Media-Library attachments, not files written outside it.
		return unlink( $path );

	}

}
