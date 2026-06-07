<?php
/**
 * The per-file result of one ingestion: its outcome and what it produced.
 *
 * One `Ingestor::ingest()` call yields one of these. It pairs the closed
 * `Ingest_Outcome` with the facts a caller wants to report or act on: the source
 * label it was for, the stored main filename and absolute path when something
 * was written, and the thumbnails derived from it. The REST endpoint (#7) maps
 * this straight to a per-file JSON entry; the CLI prints it as a table row; the
 * doctor (#6) reuses the same shape — so the value object is the shared seam,
 * not a CLI-only detail.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Ingestion;

/**
 * An immutable record of how one source resolved during ingestion.
 *
 * The `outcome` is authoritative; the other fields are populated only when they
 * make sense — `stored_name` and `stored_path` are set for `Stored`/`Reencoded`
 * and `null` for `Skipped`/`Rejected`, and `thumbnails` lists the derived files
 * written (empty unless a main was stored). The static named constructors make
 * the four legal shapes the only ones a caller can build, so an impossible
 * combination (a rejected file with a stored path) cannot be represented.
 *
 * @since 0.3.0
 */
final readonly class Ingest_Result {

	/**
	 * Constructs a result from its already-consistent parts.
	 *
	 * Callers use the named constructors (`stored()`, `reencoded()`, `skipped()`,
	 * `rejected()`) rather than this directly, so each outcome's field shape is
	 * fixed in one place.
	 *
	 * @since 0.3.0
	 *
	 * @param Ingest_Outcome    $outcome     The closed per-file outcome.
	 * @param string            $source      The source label this result is for (a path or filename).
	 * @param string|null       $stored_name The stored `<original>.webp` name, or null when nothing was written.
	 * @param string|null       $stored_path The absolute stored main path, or null when nothing was written.
	 * @param array<int,string> $thumbnails  Absolute paths of thumbnails written, empty unless a main was stored.
	 */
	private function __construct(
		public Ingest_Outcome $outcome,
		public string $source,
		public ?string $stored_name,
		public ?string $stored_path,
		public array $thumbnails,
	) {}

	/**
	 * Builds the result for an already-conforming source stored verbatim.
	 *
	 * @since 0.3.0
	 *
	 * @param string            $source      The source label.
	 * @param string            $stored_name The stored main filename.
	 * @param string            $stored_path The absolute stored main path.
	 * @param array<int,string> $thumbnails  The thumbnails written from the main.
	 * @return self
	 */
	public static function stored( string $source, string $stored_name, string $stored_path, array $thumbnails ): self {
		return new self( Ingest_Outcome::Stored, $source, $stored_name, $stored_path, $thumbnails );
	}

	/**
	 * Builds the result for a source the optimiser transformed before storing.
	 *
	 * @since 0.3.0
	 *
	 * @param string            $source      The source label.
	 * @param string            $stored_name The stored main filename.
	 * @param string            $stored_path The absolute stored main path.
	 * @param array<int,string> $thumbnails  The thumbnails written from the main.
	 * @return self
	 */
	public static function reencoded(
		string $source,
		string $stored_name,
		string $stored_path,
		array $thumbnails,
	): self {
		return new self( Ingest_Outcome::Reencoded, $source, $stored_name, $stored_path, $thumbnails );
	}

	/**
	 * Builds the result for a source skipped because the target already existed.
	 *
	 * @since 0.3.0
	 *
	 * @param string $source      The source label.
	 * @param string $stored_name The existing target's stored filename.
	 * @param string $stored_path The existing target's absolute path.
	 * @return self
	 */
	public static function skipped( string $source, string $stored_name, string $stored_path ): self {
		return new self( Ingest_Outcome::Skipped, $source, $stored_name, $stored_path, [] );
	}

	/**
	 * Builds the result for a source rejected as unsafe or undecodable.
	 *
	 * @since 0.3.0
	 *
	 * @param string $source The source label.
	 * @return self
	 */
	public static function rejected( string $source ): self {
		return new self( Ingest_Outcome::Rejected, $source, null, null, [] );
	}

}
