<?php
/**
 * Orchestrates one source file into a collection through the contract boundary.
 *
 * This is the single ingestion path both `image import` and the REST upload
 * endpoint (#7) drive: confine the caller-supplied relative target with
 * `Path_Guard`, name the main with `Image_Name`, push the bytes through the
 * `Optimizer`, write the conforming main, then derive its thumbnails with the
 * `Thumbnailer`. It deliberately never writes the index — that self-heals via
 * `dirMtime` on the next gallery view (ADR-0006), so a several-hundred-file
 * batch causes no index write contention. Every call returns one `Ingest_Result`
 * carrying exactly one of the four outcomes.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Ingestion;

use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Imaging\Optimizer;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Ingests one file into one collection, reporting a per-file outcome.
 *
 * Constructed once per collection with that collection's root and descriptor (so
 * the `Path_Guard` is anchored and the contract is fixed), then driven once per
 * source file via the single deep method `ingest()`. The optimiser and
 * thumbnailer are injected so production binds the GD-backed pair while a test
 * can drive the real codec or a stub; both default to the production engine when
 * omitted. The result object is the shared seam the REST endpoint returns per
 * file and the CLI prints per row — modelled here, not at either edge.
 *
 * @since 0.3.0
 */
final class Ingestor {

	/**
	 * The guard confining every caller-supplied relative target to the root.
	 *
	 * @since 0.3.0
	 * @var Path_Guard
	 */
	private readonly Path_Guard $guard;

	/**
	 * The contract boundary that makes each source conforming.
	 *
	 * @since 0.3.0
	 * @var Optimizer
	 */
	private readonly Optimizer $optimizer;

	/**
	 * The deriver that writes each stored main's thumbnail(s).
	 *
	 * @since 0.3.0
	 * @var Thumbnailer
	 */
	private readonly Thumbnailer $thumbnailer;

	/**
	 * Constructs an ingestor anchored at one collection.
	 *
	 * The root anchors the `Path_Guard`; the descriptor fixes the output contract
	 * and the thumbnail widths every ingested file is made to honour. The engine
	 * collaborators default to the production GD-backed pair, so the CLI
	 * constructs an ingestor with just the root and descriptor; tests inject their
	 * own to exercise or fake the pixel work.
	 *
	 * @since 0.3.0
	 *
	 * @param string           $root        Absolute path to the collection root directory.
	 * @param Descriptor       $descriptor  The collection's output contract and thumbnail widths.
	 * @param Optimizer|null   $optimizer   The optimiser to use, or null for the default.
	 * @param Thumbnailer|null $thumbnailer The thumbnailer to use, or null for the default.
	 * @throws \InvalidArgumentException When the root does not resolve to a directory.
	 */
	public function __construct(
		string $root,
		private readonly Descriptor $descriptor,
		?Optimizer $optimizer = null,
		?Thumbnailer $thumbnailer = null,
	) {
		$this->guard       = new Path_Guard( $root );
		$this->optimizer   = $optimizer ?? new Optimizer();
		$this->thumbnailer = $thumbnailer ?? new Thumbnailer();
	}

	/**
	 * Ingests one source file at a caller-supplied relative target path.
	 *
	 * The relative path carries the desired location *and* filename under the
	 * collection root (`sub/dir/IMG_2024.jpg`); its sub-directories are recreated,
	 * confined by `Path_Guard`. The stored main is named by `Image_Name`
	 * (`<original>.webp`, never doubled). When the target already exists and
	 * `$overwrite` is false the source is skipped untouched; otherwise the bytes
	 * are optimised to the contract, written as the main, and thumbnailed. A path
	 * the guard rejects, or a source the optimiser cannot decode, yields a
	 * `rejected` result with nothing written. The index is never touched here.
	 *
	 * @since 0.3.0
	 *
	 * @param string $source_bytes  The raw source image bytes.
	 * @param string $relative_path The caller-supplied relative target (dir + filename) under the root.
	 * @param bool   $overwrite     Whether to overwrite an existing target main.
	 * @return Ingest_Result The single per-file outcome.
	 */
	public function ingest( string $source_bytes, string $relative_path, bool $overwrite = false ): Ingest_Result {

		// Derive the stored name from the basename alone, so a relative path's
		// directory part can never leak into the filename, and assemble the
		// relative target the guard must confine.
		$original_name = basename( $relative_path );
		$stored_name   = Image_Name::to_stored( $original_name );
		$relative_dir  = $this->relative_dir_of( $relative_path );
		$relative_main = $relative_dir === '' ? $stored_name : $relative_dir . '/' . $stored_name;

		// Confine the assembled target against the root; a null means the path was
		// hostile (traversal, scheme, NUL, symlink escape) — reject without writing.
		$target = $this->guard->resolve( $relative_main );
		if ( $target === null ) {
			Plugin::warning( "Rejected an unsafe ingestion target: {$relative_path}." );
			return Ingest_Result::rejected( $relative_path );
		}

		// Idempotency: an existing target is skipped untouched unless overwrite is
		// requested, so a re-run of a batch re-imports nothing by default.
		if ( ! $overwrite && is_file( $target ) ) {
			return Ingest_Result::skipped( $relative_path, $stored_name, $target );
		}

		// Make the source conforming; a null means it was not a decodable image or
		// could not be encoded, which is a rejection with nothing written.
		$optimized = $this->optimizer->optimize( $source_bytes, $this->descriptor );
		if ( $optimized === null ) {
			Plugin::warning( "Rejected an undecodable source during ingestion: {$relative_path}." );
			return Ingest_Result::rejected( $relative_path );
		}

		// Recreate the confined sub-directory tree and write the conforming main; a
		// write failure is a rejection so the caller never reports a phantom store.
		if ( ! $this->write_main( $target, $optimized->bytes ) ) {
			return Ingest_Result::rejected( $relative_path );
		}

		// Derive the main's thumbnail(s) from the freshly stored file. The index is
		// deliberately left untouched — it self-heals on the next gallery view.
		$thumbnails = $this->thumbnailer->generate(
			$target,
			$stored_name,
			$this->descriptor->thumbnail_widths,
			$this->descriptor->quality,
		);

		// Split the two "now stored" outcomes by whether the optimiser had to
		// transform the source, which is exactly what the upload UI wants to show.
		return $optimized->reencoded
			? Ingest_Result::reencoded( $relative_path, $stored_name, $target, $thumbnails )
			: Ingest_Result::stored( $relative_path, $stored_name, $target, $thumbnails );

	}

	/**
	 * Writes the conforming main bytes, creating the confined parent tree first.
	 *
	 * The parent directory is the confined target's own directory, so creating it
	 * stays inside the guard's confinement. A failure to create the directory or
	 * write the file is logged and reported as `false` so the caller rejects
	 * rather than claims a store that did not happen.
	 *
	 * @since 0.3.0
	 *
	 * @param string $target The confined absolute main path.
	 * @param string $bytes  The conforming WebP bytes to write.
	 * @return bool True when the main was written.
	 */
	private function write_main( string $target, string $bytes ): bool {

		// Recreate the (confined) sub-directory tree for this target before writing.
		$directory = \dirname( $target );
		if ( ! $this->ensure_dir( $directory ) ) {
			Plugin::error( "Could not create the target directory at {$directory}." );
			return false;
		}

		// Persist the main bytes; a failed write is a hard failure for this file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The plugin owns this directory tree on disk directly (ADR-0001); WP_Filesystem is the wrong abstraction for mains written outside the Media Library.
		$written = file_put_contents( $target, $bytes );
		if ( $written === false ) {
			Plugin::error( "Failed to write the main image at {$target}." );
			return false;
		}

		return true;

	}

	/**
	 * Returns the directory portion of a relative path, '' when there is none.
	 *
	 * `dirname()` returns `.` for a bare filename; that is normalised to an empty
	 * string so the caller does not prepend a spurious `./` segment before the
	 * guard sees it.
	 *
	 * @since 0.3.0
	 *
	 * @param string $relative_path The caller-supplied relative path.
	 * @return string The directory portion, or '' for a bare filename.
	 */
	private function relative_dir_of( string $relative_path ): string {
		$dir = \dirname( $relative_path );
		return $dir === '.' ? '' : $dir;
	}

	/**
	 * Creates a directory tree, preferring the WordPress helper when present.
	 *
	 * Uses `wp_mkdir_p()` inside WordPress and a recursive `mkdir()` otherwise, so
	 * a main can be written in a unit runtime without a WordPress install.
	 *
	 * @since 0.3.0
	 *
	 * @param string $directory Absolute path of the directory to create.
	 * @return bool True when the directory exists afterwards.
	 */
	private function ensure_dir( string $directory ): bool {

		// An existing directory needs nothing further.
		if ( is_dir( $directory ) ) {
			return true;
		}

		// Prefer the WordPress helper; fall back to a recursive mkdir for tests.
		if ( function_exists( 'wp_mkdir_p' ) ) {
			return wp_mkdir_p( $directory );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		return mkdir( $directory, 0755, true );

	}

}
