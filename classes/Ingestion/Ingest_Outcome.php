<?php
/**
 * The per-file outcome of ingesting one source into a collection.
 *
 * Every path that brings a file into a collection — `image import` today, the
 * REST upload endpoint (#7) tomorrow, the doctor's repair (#6) — reports its
 * result one file at a time as exactly one of these four cases. Modelling the
 * outcome as a closed, backed enum means the REST layer can return its string
 * value directly in a JSON response, the CLI can print it in a table, and a
 * reader never has to guess what the legal values are.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Ingestion;

/**
 * The closed set of per-file ingestion outcomes.
 *
 * Backed by its own lowercase string so the value crosses the CLI and REST
 * boundaries unchanged (the design's `stored | skipped | reencoded | rejected`).
 * The two "the file is now in the collection" cases are split — `Stored` for an
 * already-conforming source written verbatim, `Reencoded` for one the optimiser
 * had to decode, downscale, or re-encode — because that distinction is exactly
 * what the upload UI and the doctor's report want to surface.
 *
 * @since 0.3.0
 */
enum Ingest_Outcome: string {

	/**
	 * The source was already conforming and was stored byte-for-byte as the main.
	 *
	 * @since 0.3.0
	 */
	case Stored = 'stored';

	/**
	 * The source was decoded, downscaled, and/or re-encoded to meet the contract.
	 *
	 * @since 0.3.0
	 */
	case Reencoded = 'reencoded';

	/**
	 * Nothing was written because the target already existed and overwrite was off.
	 *
	 * @since 0.3.0
	 */
	case Skipped = 'skipped';

	/**
	 * Nothing was written because the path was unsafe or the source was undecodable.
	 *
	 * @since 0.3.0
	 */
	case Rejected = 'rejected';

}
