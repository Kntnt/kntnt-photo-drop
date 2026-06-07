<?php
/**
 * Pure helpers for the image command — source reading and path derivation.
 *
 * The WP-CLI image command is deliberately thin: the decidable rules that touch
 * neither WP-CLI nor an image library live here, in a small helper that can be
 * unit-tested directly. That is reading a source file's bytes, and deriving the
 * relative target path a source maps to under a collection root — a relative
 * source keeps its sub-directories so the tree is recreated, while an absolute
 * source collapses to its basename (it has no meaningful place in the tree).
 * Keeping these off the command also keeps them off WP-CLI's subcommand
 * reflection, so only the real verbs (`import`, `delete`) surface.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

/**
 * Stateless reader and path-deriver for the image command.
 *
 * Every method is pure apart from `read_source()`, which reads one file from
 * disk and returns its bytes or `null`. Holding no state, a single instance is
 * safe to reuse across a whole import batch.
 *
 * @since 0.3.0
 */
final class Image_Input {

	/**
	 * Derives the relative target path a source maps to under the collection root.
	 *
	 * A relative source path keeps its directory structure, so `photos/2024/x.jpg`
	 * is recreated as that same sub-tree inside the collection (the `Path_Guard`
	 * confines it before any write). An absolute source has no meaningful place in
	 * the tree, so it collapses to its basename and lands at the collection root.
	 * The returned string is always relative and is handed straight to the
	 * ingestor, which confines it.
	 *
	 * @since 0.3.0
	 *
	 * @param string $source The source path as given on the command line.
	 * @return string The relative target path (dir + filename) under the root.
	 */
	public function relative_target( string $source ): string {

		// An absolute source has no relative tree to preserve, so only its filename
		// carries over; a relative source keeps its sub-directories verbatim for the
		// guard to confine.
		if ( $this->is_absolute( $source ) ) {
			return $this->last_segment( $source );
		}

		return $source;

	}

	/**
	 * Reads a source file's bytes, or null when it cannot be read.
	 *
	 * The one impure helper: it touches the filesystem to load a source the user
	 * named on the command line. A missing or unreadable file yields `null` so the
	 * command can report that one source and carry on with the rest of the batch.
	 *
	 * @since 0.3.0
	 *
	 * @param string $source Absolute or relative path to the source image.
	 * @return string|null The file bytes, or null when missing or unreadable.
	 */
	public function read_source( string $source ): ?string {

		// The plugin reads an arbitrary operator-named file here, not a Media
		// Library attachment, so it uses the plain filesystem call directly.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = is_file( $source ) ? file_get_contents( $source ) : false;

		return $bytes === false ? null : $bytes;

	}

	/**
	 * Returns the final path segment, splitting on both `/` and `\`.
	 *
	 * PHP's `basename()` does not split on backslashes on a Unix host, so a
	 * Windows absolute path copied onto the Linux server would otherwise keep its
	 * drive and directories. Splitting on either separator extracts just the
	 * filename, so the source lands at the collection root rather than recreating
	 * a `C:\…` pseudo-directory.
	 *
	 * @since 0.3.0
	 *
	 * @param string $path The path to reduce to its last segment.
	 * @return string The final filename component.
	 */
	private function last_segment( string $path ): string {
		$segments = preg_split( '#[\\\\/]+#', $path );
		$segments = $segments === false ? [] : $segments;
		return (string) end( $segments );
	}

	/**
	 * Reports whether a path is absolute (Unix root or a Windows drive/UNC).
	 *
	 * The server target is Linux, where a leading slash is the only absolute form;
	 * the Windows forms are recognised too so a path copied from elsewhere is
	 * classified correctly rather than treated as a relative sub-tree.
	 *
	 * @since 0.3.0
	 *
	 * @param string $path The path to classify.
	 * @return bool True when the path is absolute.
	 */
	public function is_absolute( string $path ): bool {

		// A leading slash is the Unix absolute form.
		if ( str_starts_with( $path, '/' ) ) {
			return true;
		}

		// A drive-letter prefix (`C:\`) or a UNC prefix (`\\server`) is the Windows
		// absolute form; recognising it keeps a foreign absolute path from being
		// mistaken for a relative sub-tree to recreate.
		return preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) === 1 || str_starts_with( $path, '\\\\' );

	}

}
