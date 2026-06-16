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
use Kntnt\Photo_Drop\Storage\Rendition_Defaults;
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
	 * Establishes a new collection from its three-rendition settings.
	 *
	 * This is one of the two deliberate places (the admin page is the other) a
	 * collection is established (ADR-0004). Every rendition flag is *optional* and
	 * falls back to its `kntnt_photo_drop_default_*` filter, so a bare
	 * `create <slug>` writes the documented defaults: an unbounded upload width
	 * (the source's own dimensions), upload quality 95, a 1920/85 full, and a
	 * 640/75 thumbnail (ADR-0013). The stored format is always WebP. Only the
	 * **upload** pair is the immutable output contract; the full and thumbnail
	 * pairs are re-derivable and `--path-components` is mutable, all editable on
	 * the admin page afterwards. A malformed value for any flag halts before any
	 * directory is made, so a typo never seeds a degenerate collection.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection identity (lowercase, hyphen-separated, single segment).
	 *
	 * [--upload-width=<pixels>]
	 * : The immutable main-image width ceiling, or "none" for the source's own
	 * dimensions. Irreversible once set. Defaults to the
	 * kntnt_photo_drop_default_upload_width filter.
	 *
	 * [--upload-quality=<0-100>]
	 * : The immutable main-image WebP quality. Irreversible once set. Defaults to
	 * the kntnt_photo_drop_default_upload_quality filter (95).
	 *
	 * [--full-width=<pixels>]
	 * : The re-derivable full-image width. Defaults to the
	 * kntnt_photo_drop_default_full_width filter (1920).
	 *
	 * [--full-quality=<0-100>]
	 * : The re-derivable full-image WebP quality. Defaults to the
	 * kntnt_photo_drop_default_full_quality filter (85).
	 *
	 * [--thumbnail-width=<pixels>]
	 * : The re-derivable thumbnail width. Defaults to the
	 * kntnt_photo_drop_default_thumbnail_width filter (640).
	 *
	 * [--thumbnail-quality=<0-100>]
	 * : The re-derivable thumbnail WebP quality. Defaults to the
	 * kntnt_photo_drop_default_thumbnail_quality filter (75).
	 *
	 * [--path-components=<template>]
	 * : The Drop Zone placement template. Defaults to "%year%/%month%/%day%/%uploader%".
	 *
	 * [--name=<name>]
	 * : The human display name. Defaults to a humanised form of the slug.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop collection create spring-2024
	 *     wp kntnt-photo-drop collection create archive --upload-width=none --upload-quality=95
	 *     wp kntnt-photo-drop collection create web --full-width=1600 --thumbnail-width=480 --name="Web Set"
	 *
	 * @since 0.2.0
	 *
	 * @param array<int,string>         $args       Positional arguments: the slug.
	 * @param array<string,string|bool> $assoc_args Associative arguments: the rendition flags, path-components, name.
	 */
	public function create( array $args, array $assoc_args ): void {

		// The slug is the sole positional; reject a malformed one up front so the
		// user gets the same lexical contract the rest of the plugin enforces.
		$slug = $args[0] ?? '';
		if ( ! $this->repository->is_valid_slug( $slug ) ) {
			WP_CLI::error( "Invalid slug '{$slug}': use lowercase letters, digits and single hyphens." );
			return;
		}

		// Resolve each rendition field from its flag or its default; a malformed flag
		// returns null here (the resolvers have already errored out via WP_CLI::error,
		// which the production runtime exits on and the test double throws on).
		$upload_width = $this->resolve_upload_width( $assoc_args );
		$upload_qual  = $this->resolve_quality( $assoc_args, 'upload-quality', Rendition_Defaults::upload_quality() );
		$full_width   = $this->resolve_width( $assoc_args, 'full-width', Rendition_Defaults::full_width() );
		$full_qual    = $this->resolve_quality( $assoc_args, 'full-quality', Rendition_Defaults::full_quality() );
		$thumb_width  = $this->resolve_width( $assoc_args, 'thumbnail-width', Rendition_Defaults::thumbnail_width() );
		$thumb_qual   = $this->resolve_quality( $assoc_args, 'thumbnail-quality', Rendition_Defaults::thumbnail_quality() );

		// Resolve the placement template and the display name (both caller-supplied or
		// defaulted) before any filesystem effect, so a successful create writes a
		// complete record. The template's lexical validation is a later concern (#48);
		// here a blank value simply means the default.
		$path_components = $this->resolve_path_components( $assoc_args );
		$name_arg        = isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : null;
		$name            = $this->input->resolve_name( $name_arg, $slug );

		// Create the directory; a null return means the slug already exists or the
		// root is unavailable — either way nothing was written.
		$path = $this->repository->create_collection( $slug );
		if ( $path === null ) {
			WP_CLI::error( "Cannot create '{$slug}': it already exists or the uploads root is unavailable." );
			return;
		}

		// Write the descriptor that turns the bare directory into a collection,
		// carrying all three rendition tiers and the placement template.
		$descriptor = new Descriptor(
			$name,
			$upload_width,
			$upload_qual,
			$full_width,
			$full_qual,
			$thumb_width,
			$thumb_qual,
			$path_components,
		);
		if ( ! $descriptor->write( $path ) ) {
			WP_CLI::error( "Created the directory for '{$slug}' but failed to write its descriptor." );
			return;
		}

		WP_CLI::success( "Created collection '{$slug}' ({$this->describe_contract( $upload_width, $upload_qual )})." );

	}

	/**
	 * Renames a collection, changing only its mutable display name.
	 *
	 * The immutable upload contract (`upload-width`, `upload-quality`) is fixed at
	 * establishment, so passing either is rejected rather than silently ignored —
	 * the user must not believe a frozen value was changed (ADR-0013). Only the
	 * display name is rewritten here; the re-derivable full/thumbnail settings and
	 * the mutable path-components template carry over untouched (their editable
	 * re-derive flow is a later issue).
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
	 * @param array<int,string>         $args       Positional arguments: the slug.
	 * @param array<string,string|bool> $assoc_args Associative arguments: name (and rejected immutable flags).
	 */
	public function update( array $args, array $assoc_args ): void {

		// Refuse any establishment-fixed flag before doing anything else: the user
		// must not walk away believing a frozen value (the contract or the
		// placement rule) was altered.
		$offending = $this->input->find_immutable_flag( $assoc_args );
		if ( $offending !== null ) {
			WP_CLI::error( "The --{$offending} flag is immutable and cannot be changed; only --name is mutable." );
			return;
		}

		// The new name is mandatory — update has nothing else to change. A `--name`
		// always arrives as a string; coercing guards the string-typed rewrite below.
		$name = isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '';
		if ( $name === '' ) {
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

		// Rewrite the descriptor with only the name replaced; the immutable upload
		// contract, the re-derivable full/thumbnail pairs, and the path-components
		// template all carry over untouched (their editable re-derive flow is a
		// later issue).
		$updated = new Descriptor(
			$name,
			$current->upload_width,
			$current->upload_quality,
			$current->full_width,
			$current->full_quality,
			$current->thumbnail_width,
			$current->thumbnail_quality,
			$current->path_components,
		);
		if ( ! $updated->write( $path ) ) {
			WP_CLI::error( "Failed to write the updated descriptor for '{$slug}'." );
			return;
		}

		WP_CLI::success( "Renamed collection '{$slug}' to '{$name}'." );

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
	 * Resolves the `--upload-width` flag to the contract's nullable ceiling.
	 *
	 * The upload width is the only nullable rendition field: an absent flag falls
	 * back to the `kntnt_photo_drop_default_upload_width` filter (documented default
	 * "the source's own dimensions" → `null`), the literal `none` maps to `null`,
	 * and any other value must be a strictly positive integer. A malformed value
	 * halts the command via `WP_CLI::error()` before any directory is made, so a
	 * typo never seeds a degenerate collection.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,string|bool> $assoc_args The command's associative arguments.
	 * @return int|null The resolved ceiling, or null for the source's own dimensions.
	 */
	private function resolve_upload_width( array $assoc_args ): ?int {

		// An absent flag takes the filter default; a present one is parsed (with its
		// `none` → null form), and a malformed value halts before any write.
		if ( ! isset( $assoc_args['upload-width'] ) ) {
			return Rendition_Defaults::upload_width();
		}
		$parsed = $this->input->parse_upload_width( (string) $assoc_args['upload-width'] );
		if ( $parsed === false ) {
			WP_CLI::error( 'The --upload-width flag must be a positive integer or "none".' );
		}

		return $parsed;

	}

	/**
	 * Resolves a positive-integer width flag, defaulting from its filter.
	 *
	 * Shared by the `--full-width` and `--thumbnail-width` flags. An absent flag
	 * takes the supplied filter default; a present one must be a strictly positive
	 * integer, and a malformed value halts the command via `WP_CLI::error()` before
	 * any directory is made.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,string|bool> $assoc_args The command's associative arguments.
	 * @param string                    $flag       The flag name (`full-width` or `thumbnail-width`).
	 * @param int                       $default    The filter-resolved default width.
	 * @return int The resolved positive width.
	 */
	private function resolve_width( array $assoc_args, string $flag, int $default ): int {

		// An absent flag takes the default; a present one is parsed, and a
		// non-positive or malformed value halts before any write.
		if ( ! isset( $assoc_args[ $flag ] ) ) {
			return $default;
		}
		$parsed = $this->input->parse_width( (string) $assoc_args[ $flag ] );
		if ( $parsed === false ) {
			WP_CLI::error( "The --{$flag} flag must be a positive integer." );
		}

		return $parsed;

	}

	/**
	 * Resolves a 0–100 quality flag, defaulting from its filter.
	 *
	 * Shared by every `--*-quality` flag. An absent flag takes the supplied filter
	 * default; a present one must be an integer in 0–100, and a malformed value
	 * halts the command via `WP_CLI::error()` before any directory is made.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,string|bool> $assoc_args The command's associative arguments.
	 * @param string                    $flag       The flag name (e.g. `upload-quality`).
	 * @param int                       $default    The filter-resolved default quality.
	 * @return int The resolved quality.
	 */
	private function resolve_quality( array $assoc_args, string $flag, int $default ): int {

		// An absent flag takes the default; a present one is parsed, and an
		// out-of-range or malformed value halts before any write.
		if ( ! isset( $assoc_args[ $flag ] ) ) {
			return $default;
		}
		$parsed = $this->input->parse_quality( (string) $assoc_args[ $flag ] );
		if ( $parsed === false ) {
			WP_CLI::error( "The --{$flag} flag must be an integer between 0 and 100." );
		}

		return $parsed;

	}

	/**
	 * Resolves the `--path-components` flag to the placement template.
	 *
	 * An absent or blank flag means the default template (ADR-0014); a supplied
	 * value is taken verbatim. The template's lexical validation and its
	 * server-side expansion are later concerns (issue #48); this surface only
	 * stores what it is handed.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,string|bool> $assoc_args The command's associative arguments.
	 * @return string The resolved placement template.
	 */
	private function resolve_path_components( array $assoc_args ): string {

		// A missing or blank flag is the documented default; anything else is taken
		// verbatim (its validation/expansion is a later issue).
		$raw = isset( $assoc_args['path-components'] ) ? (string) $assoc_args['path-components'] : '';

		return $raw === '' ? Descriptor::DEFAULT_PATH_COMPONENTS : $raw;

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
