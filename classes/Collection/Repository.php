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
