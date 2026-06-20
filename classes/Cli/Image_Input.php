<?php
/**
 * Pure helpers for the image command — source reading, expansion, and paths.
 *
 * The WP-CLI image command is deliberately thin: the decidable rules that touch
 * neither WP-CLI nor an image library live here, in a small helper that can be
 * unit-tested directly. That is reading a source file's bytes, expanding a source
 * into the concrete files to import — a file is itself, a directory is walked
 * recursively for every image under it — and deriving the relative target path a
 * source maps to under a collection root: a relative source keeps its
 * sub-directories so the tree is recreated, while an absolute source collapses to
 * its basename (it has no meaningful place in the tree). Keeping these off the
 * command also keeps them off WP-CLI's subcommand reflection, so only the real
 * verbs (`import`, `delete`) surface.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

use Kntnt\Photo_Drop\Ingestion\Source_Filter;

/**
 * Stateless reader, expander, and path-deriver for the image command.
 *
 * Every method is pure apart from `read_source()` and the directory walk in
 * `expand_source()`, which touch the filesystem. Holding no mutable state, a
 * single instance is safe to reuse across a whole import batch.
 *
 * @since 0.3.0
 */
final class Image_Input {

	/**
	 * Constructs the helper with the type pre-filter the directory walk applies.
	 *
	 * The filter is a pure, stateless collaborator with no dependencies, so it is
	 * default-constructed here rather than injected; passing one is allowed so a
	 * test can substitute it.
	 *
	 * @since 0.13.0
	 *
	 * @param Source_Filter $filter The RAW/video deny-list applied while walking a directory.
	 */
	public function __construct(
		private readonly Source_Filter $filter = new Source_Filter(),
	) {}

	/**
	 * Expands a source into the concrete import units it stands for.
	 *
	 * A unit pairs the filesystem path to read (`read`) with the relative target
	 * path under the collection root (`relative`) and the label the report shows
	 * for it (`label`). A file source — or a path that does not exist — stays a
	 * single unit, so the existing single-source behaviour holds verbatim, down to
	 * a missing file flowing on to be reported as a rejection. A directory source
	 * fans out into one unit per contained image, walked recursively with the Drop
	 * Zone's folder semantics (`walk_directory()`).
	 *
	 * @since 0.13.0
	 *
	 * @param string $source The source path as given on the command line.
	 * @return list<array{read:string,relative:string,label:string}> The import units.
	 */
	public function expand_source( string $source ): array {

		// A directory fans out into its contained images; anything else stays one
		// unit so a file — or a typo'd path — behaves exactly as it did before.
		if ( is_dir( $source ) ) {
			return $this->walk_directory( $source );
		}

		$relative = $this->relative_target( $source );
		return [
			[
				'read'     => $source,
				'relative' => $relative,
				'label'    => $source,
			],
		];

	}

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
	 * Walks a directory recursively into one import unit per contained image.
	 *
	 * Mirrors the Drop Zone's folder semantics: the directory's own name becomes
	 * the top-level prefix (a relative directory keeps its sub-tree, an absolute
	 * one collapses to its basename — reusing `relative_target()` so the
	 * absolute/relative and Windows-path rules are not duplicated), under which
	 * each file keeps its path relative to the directory. Two classes of entry are
	 * dropped before producing a unit, so they never reach a report row: hidden
	 * filesystem noise (any path component with a leading dot — `.DS_Store`,
	 * AppleDouble `._*`, and the contents of `.git`/`.thumbnails` and the like) and
	 * RAW/video files the deny-list refuses. The walk does not follow directory
	 * symlinks, so a symlink loop cannot occur. Filesystem order is unstable across
	 * hosts, so the units are sorted by their relative target for a deterministic
	 * report and on-disk effect, keeping each directory's files contiguous.
	 *
	 * @since 0.13.0
	 *
	 * @param string $dir The directory source as given on the command line.
	 * @return list<array{read:string,relative:string,label:string}> The import units, sorted by relative target.
	 */
	private function walk_directory( string $dir ): array {

		// Derive the top-level prefix from the directory itself, trimming one
		// trailing separator so a `photos/2024/` source does not yield a double
		// slash once each file's relative sub-path is appended.
		$prefix = rtrim( $this->relative_target( $dir ), '/' );

		// Walk depth-first over files only (LEAVES_ONLY), forcing forward slashes
		// (UNIX_PATHS) so the relative sub-path is separator-clean, and skipping an
		// unreadable sub-directory rather than aborting the batch (CATCH_GET_CHILD).
		$flags  = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;
		$walker = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, $flags ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD,
		);

		// Turn each surviving file into a unit, dropping hidden noise and
		// RAW/video siblings before any byte is read.
		$units = [];
		foreach ( $walker as $file ) {

			// The iterator yields each leaf typed as mixed; narrow it to the
			// SplFileInfo the directory walk always produces so the rest of the
			// loop — and the static analyser — can rely on its accessors.
			if ( ! $file instanceof \SplFileInfo ) {
				continue;
			}
			$sub = $walker->getSubPathName();
			if ( $this->has_hidden_segment( $sub ) || ! $this->filter->should_import( $file->getFilename() ) ) {
				continue;
			}
			$relative = $prefix . '/' . $sub;
			$units[]  = [
				'read'     => $file->getPathname(),
				'relative' => $relative,
				'label'    => $relative,
			];
		}

		// Sort by the relative target so the report and the writes are
		// host-independent and a directory's files stay contiguous.
		usort( $units, static fn ( array $a, array $b ): int => strcmp( $a['relative'], $b['relative'] ) );

		return $units;

	}

	/**
	 * Reports whether any component of a relative path has a leading dot.
	 *
	 * One leading-dot segment anywhere — a hidden file or any ancestor hidden
	 * directory — marks the whole path as filesystem noise the walk must drop. The
	 * sub-path is always forward-slash separated (the walk forces `UNIX_PATHS`), so
	 * splitting on `/` is sufficient.
	 *
	 * @since 0.13.0
	 *
	 * @param string $sub_path The file's path relative to the walked directory.
	 * @return bool True when a component is hidden and the file must be skipped.
	 */
	private function has_hidden_segment( string $sub_path ): bool {
		foreach ( explode( '/', $sub_path ) as $segment ) {
			if ( str_starts_with( $segment, '.' ) ) {
				return true;
			}
		}
		return false;
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
