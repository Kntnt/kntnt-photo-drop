<?php
/**
 * The self-healing read/write engine for per-folder indexes.
 *
 * Reads, validates, and rebuilds a content folder's hidden `index.json`. The
 * index is a regenerable cache validated by the content folder's `mtime`: one
 * `stat` decides whether the cache can be trusted. When the stored `dirMtime`
 * still matches the folder, the cached index is returned with no image measured
 * — the fast path that makes a gallery view cheap. On any mismatch (a file
 * added, removed, renamed, or moved bumps the folder mtime; a move bumps both
 * the source and destination folders), or a missing or corrupt index, the store
 * rebuilds from the directory, reads each main image's dimensions once, and
 * writes the index back. The directory is always the truth, so a hand-deleted
 * index is transparently regenerated; the upload handler deliberately never
 * writes the index, leaving the next read to self-heal once for the whole batch
 * (ADR-0003).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

use Kntnt\Photo_Drop\Plugin;

/**
 * Resolves a folder to a current `Index`, healing the cache when stale.
 *
 * The deep entry point is `get_or_rebuild()`, which hides the entire
 * cache-or-rebuild decision behind one call that always returns a current index
 * (or `null` when the folder is absent). The dimension reader is injected, so
 * the cache-hit path can be proven to measure no image and a rebuild can be
 * driven with a stub reader. The store holds no per-folder state — one instance
 * serves any number of folders — so it composes cleanly into the gallery
 * renderer and the doctor.
 *
 * Directory mtimes have one-second granularity, which opens a race the
 * mtime-validated cache (ADR-0003) must close: a rebuild that stamps second T,
 * followed by another main image written within that same second T, leaves the
 * folder's mtime unchanged — so a persisted index from that rebuild would be
 * treated as fresh forever and silently hide the new image. The store therefore
 * persists a rebuilt index only when the stamped second has fully passed on the
 * injected wall clock; a same-second rebuild returns the fresh in-memory index
 * unpersisted, and the next read simply rebuilds again until the folder has
 * been quiescent past the second boundary. The invariant this buys: every
 * persisted `dirMtime` is strictly older than its persist moment, so any later
 * mutation is guaranteed to produce a visible mtime mismatch.
 *
 * @since 0.1.0
 */
final class Index_Store {

	/**
	 * The stored extension every main image carries (see Image_Name).
	 *
	 * The rebuild scan treats a top-level `*.webp` in the content folder as a
	 * main image; thumbnails live under the hidden directory and never in the
	 * content folder, so the extension alone classifies a main image here.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const MAIN_EXTENSION = '.webp';

	/**
	 * The reader used to measure each main image during a rebuild.
	 *
	 * Injected so production uses the GD/`wp_getimagesize()` reader while a test
	 * can pass a counting or stubbed reader and assert the cache-hit path never
	 * touches it.
	 *
	 * @since 0.1.0
	 * @var Dimension_Reader
	 */
	private readonly Dimension_Reader $dimension_reader;

	/**
	 * The wall clock the persist guard compares a stamped mtime against.
	 *
	 * A rebuilt index is persisted only when its stamped folder mtime lies
	 * strictly before the current clock second (see the class description and
	 * ADR-0003). Injected so tests can freeze or advance the second boundary
	 * deterministically; production uses `time()`.
	 *
	 * @since 0.2.0
	 * @var \Closure():int
	 */
	private readonly \Closure $clock;

	/**
	 * Constructs the store with a dimension reader and a wall clock.
	 *
	 * Defaults to the WordPress/GD-backed reader and `time()`, so production
	 * callers construct it with no arguments; tests inject their own to observe
	 * or fake the one expensive step a rebuild performs and to pin the
	 * same-second persist guard without sleeping across a real second boundary.
	 *
	 * @since 0.1.0
	 *
	 * @param Dimension_Reader|null $dimension_reader The reader to measure images, or null for the default.
	 * @param (\Closure():int)|null $clock            The current-Unix-time source, or null for `time()`.
	 */
	public function __construct( ?Dimension_Reader $dimension_reader = null, ?\Closure $clock = null ) {
		$this->dimension_reader = $dimension_reader ?? new Wp_Dimension_Reader();
		$this->clock            = $clock ?? time( ... );
	}

	/**
	 * Returns a current index for a content folder, healing the cache if stale.
	 *
	 * Performs exactly one `stat` of the content folder. When a parseable cached
	 * index records a `dirMtime` equal to the folder's current mtime, that
	 * cached index is returned and no image is measured — the fast path. On any
	 * mismatch, or a missing or unparseable index, the index is rebuilt from the
	 * directory and written back (deferred to a later read while the folder's
	 * mtime second is still running — see `rebuild()`). Returns `null` only when
	 * the folder itself cannot be `stat`-ed (it does not exist) — there is then
	 * no folder to index.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to a content folder within a collection.
	 * @return Index|null A current index for the folder, or null when the folder is absent.
	 */
	public function get_or_rebuild( string $folder_path ): ?Index {

		// One stat answers the whole question. A missing folder has nothing to
		// index, so there is no index to return.
		$current_mtime = $this->folder_mtime( $folder_path );
		if ( $current_mtime === null ) {
			return null;
		}

		// Trust the cache when it parses and its recorded mtime matches the
		// folder's. read() never invokes the dimension reader, so this fast path
		// measures no image.
		$cached = $this->read( $folder_path );
		if ( $cached !== null && $cached->dir_mtime === $current_mtime ) {
			return $cached;
		}

		// Otherwise the folder changed (or the index is missing/corrupt); rebuild
		// from the directory, measuring each main image once, and persist.
		return $this->rebuild( $folder_path );

	}

	/**
	 * Rebuilds the index from the directory and writes it back.
	 *
	 * Scans the content folder for top-level main `*.webp` images, measures each
	 * one's dimensions exactly once through the injected reader, lists the
	 * folder's sub-directories, and writes the index (images sorted ascending by
	 * filename) stamped with the folder mtime. Symlinked entries are skipped
	 * outright — they did not enter through the optimisation boundary and a
	 * linked directory could cycle the recursive gallery walk. Exposed for the
	 * doctor's `--repair --force` path; the normal read path reaches it via
	 * `get_or_rebuild()`.
	 *
	 * When the stamped mtime falls in the still-running clock second, the index
	 * is returned but *not* persisted — the folder could change again within
	 * that second without a visible mtime bump, so caching it would be unsafe
	 * (see the class description and ADR-0003).
	 *
	 * The hidden `.kntnt-thumbnails/` directory is created *before* the folder
	 * mtime is stamped, because creating that sub-directory itself bumps the
	 * content folder's mtime. Stamping after creation means a freshly built index
	 * records the folder's settled mtime, so the very next read is a stable cache
	 * hit instead of a spurious second rebuild. Once the directory exists, writing
	 * `index.json` inside it touches only the thumbnails dir, never the content
	 * folder, so the stamp stays valid.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @return Index|null The freshly built index, or null when the folder is absent.
	 */
	public function rebuild( string $folder_path ): ?Index {

		// A missing folder cannot be indexed at all.
		if ( ! is_dir( $folder_path ) ) {
			return null;
		}

		// Create the hidden artifact directory first, so its creation bumps the
		// folder mtime now, before we read that mtime to stamp the index.
		if ( ! $this->ensure_thumbnails_dir( $folder_path ) ) {
			return null;
		}

		// Stamp the settled folder mtime — taken after the thumbnails dir exists,
		// so a subsequent index-file write leaves it unchanged.
		$mtime = $this->folder_mtime( $folder_path );
		if ( $mtime === null ) {
			return null;
		}

		// Walk the folder once, partitioning entries into main images and
		// sub-directories. Our own hidden artifact directory is never a content
		// sub-folder, so it is excluded from subdirs.
		$images   = [];
		$subdirs  = [];
		$entries  = scandir( $folder_path );
		$absolute = rtrim( $folder_path, '/' ) . '/';
		foreach ( $entries === false ? [] : $entries as $entry ) {

			// Skip the self/parent links and our own hidden artifact directory.
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}

			// A symlink is never indexed, mirroring the delete path's treat-as-leaf
			// stance: following a linked directory could cycle the recursive
			// gallery walk forever or surface out-of-tree content, and a linked
			// file did not enter through the optimisation boundary.
			$path = $absolute . $entry;
			if ( is_link( $path ) ) {
				Plugin::debug( "Skipping the symlink at {$path} during the index rebuild." );
				continue;
			}

			// A sub-directory is recorded for completeness so a recursive gallery
			// walk can descend; a main `*.webp` is measured and recorded.
			if ( is_dir( $path ) ) {
				$subdirs[] = $entry;
			} elseif ( $this->is_main_image( $entry ) ) {
				$measured = $this->measure( $path, $entry );
				if ( $measured !== null ) {
					$images[] = $measured;
				}
			}
		}

		// Sort images ascending by filename and subdirs ascending, so the stored
		// order is deterministic and stable across rebuilds.
		usort( $images, static fn ( Image_Entry $a, Image_Entry $b ): int => strcmp( $a->file, $b->file ) );
		sort( $subdirs );

		// Materialise the rebuilt index, but persist it only once the folder has
		// been quiescent past the stamped second: mtimes have one-second
		// granularity, so a folder that can still change within second T would
		// otherwise leave a persisted index that matches the mtime forever while
		// silently missing the late arrivals (see the class description and
		// ADR-0003). An unpersisted index simply costs one more rebuild on the
		// next read.
		$index = new Index( $mtime, $subdirs, $images );
		if ( $mtime < ( $this->clock )() ) {
			$this->write( $folder_path, $index );
		}

		return $index;

	}

	/**
	 * Reads and decodes a folder's `index.json` without measuring any image.
	 *
	 * Returns the cached index, or `null` when the file is missing, unreadable,
	 * not valid JSON, or lacks a usable `dirMtime`. This is the cache-hit path
	 * and never invokes the dimension reader — dimensions come straight from the
	 * stored entries. A malformed image row is skipped rather than failing the
	 * whole read, but a missing or non-integer `dirMtime` makes the cache
	 * untrustworthy and yields `null`, forcing a rebuild.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @return Index|null The cached index, or null when it cannot be trusted.
	 */
	public function read( string $folder_path ): ?Index {

		// Read the raw bytes; a missing index is the normal "rebuild me" signal,
		// not an error, so it is silent here. The plugin owns this directory tree
		// on disk directly (ADR-0001), so it reads the file rather than routing
		// through the Media Library.
		$file = $this->path_for( $folder_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = is_file( $file ) ? file_get_contents( $file ) : false;
		if ( $raw === false ) {
			return null;
		}

		// Decode; a non-object payload or a missing dirMtime means the cache is
		// untrustworthy, so we force a rebuild by returning null.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['dirMtime'] ) || ! is_int( $data['dirMtime'] ) ) {
			return null;
		}

		// Map the stored arrays back to value objects, skipping any malformed
		// image row rather than corrupting the whole read.
		$subdirs = $this->read_subdirs( $data['subdirs'] ?? [] );
		$images  = $this->read_images( $data['images'] ?? [] );

		return new Index( $data['dirMtime'], $subdirs, $images );

	}

	/**
	 * Writes an index to a folder as stable, pretty JSON.
	 *
	 * Ensures the hidden `.kntnt-thumbnails/` directory exists, then writes
	 * `index.json` pretty-printed in the value object's fixed key order, so a
	 * re-write with unchanged data is byte-identical. Returns whether the write
	 * succeeded; a failure is a warning, not a hard error, since the folder will
	 * simply self-heal again on the next read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @param Index  $index       The index to persist.
	 * @return bool True when the index was written, false on failure.
	 */
	public function write( string $folder_path, Index $index ): bool {

		// Ensure the hidden artifact directory exists before writing into it; a
		// failure to create it means there is nowhere to store the cache.
		if ( ! $this->ensure_thumbnails_dir( $folder_path ) ) {
			return false;
		}

		// Encode in the value object's fixed key order for a deterministic file.
		// A failed encode is reported, never written half-formed.
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json  = wp_json_encode( $index->to_array(), $flags );
		if ( $json === false ) {
			Plugin::warning( "Failed to encode the index for {$folder_path}." );
			return false;
		}

		// Publish the cache file atomically so a concurrent gallery read never
		// parses a torn index; a failed write leaves the folder to self-heal
		// again next time, so it is a warning rather than a hard error.
		$file = $this->path_for( $folder_path );
		if ( ! Atomic_Writer::write( $file, $json . "\n" ) ) {
			Plugin::warning( "Could not write the index at {$file}." );
			return false;
		}

		return true;

	}

	/**
	 * Measures one main image and returns its entry, or null when unreadable.
	 *
	 * The single place the dimension reader is invoked, so a rebuild costs
	 * exactly one read per main image. A file the reader cannot measure is
	 * dropped (the caller skips a null) rather than recorded with bogus zero
	 * dimensions.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Absolute path to the main image.
	 * @param string $filename The image filename relative to its folder.
	 * @return Image_Entry|null The measured entry, or null when unreadable.
	 */
	private function measure( string $path, string $filename ): ?Image_Entry {

		// Read the dimensions once; an unreadable file yields no entry.
		$dimensions = $this->dimension_reader->dimensions( $path );
		if ( $dimensions === null ) {
			return null;
		}

		return new Image_Entry( $filename, $dimensions[0], $dimensions[1] );

	}

	/**
	 * Maps stored sub-directory data back to a clean list of strings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The raw `subdirs` value from the decoded index.
	 * @return array<int,string> The sub-directory names, non-strings dropped.
	 */
	private function read_subdirs( mixed $value ): array {

		// Keep only string entries so a tampered file cannot inject non-strings
		// into the subdir list.
		$subdirs = [];
		foreach ( is_array( $value ) ? $value : [] as $entry ) {
			if ( is_string( $entry ) ) {
				$subdirs[] = $entry;
			}
		}

		return $subdirs;

	}

	/**
	 * Maps stored image rows back to Image_Entry value objects.
	 *
	 * A row missing `file`, `width`, or `height`, or carrying the wrong type, is
	 * skipped — a partially corrupt index degrades to fewer images rather than a
	 * failed read.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The raw `images` value from the decoded index.
	 * @return array<int,Image_Entry> The decoded entries, malformed rows dropped.
	 */
	private function read_images( mixed $value ): array {

		// Validate each row's shape before constructing an entry; anything that
		// is not a well-formed `{ file, width, height }` is dropped.
		$images = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if (
				is_array( $row )
				&& isset( $row['file'], $row['width'], $row['height'] )
				&& is_string( $row['file'] )
				&& is_int( $row['width'] )
				&& is_int( $row['height'] )
			) {
				$images[] = new Image_Entry( $row['file'], $row['width'], $row['height'] );
			}
		}

		return $images;

	}

	/**
	 * Reports whether a filename is a stored main image.
	 *
	 * A main image is a top-level `*.webp` in the content folder, matched
	 * case-insensitively on the extension. Thumbnails never live here (they are
	 * under the hidden directory), so the extension alone classifies a main.
	 *
	 * @since 0.1.0
	 *
	 * @param string $filename The directory entry name.
	 * @return bool True when the entry is a main image.
	 */
	private function is_main_image( string $filename ): bool {
		return str_ends_with( strtolower( $filename ), self::MAIN_EXTENSION );
	}

	/**
	 * Stats a folder and returns its mtime, or null when it does not exist.
	 *
	 * The single `stat` that drives the self-heal: the returned mtime is what
	 * `get_or_rebuild()` compares against the index's stored `dirMtime`. The
	 * stat cache is cleared for the path first so a directory mutation made
	 * earlier in the same request is observed rather than served stale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @return int|null The folder mtime, or null when the folder is absent.
	 */
	private function folder_mtime( string $folder_path ): ?int {

		// A non-directory has no mtime to compare against the cached dirMtime.
		if ( ! is_dir( $folder_path ) ) {
			return null;
		}

		// Clear the stat cache for this path so an in-request mutation is seen,
		// then take the live mtime.
		clearstatcache( true, $folder_path );
		$mtime = filemtime( $folder_path );

		return $mtime === false ? null : $mtime;

	}

	/**
	 * Returns the absolute path of the index file inside a folder.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @return string The absolute `index.json` path.
	 */
	private function path_for( string $folder_path ): string {
		return rtrim( $folder_path, '/' ) . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME;
	}

	/**
	 * Ensures the hidden `.kntnt-thumbnails/` directory exists in a folder.
	 *
	 * Creating this sub-directory bumps the content folder's mtime, so the
	 * rebuild calls this *before* stamping the index, keeping the stamped mtime
	 * settled. Idempotent: an existing directory is left untouched.
	 *
	 * @since 0.1.0
	 *
	 * @param string $folder_path Absolute path to the content folder.
	 * @return bool True when the directory exists afterwards, false on failure.
	 */
	private function ensure_thumbnails_dir( string $folder_path ): bool {

		// Create the directory only when missing; report a failure since there is
		// then nowhere to store the cache.
		$thumbs_dir = rtrim( $folder_path, '/' ) . '/' . Index::THUMBNAILS_DIRNAME;
		if ( ! is_dir( $thumbs_dir ) && ! $this->make_directory( $thumbs_dir ) ) {
			Plugin::warning( "Could not create the thumbnails directory at {$thumbs_dir}." );
			return false;
		}

		return true;

	}

	/**
	 * Creates a directory tree, preferring the WordPress helper when present.
	 *
	 * Uses `wp_mkdir_p()` inside WordPress and a recursive `mkdir()` otherwise,
	 * so the index can heal itself in a unit runtime without a WordPress install.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory Absolute path of the directory to create.
	 * @return bool True when the directory exists afterwards.
	 */
	private function make_directory( string $directory ): bool {

		// Prefer the WordPress helper; fall back to a recursive mkdir so the
		// rebuild path works in a plain-PHP test runtime too.
		if ( function_exists( 'wp_mkdir_p' ) ) {
			return wp_mkdir_p( $directory );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		return is_dir( $directory ) || mkdir( $directory, 0755, true );

	}

}
