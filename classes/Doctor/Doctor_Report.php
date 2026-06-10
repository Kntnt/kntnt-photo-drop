<?php
/**
 * The outcome of a doctor run — the findings plus what `--repair` actually did.
 *
 * The doctor always produces a diagnosis: a flat list of typed `Finding`s. In
 * report-only mode that list *is* the result (the report is the dry run). Under
 * `--repair` the same diagnosis is acted on, and this object additionally
 * records the counts of artifacts created and removed so the command can report
 * a faithful summary of the effect. Holding both in one immutable value keeps
 * the act vs report split clean: the command renders the findings the same way
 * either mode, then appends the repair counts only when it acted.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Doctor;

/**
 * An immutable `{ findings, created, removed, pruned, repaired }` doctor result.
 *
 * `findings` is the full diagnosis regardless of mode. `created` and `removed`
 * count the derived artifacts a repair wrote and deleted, and `pruned` counts
 * the stale-width thumbnails a forced repair retired after a thumbnail-width
 * change (all zero in report-only mode). `repaired` records whether acting
 * happened at all, so a command can tell "report-only, nothing to do" apart
 * from "repaired, nothing to do".
 *
 * @since 0.4.0
 */
final readonly class Doctor_Report {

	/**
	 * Constructs a report from the diagnosis and the repair tallies.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,Finding> $findings The full list of typed findings.
	 * @param int                $created  Derived artifacts created by a repair.
	 * @param int                $removed  Orphaned artifacts removed by a repair.
	 * @param int                $pruned   Stale-width thumbnails removed by a forced repair.
	 * @param bool               $repaired Whether the run acted (true) or only reported (false).
	 */
	public function __construct(
		public array $findings,
		public int $created = 0,
		public int $removed = 0,
		public int $pruned = 0,
		public bool $repaired = false,
	) {}

	/**
	 * Returns the findings of one kind, preserving their order.
	 *
	 * Lets the command (and a test) pull out just the contract violations or just
	 * the foreign files without re-walking the list by hand, so the printer can
	 * group its output by kind.
	 *
	 * @since 0.4.0
	 *
	 * @param Finding_Kind $kind The kind to filter by.
	 * @return array<int,Finding> The matching findings, in their original order.
	 */
	public function of_kind( Finding_Kind $kind ): array {
		return array_values(
			array_filter( $this->findings, static fn ( Finding $finding ): bool => $finding->kind === $kind ),
		);
	}

}
