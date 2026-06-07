<?php
/**
 * The read side of "the filesystem is the source of truth" for collections.
 *
 * Resolves the uploads root, ensures it exists with directory listing
 * disabled, runs the discovery scan (every directory under the root holding a
 * `collection.json`), and resolves a slug to an absolute collection path. No
 * database rows are involved: a collection copied in from another site appears
 * automatically and a deleted directory disappears, with no registry to keep
 * in sync.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

use Kntnt\Photo_Drop\Plugin;

/**
 * Locates the uploads root and the collections within it.
 *
 * The external interface is small and read-only: get the root, list the
 * discovered slugs, resolve a slug to a path. Each call recomputes from disk
 * (one cheap `wp_upload_dir()` plus one directory scan), so the answer always
 * reflects the current filesystem rather than a cached snapshot that could
 * drift from the truth.
 *
 * @since 0.1.0
 */
final class Repository {

	/**
	 * The descriptor filename that marks a directory as a collection.
	 *
	 * A directory under the root is a collection if, and only if, it contains
	 * a file by this name (the irreplaceable descriptor — see ADR-0003).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const DESCRIPTOR_FILENAME = 'collection.json';

	/**
	 * The leaf directory name appended to the WordPress uploads basedir.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const ROOT_DIRNAME = 'kntnt-photo-drop';

	/**
	 * The pattern a valid slug must match: lowercase, URL-safe, single segment.
	 *
	 * Lowercase ASCII letters, digits, and hyphens only, with no leading or
	 * trailing hyphen. This excludes path separators, dots, and case variants,
	 * so a slug can never carry traversal and is collision-free under the
	 * case-sensitive server (no two slugs differ only in case).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * Returns the absolute path to the uploads root, ensuring it exists.
	 *
	 * The root is `wp_upload_dir()['basedir'] . '/kntnt-photo-drop/'`, made
	 * overridable by the `kntnt_photo_drop_root` filter. On multisite,
	 * `wp_upload_dir()` already yields a per-site basedir, so each site gets an
	 * isolated root for free. The directory is created on first use and seeded
	 * with a blank `index.php` so a misconfigured server cannot list it and
	 * enumerate collection paths.
	 *
	 * Returns `null` when the root cannot be resolved or created — a degraded
	 * state in which no collection can be found, which callers surface rather
	 * than crash on.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The trailing-slashed absolute root path, or null on failure.
	 */
	public function get_root(): ?string {

		// Resolve the WordPress uploads basedir; a non-empty 'basedir' is
		// required to anchor the root. A populated 'error' or a missing basedir
		// means uploads are unavailable, so there is no root to offer.
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'] ?? '';
		if ( ! empty( $upload_dir['error'] ) || ! is_string( $basedir ) || $basedir === '' ) {
			Plugin::error( 'Cannot resolve the uploads basedir; collection root is unavailable.' );
			return null;
		}

		// Build the default root and let a filter override it. The filtered
		// value is normalised to a trailing slash so callers can concatenate a
		// slug directly. Anything non-string from the filter is a misuse and is
		// rejected back to the unavailable state.
		$default_root = trailingslashit( $basedir ) . self::ROOT_DIRNAME;
		$filtered     = apply_filters( 'kntnt_photo_drop_root', $default_root );
		if ( ! is_string( $filtered ) || $filtered === '' ) {
			Plugin::error( 'The kntnt_photo_drop_root filter returned a non-string or empty path.' );
			return null;
		}
		$root = trailingslashit( $filtered );

		// Ensure the directory exists and is shielded from listing before any
		// caller relies on it; a failure here leaves no usable root.
		if ( ! $this->ensure_root( $root ) ) {
			return null;
		}

		return $root;

	}

	/**
	 * Discovers every collection under the uploads root.
	 *
	 * A collection is any immediate sub-directory of the root that contains a
	 * `collection.json`. The scan is one level deep by design: collections are
	 * top-level slugs, and content sub-folders live *inside* a collection, not
	 * beside it. Returns a slug-keyed map to the absolute collection path, so a
	 * caller has both the identity and the location in one pass. The map is
	 * sorted by slug for stable, predictable listing order.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string> Map of slug to absolute collection directory path.
	 */
	public function discover(): array {

		// Without a root there is nothing to scan; return an empty discovery.
		$root = $this->get_root();
		if ( $root === null ) {
			return [];
		}

		// Enumerate immediate sub-directories whose name is a valid slug and
		// which hold a descriptor. A non-slug directory (junk, a half-renamed
		// folder) is silently skipped: it is simply not a collection. glob()
		// returns false on error, which we normalise to an empty list.
		$collections = [];
		$directories = glob( $root . '*', GLOB_ONLYDIR );
		foreach ( ( $directories === false ? [] : $directories ) as $directory ) {
			$slug = basename( $directory );
			if ( ! $this->is_valid_slug( $slug ) ) {
				continue;
			}
			if ( is_file( trailingslashit( $directory ) . self::DESCRIPTOR_FILENAME ) ) {
				$collections[ $slug ] = $directory;
			}
		}

		// Sort by slug so listings (dropdowns, the admin page) are deterministic.
		ksort( $collections );

		return $collections;

	}

	/**
	 * Resolves a slug to its absolute collection path, or null when absent.
	 *
	 * A slug resolves only when it is syntactically valid *and* names a
	 * directory that currently holds a descriptor — the same existence test
	 * the discovery scan applies. An invalid or unknown slug yields `null`
	 * rather than a path, so callers cannot act on a collection that is not
	 * there. Validation precedes any filesystem access, so a hostile slug
	 * never reaches `glob()` or the filesystem at all.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The collection identity to resolve.
	 * @return string|null The absolute collection directory path, or null.
	 */
	public function resolve_slug( string $slug ): ?string {

		// Reject anything that is not a well-formed slug before touching disk.
		if ( ! $this->is_valid_slug( $slug ) ) {
			return null;
		}

		// Without a root there is nothing to resolve against.
		$root = $this->get_root();
		if ( $root === null ) {
			return null;
		}

		// A slug names a collection only when its directory holds a descriptor;
		// a bare directory with no `collection.json` is not a collection.
		$path = $root . $slug;
		if ( ! is_dir( $path ) || ! is_file( trailingslashit( $path ) . self::DESCRIPTOR_FILENAME ) ) {
			return null;
		}

		return $path;

	}

	/**
	 * Reports whether a string is a syntactically valid collection slug.
	 *
	 * Validity is purely lexical: lowercase, URL-safe, single segment (see
	 * SLUG_PATTERN). This is the gate that guarantees a slug can never carry a
	 * path separator or traversal, so it is safe to concatenate onto the root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The candidate slug.
	 * @return bool True when the slug matches the required pattern.
	 */
	public function is_valid_slug( string $slug ): bool {
		return preg_match( self::SLUG_PATTERN, $slug ) === 1;
	}

	/**
	 * Creates the empty directory for a new collection and returns its path.
	 *
	 * This is the write counterpart to `resolve_slug()`: it owns the one
	 * filesystem mutation the lifecycle needs (an `mkdir` under the root), so
	 * the command above stays thin and free of path arithmetic. It establishes
	 * the directory only — the descriptor is written separately by the caller
	 * via `Descriptor::write()`, keeping the directory and its `collection.json`
	 * as two explicit steps the caller can order and report on.
	 *
	 * Returns `null` (creating nothing) when the slug is malformed, the root is
	 * unavailable, or a directory already exists at the slug — so a caller can
	 * map each failure to a clear message and never silently clobbers an
	 * existing collection.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug The collection identity to create.
	 * @return string|null The absolute collection directory path, or null on refusal.
	 */
	public function create_collection( string $slug ): ?string {

		// Reject a malformed slug before touching disk; the same lexical gate
		// resolve_slug() applies, so a hostile slug never reaches the filesystem.
		if ( ! $this->is_valid_slug( $slug ) ) {
			return null;
		}

		// Without a root there is nowhere to create the collection.
		$root = $this->get_root();
		if ( $root === null ) {
			return null;
		}

		// Refuse to create over an existing directory; establishment must never
		// clobber a collection (or any directory) that is already there.
		$path = $root . $slug;
		if ( is_dir( $path ) ) {
			return null;
		}

		// Create the directory tree; a failure here yields the refusal state so
		// the caller reports it rather than proceeding to write a descriptor into
		// a directory that does not exist.
		if ( ! wp_mkdir_p( $path ) ) {
			Plugin::error( "Failed to create the collection directory at {$path}." );
			return null;
		}

		return $path;

	}

	/**
	 * Removes a collection directory and everything beneath it.
	 *
	 * The destructive counterpart to discovery: it resolves the slug to an
	 * existing collection first (so only a real collection — a directory holding
	 * a descriptor — can ever be targeted) and then deletes the whole tree,
	 * mains, thumbnails, indexes and descriptor alike. The filesystem is the
	 * source of truth, so removing the directory is the entire deletion; there
	 * is no registry row to also clear.
	 *
	 * Returns `false` (deleting nothing) when the slug does not resolve to a
	 * collection, and `false` when the recursive removal fails partway, so the
	 * caller can surface the outcome rather than assume success.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug The collection identity to delete.
	 * @return bool True when the collection directory was fully removed.
	 */
	public function delete_collection( string $slug ): bool {

		// Resolve to a real collection first; an unknown or malformed slug deletes
		// nothing, and a bare directory without a descriptor is not a collection.
		$path = $this->resolve_slug( $slug );
		if ( $path === null ) {
			return false;
		}

		// Remove the entire tree. The plugin owns this directory on disk directly
		// (ADR-0001), so it deletes the files itself rather than routing through
		// the Media Library, which knows nothing about out-of-library collections.
		return $this->remove_tree( $path );

	}

	/**
	 * Recursively deletes a directory tree, returning whether it fully succeeded.
	 *
	 * Walks children depth-first, unlinking files and symlinks (never following a
	 * symlink into its target) and recursing into real sub-directories, then
	 * removes the now-empty directory itself. A single failed unlink or rmdir
	 * propagates as `false` so the caller learns the tree was not fully cleared.
	 *
	 * @since 0.2.0
	 *
	 * @param string $dir Absolute path of the directory to remove.
	 * @return bool True when the directory and all its contents were removed.
	 */
	private function remove_tree( string $dir ): bool {

		// Treat a symlink or non-directory as a leaf: unlink it rather than
		// recursing, so a symlink inside a collection cannot lead the delete out
		// of the tree. The plugin owns this directory tree on disk directly
		// (ADR-0001), so it unlinks the file rather than routing through the
		// Media Library, which knows nothing about out-of-library collections.
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin owns this directory tree on disk directly (ADR-0001); wp_delete_file is for Media-Library attachments, not files written outside it.
			return unlink( $dir );
		}

		// Delete every child first; any failure short-circuits the whole removal
		// so a partial delete is reported rather than masked.
		$entries = scandir( $dir );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			if ( ! $this->remove_tree( $dir . '/' . $entry ) ) {
				return false;
			}
		}

		// Remove the now-empty directory itself. The plugin owns this directory
		// tree on disk directly (ADR-0001), so it removes the directory itself
		// rather than routing through WP_Filesystem, which is the Media Library's
		// abstraction and is not loaded in every context the CLI runs in.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin owns this directory tree on disk directly (ADR-0001); WP_Filesystem is the wrong abstraction for collection directories written outside the Media Library.
		return rmdir( $dir );

	}

	/**
	 * Creates the root directory if missing and disables directory listing.
	 *
	 * Creates the directory tree with `wp_mkdir_p()` and drops a blank
	 * `index.php` ("Silence is golden") so a server without an explicit
	 * listing-off configuration still cannot enumerate collection paths. Both
	 * steps are idempotent: an existing directory and an existing guard file
	 * are left untouched.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root The trailing-slashed absolute root path.
	 * @return bool True when the root exists and is shielded, false on failure.
	 */
	private function ensure_root( string $root ): bool {

		// Create the directory tree on first use; a failure to create it is a
		// hard stop, since nothing downstream can work without the root.
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			Plugin::error( "Failed to create the collection root at {$root}." );
			return false;
		}

		// Seed a blank index.php so directory listing cannot enumerate paths.
		// A pre-existing guard file is honoured; a failed write is logged but
		// not fatal, since listing may already be disabled at the server level.
		$index_file = $root . 'index.php';
		if ( ! is_file( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The plugin owns this directory tree on disk directly (ADR-0001); WP_Filesystem is the wrong abstraction for a guard file written outside the Media Library.
			$written = file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
			if ( $written === false ) {
				Plugin::warning( "Could not write the directory-listing guard at {$index_file}." );
			}
		}

		return true;

	}

}
