<?php
/**
 * WP-CLI image commands — import into and delete from an existing collection.
 *
 * Registered as `wp kntnt-photo-drop image`, this is the trusted, browser-free
 * consumer of the optimisation boundary. `import` reads a target collection's
 * descriptor and drives the shared `Ingestor` for each source — the very same
 * code path the REST upload endpoint (#7) will use — so "conforming by
 * construction" holds here exactly as it does for an upload. `delete` removes a
 * main and its derived thumbnails, leaving the index to self-heal. The command
 * carries no contract flags: establishing a contract is `collection create`'s
 * sole job (ADR-0004).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

use Kntnt\Photo_Drop\Collection\Image_Deleter;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Ingestion\Ingest_Outcome;
use Kntnt\Photo_Drop\Ingestion\Ingest_Result;
use Kntnt\Photo_Drop\Ingestion\Ingestor;
use Kntnt\Photo_Drop\Storage\Descriptor;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Implements `wp kntnt-photo-drop image {import,delete}`.
 *
 * Registered by Plugin::__construct() only when WP_CLI is defined, so the file
 * imposes no cost on web requests. Each public verb method carries its own
 * `## OPTIONS` / `## EXAMPLES` docblock that WP-CLI reads as the subcommand
 * synopsis; only the two verbs are public, so no helper leaks as a subcommand.
 * The pure source/path logic is delegated to `Image_Input`; the ingestion glue
 * lives on the `Ingestor`, so each verb reads as a short script.
 *
 * @since 0.3.0
 */
final class Image_Command {

	/**
	 * The pure source-reader and path-deriver the verbs delegate to.
	 *
	 * @since 0.3.0
	 * @var Image_Input
	 */
	private readonly Image_Input $input;

	/**
	 * The factory producing the batch's ingestor for a collection and contract.
	 *
	 * @since 0.2.0
	 * @var \Closure(string, Descriptor): Ingestor
	 */
	private readonly \Closure $ingestor_factory;

	/**
	 * Constructs the command with the collection repository it resolves against.
	 *
	 * The input helper is a stateless collaborator the command owns directly; it
	 * takes no dependencies, so it is constructed here rather than injected. The
	 * ingestor factory defaults to constructing the production `Ingestor` — whose
	 * optimiser throws when no codec on the host can encode WebP — and is
	 * injectable so a test can drive that misconfigured-host error path.
	 *
	 * @since 0.3.0
	 *
	 * @param Repository                                  $repository       The collection resolver.
	 * @param \Closure(string, Descriptor): Ingestor|null $ingestor_factory The factory, or null for production.
	 */
	public function __construct(
		private readonly Repository $repository,
		?\Closure $ingestor_factory = null,
	) {
		$this->input            = new Image_Input();
		$this->ingestor_factory = $ingestor_factory
			?? static fn ( string $path, Descriptor $descriptor ): Ingestor => new Ingestor( $path, $descriptor );
	}

	/**
	 * Imports source images — files or whole directories — into a collection.
	 *
	 * A pure consumer: it reads the target collection's descriptor and optimises
	 * every source to that contract, carrying no contract flags of its own. Each
	 * file is made conforming (accepted as-is when already WebP and within the
	 * ceiling, otherwise downscaled and re-encoded), stored as `<original>.webp`,
	 * and thumbnailed. A source may be a directory: it is traversed recursively and
	 * every image under it is imported with its sub-directory structure preserved —
	 * the same folder semantics as a Drop Zone drop, skipping hidden filesystem
	 * noise and RAW/video siblings, and still writing the literal source-relative
	 * target with no date/uploader template expansion. A relative source keeps its
	 * sub-directories (recreated and `Path_Guard`-confined); an absolute file lands
	 * at the collection root, an absolute directory under its own basename. Import
	 * is idempotent: an existing target is skipped unless `--overwrite` is given.
	 * One failing file never aborts the batch — every imported file yields a
	 * reported outcome.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The existing collection to import into.
	 *
	 * <source>...
	 * : One or more source images or directories. A directory is traversed
	 * recursively, importing every image under it (hidden files and RAW/video
	 * siblings are skipped). A relative path recreates its sub-directories.
	 *
	 * [--overwrite]
	 * : Overwrite a target that already exists. Without it, an existing target is skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop image import spring-2024 photo.jpg
	 *     wp kntnt-photo-drop image import spring-2024 photos/2024/*.jpg --overwrite
	 *     wp kntnt-photo-drop image import spring-2024 ~/Pictures/trip
	 *
	 * @since 0.3.0
	 *
	 * @param array<int,string>    $args       Positional arguments: slug then one or more sources.
	 * @param array<string,string> $assoc_args Associative arguments: overwrite.
	 */
	public function import( array $args, array $assoc_args ): void {

		// The first positional is the collection; the rest are sources. A pure
		// consumer requires the collection to already exist and carry a readable
		// descriptor — neither verb ever establishes a collection.
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

		// At least one source is required; importing nothing is a usage error.
		$sources = array_slice( $args, 1 );
		if ( $sources === [] ) {
			WP_CLI::error( 'Provide at least one source image to import.' );
			return;
		}

		// Expand every source into the concrete files to import: a file is itself,
		// a directory is walked recursively for every image under it. A source that
		// contributes nothing — an empty or all-noise folder, or a typo'd directory
		// path — simply adds no units; if the whole batch expands to nothing there
		// is no work to do, a usage-level error a script can trip on.
		$units = array_merge(
			...array_map( $this->input->expand_source( ... ), $sources ),
		);
		if ( $units === [] ) {
			WP_CLI::error( 'No importable image files were found in the given source(s).' );
			return;
		}

		// Build one ingestor for the whole batch (one anchored Path_Guard, one
		// fixed contract). Its optimiser throws when no codec on the host can
		// encode WebP; that is a host misconfiguration the operator must fix, so
		// it surfaces as an actionable error — mirroring the REST controller —
		// rather than an uncaught exception.
		$overwrite = isset( $assoc_args['overwrite'] );
		try {
			$ingestor = ( $this->ingestor_factory )( $path, $descriptor );
		} catch ( \RuntimeException $exception ) {
			WP_CLI::error( "Cannot import: {$exception->getMessage()}" );
			return;
		}

		// Ingest each unit, collecting a row per file; one failing file never
		// aborts the batch.
		$rows = array_map(
			fn ( array $unit ): array => $this->import_one( $ingestor, $unit, $overwrite ),
			$units,
		);

		// Report every outcome as a table and summarise; one failure never aborts
		// the batch, so the summary reflects the whole run.
		$this->report_import( $rows );

	}

	/**
	 * Deletes a main image and its derived thumbnails from a collection.
	 *
	 * The main is the unit of truth, so removing it and its thumbnails is the
	 * whole deletion; the per-folder index self-heals on the next gallery view.
	 * The `<path>` is confined to the collection root, so a typo or a hostile path
	 * deletes nothing outside the collection, and only a real main (or its
	 * original-named form) is targeted — never a foreign file. Prompts unless
	 * `--yes` is given.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The collection to delete from.
	 *
	 * <path>
	 * : The main image's path relative to the collection root (stored or original name).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop image delete spring-2024 photos/2024/IMG_2024.jpg.webp
	 *     wp kntnt-photo-drop image delete spring-2024 photo.jpg --yes
	 *
	 * @since 0.3.0
	 *
	 * @param array<int,string>    $args       Positional arguments: slug then the relative path.
	 * @param array<string,string> $assoc_args Associative arguments: yes.
	 */
	public function delete( array $args, array $assoc_args ): void {

		// Resolve the collection up front so a delete targets a real collection.
		$slug = $args[0] ?? '';
		$path = $this->repository->resolve_slug( $slug );
		if ( $path === null ) {
			WP_CLI::error( "No collection named '{$slug}' was found." );
			return;
		}

		// The relative path of the main is required.
		$relative = $args[1] ?? '';
		if ( $relative === '' ) {
			WP_CLI::error( 'Provide the path of the image to delete, relative to the collection root.' );
			return;
		}

		// Confine the path to the collection and resolve it to an existing main,
		// accepting either the stored `<original>.webp` name or the original name.
		// The deletion routine is the one shared with the gallery's trash write-path
		// (ADR-0015), so the CLI and the REST endpoint name and remove files
		// identically.
		$deleter = new Image_Deleter();
		$main    = $deleter->resolve_main( $path, $relative );
		if ( $main === null ) {
			WP_CLI::error( "No image '{$relative}' was found in collection '{$slug}'." );
			return;
		}

		// Confirm the destructive act unless --yes; confirm() aborts on decline.
		WP_CLI::confirm( "Delete the image '{$relative}' and its derived artifacts from '{$slug}'?", $assoc_args );

		// Remove the main and every derived artifact slaved to it; a failed main
		// removal is a hard error, while derived removal is best-effort (the doctor
		// heals a stray) and the index self-heals on the next view.
		if ( ! $deleter->delete( $main ) ) {
			WP_CLI::error( "Failed to delete the main image at '{$relative}'." );
			return;
		}

		WP_CLI::success( "Deleted '{$relative}' and its derived artifacts from '{$slug}'." );

	}

	/**
	 * Ingests one import unit and maps it to a reportable table row.
	 *
	 * Reads the unit's source bytes (a missing or unreadable file is reported as a
	 * rejection without touching the ingestor), ingests them to the unit's confined
	 * relative target, and flattens the result into the columns the report prints.
	 * The unit's `label` is the row's `source` column, so a file source shows the
	 * path given on the command line and a directory-walked file shows its relative
	 * target.
	 *
	 * @since 0.3.0
	 *
	 * @param Ingestor                                        $ingestor  The batch's ingestor.
	 * @param array{read:string,relative:string,label:string} $unit      The import unit: file to read, target, label.
	 * @param bool                                            $overwrite Whether to overwrite an existing target.
	 * @return array{source:string,outcome:string,stored:string} One report row.
	 */
	private function import_one( Ingestor $ingestor, array $unit, bool $overwrite ): array {

		// A file that cannot be read never reaches the ingestor; it is reported as a
		// rejection so the batch continues with the remaining files.
		$bytes = $this->input->read_source( $unit['read'] );
		if ( $bytes === null ) {
			WP_CLI::warning( "Cannot read source '{$unit['label']}'; skipping." );
			return $this->row_for( $unit['label'], Ingest_Result::rejected( $unit['label'] ) );
		}

		// Ingest the bytes to the unit's confined relative target; the ingestor
		// returns exactly one of the four outcomes.
		$result = $ingestor->ingest( $bytes, $unit['relative'], $overwrite );

		return $this->row_for( $unit['label'], $result );

	}

	/**
	 * Flattens an ingestion result into the report's three columns under a label.
	 *
	 * The `source` column is the unit's label rather than the ingestor's own
	 * source string, so the report identifies each file by what the operator gave
	 * (a path) or, for a directory-walked file, by its relative target.
	 *
	 * @since 0.3.0
	 *
	 * @param string        $label  The label to show in the `source` column.
	 * @param Ingest_Result $result The per-file ingestion result.
	 * @return array{source:string,outcome:string,stored:string} The report row.
	 */
	private function row_for( string $label, Ingest_Result $result ): array {
		return [
			'source'  => $label,
			'outcome' => $result->outcome->value,
			'stored'  => $result->stored_name ?? '',
		];
	}

	/**
	 * Prints the per-file outcomes as a table and a one-line summary.
	 *
	 * Uses WP-CLI's `format_items()` for the table (so the operator gets the same
	 * `table`/`csv`/`json` shape the rest of the CLI uses), then summarises the
	 * outcome counts. Per-file isolation holds — one bad file never aborts the
	 * run — but the exit code must stay scriptable: a batch in which *every*
	 * source was rejected ends with `WP_CLI::error()` (non-zero exit) after the
	 * table, while a partial failure keeps the success exit and flags the
	 * rejection count in a warning line.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int,array{source:string,outcome:string,stored:string}> $rows The per-file rows.
	 */
	private function report_import( array $rows ): void {

		// Render the per-file table in the canonical column order.
		Utils\format_items( 'table', $rows, [ 'source', 'outcome', 'stored' ] );

		// Tally the outcomes for a compact summary line so a large batch is legible
		// at a glance without reading every row.
		$counts = array_count_values( array_column( $rows, 'outcome' ) );
		$summary = implode(
			', ',
			array_map(
				static fn ( string $outcome, int $count ): string => "{$count} {$outcome}",
				array_keys( $counts ),
				array_values( $counts ),
			),
		);

		// A batch where every source was rejected is a total failure a script must
		// be able to trip on; the table above already told the per-file story.
		$rejected = $counts[ Ingest_Outcome::Rejected->value ] ?? 0;
		$total    = count( $rows );
		if ( $total > 0 && $rejected === $total ) {
			WP_CLI::error( "Import failed: all {$total} source(s) were rejected." );
			return;
		}

		// A partial failure keeps the success exit but makes the rejections
		// impossible to miss, with counts a script can grep for.
		if ( $rejected > 0 ) {
			WP_CLI::warning( "{$rejected} of {$total} source(s) were rejected." );
		}

		WP_CLI::success( "Import complete: {$summary}." );

	}

}
