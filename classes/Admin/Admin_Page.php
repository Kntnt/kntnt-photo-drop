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
use Kntnt\Photo_Drop\Collection\Path_Template;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Kntnt\Photo_Drop\Storage\Rendition_Defaults;

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
	 * The inline-only script handle for the live path-components preview.
	 *
	 * Registered with no source file (`false`) and carries only the preview's
	 * config and body as inline scripts, so the create/edit Path components field's
	 * expanded-path preview updates as the field is typed without shipping a
	 * separate asset (ADR-0014).
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const PREVIEW_HANDLE = 'kntnt-photo-drop-path-preview';

	/**
	 * The script handle for the Edit page's browser-driven regenerate UI.
	 *
	 * Enqueued on the Edit view only and built by `@wordpress/scripts` into
	 * `build/admin/regenerate.js`. It drives the batched re-derive against the
	 * manage-gated REST endpoint and renders progress through the shared Drop Zone
	 * progress view, so the regenerate-then-flip logic is not duplicated (ADR-0013).
	 *
	 * @since 0.11.0
	 * @var string
	 */
	private const REGENERATE_HANDLE = 'kntnt-photo-drop-regenerate';

	/**
	 * The script handle for the Create page's slug on-blur default.
	 *
	 * Enqueued on the Create view only and built by `@wordpress/scripts` into
	 * `build/admin/slug.js`. It previews the unique `sanitize_title` default in the
	 * optional Slug field's placeholder, refreshed on-blur of the Display name; the
	 * server resolves and re-verifies the same default at submit (blocks.md "Create").
	 *
	 * @since 0.12.0
	 * @var string
	 */
	private const SLUG_HANDLE = 'kntnt-photo-drop-slug';

	/**
	 * The literal "Upload width" form value that maps to "source dimensions" (`null`).
	 *
	 * The upload contract is irreversible, so the width must be stated explicitly;
	 * this radio choice is the one explicit way to say "do not cap width" — store
	 * the source's own dimensions.
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
	 * @since 0.4.0
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
	 * Adds the page's stylesheet, the live path-preview script, and — on the Edit
	 * view — the regenerate script, scoped to this admin screen only.
	 *
	 * Wired to `admin_enqueue_scripts`. The CSS is the presentation the list markup
	 * should not carry inline (the header gap and the right-aligned actions column),
	 * riding the always-present `common` stylesheet so no extra request is made. The
	 * preview script powers the create/edit Path components field's live expanded-path
	 * preview (ADR-0014): it substitutes the four placeholders with the same sample
	 * values the server-rendered initial preview uses (passed as config so PHP stays
	 * the single source of truth), updating the preview as the field is typed. On the
	 * Edit view it additionally enqueues the browser-driven regenerate script that
	 * drives the manage-gated re-derive endpoint and the shared progress view
	 * (ADR-0013). Both scripts are presentational/operational on top of a server that
	 * re-validates everything, so the page degrades gracefully when either is absent.
	 *
	 * @since 0.4.0
	 *
	 * @param string $hook_suffix The current admin screen's hook suffix.
	 */
	public function enqueue_styles( string $hook_suffix ): void {

		// Every other admin screen passes through untouched.
		if ( $this->page_hook === '' || $hook_suffix !== $this->page_hook ) {
			return;
		}

		// The spacing rule separates the header row from the list table; the actions
		// rule pins the Edit/Delete buttons to the row's right-hand end; the regenerate
		// rules give the shared progress view a visible bar and a readable summary row
		// on the Edit view (the same block-element classes the Drop Zone bar uses, under
		// this page's own prefix).
		wp_add_inline_style(
			'common',
			'.kntnt-photo-drop-collections { margin-top: 1em; }'
			. ' .kntnt-photo-drop-actions { text-align: right; white-space: nowrap; }'
			. ' .kntnt-photo-drop-regenerate__progress-bar { position: relative; height: 1.5em; max-width: 30em;'
			. ' margin: 0.5em 0; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 2px;'
			. ' overflow: hidden; }'
			. ' .kntnt-photo-drop-regenerate__progress-fill { display: block; height: 100%; background: #2271b1;'
			. ' transition: width 0.2s ease; }'
			. ' .kntnt-photo-drop-regenerate__progress-summary { display: flex; justify-content: space-between;'
			. ' max-width: 30em; margin: 0.25em 0; }'
			. ' .kntnt-photo-drop-regenerate__progress-text { margin: 0; }',
		);

		// Register an inline-only handle and attach the live path-preview script,
		// fed the sample tokens and the default template so the JS substitution
		// matches Path_Template::sample_expansion() exactly.
		wp_register_script( self::PREVIEW_HANDLE, false, [], '0.7.0', true );
		wp_enqueue_script( self::PREVIEW_HANDLE );
		wp_add_inline_script( self::PREVIEW_HANDLE, $this->preview_config_script(), 'before' );
		wp_add_inline_script( self::PREVIEW_HANDLE, $this->preview_script() );

		// Each view enqueues only the built script it needs (the action is a read-only
		// navigational query var; the state-changing POSTs are nonce-checked in their
		// own handlers): the Edit view drives the manage-gated re-derive endpoint
		// (ADR-0013), and the Create view previews the slug default on-blur.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing; no state change.
		$action = $this->read_string( $_GET, 'action' );
		if ( $action === 'edit' ) {
			$this->enqueue_regenerate_script();
		} elseif ( $action === 'create' ) {
			$this->enqueue_built_admin_script( self::SLUG_HANDLE, 'slug' );
		}

	}

	/**
	 * Enqueues the built regenerate script and hands it the REST root.
	 *
	 * Loads `build/admin/regenerate.js` through the shared built-asset enqueuer, then —
	 * when it actually enqueued — localises the REST root onto the page so the script
	 * can address the regenerate route under any permalink structure (the per-collection
	 * `wp_rest` nonce travels on the host element's data attribute, not here). A missing
	 * build file (an un-built checkout) enqueues nothing, so the page degrades to
	 * read-only rendition fields rather than erroring.
	 *
	 * @since 0.11.0
	 */
	private function enqueue_regenerate_script(): void {

		// Enqueue the built script; only when that succeeded is the REST root worth
		// localising, since an un-built checkout enqueued nothing to localise onto.
		if ( ! $this->enqueue_built_admin_script( self::REGENERATE_HANDLE, 'regenerate' ) ) {
			return;
		}
		$rest_config = wp_json_encode( [ 'root' => esc_url_raw( rest_url() ) ] );
		wp_add_inline_script(
			self::REGENERATE_HANDLE,
			'window.wpApiSettings = window.wpApiSettings || ' . $rest_config . ';',
			'before',
		);

	}

	/**
	 * Enqueues one built admin script from its `@wordpress/scripts` asset manifest.
	 *
	 * Shared by the Edit-view regenerate UI and the Create-view slug default: it reads
	 * `build/admin/<name>.asset.php` for the script's dependencies and content-hash
	 * version and enqueues `build/admin/<name>.js` in the footer. A missing or
	 * malformed manifest (an un-built checkout) enqueues nothing and returns `false`, so
	 * every caller degrades to a server that re-validates everything rather than
	 * erroring. The dependency list is coerced to a string array since the manifest is
	 * typed loose.
	 *
	 * @since 0.12.0
	 *
	 * @param string $handle The script handle to register under.
	 * @param string $name   The build basename under `build/admin/` (no extension).
	 * @return bool True when the script was enqueued.
	 */
	private function enqueue_built_admin_script( string $handle, string $name ): bool {

		// Resolve the built asset and its manifest; an un-built checkout has neither, so
		// the script is simply not enqueued.
		$plugin_dir = plugin_dir_path( Plugin::get_plugin_file() );
		$asset_file = $plugin_dir . "build/admin/{$name}.asset.php";
		if ( ! is_file( $asset_file ) ) {
			return false;
		}
		$asset = require $asset_file;
		if ( ! is_array( $asset ) ) {
			return false;
		}

		// Enqueue the script with the manifest's dependencies and content-hash version.
		$dependencies = array_values( array_filter(
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : [],
			'is_string',
		) );
		$version = is_string( $asset['version'] ?? null ) ? $asset['version'] : null;
		wp_enqueue_script(
			$handle,
			plugins_url( "build/admin/{$name}.js", Plugin::get_plugin_file() ),
			$dependencies,
			$version,
			true,
		);

		return true;

	}

	/**
	 * Builds the `var` config the preview script reads.
	 *
	 * Emits the sample placeholder values and the default template as a JSON object
	 * so the client substitution mirrors `Path_Template::sample_expansion()` without
	 * duplicating the sample tokens in JavaScript — PHP remains the single source of
	 * truth for them.
	 *
	 * @since 0.7.0
	 *
	 * @return string The `var kntntPhotoDropPathPreview = {…};` declaration.
	 */
	private function preview_config_script(): string {

		// Mirror Path_Template's sample tokens and default so the JS preview matches
		// the server-rendered one exactly.
		$config = [
			'defaultTemplate' => Descriptor::DEFAULT_PATH_COMPONENTS,
			'samples'         => [
				'year'     => Path_Template::SAMPLE_YEAR,
				'month'    => Path_Template::SAMPLE_MONTH,
				'day'      => Path_Template::SAMPLE_DAY,
				'uploader' => Path_Template::SAMPLE_UPLOADER,
			],
		];

		return 'var kntntPhotoDropPathPreview = ' . wp_json_encode( $config ) . ';';

	}

	/**
	 * Returns the live path-preview script body.
	 *
	 * A small, dependency-free script: it finds the path-components input and its
	 * preview element, and on each input event substitutes the four known
	 * placeholders with the configured sample values (a blank field previews the
	 * default template), leaving literals and unknown tokens verbatim — exactly as
	 * the save-time validation will see them. It carries no safety logic; rejection
	 * is the server's job.
	 *
	 * @since 0.7.0
	 *
	 * @return string The preview script source.
	 */
	private function preview_script(): string {
		return <<<'JS'
			( function () {
				var config = window.kntntPhotoDropPathPreview || { defaultTemplate: '', samples: {} };
				var input = document.querySelector( '[data-kntnt-photo-drop-path-input]' );
				var preview = document.querySelector( '[data-kntnt-photo-drop-path-preview]' );
				if ( ! input || ! preview ) {
					return;
				}
				var expand = function ( template ) {
					var effective = template.trim() === '' ? config.defaultTemplate : template;
					return effective.replace( /%(year|month|day|uploader)%/g, function ( match, name ) {
						return config.samples[ name ];
					} );
				};
				input.addEventListener( 'input', function () {
					preview.textContent = expand( input.value );
				}, { passive: true } );
			} )();
			JS;
	}

	/**
	 * Handles the create-collection form POST.
	 *
	 * Wired to `admin_post_{ACTION_CREATE}`. Verifies the capability and the
	 * nonce, reads and sanitises the fields from `$_POST`, resolves a blank
	 * (optional) slug to its unique `sanitize_title` default suffixed against the
	 * slugs in use, then delegates the decision logic to `create_collection()`.
	 * Always ends by redirecting back to the list with a queued notice, so the page
	 * follows the post/redirect/get pattern and never re-submits on refresh.
	 *
	 * @since 0.5.0
	 */
	public function handle_create(): void {

		// Authorise and verify the form before reading any field; an un-capable
		// user or a forged request never reaches the filesystem.
		$this->guard_request( self::ACTION_CREATE );

		// Read and sanitise the create fields from the request. The slug and name
		// are text; the six rendition fields are read as raw strings and parsed by
		// the shared Collection_Input below. The upload-width radio picks between an
		// explicit pixel ceiling and the "source dimensions" choice, mapped to the
		// same `none` → null spelling the CLI uses. The path-components field is read
		// raw for the placement-template gate. The nonce is verified in
		// guard_request() above, before any field is read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard_request().
		$slug              = $this->read_string( $_POST, 'slug' );
		$name              = $this->read_string( $_POST, 'name' );
		$path_components   = $this->read_string( $_POST, 'path_components' );
		$upload_width_mode = $this->read_string( $_POST, 'upload_width_mode' );
		$renditions        = [
			'upload-width'      => $upload_width_mode === self::NO_LIMIT_VALUE
				? self::NO_LIMIT_VALUE
				: $this->read_string( $_POST, 'upload_width' ),
			'upload-quality'    => $this->read_string( $_POST, 'upload_quality' ),
			'full-width'        => $this->read_string( $_POST, 'full_width' ),
			'full-quality'      => $this->read_string( $_POST, 'full_quality' ),
			'thumbnail-width'   => $this->read_string( $_POST, 'thumbnail_width' ),
			'thumbnail-quality' => $this->read_string( $_POST, 'thumbnail_quality' ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A blank slug field is optional: resolve it to the unique sanitize_title
		// default of the name, suffixed against the slugs in use so it never collides
		// (the placeholder previews the same value on-blur). A *typed* slug passes
		// through untouched, so a typed collision still surfaces as the create error
		// below rather than being silently auto-suffixed.
		if ( $slug === '' ) {
			$slug = $this->unique_slug_default( $name, array_keys( $this->repository->discover() ) );
		}

		// Run the decision logic and redirect back to the list with its notice.
		$this->create_collection( $slug, $name, $renditions, $path_components );
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

		// Read and sanitise the slug, the display name, and the mutable placement
		// template. The nonce is verified in guard_request() above, before any field
		// is read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard_request().
		$slug            = $this->read_string( $_POST, 'slug' );
		$name            = $this->read_string( $_POST, 'name' );
		$path_components = $this->read_string( $_POST, 'path_components' );

		// The raw POST keys are inspected so the handler can detect a tampered
		// contract field and reject it server-side, even though the form renders
		// those fields disabled and never submits them.
		$tampered = $this->has_contract_field( array_keys( $_POST ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Run the decision logic and redirect back to the list with its notice. The
		// edit form always submits the template field, so it is passed (not null):
		// a blank field resets it to the default.
		$this->update_collection( $slug, $name, $tampered, $path_components );
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
	 * Computes the unique slug a blank Slug field defaults to.
	 *
	 * The Create form's Slug field is optional: a blank field falls back to the
	 * `sanitize_title` of the display name, made unique against the slugs already in
	 * use by appending the lowest free numeric suffix from `-2` (never `-1`) — the
	 * same scheme the on-blur placeholder previews client-side, with the server the
	 * authority (blocks.md "Create"). A name that slugifies to nothing yields the
	 * empty string, so the caller can reject a blank slug that has no base to default
	 * from rather than write an empty-named directory. Pure: the existing slugs are
	 * passed in, so the unit tests drive it over plain arrays.
	 *
	 * @since 0.12.0
	 *
	 * @param string            $name           The display name to derive the slug from.
	 * @param array<int,string> $existing_slugs The slugs already in use.
	 * @return string The unique default slug, or '' when the name slugifies to nothing.
	 */
	public function unique_slug_default( string $name, array $existing_slugs ): string {

		// Slugify the display name; an empty base means there is nothing to default
		// from, so the caller must demand a typed slug instead.
		$base = sanitize_title( $name );
		if ( $base === '' ) {
			return '';
		}

		// The bare base wins when it is free; a flip set makes the membership test
		// O(1) regardless of how many collections exist.
		$taken = array_flip( $existing_slugs );
		if ( ! isset( $taken[ $base ] ) ) {
			return $base;
		}

		// Otherwise append the lowest free numeric suffix from -2 upward, advancing
		// past every taken candidate in turn.
		$suffix = 2;
		while ( isset( $taken[ "{$base}-{$suffix}" ] ) ) {
			++$suffix;
		}

		return "{$base}-{$suffix}";

	}

	/**
	 * Establishes a new collection: validates, then creates the directory and
	 * writes its descriptor.
	 *
	 * The GUI counterpart of `collection create`. The slug must be a valid,
	 * unused slug; the six rendition fields are parsed by the shared
	 * `Collection_Input` (so the upload width's `none` → null form and the 0–100
	 * quality bounds match the CLI); the placement template is normalised and
	 * validated by the shared `Descriptor::normalize_path_components()` gate (a
	 * blank field means the default, a stray `%` or an unsafe template is rejected;
	 * ADR-0014). On success it creates the directory and writes `collection.json`
	 * with the three-rendition shape and the validated template. Each failure
	 * queues a precise error notice and returns without a partial write. Returns
	 * whether the collection was established, so a test can assert the effect
	 * without reading the notice queue.
	 *
	 * @since 0.5.0
	 *
	 * @param string               $slug            The collection identity to create.
	 * @param string               $name            The optional display name; humanised from the slug when empty.
	 * @param array<string,string> $renditions      The six raw rendition values keyed by flag name.
	 * @param string               $path_components The raw placement template; blank means the default (ADR-0014).
	 * @return bool True when the collection was established.
	 */
	public function create_collection(
		string $slug,
		string $name,
		array $renditions,
		string $path_components = ''
	): bool {

		// Reject a malformed slug up front so the user gets the same lexical
		// contract the rest of the plugin enforces.
		if ( ! $this->repository->is_valid_slug( $slug ) ) {
			$this->add_error(
				__( 'Invalid slug: use lowercase letters, digits and single hyphens.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Parse the six rendition fields, each defaulting from its filter when the
		// field is blank; a malformed value queues a precise error and aborts before
		// any directory is made. Only the upload pair is the irreversible contract.
		$renditions = $this->parse_renditions( $renditions );
		if ( $renditions === null ) {
			return false;
		}

		// Normalise and validate the placement template; a blank field means the
		// default, and a stray `%` or an unsafe template queues an error and aborts
		// before any directory is made (ADR-0014).
		$template = $this->validate_path_components( $path_components );
		if ( $template === null ) {
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

		// Write the descriptor that turns the bare directory into a collection,
		// carrying all three rendition tiers and the validated placement template.
		$descriptor = new Descriptor(
			$display_name,
			$renditions['upload_width'],
			$renditions['upload_quality'],
			$renditions['full_width'],
			$renditions['full_quality'],
			$renditions['thumbnail_width'],
			$renditions['thumbnail_quality'],
			$template,
		);
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
	 * Parses the six raw rendition fields, defaulting each from its filter.
	 *
	 * A blank field falls back to its `kntnt_photo_drop_default_*` filter (via
	 * `Rendition_Defaults`); a present field is parsed by the shared
	 * `Collection_Input` so the upload width's `none` → null form, the positive-int
	 * widths, and the 0–100 qualities all match the CLI exactly. The first
	 * malformed value queues a precise error and returns `null`, so the caller
	 * aborts before any directory is made. On success it returns the typed values
	 * keyed for the descriptor constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,string> $raw The six raw rendition strings keyed by flag name.
	 * @return array{upload_width:int|null,upload_quality:int,full_width:int,full_quality:int,thumbnail_width:int,thumbnail_quality:int}|null
	 */
	private function parse_renditions( array $raw ): ?array {

		// The upload width is the nullable half of the contract: a blank field takes
		// the filter default, "none" maps to the source's own dimensions, and any
		// other value must be a positive integer.
		$upload_width_raw = $raw['upload-width'] ?? '';
		if ( $upload_width_raw === '' ) {
			$upload_width = Rendition_Defaults::upload_width();
		} else {
			$upload_width = $this->input->parse_upload_width( $upload_width_raw );
			if ( $upload_width === false ) {
				$this->add_error(
					// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
					__( 'Upload width must be a positive integer, or choose “Original dimensions”.', 'kntnt-photo-drop' ),
				);
				return null;
			}
		}

		// Parse the four positive-int widths/quality pairs for the full and thumbnail
		// renditions and the upload quality; the first malformed one aborts. Each
		// blank field defaults from its filter.
		$upload_quality = $this->parse_quality_field(
			$raw['upload-quality'] ?? '',
			Rendition_Defaults::upload_quality(),
			__( 'Upload quality must be an integer between 0 and 100.', 'kntnt-photo-drop' ),
		);
		$full_width = $this->parse_width_field(
			$raw['full-width'] ?? '',
			Rendition_Defaults::full_width(),
			__( 'Full width must be a positive integer.', 'kntnt-photo-drop' ),
		);
		$full_quality = $this->parse_quality_field(
			$raw['full-quality'] ?? '',
			Rendition_Defaults::full_quality(),
			__( 'Full quality must be an integer between 0 and 100.', 'kntnt-photo-drop' ),
		);
		$thumb_width = $this->parse_width_field(
			$raw['thumbnail-width'] ?? '',
			Rendition_Defaults::thumbnail_width(),
			__( 'Thumbnail width must be a positive integer.', 'kntnt-photo-drop' ),
		);
		$thumb_quality = $this->parse_quality_field(
			$raw['thumbnail-quality'] ?? '',
			Rendition_Defaults::thumbnail_quality(),
			__( 'Thumbnail quality must be an integer between 0 and 100.', 'kntnt-photo-drop' ),
		);

		// Any malformed numeric field has already queued its error and read back as
		// null; one null aborts the whole parse so nothing is written.
		$malformed = $upload_quality === null
			|| $full_width === null
			|| $full_quality === null
			|| $thumb_width === null
			|| $thumb_quality === null;
		if ( $malformed ) {
			return null;
		}

		return [
			'upload_width'      => $upload_width,
			'upload_quality'    => $upload_quality,
			'full_width'        => $full_width,
			'full_quality'      => $full_quality,
			'thumbnail_width'   => $thumb_width,
			'thumbnail_quality' => $thumb_quality,
		];

	}

	/**
	 * Parses one positive-integer width field, defaulting from its filter.
	 *
	 * A blank field takes the supplied filter default; a present one must be a
	 * strictly positive integer, and a malformed value queues the supplied error
	 * and returns `null` so the caller aborts.
	 *
	 * @since 0.7.0
	 *
	 * @param string $value   The raw field value.
	 * @param int    $fallback The filter-resolved default width.
	 * @param string $error   The translated error to queue when the value is malformed.
	 * @return int|null The parsed width, or null when malformed.
	 */
	private function parse_width_field( string $value, int $fallback, string $error ): ?int {

		// A blank field is the documented default; a present one is parsed, and a
		// non-positive or malformed value queues its error and aborts.
		if ( $value === '' ) {
			return $fallback;
		}
		$parsed = $this->input->parse_width( $value );
		if ( $parsed === false ) {
			$this->add_error( $error );
			return null;
		}

		return $parsed;

	}

	/**
	 * Parses one 0–100 quality field, defaulting from its filter.
	 *
	 * A blank field takes the supplied filter default; a present one must be an
	 * integer in 0–100, and a malformed value queues the supplied error and returns
	 * `null` so the caller aborts.
	 *
	 * @since 0.7.0
	 *
	 * @param string $value   The raw field value.
	 * @param int    $fallback The filter-resolved default quality.
	 * @param string $error   The translated error to queue when the value is malformed.
	 * @return int|null The parsed quality, or null when malformed.
	 */
	private function parse_quality_field( string $value, int $fallback, string $error ): ?int {

		// A blank field is the documented default; a present one is parsed, and an
		// out-of-range or malformed value queues its error and aborts.
		if ( $value === '' ) {
			return $fallback;
		}
		$parsed = $this->input->parse_quality( $value );
		if ( $parsed === false ) {
			$this->add_error( $error );
			return null;
		}

		return $parsed;

	}

	/**
	 * Normalises and validates the placement template, queuing an error on rejection.
	 *
	 * Thin wrapper over the shared `Descriptor::normalize_path_components()` gate so
	 * the admin page rejects the same templates the CLI does: a blank field becomes
	 * the default, a stray `%` (the `%`-reservation) or a template whose sample
	 * expansion fails the `Path_Guard` lexical checks queues a precise error and
	 * returns `null` so the caller aborts before any write (ADR-0014).
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw The raw placement template from the form field.
	 * @return string|null The normalised template, or null when rejected.
	 */
	private function validate_path_components( string $raw ): ?string {

		// Defer to the shared gate; a false return is a rejection the user must see
		// named rather than have silently coerced.
		$template = Descriptor::normalize_path_components( $raw );
		if ( $template === false ) {
			$this->add_error(
				sprintf(
					/* translators: %s: the comma-separated list of recognised placeholder tokens. */
					__( 'Invalid path components: use only %s, and no “..” or absolute path.', 'kntnt-photo-drop' ),
					'%year%, %month%, %day%, %uploader%',
				),
			);
			return null;
		}

		return $template;

	}

	/**
	 * Updates a collection's mutable fields — the display name and placement template.
	 *
	 * The GUI counterpart of `collection update`. The display name and the
	 * placement template are the mutable fields; the output contract is immutable,
	 * so a tampered request that carries a contract field is rejected before
	 * anything is written — the user must never believe a frozen contract was
	 * changed. An explicit `$path_components` is normalised and validated by the
	 * shared gate (a stray `%` or an unsafe template is rejected; ADR-0014), while a
	 * `null` carries the current template over so a plain rename never disturbs it.
	 * The slug must resolve to an existing collection with a readable descriptor; on
	 * success the descriptor is rewritten with the name and template replaced.
	 * Returns whether the update happened.
	 *
	 * @since 0.5.0
	 *
	 * @param string      $slug             The collection identity to update.
	 * @param string      $name             The new, non-empty display name.
	 * @param bool        $carries_contract Whether the request tampered in a contract field.
	 * @param string|null $path_components  The raw placement template, or null to carry over.
	 * @return bool True when the descriptor was rewritten.
	 */
	public function update_collection(
		string $slug,
		string $name,
		bool $carries_contract,
		?string $path_components = null
	): bool {

		// Refuse any immutable-contract field before doing anything else: the user
		// must not walk away believing a frozen contract was altered.
		if ( $carries_contract ) {
			$this->add_error(
				__( 'The output contract is immutable; only the display name and path components can be changed.', 'kntnt-photo-drop' ), // phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
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
		// contract values exactly and touches only the mutable fields.
		$current = Descriptor::read( $path );
		if ( $current === null ) {
			$this->add_error(
				__( 'Cannot read the collection descriptor; refusing to overwrite it.', 'kntnt-photo-drop' ),
			);
			return false;
		}

		// Resolve the placement template: an explicit value is normalised and
		// validated (a rejection aborts before any write), while a null carries the
		// current template over so a plain rename leaves it untouched (ADR-0014).
		$template = $current->path_components;
		if ( $path_components !== null ) {
			$template = $this->validate_path_components( $path_components );
			if ( $template === null ) {
				return false;
			}
		}

		// Rewrite the descriptor with the name and the (possibly mutated) placement
		// template replaced; the immutable upload contract and the re-derivable
		// full/thumbnail pairs carry over untouched (their editable re-derive flow is
		// a later issue).
		$updated = new Descriptor(
			$name,
			$current->upload_width,
			$current->upload_quality,
			$current->full_width,
			$current->full_quality,
			$current->thumbnail_width,
			$current->thumbnail_quality,
			$template,
		);
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
	 * name, slug, the upload contract (width and quality), the full and thumbnail
	 * renditions, the always-WebP format, and the live image count, with
	 * always-visible Edit and Delete buttons in the rightmost column. A collection
	 * copied in from another site appears automatically; a deleted directory
	 * disappears.
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
		echo '<th scope="col">' . esc_html__( 'Upload', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Full', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Thumbnail', 'kntnt-photo-drop' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Format', 'kntnt-photo-drop' ) . '</th>';
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

		// Resolve the rendition cells from the descriptor; a missing descriptor renders
		// each one as a dash so a broken collection still lists by slug.
		$upload_cell = $descriptor !== null
			? $this->format_rendition_or_source( $descriptor->upload_width, $descriptor->upload_quality )
			: '—';
		$full_cell   = $descriptor !== null
			? $this->format_rendition( $descriptor->full_width, $descriptor->full_quality )
			: '—';
		$thumb_cell  = $descriptor !== null
			? $this->format_rendition( $descriptor->thumbnail_width, $descriptor->thumbnail_quality )
			: '—';
		$format_cell = $descriptor !== null ? __( 'WebP', 'kntnt-photo-drop' ) : '—';

		// The image count is read live from disk; an unreadable subtree yields an
		// unknown count, rendered as a dash rather than failing the whole page.
		$count       = $this->count_images( $path );
		$images_cell = $count === null ? '—' : (string) $count;

		echo '<tr>';
		echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
		echo '<td><code>' . esc_html( $slug ) . '</code></td>';
		echo '<td>' . esc_html( $upload_cell ) . '</td>';
		echo '<td>' . esc_html( $full_cell ) . '</td>';
		echo '<td>' . esc_html( $thumb_cell ) . '</td>';
		echo '<td>' . esc_html( $format_cell ) . '</td>';
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
	 * The GUI counterpart of `collection create`: an optional display name, an
	 * optional slug (its placeholder previews the unique `sanitize_title` default,
	 * refreshed on-blur of the display name), the mutable placement template (with a
	 * live expanded-path preview), and the six rendition fields — the immutable upload
	 * width/quality contract and the re-derivable full and thumbnail width/quality
	 * pairs — each pre-filled from its `kntnt_photo_drop_default_*` filter via
	 * `Rendition_Defaults`. The six fields are presented as one uniform "Image
	 * settings" section, not a contract/renditions split (#67) — uniform layout, not
	 * uniform behaviour. The upload width offers an explicit "Original dimensions"
	 * choice. There is deliberately no format field (always WebP). The slug and the
	 * upload pair carry a permanence ⚠️ marker; a prominent irreversibility warning
	 * sits above the two upload-contract fields, and a set-once rule line sits beside
	 * the Save button (blocks.md "Create", ADR-0013).
	 *
	 * @since 0.5.0
	 */
	private function render_create_form(): void {

		echo '<h1>' . esc_html__( 'Create collection', 'kntnt-photo-drop' ) . '</h1>';
		settings_errors( self::NOTICE_SLUG );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_CREATE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Display name — optional; the slug defaults from it (and it falls back to a
		// humanised slug when both are blank). The data hook lets the on-blur script
		// recompute the slug placeholder when this field loses focus.
		$name_label = __( 'Display name', 'kntnt-photo-drop' );
		$name_help  = __( 'Optional. Defaults to a humanised form of the slug.', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-name">' . esc_html( $name_label ) . '</label></th><td>';
		echo '<input name="name" id="kntnt-photo-drop-name" type="text" class="regular-text" ';
		echo 'data-kntnt-photo-drop-name-input />';
		echo '<p class="description">' . esc_html( $name_help ) . '</p>';
		echo '</td></tr>';

		// Slug — optional and permanent: a blank field defaults to the unique
		// sanitize_title of the display name (the placeholder previews it, refreshed
		// on-blur of the display name), while a typed collision is a save-time error.
		// The existing slugs are rendered so the on-blur compute can suffix against
		// them without a round-trip; the server re-verifies uniqueness at submit.
		$slug_label = __( 'Slug', 'kntnt-photo-drop' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$slug_help = __( 'Optional. Blank uses the unique default shown as the placeholder. Lowercase letters, digits and single hyphens.', 'kntnt-photo-drop' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$slug_reason   = __( 'Permanent: the slug is the collection’s identity and cannot be changed after creation.', 'kntnt-photo-drop' );
		$existing_json = (string) wp_json_encode( array_keys( $this->repository->discover() ) );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-slug">' . esc_html( $slug_label ) . '</label> ';
		$this->render_permanence_marker( $slug_reason );
		echo '</th><td>';
		printf(
			'<input name="slug" id="kntnt-photo-drop-slug" type="text" class="regular-text"'
			. ' pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-kntnt-photo-drop-slug-input'
			. ' data-kntnt-photo-drop-existing-slugs="%s" />',
			esc_attr( $existing_json ),
		);
		echo '<p class="description">' . esc_html( $slug_help ) . '</p>';
		echo '</td></tr>';

		// Path components — optional placement template; blank uses the default. An
		// empty value field lets the placeholder document the default, and the live
		// preview shows the resulting path shape.
		$this->render_path_components_field( '' );

		echo '</tbody></table>';

		// All three tiers — upload (main), full, thumbnail — sit under one uniform
		// heading: every field is a width/quality pair, so splitting the upload pair
		// from the full/thumbnail pairs into separately-labelled sections is just
		// visual noise (#67). Uniform layout is not uniform behaviour: only the upload
		// pair is permanent, so the irreversibility warning still sits directly above
		// it, and the re-derivable pairs still carry their milder note below.
		echo '<h2>' . esc_html__( 'Image settings', 'kntnt-photo-drop' ) . '</h2>';

		// The irreversibility warning sits directly above the two upload-contract
		// fields so it cannot be missed before they are set; only the upload pair is
		// permanent (ADR-0013).
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$warning_lead = __( 'The upload width and quality fix the collection’s immutable output contract and cannot be changed afterwards.', 'kntnt-photo-drop' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$warning_body = __( 'The main image is downscaled and re-encoded at ingestion and the original is never kept, so these two values are permanent. The full and thumbnail renditions below are re-derived from the main and can be changed later.', 'kntnt-photo-drop' );
		echo '<div class="notice notice-warning inline"><p><strong>';
		echo esc_html( $warning_lead );
		echo '</strong> ';
		echo esc_html( $warning_body );
		echo '</p></div>';

		echo '<table class="form-table" role="presentation"><tbody>';

		// The permanence reason shared by the two upload-contract fields names why they
		// are set once — the source bytes are discarded at ingestion.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$upload_reason = __( 'Permanent: the main image is downscaled and re-encoded at ingestion and the source is discarded, so this cannot be changed afterwards.', 'kntnt-photo-drop' );

		// Upload width — permanent; a radio chooses between a pixel ceiling and the
		// explicit "Original dimensions" choice, with the number input carrying the
		// filter default, and the label carries the ⚠️ permanence marker.
		$width_label = __( 'Upload width', 'kntnt-photo-drop' );
		echo '<tr><th scope="row">' . esc_html( $width_label ) . ' ';
		$this->render_permanence_marker( $upload_reason );
		echo '</th><td>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html( $width_label ) . '</legend>';
		echo '<label><input type="radio" name="upload_width_mode" value="limit" checked /> ';
		echo esc_html__( 'Limit to', 'kntnt-photo-drop' ) . ' ';
		printf(
			'<input name="upload_width" type="number" min="1" step="1" value="%s" class="small-text" /> %s',
			esc_attr( $this->prefill_width( Rendition_Defaults::upload_width() ) ),
			esc_html__( 'pixels', 'kntnt-photo-drop' ),
		);
		echo '</label><br />';
		echo '<label><input type="radio" name="upload_width_mode" value="' . esc_attr( self::NO_LIMIT_VALUE ) . '"';
		echo Rendition_Defaults::upload_width() === null ? ' checked' : '';
		echo ' /> ';
		echo esc_html__( 'Original dimensions', 'kntnt-photo-drop' );
		echo '</label></fieldset></td></tr>';

		// Upload quality — permanent; pre-filled from its filter default and carrying
		// the same ⚠️ marker.
		$this->render_quality_field(
			'upload_quality',
			__( 'Upload quality', 'kntnt-photo-drop' ),
			Rendition_Defaults::upload_quality(),
			$upload_reason,
		);

		echo '</tbody></table>';

		// The re-derivable full and thumbnail pairs continue the same section — no
		// separate heading (#67) — without the irreversibility warning, because they
		// can be regenerated later; a mild note makes that consequence explicit (no
		// permanence marker here; ADR-0013).
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$rederive_note = __( 'These are not permanent, but changing them later regenerates every existing image.', 'kntnt-photo-drop' );
		echo '<p class="description">' . esc_html( $rederive_note ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_width_field(
			'full_width',
			__( 'Full width', 'kntnt-photo-drop' ),
			Rendition_Defaults::full_width(),
		);
		$this->render_quality_field(
			'full_quality',
			__( 'Full quality', 'kntnt-photo-drop' ),
			Rendition_Defaults::full_quality(),
		);
		$this->render_width_field(
			'thumbnail_width',
			__( 'Thumbnail width', 'kntnt-photo-drop' ),
			Rendition_Defaults::thumbnail_width(),
		);
		$this->render_quality_field(
			'thumbnail_quality',
			__( 'Thumbnail quality', 'kntnt-photo-drop' ),
			Rendition_Defaults::thumbnail_quality(),
		);
		echo '</tbody></table>';

		// The Save button carries a rule line stating that the ⚠️-marked fields (the
		// slug and the upload pair) are set once and cannot be changed afterwards, so
		// the consequence is stated at the moment of commitment.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$set_once = __( 'Fields marked ⚠ are set once and cannot be changed after the collection is created.', 'kntnt-photo-drop' );
		submit_button( __( 'Create collection', 'kntnt-photo-drop' ) );
		echo '<p class="description kntnt-photo-drop-permanence-rule">' . esc_html( $set_once ) . '</p>';
		echo '</form>';

	}

	/**
	 * Renders one positive-integer width field pre-filled from its default.
	 *
	 * Shared by the create form's full and thumbnail width rows. The field name is
	 * the raw POST key the handler reads; the value carries the filter default.
	 *
	 * @since 0.7.0
	 *
	 * @param string $name    The form field name (and id stem).
	 * @param string $label   The translated field label.
	 * @param int    $fallback The filter-resolved default width to pre-fill.
	 */
	private function render_width_field( string $name, string $label, int $fallback ): void {

		// A plain number input pre-filled with the default; the handler parses it as
		// a positive integer.
		$id = 'kntnt-photo-drop-' . str_replace( '_', '-', $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input name="%s" id="%s" type="number" min="1" step="1" value="%s" class="small-text" /> %s',
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( (string) $fallback ),
			esc_html__( 'pixels', 'kntnt-photo-drop' ),
		);
		echo '</td></tr>';

	}

	/**
	 * Renders one 0–100 quality field pre-filled from its default.
	 *
	 * Shared by the create form's upload, full, and thumbnail quality rows. The
	 * field name is the raw POST key the handler reads; the value carries the filter
	 * default. A non-empty permanence reason appends the ⚠️ marker to the label cell —
	 * supplied for the permanent upload-quality row, omitted for the re-derivable
	 * full/thumbnail rows.
	 *
	 * @since 0.7.0
	 *
	 * @param string $name           The form field name (and id stem).
	 * @param string $label          The translated field label.
	 * @param int    $fallback       The filter-resolved default quality to pre-fill.
	 * @param string $marker_reason  The permanence reason, or '' for no marker.
	 */
	private function render_quality_field(
		string $name,
		string $label,
		int $fallback,
		string $marker_reason = ''
	): void {

		// A bounded number input pre-filled with the default; the handler parses it
		// as a 0–100 integer. The label cell carries the optional permanence marker.
		$id = 'kntnt-photo-drop-' . str_replace( '_', '-', $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> ';
		if ( $marker_reason !== '' ) {
			$this->render_permanence_marker( $marker_reason );
		}
		echo '</th><td>';
		printf(
			'<input name="%s" id="%s" type="number" min="0" max="100" step="1" value="%s" class="small-text" />',
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( (string) $fallback ),
		);
		echo ' <span class="description">' . esc_html__( 'WebP quality, 0–100.', 'kntnt-photo-drop' ) . '</span>';
		echo '</td></tr>';

	}

	/**
	 * Echoes the ⚠️ permanence marker appended to a permanent field's label.
	 *
	 * A small, accessible badge for every field that is set once and cannot be changed
	 * after the collection is created — the slug and the upload width/quality (blocks.md
	 * "Create"). The visible glyph is hidden from assistive tech (`aria-hidden`); the
	 * accessible name and the hover tooltip both carry the supplied reason, so the
	 * warning reaches sighted, screen-reader, and pointer-hover users alike. The reason
	 * is escaped at output, so the marker is safe to render directly into a label cell.
	 *
	 * @since 0.12.0
	 *
	 * @param string $reason The translated explanation of why this field is permanent.
	 */
	private function render_permanence_marker( string $reason ): void {
		printf(
			'<span class="kntnt-photo-drop-permanence" role="img" aria-label="%1$s" title="%1$s">'
			. '<span aria-hidden="true">⚠</span></span>',
			esc_attr( $reason ),
		);
	}

	/**
	 * Returns the pre-fill string for the upload-width number input.
	 *
	 * The "Original dimensions" default (`null`) leaves the number input blank (the
	 * radio defaults to that choice instead); a concrete ceiling pre-fills the input
	 * with its value so a builder who flips back to "Limit to" sees a usable number.
	 *
	 * @since 0.7.0
	 *
	 * @param int|null $fallback The filter-resolved default upload width.
	 * @return string The value attribute for the number input.
	 */
	private function prefill_width( ?int $fallback ): string {
		return $fallback === null ? '' : (string) $fallback;
	}

	/**
	 * Renders the Path components field plus its live expanded-path preview.
	 *
	 * Shared by the create and edit forms (ADR-0014). The text input carries the
	 * stored value (blank on create, so the placeholder documents the default
	 * template), and a sibling element shows the template expanded with sample
	 * values so the builder sees the resulting path shape. The preview is
	 * presentational only — it carries no safety logic; the save-time gate is what
	 * accepts or rejects a template. The `data-kntnt-photo-drop-path-preview` and
	 * `data-kntnt-photo-drop-path-input` hooks let the inline admin script update
	 * the preview as the field is typed, and the field renders a correct initial
	 * preview with no JS at all.
	 *
	 * @since 0.7.0
	 *
	 * @param string $value The stored template to pre-fill (empty on create).
	 */
	private function render_path_components_field( string $value ): void {

		// Resolve the sample values and the initial preview: a blank field previews
		// the default template, a present one previews itself, so the preview always
		// reflects what would be stored.
		$default = Descriptor::DEFAULT_PATH_COMPONENTS;
		$preview = Path_Template::sample_expansion( $value === '' ? $default : $value );
		$label   = __( 'Path components', 'kntnt-photo-drop' );
		$help    = sprintf(
			/* translators: %s: the comma-separated list of recognised placeholder tokens. */
			__( 'Where Drop Zone uploads are placed. Blank uses the default. Placeholders: %s.', 'kntnt-photo-drop' ),
			'%year%, %month%, %day%, %uploader%',
		);

		// Render the labelled text input carrying the sample-token list and the
		// default as its placeholder, the live preview line, and the help text.
		echo '<tr><th scope="row">';
		echo '<label for="kntnt-photo-drop-path-components">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input name="path_components" id="kntnt-photo-drop-path-components" type="text" class="regular-text code"'
			. ' value="%s" placeholder="%s" data-kntnt-photo-drop-path-input />',
			esc_attr( $value ),
			esc_attr( $default ),
		);
		printf(
			'<p class="description">%s <code data-kntnt-photo-drop-path-preview>%s</code></p>',
			esc_html__( 'Example path:', 'kntnt-photo-drop' ),
			esc_html( $preview ),
		);
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</td></tr>';

	}

	/**
	 * Renders the edit form for one collection.
	 *
	 * The display name and the placement template are editable and saved instantly by
	 * the form POST (the template affects only future uploads, so it is safe to
	 * change; ADR-0014). The immutable upload width/quality and the always-WebP format
	 * are shown read-only — never as editable fields, so a save can never touch the
	 * irreversible pair. The re-derivable full and thumbnail width/quality are
	 * editable, but in a separate regenerate section *after* the form
	 * (`render_regenerate_section()`): changing one takes effect by browser-driven
	 * regenerate-then-flip (ADR-0013), not the instant-save POST. The read-only upload
	 * pair and the editable regenerate pairs share one uniform "Image settings"
	 * heading (#67) — uniform layout, not uniform behaviour: the section split is
	 * dropped, the submission split is not. The slug is shown read-only as the durable
	 * identity. An unknown slug shows an error and a link back to the list.
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

		// Display name — editable.
		$name_label = __( 'Display name', 'kntnt-photo-drop' );
		echo '<tr><th scope="row"><label for="kntnt-photo-drop-name">' . esc_html( $name_label ) . '</label></th><td>';
		echo '<input name="name" id="kntnt-photo-drop-name" type="text" class="regular-text" value="';
		echo esc_attr( $descriptor->name ) . '" required />';
		echo '</td></tr>';

		// Path components — editable; it affects only future uploads, so it is safe
		// to change (ADR-0014). Pre-filled with the stored template and shown with
		// the live preview.
		$this->render_path_components_field( $descriptor->path_components );

		echo '</tbody></table>';

		// All three tiers — upload (main), full, thumbnail — sit under one uniform
		// heading (#67); the read-only upload pair opens it here, inside the
		// instant-save form, and the editable full/thumbnail pairs continue it in the
		// regenerate section below (no separate heading there). Uniform layout is not
		// uniform behaviour: the upload width and quality were fixed at establishment
		// (the source is discarded) and the format is always WebP, so none of these is
		// an editable field and none POSTs — a save touches only the display name and
		// the placement template.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$contract_note = __( 'The upload width and quality were fixed when the collection was established and cannot be changed.', 'kntnt-photo-drop' );
		echo '<h2>' . esc_html__( 'Image settings', 'kntnt-photo-drop' ) . '</h2>';
		echo '<p class="description">' . esc_html( $contract_note ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_disabled_row(
			__( 'Upload width', 'kntnt-photo-drop' ),
			$this->format_upload_width( $descriptor->upload_width ),
		);
		$this->render_disabled_row( __( 'Upload quality', 'kntnt-photo-drop' ), (string) $descriptor->upload_quality );
		$this->render_disabled_row( __( 'Format', 'kntnt-photo-drop' ), __( 'WebP', 'kntnt-photo-drop' ) );
		echo '</tbody></table>';

		// The Save button commits the instant fields (display name + placement
		// template); the re-derivable renditions are handled by their own regenerate
		// section outside this form, so a plain save never disturbs them.
		submit_button( __( 'Save changes', 'kntnt-photo-drop' ) );
		$this->render_cancel_link();
		echo '</form>';

		// Render the re-derivable renditions section after the form, so it is driven by
		// the browser-side regenerate flow (regenerate-then-flip; ADR-0013) rather than
		// the instant-save POST.
		$this->render_regenerate_section( $slug, $descriptor );

	}

	/**
	 * Renders the editable re-derivable renditions and the regenerate control.
	 *
	 * The browser-driven half of the Edit page (ADR-0013): the four re-derivable
	 * fields — full width/quality and thumbnail width/quality — are editable number
	 * inputs pre-filled from the descriptor, and a Regenerate button drives the
	 * batched re-derive against the manage-gated REST endpoint. The descriptor's
	 * active widths flip only once every main is re-derived, so the gallery keeps
	 * serving the old renditions until the run completes; an interrupted run is safe
	 * because the descriptor was never flipped. This section sits **outside** the
	 * instant-save form: the inputs never POST to the update handler, so a plain save
	 * cannot flip the widths without first regenerating (which would leave the gallery
	 * pointing at missing files). The host element carries the collection slug and the
	 * `wp_rest` nonce the regenerate script reads, plus the empty progress region the
	 * shared progress view writes into.
	 *
	 * @since 0.11.0
	 *
	 * @param string     $slug       The collection slug the regenerate run targets.
	 * @param Descriptor $descriptor The collection descriptor, for the pre-filled values.
	 */
	private function render_regenerate_section( string $slug, Descriptor $descriptor ): void {

		// Continue the single "Image settings" section the read-only upload pair opened
		// (#67) — no separate heading — and name the consequence of changing a value,
		// so the builder understands a change triggers a regenerate rather than an
		// instant save.
		// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		$note = __( 'The full and thumbnail renditions are re-derived from the main image. Changing a width or quality regenerates every image; the gallery keeps showing the current renditions until the regeneration finishes.', 'kntnt-photo-drop' );
		echo '<p class="description">' . esc_html( $note ) . '</p>';

		// The host element carries the slug and a fresh wp_rest nonce so the regenerate
		// script can drive the REST endpoint, and wraps the editable fields, the
		// Regenerate button, and the progress region the shared progress view fills.
		printf(
			'<div data-kntnt-photo-drop-regenerate data-kntnt-photo-drop-collection="%s"'
			. ' data-kntnt-photo-drop-nonce="%s">',
			esc_attr( $slug ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
		);

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_editable_width_field(
			'full_width',
			__( 'Full width', 'kntnt-photo-drop' ),
			$descriptor->full_width,
		);
		$this->render_editable_quality_field(
			'full_quality',
			__( 'Full quality', 'kntnt-photo-drop' ),
			$descriptor->full_quality,
		);
		$this->render_editable_width_field(
			'thumbnail_width',
			__( 'Thumbnail width', 'kntnt-photo-drop' ),
			$descriptor->thumbnail_width,
		);
		$this->render_editable_quality_field(
			'thumbnail_quality',
			__( 'Thumbnail quality', 'kntnt-photo-drop' ),
			$descriptor->thumbnail_quality,
		);
		echo '</tbody></table>';

		// The Regenerate trigger and the empty progress/status regions: the regenerate
		// script wires the button to the batched REST run and writes the aggregate bar
		// and the final summary into these regions (the shared Drop Zone progress view).
		printf(
			'<p><button type="button" class="button button-primary"'
			. ' data-kntnt-photo-drop-regenerate-start>%s</button></p>',
			esc_html__( 'Regenerate renditions', 'kntnt-photo-drop' ),
		);
		echo '<div class="kntnt-photo-drop-regenerate__progress" data-kntnt-photo-drop-regenerate-progress></div>';
		echo '<div class="kntnt-photo-drop-regenerate__status" data-kntnt-photo-drop-regenerate-status></div>';
		echo '<div class="screen-reader-text" aria-live="polite" data-kntnt-photo-drop-regenerate-summary></div>';

		echo '</div>';

	}

	/**
	 * Renders one editable positive-integer width field pre-filled from the descriptor.
	 *
	 * Shared by the regenerate section's full and thumbnail width rows. The field name
	 * is the key the regenerate script reads; the field never POSTs to the update
	 * handler (it sits outside the form), so the value reaches the server only through
	 * the regenerate REST call.
	 *
	 * @since 0.11.0
	 *
	 * @param string $name    The form field name (and id stem).
	 * @param string $label   The translated field label.
	 * @param int    $current The stored width to pre-fill.
	 */
	private function render_editable_width_field( string $name, string $label, int $current ): void {

		// A plain number input pre-filled with the stored width; the regenerate script
		// reads it as the target full/thumbnail width.
		$id = 'kntnt-photo-drop-' . str_replace( '_', '-', $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input name="%s" id="%s" type="number" min="1" step="1" value="%s" class="small-text" /> %s',
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( (string) $current ),
			esc_html__( 'pixels', 'kntnt-photo-drop' ),
		);
		echo '</td></tr>';

	}

	/**
	 * Renders one editable 0–100 quality field pre-filled from the descriptor.
	 *
	 * Shared by the regenerate section's full and thumbnail quality rows. Like the
	 * width field it sits outside the instant-save form and is read only by the
	 * regenerate script.
	 *
	 * @since 0.11.0
	 *
	 * @param string $name    The form field name (and id stem).
	 * @param string $label   The translated field label.
	 * @param int    $current The stored quality to pre-fill.
	 */
	private function render_editable_quality_field( string $name, string $label, int $current ): void {

		// A bounded number input pre-filled with the stored quality; the regenerate
		// script reads it as the target full/thumbnail quality.
		$id = 'kntnt-photo-drop-' . str_replace( '_', '-', $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input name="%s" id="%s" type="number" min="0" max="100" step="1" value="%s" class="small-text" />',
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( (string) $current ),
		);
		echo ' <span class="description">' . esc_html__( 'WebP quality, 0–100.', 'kntnt-photo-drop' ) . '</span>';
		echo '</td></tr>';

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
	 * Used by the edit view to display each rendition value (upload width/quality,
	 * full, thumbnail, format) without making it editable. The disabled input never
	 * POSTs, so the value survives a save untouched.
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
	 * The edit form renders the immutable upload-contract fields disabled and never
	 * submits them, so their presence in a POST signals a tampered request. The two
	 * fields (`upload_width`, `upload_quality`) mirror the CLI's immutable contract
	 * flags, so the page and the CLI judge a contract change by the same rule; either
	 * present on an update is a tampered contract change.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,int|string> $keys The request's field names (e.g. `array_keys( $_POST )`).
	 * @return bool True when an immutable upload-contract field is present.
	 */
	private function has_contract_field( array $keys ): bool {
		return in_array( 'upload_width', $keys, true ) || in_array( 'upload_quality', $keys, true );
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
	 * Formats an upload-width value for display.
	 *
	 * A `null` ceiling renders as the translatable "Original dimensions"; a positive
	 * integer renders as its pixel count with a "px" suffix.
	 *
	 * @since 0.5.0
	 *
	 * @param int|null $upload_width The immutable upload ceiling, or null for the source's own dimensions.
	 * @return string A display string such as "1920 px" or "Original dimensions".
	 */
	private function format_upload_width( ?int $upload_width ): string {
		return $upload_width === null
			? __( 'Original dimensions', 'kntnt-photo-drop' )
			/* translators: %d: width in pixels. */
			: sprintf( __( '%d px', 'kntnt-photo-drop' ), $upload_width );
	}

	/**
	 * Formats a rendition's width and quality as one "W px, quality Q" string.
	 *
	 * Used for the re-derivable full and thumbnail renditions in the list and edit
	 * views.
	 *
	 * @since 0.7.0
	 *
	 * @param int $width   The rendition width in pixels.
	 * @param int $quality The rendition WebP quality (0–100).
	 * @return string A display string such as "1920 px, quality 85".
	 */
	private function format_rendition( int $width, int $quality ): string {
		/* translators: 1: rendition width in pixels; 2: WebP quality, 0–100. */
		return sprintf( __( '%1$d px, quality %2$d', 'kntnt-photo-drop' ), $width, $quality );
	}

	/**
	 * Formats the upload rendition's width and quality for the list cell.
	 *
	 * Like `format_rendition()` but the width half honours the nullable upload
	 * ceiling — "Original dimensions" when unbounded — since the upload width is the
	 * only nullable rendition.
	 *
	 * @since 0.7.0
	 *
	 * @param int|null $width   The upload ceiling, or null for the source's own dimensions.
	 * @param int      $quality The upload WebP quality (0–100).
	 * @return string A display string such as "1920 px, quality 95".
	 */
	private function format_rendition_or_source( ?int $width, int $quality ): string {

		// The width half honours the nullable upload ceiling; the quality is appended
		// as a plain integer.
		$width_label = $this->format_upload_width( $width );

		/* translators: 1: upload width (a pixel count or "Original dimensions"); 2: WebP quality, 0–100. */
		return sprintf( __( '%1$s, quality %2$d', 'kntnt-photo-drop' ), $width_label, $quality );

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
