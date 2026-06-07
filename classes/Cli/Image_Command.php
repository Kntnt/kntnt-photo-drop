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

use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Ingestion\Ingest_Result;
use Kntnt\Photo_Drop\Ingestion\Ingestor;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
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
	 * Constructs the command with the collection repository it resolves against.
	 *
	 * The input helper is a stateless collaborator the command owns directly; it
	 * takes no dependencies, so it is constructed here rather than injected.
	 *
	 * @since 0.3.0
	 *
	 * @param Repository $repository The read side of "the filesystem is the source of truth".
	 */
	public function __construct(
		private readonly Repository $repository,
	) {
		$this->input = new Image_Input();
	}

	/**
	 * Imports one or more source images into an existing collection.
	 *
	 * A pure consumer: it reads the target collection's descriptor and optimises
	 * every source to that contract, carrying no contract flags of its own. Each
	 * source is made conforming (accepted as-is when already WebP and within the
	 * ceiling, otherwise downscaled and re-encoded), stored as `<original>.webp`,
	 * and thumbnailed. A relative source keeps its sub-directories (recreated and
	 * `Path_Guard`-confined); an absolute source lands at the collection root.
	 * Import is idempotent: an existing target is skipped unless `--overwrite` is
	 * given. One failing source never aborts the batch — every source yields a
	 * reported outcome.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The existing collection to import into.
	 *
	 * <source>...
	 * : One or more source image paths. A relative path recreates its sub-directories.
	 *
	 * [--overwrite]
	 * : Overwrite a target that already exists. Without it, an existing target is skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-photo-drop image import spring-2024 photo.jpg
	 *     wp kntnt-photo-drop image import spring-2024 photos/2024/*.jpg --overwrite
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

		// Build one ingestor for the whole batch (one anchored Path_Guard, one
		// fixed contract), then ingest each source, collecting a row per file.
		$overwrite = isset( $assoc_args['overwrite'] );
		$ingestor  = new Ingestor( $path, $descriptor );
		$rows      = array_map(
			fn ( string $source ): array => $this->import_one( $ingestor, $source, $overwrite ),
			$sources,
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
		$main = $this->resolve_main( $path, $relative );
		if ( $main === null ) {
			WP_CLI::error( "No image '{$relative}' was found in collection '{$slug}'." );
			return;
		}

		// Confirm the destructive act unless --yes; confirm() aborts on decline.
		WP_CLI::confirm( "Delete the image '{$relative}' and its thumbnails from '{$slug}'?", $assoc_args );

		// Remove the main first, then its thumbnails; a failed main removal is a
		// hard error, while thumbnail removal is best-effort (the doctor heals it).
		if ( ! $this->unlink_file( $main ) ) {
			WP_CLI::error( "Failed to delete the main image at '{$relative}'." );
			return;
		}
		$removed = $this->remove_thumbnails( $main );

		WP_CLI::success( "Deleted '{$relative}' and {$removed} thumbnail(s) from '{$slug}'." );

	}

	/**
	 * Ingests one source and maps it to a reportable table row.
	 *
	 * Reads the source bytes (a missing or unreadable file is reported as a
	 * rejection without touching the ingestor), derives the relative target, runs
	 * the ingestor, and flattens the result into the columns the report prints.
	 *
	 * @since 0.3.0
	 *
	 * @param Ingestor $ingestor  The batch's ingestor.
	 * @param string   $source    The source path as given on the command line.
	 * @param bool     $overwrite Whether to overwrite an existing target.
	 * @return array{source:string,outcome:string,stored:string} One report row.
	 */
	private function import_one( Ingestor $ingestor, string $source, bool $overwrite ): array {

		// A source that cannot be read never reaches the ingestor; it is reported as
		// a rejection so the batch continues with the remaining sources.
		$bytes = $this->input->read_source( $source );
		if ( $bytes === null ) {
			WP_CLI::warning( "Cannot read source '{$source}'; skipping." );
			return $this->row( Ingest_Result::rejected( $source ) );
		}

		// Derive the confined relative target and ingest; the ingestor returns
		// exactly one of the four outcomes.
		$relative = $this->input->relative_target( $source );
		$result   = $ingestor->ingest( $bytes, $relative, $overwrite );

		return $this->row( $result );

	}

	/**
	 * Flattens an ingestion result into the report's three columns.
	 *
	 * @since 0.3.0
	 *
	 * @param Ingest_Result $result The per-file ingestion result.
	 * @return array{source:string,outcome:string,stored:string} The report row.
	 */
	private function row( Ingest_Result $result ): array {
		return [
			'source'  => $result->source,
			'outcome' => $result->outcome->value,
			'stored'  => $result->stored_name ?? '',
		];
	}

	/**
	 * Prints the per-file outcomes as a table and a one-line summary.
	 *
	 * Uses WP-CLI's `format_items()` for the table (so the operator gets the same
	 * `table`/`csv`/`json` shape the rest of the CLI uses), then summarises the
	 * outcome counts. A batch with any rejection still succeeds overall — one bad
	 * file never aborts the run — but the summary makes the rejections visible.
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

		WP_CLI::success( "Import complete: {$summary}." );

	}

	/**
	 * Resolves a relative path to an existing main image inside the collection.
	 *
	 * Confines the path with `Path_Guard` first, so nothing outside the collection
	 * can be named. Only a stored main — a `<original>.webp` file — can ever
	 * resolve: the `Image_Name::to_stored()` form of the confined path is computed
	 * (which is a no-op for a path already ending in `.webp`, and appends `.webp`
	 * to an original name) and accepted only when that exact `.webp` file exists.
	 * A foreign file like `notes.txt` therefore never resolves to a main, so a
	 * delete can never remove it. Returns the absolute main path, or `null`.
	 *
	 * @since 0.3.0
	 *
	 * @param string $collection_path The absolute collection root.
	 * @param string $relative        The caller-supplied relative path.
	 * @return string|null The absolute main path, or null when no main matches.
	 */
	private function resolve_main( string $collection_path, string $relative ): ?string {

		// Confine the path to the collection; a rejected path resolves to no main.
		$guard    = new Path_Guard( $collection_path );
		$resolved = $guard->resolve( $relative );
		if ( $resolved === null ) {
			return null;
		}

		// Map the confined path to its stored main name — a no-op when it already
		// ends in `.webp`, otherwise `<original>.webp` — so only a real main is ever
		// targeted and a foreign non-`.webp` file can never be deleted.
		$main = \dirname( $resolved ) . '/' . Image_Name::to_stored( basename( $resolved ) );

		return is_file( $main ) ? $main : null;

	}

	/**
	 * Removes every thumbnail derived from a main, returning how many were removed.
	 *
	 * Thumbnails live at `.kntnt-thumbnails/<width>/<name>.webp`; the configured
	 * widths may have changed since the main was imported, so this scans every
	 * width sub-directory present and removes the one named for this main rather
	 * than trusting the current descriptor. It never recurses or deletes anything
	 * but this main's own thumbnail files, so a foreign file is never touched.
	 *
	 * @since 0.3.0
	 *
	 * @param string $main_path Absolute path to the main image being deleted.
	 * @return int The number of thumbnail files removed.
	 */
	private function remove_thumbnails( string $main_path ): int {

		// The thumbnails root sits beside the main, inside the content folder.
		$folder      = \dirname( $main_path );
		$stored_name = basename( $main_path );
		$thumbs_root = $folder . '/' . Index::THUMBNAILS_DIRNAME;
		if ( ! is_dir( $thumbs_root ) ) {
			return 0;
		}

		// Walk each width sub-directory and remove this main's thumbnail there;
		// only the file named exactly for this main is touched, never a sibling.
		$removed = 0;
		$entries = scandir( $thumbs_root );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$candidate = $thumbs_root . '/' . $entry . '/' . $stored_name;
			if ( is_file( $candidate ) && $this->unlink_file( $candidate ) ) {
				++$removed;
			}
		}

		return $removed;

	}

	/**
	 * Unlinks a single file, returning whether it was removed.
	 *
	 * The plugin owns this directory tree on disk directly (ADR-0001), so it
	 * unlinks the file rather than routing through `wp_delete_file`, which is for
	 * Media-Library attachments, not files written outside it.
	 *
	 * @since 0.3.0
	 *
	 * @param string $path Absolute path to the file to remove.
	 * @return bool True when the file was removed.
	 */
	private function unlink_file( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin owns this directory tree on disk directly (ADR-0001); wp_delete_file is for Media-Library attachments, not files written outside it.
		return unlink( $path );
	}

}
