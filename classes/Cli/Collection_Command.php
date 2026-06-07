<?php
/**
 * WP-CLI lifecycle commands for collections — create, update, delete.
 *
 * Registered as `wp kntnt-photo-drop collection`, this is the trusted,
 * deliberate place — alongside the admin page — where a collection is
 * *established* (its immutable output contract fixed), *renamed* (the only
 * mutable field), and *removed*. Blocks are select-only consumers and never
 * reach this surface (ADR-0004).
 *
 * The command is thin on purpose. Its only three public methods are the verbs
 * `create` / `update` / `delete`, which is exactly the subcommand set WP-CLI's
 * reflection should surface. The filesystem mutations live on the collection
 * Repository, the descriptor shape lives on the Descriptor, and every decidable
 * flag rule lives on the pure Collection_Input helper — so the verbs read as a
 * short script and the parts they orchestrate are each independently testable.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;
use WP_CLI;

/**
 * Implements `wp kntnt-photo-drop collection {create,update,delete}`.
 *
 * Registered by Plugin::__construct() only when WP_CLI is defined, so the file
 * imposes no cost on web requests. Each public verb method carries its own
 * `## OPTIONS` / `## EXAMPLES` docblock, which WP-CLI reads as the subcommand
 * synopsis; no other public method exists, so no helper leaks as a subcommand.
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
