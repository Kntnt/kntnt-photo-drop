<?php
/**
 * The closed set of things the doctor can find while reconciling a collection.
 *
 * Each finding the doctor produces is one of these kinds. The kind alone decides
 * how the finding is reported (a `WP_CLI::log` vs a `WP_CLI::warning`) and, in
 * acting mode, what `--repair` does with it — so keeping the set closed and
 * backed means both the printer and the repairer can `match` on it exhaustively
 * with no untyped string drift.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Doctor;

/**
 * The kind of a single doctor finding.
 *
 * Backed by a stable string so a finding can cross the report boundary (a table
 * cell, a log line) and still be matched on. The five cases mirror the design's
 * Doctor section exactly: a derived artifact missing for a present main, an
 * orphaned derived artifact whose main is gone, a main that violates the
 * immutable contract, a foreign file, and a file skipped by the ignore list.
 *
 * @since 0.4.0
 */
enum Finding_Kind: string {

	/**
	 * A main is present but a derived artifact it should have is missing.
	 *
	 * The thumbnail at a configured width below the main's own width does not
	 * exist, or the per-folder index lacks the main's entry. `--repair` creates
	 * the missing artifact; the main is the unit of truth and is never touched.
	 *
	 * @since 0.4.0
	 */
	case Missing_Derived = 'missing-derived';

	/**
	 * A derived artifact is present but its main is gone.
	 *
	 * An orphan thumbnail under `.kntnt-thumbnails/<width>/` whose main no longer
	 * exists, or a stale index entry. `--repair` removes the orphan thumbnail; the
	 * index self-heals on its next rebuild.
	 *
	 * @since 0.4.0
	 */
	case Orphan_Derived = 'orphan-derived';

	/**
	 * A main violates the immutable output contract.
	 *
	 * Over the width ceiling, or not WebP — a state only reachable by an
	 * out-of-band copy, since every plugin ingestion path is conforming by
	 * construction. Always warned about, never processed in place, never deleted,
	 * even with `--repair`.
	 *
	 * @since 0.4.0
	 */
	case Contract_Violation = 'contract-violation';

	/**
	 * A file that is none of: a main, a thumbnail, an index, or the descriptor.
	 *
	 * Warned about but never deleted, even with `--repair`. A user's own
	 * `.thumbnails` directory (from another photo tool) is foreign — it is not our
	 * namespaced `.kntnt-thumbnails`.
	 *
	 * @since 0.4.0
	 */
	case Foreign = 'foreign';

	/**
	 * A foreign file skipped by the ignore list rather than warned about.
	 *
	 * OS junk on the built-in list (`.DS_Store`, `Thumbs.db`, …) or a path matched
	 * by a caller-supplied `--ignore` glob. Surfaced only when `--show-ignored` is
	 * given, so an operator can see what was passed over.
	 *
	 * @since 0.4.0
	 */
	case Ignored = 'ignored';

}
