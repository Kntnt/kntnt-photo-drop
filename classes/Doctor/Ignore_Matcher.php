<?php
/**
 * Decides whether a foreign path is OS junk the doctor should skip silently.
 *
 * The doctor warns about every foreign file except a short built-in list of
 * operating-system junk — macOS metadata (`.DS_Store`, `._*`, `.Spotlight-V100`,
 * `.Trashes`, `.fseventsd`) and Windows shell droppings (`Thumbs.db`,
 * `desktop.ini`). A caller extends that list with `--ignore=<glob>` (one or more
 * comma-separated globs). This matcher holds that decision, pure and testable:
 * it never touches the filesystem and answers from the path alone. A user's own
 * `.thumbnails` directory is deliberately *not* on the list — it is foreign, not
 * ours, because our artifacts live under the namespaced `.kntnt-thumbnails`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Doctor;

/**
 * Matches a path against the built-in OS-junk list plus caller-supplied globs.
 *
 * Constructed once per doctor run with the parsed `--ignore` globs, then queried
 * per foreign path. Matching is on the path's basename for the built-in list (OS
 * junk is identified by name wherever it sits) and on both the basename and the
 * full collection-relative path for caller globs (so `--ignore=raw/*` can target
 * a sub-tree). All matching is via `fnmatch()`, so `*` and `?` work as a shell
 * would expand them; a literal name like `.DS_Store` is just a glob with no
 * wildcard.
 *
 * The two lists are exposed separately because they carry different authority:
 * the built-in list runs *before* main classification (an AppleDouble
 * `._photo.jpg.webp` must never be mistaken for a main), while a caller glob is
 * only consulted for files that are *not* mains — a stored main can never be
 * de-classified by `--ignore`, so its derived artifacts never become deletable
 * orphans through a broad glob. `matches()` remains the union for callers that
 * only need the ignored-vs-foreign verdict.
 *
 * @since 0.4.0
 */
final class Ignore_Matcher {

	/**
	 * The built-in OS-junk globs matched against a path's basename.
	 *
	 * These cover macOS Finder/Spotlight metadata and AppleDouble sidecars
	 * (`._name`), plus the two common Windows shell files. Each is matched
	 * case-sensitively against the basename via `fnmatch()`, so `._*` catches every
	 * AppleDouble file while the rest are exact names. A user's own `.thumbnails` is
	 * intentionally absent — it is a foreign file, not OS junk (ADR-0003).
	 *
	 * @since 0.4.0
	 * @var array<int,string>
	 */
	private const BUILTIN_GLOBS = [
		'.DS_Store',
		'._*',
		'.Spotlight-V100',
		'.Trashes',
		'.fseventsd',
		'Thumbs.db',
		'desktop.ini',
	];

	/**
	 * The caller-supplied globs from `--ignore`, already split and trimmed.
	 *
	 * @since 0.4.0
	 * @var array<int,string>
	 */
	private readonly array $extra_globs;

	/**
	 * Constructs a matcher with the caller's extra ignore globs.
	 *
	 * The raw `--ignore` value is one or more comma-separated globs; it is split
	 * here so the command stays free of parsing. Empty segments (a stray comma, an
	 * absent flag) are dropped, so an unset `--ignore` yields a matcher that
	 * applies only the built-in list.
	 *
	 * @since 0.4.0
	 *
	 * @param string|null $ignore_value The raw `--ignore` flag value, or null when absent.
	 */
	public function __construct( ?string $ignore_value ) {
		$this->extra_globs = $this->split_globs( $ignore_value );
	}

	/**
	 * Reports whether a collection-relative path should be ignored.
	 *
	 * A path is ignored when its basename matches a built-in OS-junk glob, or when
	 * its basename or full relative path matches a caller-supplied `--ignore`
	 * glob. Everything else is a real foreign file the doctor warns about.
	 *
	 * @since 0.4.0
	 *
	 * @param string $relative_path The path relative to the collection root.
	 * @return bool True when the path is OS junk or matches a caller glob.
	 */
	public function matches( string $relative_path ): bool {
		return $this->matches_builtin( $relative_path ) || $this->matches_caller( $relative_path );
	}

	/**
	 * Reports whether a path is on the built-in OS-junk list.
	 *
	 * This is the half that runs *before* main classification: OS junk is
	 * identified by name wherever it sits, so an AppleDouble `._photo.jpg.webp`
	 * is recognised as junk even though it ends in `.webp` and would otherwise
	 * look like a main.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative_path The path relative to the collection root.
	 * @return bool True when the path's basename is built-in OS junk.
	 */
	public function matches_builtin( string $relative_path ): bool {

		// OS junk is identified by name wherever it sits, so the built-in list is
		// matched against the basename alone.
		$basename = basename( $relative_path );
		foreach ( self::BUILTIN_GLOBS as $glob ) {
			if ( fnmatch( $glob, $basename ) ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Reports whether a path matches a caller-supplied `--ignore` glob.
	 *
	 * This is the half that is only consulted for files that are not mains: a
	 * caller glob silences foreign-file warnings but carries no authority to
	 * de-classify a stored main, so `--ignore=raw/*` never turns the mains under
	 * `raw/` into orphan-producing foreigners.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative_path The path relative to the collection root.
	 * @return bool True when a caller glob matches the basename or the full path.
	 */
	public function matches_caller( string $relative_path ): bool {

		// A caller glob may target a name (`*.tmp`) or a sub-tree (`raw/*`), so it
		// is tried against both the basename and the full relative path.
		$basename = basename( $relative_path );
		foreach ( $this->extra_globs as $glob ) {
			if ( fnmatch( $glob, $basename ) || fnmatch( $glob, $relative_path ) ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Splits a raw comma-separated `--ignore` value into clean globs.
	 *
	 * Trims whitespace around each segment and drops empties, so `" a , ,b "`
	 * yields `[ 'a', 'b' ]` and an absent or blank flag yields `[]`.
	 *
	 * @since 0.4.0
	 *
	 * @param string|null $ignore_value The raw flag value, or null when absent.
	 * @return array<int,string> The cleaned globs, possibly empty.
	 */
	private function split_globs( ?string $ignore_value ): array {

		// An absent flag contributes no globs at all.
		if ( $ignore_value === null || $ignore_value === '' ) {
			return [];
		}

		// Split on commas, trim each segment, and keep only the non-empty ones so a
		// trailing or doubled comma never produces an empty glob that matches all.
		$segments = array_map( 'trim', explode( ',', $ignore_value ) );

		return array_values( array_filter( $segments, static fn ( string $glob ): bool => $glob !== '' ) );

	}

}
