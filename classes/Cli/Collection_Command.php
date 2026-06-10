<?php
/**
 * WP-CLI lifecycle commands for collections — create, update, delete.
 *
 * Registered as `wp kntnt-photo-drop collection`, this is the trusted,
 * deliberate place — alongside the admin page — where a collection is
 * *established* (its immutable output contract fixed), *renamed* (the only
 * mutable field), *removed*, and reconciled by the *doctor*. Blocks are
 * select-only consumers and never reach this surface (ADR-0004).
 *
 * The command is thin on purpose. Its only four public methods are the verbs
 * `create` / `update` / `delete` / `doctor`, which is exactly the subcommand set
 * WP-CLI's reflection should surface. The filesystem mutations live on the
 * collection Repository, the descriptor shape lives on the Descriptor, the
 * reconciliation logic lives on the `Doctor` service, and every decidable flag
 * rule lives on the pure Collection_Input helper — so the verbs read as a short
 * script and the parts they orchestrate are each independently testable.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Doctor\Doctor;
use Kntnt\Photo_Drop\Doctor\Doctor_Report;
use Kntnt\Photo_Drop\Doctor\Finding;
use Kntnt\Photo_Drop\Doctor\Finding_Kind;
use Kntnt\Photo_Drop\Doctor\Ignore_Matcher;
use Kntnt\Photo_Drop\Storage\Descriptor;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Implements `wp kntnt-photo-drop collection {create,update,delete}`.
 *
 * Registered by Plugin::__construct() only when WP_CLI is defined, so the file
 * imposes no cost on web requests. Each public verb method carries its own
 * `## OPTIONS` / `## EXAMPLES` docblock, which WP-CLI reads as the subcommand
 * synopsis; no other public method is public, so no helper leaks as a subcommand.
 *
 * @since 0.2.0
 */
final class Collection_Command {

	/**
	 * The pure parser/validator for the lifecycle flags.
	 *
	 * @since 0.2.0
	 * @var Collection_Input
	 */
	private readonly Collection_Input $input;

	/**
	 * Constructs the command with the collection repository it drives.
	 *
	 * The flag parser is a stateless helper the command owns directly; it takes
	 * no collaborators, so it is constructed here rather than injected.
	 *
	 * @since 0.2.0
	 *
	 * @param Repository $repository The read/write side of "the filesystem is the source of truth".
	 */
	public function __construct(
		private readonly Repository $repository,
	) {
		$this->input = new Collection_Input();
	}

	/**
	 * Establishes a new collection, fixing its immutable output contract.
	 *
	 * This is the one deliberate CLI place a contract is set. `--max-width` and
	 * `--quality` are required because the contract is irreversible — no silent
	 * default may freeze it (ADR-0002, ADR-0004). The stored format is always
	 * WebP; the thumbnail width(s) come from the `kntnt_photo_drop_thumbnail_width`
	 * filter, not from a flag, because thumbnail width is a re-derivable setting
	 * outside the contract.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection identity (lowercase, hyphen-separated, single segment).
	 *
	 * --max-width=<pixels>
	 * : The contract's maximum width in pixels, or "none" for no limit. Required;
	 * irreversible once set.
	 *
	 * --quality=<0-100>
	 * : The WebP compression quality. Required; irreversible once set.
	 *
	 * [--name=<name>]
	 * : The human display name. Defaults to a humanised form of the slug.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop collection create spring-2024 --max-width=1920 --quality=80
	 *     wp kntnt-photo-drop collection create archive --max-width=none --quality=90 --name="Full Archive"
	 *
	 * @since 0.2.0
	 *
	 * @param array<int,string>    $args       Positional arguments: the slug.
	 * @param array<string,string> $assoc_args Associative arguments: max-width, quality, name.
	 */
	public function create( array $args, array $assoc_args ): void {

		// The slug is the sole positional; reject a malformed one up front so the
		// user gets the same lexical contract the rest of the plugin enforces.
		$slug = $args[0] ?? '';
		if ( ! $this->repository->is_valid_slug( $slug ) ) {
			WP_CLI::error( "Invalid slug '{$slug}': use lowercase letters, digits and single hyphens." );
			return;
		}

		// Both contract flags are mandatory; their absence is a hard error rather
		// than a silently defaulted, frozen contract.
		if ( ! isset( $assoc_args['max-width'] ) ) {
			WP_CLI::error( 'The --max-width flag is required (the contract is irreversible). Pass a width or "none".' );
			return;
		}
		if ( ! isset( $assoc_args['quality'] ) ) {
			WP_CLI::error( 'The --quality flag is required (the contract is irreversible). Pass 0 to 100.' );
			return;
		}

		// Parse the two lossy contract values, validating each in isolation so the
		// user learns precisely which one was malformed.
		$max_width = $this->input->parse_max_width( $assoc_args['max-width'] );
		if ( $max_width === false ) {
			WP_CLI::error( 'The --max-width flag must be a positive integer or "none".' );
			return;
		}
		$quality = $this->input->parse_quality( $assoc_args['quality'] );
		if ( $quality === false ) {
			WP_CLI::error( 'The --quality flag must be an integer between 0 and 100.' );
			return;
		}

		// Resolve the display name (caller-supplied, or a humanised slug) before
		// any filesystem effect, so a successful create writes a complete record.
		$name = $this->input->resolve_name( $assoc_args['name'] ?? null, $slug );

		// Create the directory; a null return means the slug already exists or the
		// root is unavailable — either way nothing was written.
		$path = $this->repository->create_collection( $slug );
		if ( $path === null ) {
			WP_CLI::error( "Cannot create '{$slug}': it already exists or the uploads root is unavailable." );
			return;
		}

		// Write the descriptor that turns the bare directory into a collection;
		// the thumbnail width(s) are filter-derived inside from_filter().
		$descriptor = Descriptor::from_filter( $name, $max_width, $quality );
		if ( ! $descriptor->write( $path ) ) {
			WP_CLI::error( "Created the directory for '{$slug}' but failed to write its descriptor." );
			return;
		}

		WP_CLI::success( "Created collection '{$slug}' ({$this->describe_contract( $max_width, $quality )})." );

	}

	/**
	 * Renames a collection, changing only its mutable display name.
	 *
	 * The display name is the single mutable field; the output contract
	 * (`max-width`, `quality`) is immutable, so any attempt to pass those flags
	 * is rejected rather than silently ignored — the user must not believe a
	 * frozen contract was changed (ADR-0002).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection identity to rename.
	 *
	 * --name=<name>
	 * : The new human display name. Required.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop collection update spring-2024 --name="Spring 2024 — Field Trip"
	 *
	 * @since 0.2.0
	 *
	 * @param array<int,string>    $args       Positional arguments: the slug.
	 * @param array<string,string> $assoc_args Associative arguments: name (and rejected contract flags).
	 */
	public function update( array $args, array $assoc_args ): void {

		// Refuse any immutable-contract flag before doing anything else: the user
		// must not walk away believing a frozen contract was altered.
		$offending = $this->input->find_contract_flag( $assoc_args );
		if ( $offending !== null ) {
			WP_CLI::error( "The --{$offending} flag is immutable and cannot be changed; only --name is mutable." );
			return;
		}

		// The new name is mandatory — update has nothing else to change.
		if ( ! isset( $assoc_args['name'] ) || $assoc_args['name'] === '' ) {
			WP_CLI::error( 'The --name flag is required and must be non-empty.' );
			return;
		}

		// Resolve the slug to an existing collection; an unknown slug changes
		// nothing.
		$slug = $args[0] ?? '';
		$path = $this->repository->resolve_slug( $slug );
		if ( $path === null ) {
			WP_CLI::error( "No collection named '{$slug}' was found." );
			return;
		}

		// Read the current descriptor so the rewrite preserves the immutable
		// contract values exactly and touches only the name.
		$current = Descriptor::read( $path );
		if ( $current === null ) {
			WP_CLI::error( "Cannot read the descriptor for '{$slug}'; refusing to overwrite it." );
			return;
		}

		// Rewrite the descriptor with only the name replaced; max-width, quality
		// and the thumbnail widths carry over untouched.
		$updated = new Descriptor(
			$assoc_args['name'],
			$current->max_width,
			$current->quality,
			$current->thumbnail_widths,
		);
		if ( ! $updated->write( $path ) ) {
			WP_CLI::error( "Failed to write the updated descriptor for '{$slug}'." );
			return;
		}

		WP_CLI::success( "Renamed collection '{$slug}' to '{$assoc_args['name']}'." );

	}

	/**
	 * Deletes a collection directory and everything in it.
	 *
	 * The filesystem is the source of truth, so removing the directory is the
	 * entire deletion — mains, thumbnails, indexes and descriptor all go. Prompts
	 * for confirmation unless `--yes` is given.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection identity to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop collection delete spring-2024
	 *     wp kntnt-photo-drop collection delete spring-2024 --yes
	 *
	 * @since 0.2.0
	 *
	 * @param array<int,string>    $args       Positional arguments: the slug.
	 * @param array<string,string> $assoc_args Associative arguments: yes.
	 */
	public function delete( array $args, array $assoc_args ): void {

		// Resolve to an existing collection first so the confirmation prompt names
		// a real target and a typo deletes nothing.
		$slug = $args[0] ?? '';
		if ( $this->repository->resolve_slug( $slug ) === null ) {
			WP_CLI::error( "No collection named '{$slug}' was found." );
			return;
		}

		// Confirm the destructive act unless --yes was passed; confirm() aborts the
		// command itself when the operator declines.
		WP_CLI::confirm( "Permanently delete collection '{$slug}' and all its images?", $assoc_args );

		// Remove the whole tree; a false return means the removal failed partway.
		if ( ! $this->repository->delete_collection( $slug ) ) {
			WP_CLI::error( "Failed to delete collection '{$slug}'; it may be partially removed." );
			return;
		}

		WP_CLI::success( "Deleted collection '{$slug}'." );

	}

	/**
	 * Reconciles a collection's derived artifacts to its main images.
	 *
	 * The diagnostic the design calls for: the main image is the unit of truth, and
	 * the doctor finds every place a derived artifact has drifted from the mains.
	 * It is report-only by default — the report *is* the dry run, so nothing on disk
	 * changes, and the command exits non-zero when actionable findings exist so a
	 * monitoring script can trip on drift. `--repair` acts: it creates missing
	 * thumbnails, refreshes the index, and removes orphan thumbnails. `--repair
	 * --force` re-derives everything (regenerates all thumbnails, rebuilds all
	 * indexes, prunes the width directories of de-configured widths), the path to
	 * take after a `kntnt_photo_drop_thumbnail_width` change. A main that violates the immutable
	 * contract (over the ceiling, or not WebP — only arrivable by an out-of-band
	 * copy) is warned about, never processed in place, never deleted; a foreign file
	 * is warned about, never deleted — even with `--repair`. The built-in OS-junk
	 * ignore list (`.DS_Store`, `._*`, `Thumbs.db`, …) is skipped silently;
	 * `--ignore=<glob>` (one or more comma-separated globs) extends it, and
	 * `--show-ignored` reveals what was skipped.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection to reconcile.
	 *
	 * [--repair]
	 * : Act on the findings instead of only reporting them.
	 *
	 * [--force]
	 * : With --repair, re-derive every thumbnail, rebuild every index, and prune de-configured width directories.
	 *
	 * [--ignore=<glob>]
	 * : Extra foreign-file globs to ignore, comma-separated (e.g. "*.tmp,raw/*").
	 *
	 * [--show-ignored]
	 * : List the files skipped by the ignore list rather than passing over them silently.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop collection doctor spring-2024
	 *     wp kntnt-photo-drop collection doctor spring-2024 --repair
	 *     wp kntnt-photo-drop collection doctor spring-2024 --repair --force
	 *     wp kntnt-photo-drop collection doctor spring-2024 --ignore="*.tmp,raw/*" --show-ignored
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string>    $args       Positional arguments: the slug.
	 * @param array<string,string> $assoc_args Associative arguments: repair, force, ignore, show-ignored.
	 */
	public function doctor( array $args, array $assoc_args ): void {

		// Resolve the collection up front and read its descriptor — the doctor needs
		// the output contract (to judge a violation) and the thumbnail widths (to know
		// which derived artifacts to demand).
		$slug = $args[0] ?? '';
		$path = $this->repository->resolve_slug( $slug );
		if ( $path === null ) {
			WP_CLI::error( "No collection named '{$slug}' was found." );
			return;
		}
		$descriptor = Descriptor::read( $path );
		if ( $descriptor === null ) {
			WP_CLI::error( "Cannot read the descriptor for collection '{$slug}'." );
			return;
		}

		// --force only acts together with --repair; forcing without repairing would
		// imply an act the report-only run never performs, so it is rejected.
		$repair = isset( $assoc_args['repair'] );
		$force  = isset( $assoc_args['force'] );
		if ( $force && ! $repair ) {
			WP_CLI::error( 'The --force flag only applies with --repair.' );
			return;
		}

		// Build the doctor for this collection and run it; the service computes the
		// full diagnosis first, then either reports it or acts on it.
		$ignore = new Ignore_Matcher( $assoc_args['ignore'] ?? null );
		$doctor = new Doctor( $path, $descriptor, $ignore );
		$report = $doctor->run( $repair, $force );

		// Print the diagnosis (and, when acting, the repair summary), revealing the
		// ignored files only when the operator asked to see them.
		$this->report_doctor( $slug, $report, isset( $assoc_args['show-ignored'] ) );

	}

	/**
	 * Prints a doctor report: a finding table, per-kind warnings, and a summary.
	 *
	 * Renders the actionable findings (missing-derived, orphan-derived, contract
	 * violations, foreign files) as one `format_items()` table so the operator sees
	 * the whole picture at a glance, raises a `WP_CLI::warning` for each
	 * contract-violating main and foreign file (the two states a human must resolve
	 * by hand), lists the ignored files only when `--show-ignored` is set, and
	 * closes with a one-line summary reflecting whether the run reported or
	 * repaired. A report-only run that found actionable findings closes through
	 * `WP_CLI::error()` — after the full report — so a monitoring script can trip
	 * on the non-zero exit; a repair (the act applied) keeps exit 0.
	 *
	 * @since 0.4.0
	 *
	 * @param string        $slug         The collection slug, for the summary line.
	 * @param Doctor_Report $report       The diagnosis and any repair tallies.
	 * @param bool          $show_ignored Whether to list the ignored files.
	 */
	private function report_doctor( string $slug, Doctor_Report $report, bool $show_ignored ): void {

		// Render every actionable finding as one table; the ignored ones are held back
		// for the optional --show-ignored listing so the main table stays signal.
		$actionable = $this->actionable_findings( $report );
		if ( $actionable !== [] ) {
			Utils\format_items(
				'table',
				array_map( static fn ( Finding $finding ): array => $finding->to_row(), $actionable ),
				[ 'kind', 'path', 'detail' ],
			);
		}

		// Warn, one line each, about the two states a human must resolve: a main that
		// breaks the contract (never processed in place, never deleted) and a foreign
		// file (never deleted).
		foreach ( $report->of_kind( Finding_Kind::Contract_Violation ) as $finding ) {
			WP_CLI::warning( "Contract violation: {$finding->path} — {$finding->detail}. Not processed; not deleted." );
		}
		foreach ( $report->of_kind( Finding_Kind::Foreign ) as $finding ) {
			WP_CLI::warning( "Foreign file: {$finding->path}. Not deleted." );
		}

		// Reveal the silently-skipped files only on request, so an operator can audit
		// what the ignore list passed over.
		if ( $show_ignored ) {
			foreach ( $report->of_kind( Finding_Kind::Ignored ) as $finding ) {
				WP_CLI::log( "Ignored: {$finding->path}." );
			}
		}

		// Close with a summary whose wording matches the mode: a dry-run count when
		// reporting, the created/removed tallies when repairing. A report-only run
		// with actionable findings exits non-zero so monitoring can trip on it; a
		// repair that applied cleanly keeps exit 0.
		$summary = $this->doctor_summary( $slug, $report );
		if ( ! $report->repaired && $actionable !== [] ) {
			WP_CLI::error( $summary );
			return;
		}
		WP_CLI::success( $summary );

	}

	/**
	 * Returns the report's actionable findings — everything but the ignored ones.
	 *
	 * "Actionable" is what a `--repair` would address or a human must resolve, so
	 * it drives the finding table, the dry-run count, and the report-only exit
	 * code alike.
	 *
	 * @since 0.2.0
	 *
	 * @param Doctor_Report $report The diagnosis to filter.
	 * @return array<int,Finding> The non-ignored findings, in their original order.
	 */
	private function actionable_findings( Doctor_Report $report ): array {
		return array_values(
			array_filter(
				$report->findings,
				static fn ( Finding $finding ): bool => $finding->kind !== Finding_Kind::Ignored,
			),
		);
	}

	/**
	 * Composes the doctor's one-line closing summary for the run's mode.
	 *
	 * In report-only mode the summary counts the actionable findings (the dry run);
	 * in repair mode it reports what was created and removed — and, when a forced
	 * repair retired de-configured width buckets, what was pruned. Either way it
	 * names the collection so a multi-collection session stays legible.
	 *
	 * @since 0.4.0
	 *
	 * @param string        $slug   The collection slug.
	 * @param Doctor_Report $report The diagnosis and any repair tallies.
	 * @return string The summary line.
	 */
	private function doctor_summary( string $slug, Doctor_Report $report ): string {

		// A repaired run reports its effect, mentioning the pruned stale-width
		// thumbnails only when a forced run actually retired any.
		if ( $report->repaired ) {
			$summary = "Doctored '{$slug}': created {$report->created} derived artifact(s), "
				. "removed {$report->removed} orphan(s).";
			if ( $report->pruned > 0 ) {
				$summary .= " Pruned {$report->pruned} stale-width thumbnail(s).";
			}
			return $summary;
		}

		// A report-only run reports the finding count it would act on, since the
		// report is the dry run.
		$actionable = count( $this->actionable_findings( $report ) );

		return "Doctored '{$slug}' (report-only): {$actionable} finding(s). Re-run with --repair to act.";

	}

	/**
	 * Renders the contract as a short, human-readable phrase for the success line.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $max_width The contract ceiling, or null for no limit.
	 * @param int      $quality   The WebP quality.
	 * @return string A phrase such as "max-width 1920px, quality 80, WebP".
	 */
	private function describe_contract( ?int $max_width, int $quality ): string {
		$width = $max_width === null ? 'no width limit' : "max-width {$max_width}px";
		return "{$width}, quality {$quality}, WebP";
	}

}
