<?php
/**
 * The collection-lifecycle admin page — the GUI mirror of the WP-CLI
 * `collection {create,update,delete}` verbs.
 *
 * This is one of the two deliberate, trusted contexts (the CLI is the other)
 * where a collection's lifecycle is driven: established with its immutable
 * output contract, renamed (the only mutable field), and removed. Blocks are
 * select-only consumers and never appear here. The page is gated by
 * `manage_options` (filter `kntnt_photo_drop_manage_capability`); every form is
 * nonce-protected, every superglobal sanitised, every output escaped, and every
 * string translatable.
 *
 * The page is intentionally thin: it mirrors the CLI's exact semantics by
 * reusing the same pure `Collection_Input` parser/validator and the same
 * `Repository` write side and `Descriptor` codec the CLI drives, so the GUI and
 * the headless surface can never drift apart. Request handling lives in small,
 * directly-testable methods; the markup lives in clearly separated render
 * methods.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Admin;

use Kntnt\Photo_Drop\Cli\Collection_Input;
use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;

/**
 * Registers and renders the collection-lifecycle admin page.
 *
 * Wired from `Plugin::__construct()` to `admin_menu` (menu registration) and
 * `admin_post_*` (the create/update/delete form handlers). Holds only the
 * injected `Repository` (the shared read/write side of "the filesystem is the
 * source of truth") and a stateless `Collection_Input`; everything else is
 * recomputed per request from disk, so the page always reflects the current
 * filesystem rather than a cached snapshot.
 *
 * @since 0.5.0
 */
final class Admin_Page {

	/**
	 * The menu/page slug, also the `page` query var and the handler suffix base.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const MENU_SLUG = 'kntnt-photo-drop';

	/**
	 * The `admin_post` action name for the create-collection form.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const ACTION_CREATE = 'kntnt_photo_drop_create_collection';

	/**
	 * The `admin_post` action name for the update (rename) form.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const ACTION_UPDATE = 'kntnt_photo_drop_update_collection';

	/**
	 * The `admin_post` action name for the delete-confirmation form.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const ACTION_DELETE = 'kntnt_photo_drop_delete_collection';

	/**
	 * The `settings_errors` slug under which this page's notices are queued.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const NOTICE_SLUG = 'kntnt_photo_drop_admin';

	/**
	 * The literal "Maximum width" form value that maps to "no limit" (`null`).
	 *
	 * The contract is irreversible, so a max width must be stated explicitly;
	 * this radio choice is the one explicit way to say "do not cap width".
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const NO_LIMIT_VALUE = 'none';

	/**
	 * The capability filter that gates reading and writing on this page.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const CAPABILITY_FILTER = 'kntnt_photo_drop_manage_capability';

	/**
	 * The default-max-width filter that pre-fills the create form.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_MAX_WIDTH_FILTER = 'kntnt_photo_drop_default_max_width';

	/**
	 * The default-quality filter that pre-fills the create form.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_QUALITY_FILTER = 'kntnt_photo_drop_default_quality';

	/**
	 * The pure parser/validator shared with the CLI so the two agree exactly.
	 *
	 * @since 0.5.0
	 * @var Collection_Input
	 */
	private readonly Collection_Input $input;

	/**
	 * The hook suffix `add_submenu_page()` returned for this page.
	 *
	 * Captured by `register_menu()` and compared by `enqueue_styles()`, so the
	 * page's stylesheet is added on this screen only. Empty until the menu is
	 * registered (and when registration fails), in which case no styles are
	 * ever added.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Constructs the page with the collection repository it drives.
	 *
	 * The flag parser is a stateless helper the page owns directly; it takes no
	 * collaborators, so it is constructed here rather than injected.
	 *
	 * @since 0.5.0
	 *
	 * @param Repository $repository The read/write side of "the filesystem is the source of truth".
	 */
	public function __construct(
		private readonly Repository $repository,
	) {
		$this->input = new Collection_Input();
	}

	/**
	 * Resolves the capability that gates this page, filterable.
	 *
	 * Defaults to `manage_options`; the `kntnt_photo_drop_manage_capability`
	 * filter overrides it (ADR-0006). A non-string filter return is rejected
	 * back to the default so the gate can never be silently disabled.
	 *
	 * @since 0.5.0
	 *
	 * @return string The capability required to manage collections.
	 */
	public function capability(): string {
		$filtered = apply_filters( self::CAPABILITY_FILTER, 'manage_options' );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : 'manage_options';
	}

	/**
	 * Registers the admin menu entry under the Media menu.
	 *
	 * Wired to `admin_menu`. The page gates on the filtered manage capability so
	 * only users who hold it see the entry or can reach the URL; the render
	 * callback re-checks the same capability as defence in depth. The page lives
	 * under Media because a collection is, conceptually, a managed set of media
	 * files kept outside the Media Library. The returned hook suffix is kept so
	 * `enqueue_styles()` can scope the page's stylesheet to this screen.
	 *
	 * @since 0.5.0
	 */
	public function register_menu(): void {

		// Register the page and keep its hook suffix; a false return (an
		// un-capable user) leaves the suffix empty, so no styles are added.
		$hook = add_submenu_page(
			'upload.php',
			__( 'Photo Drop Collections', 'kntnt-photo-drop' ),
			__( 'Photo Drop', 'kntnt-photo-drop' ),
			$this->capability(),
			self::MENU_SLUG,
			[ $this, 'render_page' ],
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';

	}

	/**
	 * Adds the page's small stylesheet on this admin screen only.
	 *
	 * Wired to `admin_enqueue_scripts`. The rules are the presentation the list
	 * markup should not carry inline: the vertical gap between the page header
	 * and the list table, and the right-aligned, non-wrapping actions column.
	 * They ride the always-present `common` admin stylesheet as inline CSS, so
	 * no extra stylesheet request is made for a few rules.
	 *
	 * @since 0.5.0
	 *
	 * @param string $hook_suffix The current admin screen's hook suffix.
	 */
	public function enqueue_styles( string $hook_suffix ): void {

		// Every other admin screen passes through untouched.
		if ( $this->page_hook === '' || $hook_suffix !== $this->page_hook ) {
			return;
		}

		// The spacing rule separates the header row from the list table; the
		// actions rule pins the Edit/Delete buttons to the row's right-hand end.
		wp_add_inline_style(
			'common',
			'.kntnt-photo-drop-collections { margin-top: 1em; }'
			. ' .kntnt-photo-drop-actions { text-align: right; white-space: nowrap; }',
		);

	}

	/**
	 * Handles the create-collection form POST.
	 *
	 * Wired to `admin_post_{ACTION_CREATE}`. Verifies the capability and the
	 * nonce, reads and sanitises the four fields from `$_POST`, then delegates
	 * the decision logic to `create_collection()`. Always ends by redirecting
	 * back to the list with a queued notice, so the page follows the
	 * post/redirect/get pattern and never re-submits on refresh.
	 *
	 * @since 0.5.0
	 */
	public function handle_create(): void {

		// Authorise and verify the form before reading any field; an un-capable
		// user or a forged request never reaches the filesystem.
		$this->guard_request( self::ACTION_CREATE );

		// Read and sanitise the four create fields from the request. The slug and
		// name are text; the max-width choice and value and the quality are read
		// as raw strings and parsed by the shared Collection_Input below. The nonce
		// is verified in guard_request() above, before any field is read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard_request().
		$slug           = $this->read_string( $_POST, 'slug' );
		$name           = $this->read_string( $_POST, 'name' );
		$max_width_mode = $this->read_string( $_POST, 'max_width_mode' );
		$max_width_raw  = $this->read_string( $_POST, 'max_width' );
		$quality_raw    = $this->read_string( $_POST, 'quality' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// "No limit" is an explicit mode; otherwise the typed width is the value
		// Collection_Input parses, so the same `none` → null mapping the CLI uses
		// applies here verbatim.
		$max_width_value = $max_width_mode === self::NO_LIMIT_VALUE ? self::NO_LIMIT_VALUE : $max_width_raw;

		// Run the decision logic and redirect back to the list with its notice.
		$this->create_collection( $slug, $name, $max_width_value, $quality_raw );
		$this->redirect_to_list();

	}

	/**
	 * Handles the update (rename) form POST.
	 *
	 * Wired to `admin_post_{ACTION_UPDATE}`. Verifies the capability and the
	 * nonce, reads the slug and the new name from `$_POST`, and delegates to
	 * `update_collection()`, which rewrites only the descriptor's `name` and
	 * rejects any tampered contract change. Ends by redirecting back to the list.
	 *
	 * @since 0.5.0
	 */
	public function handle_update(): void {

		// Authorise and verify before touching any field.
		$this->guard_request( self::ACTION_UPDATE );

		// Read and sanitise the slug and the only mutable field, the display name.
		// The nonce is verified in guard_request() above, before any field is read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard_request().
		$slug = $this->read_string( $_POST, 'slug' );
		$name = $this->read_string( $_POST, 'name' );

		// The raw POST keys are inspected so the handler can detect a tampered
		// contract field and reject it server-side, even though the form renders
		// those fields disabled and never submits them.
		$tampered = $this->has_contract_field( array_keys( $_POST ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Run the decision logic and redirect back to the list with its notice.
		$this->update_collection( $slug, $name, $tampered );
		$this->redirect_to_list();

	}

	/**
	 * Handles the delete-confirmation form POST.
	 *
	 * Wired to `admin_post_{ACTION_DELETE}`. Verifies the capability and the
	 * nonce, reads the slug, and delegates to `delete_collection()`, which
	 * removes the whole collection directory. Ends by redirecting back to the
	 * list. The confirmation step itself is a GET view (`render_delete_form()`),
	 * so a delete only ever happens after an explicit confirming POST.
	 *
	 * @since 0.5.0
	 */
	public function handle_delete(): void {

		// Authorise and verify before touching the filesystem.
		$this->guard_request( self::ACTION_DELETE );

		// Read and sanitise the slug, then remove the directory and redirect. The
		// nonce is verified in guard_request() above, before the field is read.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard_request().
		$slug = $this->read_string( $_POST, 'slug' );
		$this->delete_collection( $slug );
		$this->redirect_to_list();

	}

	/**
	 * Establishes a new collection: validates, then creates the directory and
	 * writes its descriptor.
	 *
	 * The GUI counterpart of `collection create`. The slug must be a valid,
	 * unused slug; both contract values are required and parsed by the shared
	 * `Collection_Input` (so `none` → null and 0–100 bounds match the CLI). On
	 * success it creates the directory and writes `collection.json`. Each failure
	 * queues a precise error notice and returns without a partial write. Returns
	 * whether the collection was established, so a test can assert the effect
	 * without reading the notice queue.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug            The collection identity to create.
	 * @param string $name            The optional display name; humanised from the slug when empty.
	 * @param string $max_width_value The raw max-width value ("none" or a positive integer).
	 * @param string $quality_value   The raw quality value (0–100).
	 * @return bool True when the collection was established.
	 */
	public function create_collection(
		string $slug,
		string $name,
		string $max_width_value,
		string $quality_value,
	): bool {

		// Reject a malformed slug up front so the user gets the same lexical
		// contract the rest of the plugin enforces.
		if ( ! $this->repository->is_valid_slug( $slug ) ) {
			$this->add_error(
				__( 'Invalid slug: use lowercase letters, digits and single hyphens.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Both contract values are mandatory and irreversible; parse each in
		// isolation so the user learns precisely which one was malformed.
		$max_width = $this->input->parse_max_width( $max_width_value );
		if ( $max_width === false ) {
			$this->add_error(
				__( 'Maximum width must be a positive integer, or choose "No limit".', 'kntnt-photo-drop' ),
			);
			return false;
		}
		$quality = $this->input->parse_quality( $quality_value );
		if ( $quality === false ) {
			$this->add_error(
				__( 'Quality must be an integer between 0 and 100.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Resolve the display name (caller-supplied, or a humanised slug) before
		// any filesystem effect, so a successful create writes a complete record.
		$display_name = $this->input->resolve_name( $name, $slug );

		// Create the directory; a null return means the slug already exists or the
		// root is unavailable — either way nothing was written.
		$path = $this->repository->create_collection( $slug );
		if ( $path === null ) {
			$this->add_error(
				// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
				__( 'Cannot create: the collection already exists or the uploads root is unavailable.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Write the descriptor that turns the bare directory into a collection;
		// the thumbnail width(s) are filter-derived inside from_filter().
		$descriptor = Descriptor::from_filter( $display_name, $max_width, $quality );
		if ( ! $descriptor->write( $path ) ) {
			$this->add_error(
				__( 'Created the directory but failed to write the collection descriptor.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		$this->add_success(
			/* translators: %s: collection slug. */
			sprintf( __( 'Created collection “%s”.', 'kntnt-photo-drop' ), $slug ),
		);
		return true;

	}

	/**
	 * Renames a collection, rewriting only the descriptor's mutable display name.
	 *
	 * The GUI counterpart of `collection update`. The display name is the single
	 * mutable field; the output contract is immutable, so a tampered request that
	 * carries a contract field is rejected before anything is written — the user
	 * must never believe a frozen contract was changed. The slug must resolve to
	 * an existing collection with a readable descriptor; on success the descriptor
	 * is rewritten with only `name` replaced. Returns whether the rename happened.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug              The collection identity to rename.
	 * @param string $name              The new, non-empty display name.
	 * @param bool   $carries_contract  Whether the request tampered in a contract field.
	 * @return bool True when the display name was rewritten.
	 */
	public function update_collection( string $slug, string $name, bool $carries_contract ): bool {

		// Refuse any immutable-contract field before doing anything else: the user
		// must not walk away believing a frozen contract was altered.
		if ( $carries_contract ) {
			$this->add_error(
				__( 'The output contract is immutable; only the display name can be changed.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// The new name is mandatory — update has nothing else to change.
		if ( $name === '' ) {
			$this->add_error(
				__( 'The display name is required and must not be empty.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Resolve the slug to an existing collection; an unknown slug changes
		// nothing.
		$path = $this->repository->resolve_slug( $slug );
		if ( $path === null ) {
			$this->add_error( __( 'No such collection was found.', 'kntnt-photo-drop' ) );
			return false;
		}

		// Read the current descriptor so the rewrite preserves the immutable
		// contract values exactly and touches only the name.
		$current = Descriptor::read( $path );
		if ( $current === null ) {
			$this->add_error(
				__( 'Cannot read the collection descriptor; refusing to overwrite it.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Rewrite the descriptor with only the name replaced; max-width, quality
		// and the thumbnail widths carry over untouched.
		$updated = new Descriptor( $name, $current->max_width, $current->quality, $current->thumbnail_widths );
		if ( ! $updated->write( $path ) ) {
			$this->add_error(
				__( 'Failed to write the updated collection descriptor.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		$this->add_success(
			/* translators: %s: collection slug. */
			sprintf( __( 'Renamed collection “%s”.', 'kntnt-photo-drop' ), $slug ),
		);
		return true;

	}

	/**
	 * Deletes a collection directory and everything beneath it.
	 *
	 * The GUI counterpart of `collection delete`. The repository resolves the slug
	 * to a real collection first, so only a directory holding a descriptor can be
	 * targeted; the whole tree is then removed (mains, thumbnails, indexes and
	 * descriptor alike). Returns whether the directory was fully removed.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug The collection identity to delete.
	 * @return bool True when the collection directory was removed.
	 */
	public function delete_collection( string $slug ): bool {

		// Remove the whole tree; a false return means the slug did not resolve to a
		// collection or the removal failed partway.
		if ( ! $this->repository->delete_collection( $slug ) ) {
			$this->add_error(
				__( 'Failed to delete the collection: it was not found or could not be removed.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		$this->add_success(
			/* translators: %s: collection slug. */
			sprintf( __( 'Deleted collection “%s”.', 'kntnt-photo-drop' ), $slug ),
		);
		return true;

	}

	/**
	 * Renders the admin page, dispatching to the list, create, edit, or delete
	 * view by the `action` query var.
	 *
	 * The render callback registered with `add_submenu_page()`. It re-checks the
	 * capability as defence in depth against a direct URL hit by a user whose
	 * capability set changed mid-session, then routes by the read-only `action`
	 * query parameter to the matching view. An unknown action falls back to the
	 * list.
	 *
	 * @since 0.5.0
	 */
	public function render_page(): void {

		// Defence-in-depth capability re-check: the menu is already gated, but a
		// direct URL hit must be refused too.
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Photo Drop collections.', 'kntnt-photo-drop' ) );
		}

		// Route by the read-only action query var to the matching view; an unknown
		// or absent action shows the list. These are navigational reads (which view
		// to render), not state changes; the state-changing POSTs are nonce-checked
		// in their own handlers.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view routing; no state change.
		$requested = $this->read_string( $_GET, 'action' );
		$action    = sanitize_key( $requested === '' ? 'list' : $requested );
		$slug      = $this->read_string( $_GET, 'collection' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		match ( $action ) {
			'create' => $this->render_create_form(),
			'edit'   => $this->render_edit_form( $slug ),
			'delete' => $this->render_delete_form( $slug ),
			default  => $this->render_list(),
		};
		echo '</div>';

	}

	/**
	 * Renders the list view: a table of discovered collections plus a create button.
	 *
	 * One row per discovered collection (the discovery scan), showing the display
	 * name, slug, the immutable contract (max width or "No limit", quality, the
	 * always-WebP format), the filter-driven thumbnail width(s), and the live
	 * image count, with always-visible Edit and Delete buttons in the rightmost
	 * column. A collection copied in from another site appears automatically; a
	 * deleted directory disappears.
	 *
	 * @since 0.5.0
	 */
	private function render_list(): void {

		// Render the heading and the create-collection button above the table.
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Photo Drop Collections', 'kntnt-photo-drop' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( $this->page_url( 'create' ) ),
			esc_html__( 'Create collection', 'kntnt-photo-drop' ),
		);
		echo '<hr class="wp-header-end">';

		// Surface any notice stashed by the preceding redirect, then render it.
		$this->maybe_replay_notices();
		settings_errors( self::NOTICE_SLUG );

		// Build one row per discovered collection. Each row reads the descriptor for
		// its contract and counts the mains on disk for the image column.
		$collections = $this->repository->discover();

		echo '<table class="wp-list-table widefat fixed striped kntnt-photo-drop-collections">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Slug', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Max width', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Quality', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Format', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Thumbnail width', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Images', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col" class="kntnt-photo-drop-actions">';
		echo esc_html__( 'Actions', 'kntnt-photo-drop' ) . '</th>';
		echo '</tr></thead><tbody>';

		// An empty discovery shows a single explanatory row rather than a bare table.
		if ( $collections === [] ) {
			$empty = __( 'No collections yet. Create one to get started.', 'kntnt-photo-drop' );
			echo '<tr><td colspan="8">' . esc_html( $empty ) . '</td></tr>';
		}

		foreach ( $collections as $row_slug => $path ) {
			$this->render_list_row( $row_slug, $path );
		}

		echo '</tbody></table>';

	}

	/**
	 * Renders a single collection row in the list table.
	 *
	 * Reads the row's descriptor for the contract and the display name, counts the
	 * mains on disk for the image column, and renders always-visible Edit and
	 * Delete buttons in the rightmost actions cell — Delete leads to the
	 * confirmation view, never straight to removal. A collection whose descriptor
	 * cannot be read still renders by slug so it can be deleted, rather than
	 * vanishing from the table.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug The collection slug (directory name).
	 * @param string $path The absolute collection directory path.
	 */
	private function render_list_row( string $slug, string $path ): void {

		// Read the descriptor; a missing one still renders the row by slug so the
		// collection remains visible and removable.
		$descriptor = Descriptor::read( $path );
		$name       = $descriptor !== null && $descriptor->name !== '' ? $descriptor->name : $slug;

		// Resolve the contract cells from the descriptor; a missing descriptor renders
		// each contract cell as a dash so a broken collection still lists by slug.
		$max_width_cell = $descriptor !== null ? $this->format_max_width( $descriptor->max_width ) : '—';
		$quality_cell   = $descriptor !== null ? (string) $descriptor->quality : '—';
		$thumbs_cell    = $descriptor !== null ? $this->format_thumbnail_widths( $descriptor->thumbnail_widths ) : '—';

		// The image count is read live from disk; an unreadable subtree yields an
		// unknown count, rendered as a dash rather than failing the whole page.
		$count       = $this->count_images( $path );
		$images_cell = $count === null ? '—' : (string) $count;

		echo '<tr>';
		echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
		echo '<td><code>' . esc_html( $slug ) . '</code></td>';
		echo '<td>' . esc_html( $max_width_cell ) . '</td>';
		echo '<td>' . esc_html( $quality_cell ) . '</td>';
		echo '<td>' . esc_html__( 'WebP', 'kntnt-photo-drop' ) . '</td>';
		echo '<td>' . esc_html( $thumbs_cell ) . '</td>';
		echo '<td>' . esc_html( $images_cell ) . '</td>';

		// The rightmost cell holds the always-visible action buttons; the red
		// button-link-delete styling flags Delete as the destructive one.
		printf(
			'<td class="kntnt-photo-drop-actions"><a href="%1$s" class="button">%2$s</a>'
			. ' <a href="%3$s" class="button button-link-delete">%4$s</a></td>',
			esc_url( $this->page_url( 'edit', $slug ) ),
			esc_html__( 'Edit', 'kntnt-photo-drop' ),
			esc_url( $this->page_url( 'delete', $slug ) ),
			esc_html__( 'Delete', 'kntnt-photo-drop' ),
		);

		echo '</tr>';

	}

	/**
	 * Renders the create-collection form.
	 *
	 * The GUI counterpart of `collection create`: a slug, an optional display
	 * name, a maximum width (pre-filled from `kntnt_photo_drop_default_max_width`,
	 * with an explicit "No limit" choice), and a quality (pre-filled from
	 * `kntnt_photo_drop_default_quality`). There is deliberately no format field
	 * (always WebP) and no thumbnail-width field (filter-driven). A prominent
	 * irreversibility warning sits above the two contract fields.
	 *
	 * @since 0.5.0
	 */
	private function render_create_form(): void {

		// Pre-fill the two contract fields from the default filters, so the form
		// opens on sensible, site-overridable values.
		$default_width   = $this->default_max_width();
		$default_quality = $this->default_quality();

		echo '<h1>' . esc_html__( 'Create collection', 'kntnt-photo-drop' ) . '</h1>';
		settings_errors( self::NOTICE_SLUG );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_CREATE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Slug — required; becomes the directory name and the durable identity.
		$slug_label = __( 'Slug', 'kntnt-photo-drop' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$slug_help = __( 'Lowercase letters, digits and single hyphens. Becomes the directory name and permanent identity.', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-slug">' . esc_html( $slug_label ) . '</label></th><td>';
		echo '<input name="slug" id="kntnt-photo-drop-slug" type="text" class="regular-text" required ';
		echo 'pattern="[a-z0-9]+(?:-[a-z0-9]+)*" />';
		echo '<p class="description">' . esc_html( $slug_help ) . '</p>';
		echo '</td></tr>';

		// Display name — optional; humanised from the slug when left blank.
		$name_label = __( 'Display name', 'kntnt-photo-drop' );
		$name_help  = __( 'Optional. Defaults to a humanised form of the slug.', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-name">' . esc_html( $name_label ) . '</label></th><td>';
		echo '<input name="name" id="kntnt-photo-drop-name" type="text" class="regular-text" />';
		echo '<p class="description">' . esc_html( $name_help ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// The irreversibility warning sits directly above the two contract fields so
		// it cannot be missed before they are set.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$warning_lead = __( 'These two settings fix the collection’s output contract and cannot be changed afterwards.', 'kntnt-photo-drop' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$warning_body = __( 'Images are downscaled and re-encoded at ingestion and the originals are never kept, so the maximum width and quality below are permanent.', 'kntnt-photo-drop' );
		echo '<div class="notice notice-warning inline"><p><strong>';
		echo esc_html( $warning_lead );
		echo '</strong> ';
		echo esc_html( $warning_body );
		echo '</p></div>';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Maximum width — required; a radio chooses between a pixel ceiling and an
		// explicit "No limit", and the number input carries the default.
		$width_label = __( 'Maximum width', 'kntnt-photo-drop' );
		echo '<tr><th scope="row">' . esc_html( $width_label ) . '</th><td>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html( $width_label ) . '</legend>';
		echo '<label><input type="radio" name="max_width_mode" value="limit" checked /> ';
		echo esc_html__( 'Limit to', 'kntnt-photo-drop' ) . ' ';
		printf(
			'<input name="max_width" type="number" min="1" step="1" value="%s" class="small-text" /> %s',
			esc_attr( (string) $default_width ),
			esc_html__( 'pixels', 'kntnt-photo-drop' ),
		);
		echo '</label><br />';
		echo '<label><input type="radio" name="max_width_mode" value="' . esc_attr( self::NO_LIMIT_VALUE ) . '" /> ';
		echo esc_html__( 'No limit', 'kntnt-photo-drop' );
		echo '</label></fieldset></td></tr>';

		// Quality — required; pre-filled from the default-quality filter.
		$quality_label = __( 'Quality', 'kntnt-photo-drop' );
		$quality_help  = __( 'WebP compression quality, 0–100.', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-quality">';
		echo esc_html( $quality_label ) . '</label></th><td>';
		echo '<input name="quality" id="kntnt-photo-drop-quality" type="number" min="0" max="100" ';
		echo 'step="1" value="' . esc_attr( (string) $default_quality ) . '" class="small-text" required />';
		echo '<p class="description">' . esc_html( $quality_help ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Create collection', 'kntnt-photo-drop' ) );
		echo '</form>';

	}

	/**
	 * Renders the edit (rename) form for one collection.
	 *
	 * Only the display name is editable; the contract fields — max width, quality,
	 * format, thumbnail width(s) — are shown disabled with a note that the
	 * contract is immutable and thumbnail width is changed via the filter plus
	 * `collection doctor --repair --force`. An unknown slug shows an error and a
	 * link back to the list.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug The collection slug to edit.
	 */
	private function render_edit_form( string $slug ): void {

		echo '<h1>' . esc_html__( 'Edit collection', 'kntnt-photo-drop' ) . '</h1>';
		settings_errors( self::NOTICE_SLUG );

		// Resolve and read the collection; an unknown slug or unreadable descriptor
		// shows a notice and a way back rather than an empty form.
		$path       = $this->repository->resolve_slug( $slug );
		$descriptor = $path !== null ? Descriptor::read( $path ) : null;
		if ( $descriptor === null ) {
			$this->render_not_found_notice();
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_UPDATE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UPDATE ) . '" />';
		echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Slug — shown read-only as the durable identity; renaming the slug is an
		// out-of-band `mv`, not a field on this page.
		echo '<tr><th scope="row">' . esc_html__( 'Slug', 'kntnt-photo-drop' ) . '</th>';
		echo '<td><code>' . esc_html( $slug ) . '</code></td></tr>';

		// Display name — the only editable field.
		$name_label = __( 'Display name', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-name">' . esc_html( $name_label ) . '</label></th><td>';
		echo '<input name="name" id="kntnt-photo-drop-name" type="text" class="regular-text" value="';
		echo esc_attr( $descriptor->name ) . '" required />';
		echo '</td></tr>';

		echo '</tbody></table>';

		// The contract is immutable; show the fixed values disabled with a note so
		// the administrator sees the contract but cannot change it through the UI.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$contract_note = __( 'The output contract was fixed when the collection was established and cannot be changed. Thumbnail width is filter-driven (kntnt_photo_drop_thumbnail_width); change it by re-running “collection doctor --repair --force”.', 'kntnt-photo-drop' );
		echo '<h2>' . esc_html__( 'Output contract (immutable)', 'kntnt-photo-drop' ) . '</h2>';
		echo '<p class="description">' . esc_html( $contract_note ) . '</p>';

		// Render the four immutable contract values as disabled fields.
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_disabled_row(
			__( 'Maximum width', 'kntnt-photo-drop' ),
			$this->format_max_width( $descriptor->max_width ),
		);
		$this->render_disabled_row( __( 'Quality', 'kntnt-photo-drop' ), (string) $descriptor->quality );
		$this->render_disabled_row( __( 'Format', 'kntnt-photo-drop' ), __( 'WebP', 'kntnt-photo-drop' ) );
		$this->render_disabled_row(
			__( 'Thumbnail width', 'kntnt-photo-drop' ),
			$this->format_thumbnail_widths( $descriptor->thumbnail_widths ),
		);
		echo '</tbody></table>';

		submit_button( __( 'Save display name', 'kntnt-photo-drop' ) );
		$this->render_cancel_link();
		echo '</form>';

	}

	/**
	 * Renders the delete-confirmation view for one collection.
	 *
	 * A deliberate confirmation step before the destructive POST: it names the
	 * target, warns that the directory and everything under it (including every
	 * image) is removed and that blocks referencing the slug will then dangle, and
	 * offers a confirming submit plus a cancel link. An unknown slug shows a
	 * notice and a link back to the list.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug The collection slug to delete.
	 */
	private function render_delete_form( string $slug ): void {

		echo '<h1>' . esc_html__( 'Delete collection', 'kntnt-photo-drop' ) . '</h1>';
		settings_errors( self::NOTICE_SLUG );

		// Resolve the collection so the confirmation names a real target; an unknown
		// slug shows a notice and a way back.
		$path = $this->repository->resolve_slug( $slug );
		if ( $path === null ) {
			$this->render_not_found_notice();
			return;
		}

		// Warn unmissably that deletion is permanent and removes every image.
		/* translators: %s: collection slug. */
		$confirm = __( 'Permanently delete the collection “%s” and every image in it? This removes the directory and everything under it and cannot be undone. Blocks that reference this collection will then show nothing.', 'kntnt-photo-drop' ); // phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html( sprintf( $confirm, $slug ) );
		echo '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_DELETE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '" />';
		echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '" />';
		submit_button( __( 'Delete collection permanently', 'kntnt-photo-drop' ), 'delete' );
		$this->render_cancel_link();
		echo '</form>';

	}

	/**
	 * Renders a "no such collection" error notice and a link back to the list.
	 *
	 * Shared by the edit and delete views for the unknown-slug case, so both
	 * present the same message and the same way back.
	 *
	 * @since 0.5.0
	 */
	private function render_not_found_notice(): void {
		$message = __( 'No such collection was found.', 'kntnt-photo-drop' );
		$back    = __( 'Back to collections', 'kntnt-photo-drop' );
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		echo '<p><a href="' . esc_url( $this->page_url() ) . '">' . esc_html( $back ) . '</a></p>';
	}

	/**
	 * Renders a "Cancel" link back to the list, used beside a form's submit button.
	 *
	 * @since 0.5.0
	 */
	private function render_cancel_link(): void {
		$label = __( 'Cancel', 'kntnt-photo-drop' );
		echo ' <a href="' . esc_url( $this->page_url() ) . '" class="button-link">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Renders one read-only contract row as a disabled text input.
	 *
	 * Used by the edit view to display each immutable contract value (max width,
	 * quality, format, thumbnail width) without making it editable. The disabled
	 * input never POSTs, so the value survives a save untouched.
	 *
	 * @since 0.5.0
	 *
	 * @param string $label The row label.
	 * @param string $value The contract value to display.
	 */
	private function render_disabled_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="text" class="regular-text" value="' . esc_attr( $value ) . '" disabled />';
		echo '</td></tr>';
	}

	/**
	 * Authorises and verifies a form POST, halting the request on failure.
	 *
	 * The single gate every `admin_post` handler passes through: it enforces the
	 * filtered manage capability and checks the action nonce. `check_admin_referer`
	 * and `current_user_can` both terminate the request themselves on failure, so a
	 * handler that returns from this method is known-authorised and known-genuine.
	 *
	 * @since 0.5.0
	 *
	 * @param string $action The nonce action the form was signed with.
	 */
	private function guard_request( string $action ): void {

		// Authorise first, then verify the nonce; both halt the request on failure.
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Photo Drop collections.', 'kntnt-photo-drop' ) );
		}
		check_admin_referer( $action );

	}

	/**
	 * Reports whether a request carries any immutable-contract field.
	 *
	 * The edit form renders the contract fields disabled and never submits them, so
	 * their presence in a POST signals a tampered request. The two contract fields
	 * (`max_width`, `quality`) mirror the CLI's immutable contract flags, so the
	 * page and the CLI judge a contract change by the same rule; either present on
	 * an update is a tampered contract change.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,int|string> $keys The request's field names (e.g. `array_keys( $_POST )`).
	 * @return bool True when a contract field is present.
	 */
	private function has_contract_field( array $keys ): bool {
		return in_array( 'max_width', $keys, true ) || in_array( 'quality', $keys, true );
	}

	/**
	 * Reads a single text field from a request superglobal, sanitised.
	 *
	 * The one place superglobal field access happens: it unslashes (WordPress
	 * magic-quotes the superglobals), coerces a non-scalar value to the empty
	 * string, and runs `sanitize_text_field`. A missing key yields the empty
	 * string. Callers verify the nonce before reaching here.
	 *
	 * @since 0.5.0
	 *
	 * @param array<array-key,mixed> $source The request array (`$_POST` or `$_GET`).
	 * @param string                 $key    The field name to read.
	 * @return string The sanitised field value, or '' when absent or non-scalar.
	 */
	private function read_string( array $source, string $key ): string {

		// A missing or non-scalar value is treated as absent; a scalar is unslashed
		// and sanitised before it is trusted as a string.
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $source[ $key ] ) );

	}

	/**
	 * Counts the main images stored in a collection.
	 *
	 * A main is a `<original>.webp` file anywhere under the collection root,
	 * excluding the hidden `.kntnt-thumbnails/` directories (which hold derived
	 * thumbnails and the index, not mains). Walks the tree once; a collection with
	 * no mains counts zero. The count is read live from disk, so it reflects the
	 * current filesystem rather than any cached tally. An unopenable
	 * subdirectory aborts the walk and yields `null` — the caller renders an
	 * unknown count instead of the whole page (and its Delete escape hatch)
	 * dying on one bad directory.
	 *
	 * @since 0.5.0
	 *
	 * @param string $path The absolute collection directory path.
	 * @return int|null The number of stored main images, or null when the tree
	 *                  could not be fully read.
	 */
	private function count_images( string $path ): ?int {

		// Walk the tree, skipping the hidden thumbnails directories so only mains
		// are counted; a `.webp` file outside those directories is a stored main.
		// An unopenable directory throws mid-walk; the count is then unknown.
		try {
			$count    = 0;
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
					static fn ( \SplFileInfo $info ): bool => $info->getFilename() !== Index::THUMBNAILS_DIRNAME,
				),
			);
			foreach ( $iterator as $info ) {
				$is_main = $info instanceof \SplFileInfo
					&& $info->isFile()
					&& str_ends_with( strtolower( $info->getFilename() ), '.webp' );
				if ( $is_main ) {
					++$count;
				}
			}
		} catch ( \UnexpectedValueException $exception ) {
			Plugin::warning( "Cannot count the images under {$path}: {$exception->getMessage()}" );
			return null;
		}

		return $count;

	}

	/**
	 * Resolves the pre-fill maximum width from the default-max-width filter.
	 *
	 * Defaults to 1920; the `kntnt_photo_drop_default_max_width` filter overrides
	 * it. A non-positive or non-integer filter return falls back to the default so
	 * the form always opens on a usable value.
	 *
	 * @since 0.5.0
	 *
	 * @return int The default maximum width in pixels.
	 */
	private function default_max_width(): int {
		$filtered = apply_filters( self::DEFAULT_MAX_WIDTH_FILTER, 1920 );
		return is_int( $filtered ) && $filtered > 0 ? $filtered : 1920;
	}

	/**
	 * Resolves the pre-fill quality from the default-quality filter.
	 *
	 * Defaults to 80; the `kntnt_photo_drop_default_quality` filter overrides it.
	 * A filter return outside 0–100 (or non-integer) falls back to the default.
	 *
	 * @since 0.5.0
	 *
	 * @return int The default WebP quality (0–100).
	 */
	private function default_quality(): int {
		$filtered = apply_filters( self::DEFAULT_QUALITY_FILTER, 80 );
		return is_int( $filtered ) && $filtered >= 0 && $filtered <= 100 ? $filtered : 80;
	}

	/**
	 * Formats a max-width value for display.
	 *
	 * A `null` ceiling renders as the translatable "No limit"; a positive integer
	 * renders as its pixel count with a "px" suffix.
	 *
	 * @since 0.5.0
	 *
	 * @param int|null $max_width The contract ceiling, or null for no limit.
	 * @return string A display string such as "1920 px" or "No limit".
	 */
	private function format_max_width( ?int $max_width ): string {
		return $max_width === null
			? __( 'No limit', 'kntnt-photo-drop' )
			/* translators: %d: width in pixels. */
			: sprintf( __( '%d px', 'kntnt-photo-drop' ), $max_width );
	}

	/**
	 * Formats the thumbnail-width list for display.
	 *
	 * An empty list (the "no thumbnail" canonical shape) renders as a dash; one or
	 * more widths render as a comma-separated "px" list, e.g. "320 px, 640 px".
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,int> $widths The canonical thumbnail widths.
	 * @return string A display string such as "640 px" or "—".
	 */
	private function format_thumbnail_widths( array $widths ): string {

		// An empty list is the canonical "no thumbnail" marker; show a dash.
		if ( $widths === [] ) {
			return '—';
		}

		return implode(
			', ',
			array_map(
				/* translators: %d: width in pixels. */
				fn ( int $width ): string => sprintf( __( '%d px', 'kntnt-photo-drop' ), $width ),
				$widths,
			),
		);

	}

	/**
	 * Queues a success notice for the next page view.
	 *
	 * @since 0.5.0
	 *
	 * @param string $message The translated, human-readable success message.
	 */
	private function add_success( string $message ): void {
		add_settings_error( self::NOTICE_SLUG, 'kntnt_photo_drop_success', $message, 'success' );
	}

	/**
	 * Queues an error notice for the next page view.
	 *
	 * @since 0.5.0
	 *
	 * @param string $message The translated, human-readable error message.
	 */
	private function add_error( string $message ): void {
		add_settings_error( self::NOTICE_SLUG, 'kntnt_photo_drop_error', $message, 'error' );
	}

	/**
	 * Redirects back to the list view, carrying queued notices across the redirect.
	 *
	 * The notices queued by the handlers live in the request that handled the POST;
	 * `set_transient` stashes them so the redirected GET can replay them, the
	 * standard pattern for surfacing `add_settings_error` notices on a non-options
	 * page. Always calls `exit` after the redirect.
	 *
	 * @since 0.5.0
	 */
	private function redirect_to_list(): void {

		// Stash the queued notices so the redirected GET can replay them; the list
		// view reads and clears them via maybe_replay_notices().
		set_transient( $this->notice_transient_key(), get_settings_errors(), 30 );

		wp_safe_redirect( $this->page_url() );
		exit;

	}

	/**
	 * Replays any notices stashed by the preceding redirect, then clears them.
	 *
	 * Called once at the top of the list view. Reads the per-user transient the
	 * handlers wrote, re-queues each notice so `settings_errors()` renders it, and
	 * deletes the transient so a refresh does not repeat the notice.
	 *
	 * @since 0.5.0
	 */
	public function maybe_replay_notices(): void {

		// Pull and clear the stashed notices; nothing to replay is the common case.
		$stashed = get_transient( $this->notice_transient_key() );
		if ( ! is_array( $stashed ) ) {
			return;
		}
		delete_transient( $this->notice_transient_key() );

		// Re-queue each stashed notice so settings_errors() renders it on this view.
		foreach ( $stashed as $notice ) {
			$is_renderable = is_array( $notice )
				&& isset( $notice['message'], $notice['type'] )
				&& is_string( $notice['message'] )
				&& is_string( $notice['type'] );
			if ( $is_renderable ) {
				add_settings_error( self::NOTICE_SLUG, self::NOTICE_SLUG, $notice['message'], $notice['type'] );
			}
		}

	}

	/**
	 * Returns the per-user transient key the redirect notices are stashed under.
	 *
	 * Keyed by the current user id so two administrators acting at once never see
	 * each other's notices.
	 *
	 * @since 0.5.0
	 *
	 * @return string The transient key.
	 */
	private function notice_transient_key(): string {
		return self::NOTICE_SLUG . '_notices_' . get_current_user_id();
	}

	/**
	 * Builds the admin URL for this page, optionally with an action and slug.
	 *
	 * @since 0.5.0
	 *
	 * @param string $action The view action (`create`, `edit`, `delete`), or '' for the list.
	 * @param string $slug   The collection slug the action targets, or '' when not applicable.
	 * @return string The absolute admin URL.
	 */
	private function page_url( string $action = '', string $slug = '' ): string {

		// Start from the page itself, then add the action and collection query vars
		// only when they apply, so the list URL stays clean.
		$args = [ 'page' => self::MENU_SLUG ];
		if ( $action !== '' ) {
			$args['action'] = $action;
		}
		if ( $slug !== '' ) {
			$args['collection'] = $slug;
		}

		return add_query_arg( $args, admin_url( 'upload.php' ) );

	}

}
