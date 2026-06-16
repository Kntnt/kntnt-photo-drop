<?php
/**
 * Tests for the collection-lifecycle admin page.
 *
 * The page is the GUI mirror of the CLI's create/update/delete verbs, so these
 * tests drive its small, directly-testable request-handling methods against a
 * real temp directory and assert the same on-disk effects the CLI tests assert
 * (a valid descriptor written, "No limit" → null, only `name` rewritten on
 * update, a tampered contract change rejected, a directory removed on delete).
 * WordPress admin functions are stubbed via Brain Monkey; the menu registration
 * and the capability filter are asserted directly. The pure flag rules the page
 * delegates to `Collection_Input` are covered in CollectionInputTest.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Admin\Admin_Page;
use Kntnt\Photo_Drop\Collection\Path_Template;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Tests\Unit\Fixtures\Admin_Page_Halt;

/**
 * Wires every WordPress function the page and its collaborators touch.
 *
 * The repository needs `wp_upload_dir` / `trailingslashit` / `wp_mkdir_p`; the
 * descriptor needs `wp_json_encode` / `apply_filters` (for the thumbnail width
 * and the default-contract pre-fills); the page itself needs `__` and the
 * notice/redirect stubs. All filesystem-touching functions are given real
 * behaviour against a temp basedir so the page's effects are exercised end to
 * end. Returns the canonical trailing-slashed collection root.
 *
 * @param string $basedir Absolute temp directory standing in for the uploads basedir.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_admin_stubs( string $basedir ): string {

	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => $basedir,
			'error'   => false,
		]
	);

	Functions\when( 'trailingslashit' )->alias(
		static fn ( string $path ): string => rtrim( $path, '/\\' ) . '/'
	);

	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	// apply_filters: pass every value through unchanged. The root default and the
	// six rendition defaults therefore resolve to their documented values via
	// Rendition_Defaults (upload width null, upload 95, full 1920/85, thumbnail
	// 640/75).
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed => $value
	);

	// Translation, sanitisation, and notice stubs: __ returns its source string;
	// sanitize_text_field trims; add_settings_error records nothing the tests need
	// (effects are asserted on disk).
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias(
		static fn ( string $str ): string => trim( $str )
	);
	Functions\when( 'add_settings_error' )->justReturn( null );

	return rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';

}

/**
 * Allocates a fresh temp basedir for one admin-page test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_admin_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-admin-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

/**
 * Removes a directory tree, used to clean up the temp uploads basedir.
 *
 * @param string $dir The directory to remove.
 */
function admin_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		admin_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Seeds a real collection on disk by establishing it through the repository and
 * descriptor, so an update/delete test starts from a genuine collection.
 *
 * The full and thumbnail renditions take fixed defaults (full 1920/85, thumbnail
 * 640/75); the tests that read them back only assert the upload contract and the
 * display name, so the derived values are immaterial here.
 *
 * @param string   $root         The trailing-slashed collection root.
 * @param string   $slug         The collection slug.
 * @param string   $name         The display name.
 * @param int|null $upload_width The upload ceiling, or null for the source's own dimensions.
 * @param int      $quality      The upload WebP quality.
 */
function seed_admin_collection( string $root, string $slug, string $name, ?int $upload_width, int $quality ): void {
	$path       = ( new Repository() )->create_collection( $slug );
	$descriptor = new Descriptor(
		$name,
		$upload_width,
		$quality,
		1920,
		85,
		640,
		75,
		Descriptor::DEFAULT_PATH_COMPONENTS,
	);
	$descriptor->write( (string) $path );
}

/**
 * Stubs the WordPress functions the render layer calls, so a view can be
 * captured to a string and inspected. Pass-through escapers and trivial markup
 * helpers keep the captured HTML close to what the page emits.
 *
 * @param string $basedir Absolute temp directory standing in for the uploads basedir.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_admin_render_stubs( string $basedir ): string {

	$root = wire_admin_stubs( $basedir );

	// Pass-through escapers so the captured markup is close to what is emitted; the
	// assertions search for attribute names and values the page itself echoes.
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( 'esc_url' )->returnArg( 1 );

	// URL and request helpers: build recognisable URLs and pass request values
	// through, so the page routes to the requested view and renders its links.
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'add_query_arg' )->alias(
		static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
	);
	Functions\when( 'sanitize_key' )->alias(
		static fn ( string $key ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) )
	);
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'current_user_can' )->justReturn( true );

	// Output helpers the page calls but whose output the assertions do not inspect
	// are no-ops, so the markup under test is only what the page itself echoes. The
	// regenerate section mints a wp_rest nonce for its host element; a fixed token
	// keeps the captured markup deterministic.
	Functions\when( 'wp_nonce_field' )->justReturn( '' );
	Functions\when( 'wp_create_nonce' )->justReturn( 'regen-nonce' );
	Functions\when( 'submit_button' )->justReturn( '' );
	Functions\when( 'settings_errors' )->justReturn( null );
	Functions\when( 'get_transient' )->justReturn( false );

	return $root;

}

// ---------------------------------------------------------------------------
// Create form — the six rendition fields pre-filled from their default filters
// ---------------------------------------------------------------------------

test( 'the create form pre-fills the six rendition fields from their default filters', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	// The six default filters resolve to their documented values through the
	// pass-through apply_filters stub: upload width null (the "Original dimensions"
	// radio is checked and the number input is blank), upload 95, full 1920/85,
	// thumbnail 640/75.
	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// Every rendition field is present and carries its default; the upload-width
	// radio defaults to "Original dimensions" (null) and the irreversibility
	// warning sits above the upload pair.
	expect( $html )->toContain( 'name="upload_width"' );
	expect( $html )->toContain( 'name="upload_width_mode"' );
	expect( $html )->toContain( 'name="upload_quality"' );
	expect( $html )->toContain( 'value="95"' );
	expect( $html )->toContain( 'name="full_width"' );
	expect( $html )->toContain( 'value="1920"' );
	expect( $html )->toContain( 'name="full_quality"' );
	expect( $html )->toContain( 'value="85"' );
	expect( $html )->toContain( 'name="thumbnail_width"' );
	expect( $html )->toContain( 'value="640"' );
	expect( $html )->toContain( 'name="thumbnail_quality"' );
	expect( $html )->toContain( 'value="75"' );
	expect( $html )->toContain( 'notice-warning' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form has no format field and no uploader-folders field', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The renditions never expose a format choice (always WebP); the retired
	// uploader-folders boolean is gone (replaced by the path-components template),
	// so neither input name appears.
	expect( $html )->not->toContain( 'name="format"' );
	expect( $html )->not->toContain( 'name="uploader_folders"' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Path components field + live preview on the Create form (ADR-0014)
// ---------------------------------------------------------------------------

test( 'the create form renders a path-components field with the default as placeholder', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The Path components field is present; its placeholder shows the default
	// template so a blank field documents what it falls back to (ADR-0014).
	expect( $html )->toContain( 'name="path_components"' );
	expect( $html )->toContain( 'placeholder="' . Descriptor::DEFAULT_PATH_COMPONENTS . '"' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form renders a live expanded-path preview with sample values', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// A preview element is rendered, seeded with the default template's sample
	// expansion so the builder sees the resulting path shape immediately; a data
	// attribute hooks the JS that updates it as the field is typed (ADR-0014).
	expect( $html )->toContain( 'data-kntnt-photo-drop-path-preview' );
	expect( $html )->toContain( Path_Template::sample_expansion( Descriptor::DEFAULT_PATH_COMPONENTS ) );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'create stores a normalised path-components template from the form field', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// The submitted template is normalised and stored; edge and repeated separators
	// collapse (ADR-0014).
	$created = $page->create_collection( 'placed', 'Placed', admin_renditions(), '/events/%year%//' );

	expect( $created )->toBeTrue();
	expect( Descriptor::read( $root . 'placed' )->path_components )->toBe( 'events/%year%' );

	admin_remove_tree( $basedir );
} );

test( 'create defaults the path-components template when the field is blank', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// A blank field means the default template — there is no flat-at-root placement
	// (ADR-0014).
	$page->create_collection( 'blank-template', 'Blank', admin_renditions(), '' );

	$descriptor = Descriptor::read( $root . 'blank-template' );
	expect( $descriptor->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	admin_remove_tree( $basedir );
} );

test( 'create rejects an invalid path-components template and writes nothing', function ( string $template ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	// A stray `%` or an unsafe ("..") template is rejected before any directory is
	// made (ADR-0014).
	$created = $page->create_collection( 'rejected', 'Rejected', admin_renditions(), $template );

	expect( $created )->toBeFalse();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	admin_remove_tree( $basedir );
} )->with( [
	'stray percent' => [ '%year%/%moth%' ],
	'traversal'     => [ '%year%/../../escape' ],
] );

test( 'handle_create reads the path-components field from the POST', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// The handler needs the request guard, nonce, and redirect stubs; it reads the
	// path-components field from $_POST alongside the rendition fields.
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'set_transient' )->justReturn( true );
	Functions\when( 'get_settings_errors' )->justReturn( [] );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url );
	Functions\when( 'wp_safe_redirect' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'              => 'posted-template',
		'name'              => 'Posted Template',
		'path_components'   => '%year%/%uploader%',
		'upload_width_mode' => 'limit',
		'upload_width'      => '1920',
		'upload_quality'    => '90',
		'full_width'        => '1600',
		'full_quality'      => '82',
		'thumbnail_width'   => '480',
		'thumbnail_quality' => '70',
	];

	try {
		$page->handle_create();
	} catch ( Admin_Page_Halt ) {
		$noop = true;
	}

	expect( Descriptor::read( $root . 'posted-template' )->path_components )->toBe( '%year%/%uploader%' );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'the edit form makes the re-derivable renditions editable and the upload contract read-only', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'shown', 'Shown', 1440, 65 );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'shown',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The display name and the four re-derivable rendition fields are editable inputs
	// (their re-derive flips via the browser-driven regenerate flow; ADR-0013), each
	// pre-filled with the stored value.
	expect( $html )->toContain( 'name="name"' );
	expect( $html )->toContain( 'name="full_width"' );
	expect( $html )->toContain( 'name="full_quality"' );
	expect( $html )->toContain( 'name="thumbnail_width"' );
	expect( $html )->toContain( 'name="thumbnail_quality"' );

	// The immutable upload contract stays read-only: it is shown as a disabled value
	// (1440 px) and never as an editable field name, so the page can never offer to
	// change the irreversible pair.
	expect( $html )->toContain( 'disabled' );
	expect( $html )->toContain( '1440' );
	expect( $html )->not->toContain( 'name="upload_width"' );
	expect( $html )->not->toContain( 'name="upload_quality"' );

	// The slug is shown read-only too — never as an editable text field — so a rename
	// can never ride this form.
	expect( $html )->not->toContain( 'name="slug" type="text"' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the edit form carries the regenerate progress region and the collection slug', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'regen', 'Regen', 1440, 65 );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'regen',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The browser-driven regenerate UI needs a host element the shared progress view
	// writes into, and the collection slug so the regenerate script knows which
	// collection's REST route to drive (ADR-0013).
	expect( $html )->toContain( 'data-kntnt-photo-drop-regenerate' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-collection="regen"' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// List view — always-visible Edit/Delete buttons in the rightmost column
// ---------------------------------------------------------------------------

test( 'the list shows always-visible Edit and Delete buttons instead of hover row actions', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'spring', 'Spring', 1920, 80 );

	// Notice replay touches the per-user transient key before the table renders.
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [ 'page' => Admin_Page::MENU_SLUG ];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The hover idiom is gone; both actions are persistent button-styled links
	// to the existing edit and delete views, and Delete still routes through
	// the confirmation step rather than removing anything directly.
	expect( $html )->not->toContain( 'row-actions' );
	expect( $html )->toContain( 'class="button"' );
	expect( $html )->toContain( 'action=edit&collection=spring' );
	expect( $html )->toContain( 'action=delete&collection=spring' );

	// The actions column is the rightmost one: its header closes the header row
	// and its cell closes the body row, and the table carries the spacing class
	// that separates it from the page header.
	expect( $html )->toContain( 'Actions</th></tr></thead>' );
	expect( $html )->toContain( '</a></td></tr>' );
	expect( $html )->toContain( '<table class="wp-list-table widefat fixed striped kntnt-photo-drop-collections">' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'a collection with an unreadable descriptor still lists by slug and keeps its Delete button', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );

	// A directory whose collection.json exists but cannot be parsed: discovery
	// lists it (the file is there), Descriptor::read() refuses it.
	mkdir( $root . 'broken', 0700, true );
	file_put_contents( $root . 'broken/' . Descriptor::FILENAME, 'not json' );

	// Notice replay touches the per-user transient key before the table renders.
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [ 'page' => Admin_Page::MENU_SLUG ];

	// The corrupt descriptor logs a warning; capture it away from the test
	// output and assert on the markup only.
	$log      = (string) tempnam( sys_get_temp_dir(), 'kntnt-log-' );
	$previous = ini_set( 'error_log', $log );
	try {
		ob_start();
		( new Admin_Page( new Repository() ) )->render_page();
		$html = (string) ob_get_clean();

		// The row falls back to the slug for its name, renders dashes for the
		// unreadable contract, and remains deletable through the Delete button.
		expect( $html )->toContain( '<strong>broken</strong>' );
		expect( $html )->toContain( '<code>broken</code>' );
		expect( $html )->toContain( '<td>—</td>' );
		expect( $html )->toContain( 'action=delete&collection=broken' );
	} finally {
		ini_set( 'error_log', (string) $previous );
		unlink( $log );
		$_GET = [];
		admin_remove_tree( $basedir );
	}
} );

test( 'the page assets — stylesheet and preview script — are added on this admin page only', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias( static fn ( string $hook, mixed $value ): mixed => $value );
	Functions\when( 'add_submenu_page' )->justReturn( 'media_page_kntnt-photo-drop' );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data ): string|false => json_encode( $data )
	);

	// Record every style and script call so the scoping and the preview wiring can
	// both be asserted.
	$styles  = [];
	$scripts = [];
	$inline  = [];
	Functions\when( 'wp_add_inline_style' )->alias(
		static function ( string $handle, string $css ) use ( &$styles ): bool {
			$styles[] = [ $handle, $css ];
			return true;
		}
	);
	Functions\when( 'wp_register_script' )->justReturn( true );
	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle ) use ( &$scripts ): void {
			$scripts[] = $handle;
		}
	);
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$inline ): bool {
			$inline[] = $data;
			return true;
		}
	);

	$page = new Admin_Page( new Repository() );
	$page->register_menu();

	// A foreign screen gets nothing — no style and no script.
	$page->enqueue_styles( 'edit.php' );
	expect( $styles )->toBe( [] );
	expect( $scripts )->toBe( [] );

	// The page's own hook gets the list-table CSS and the live-preview script, the
	// latter carrying its sample config and the placeholder-substitution logic.
	$page->enqueue_styles( 'media_page_kntnt-photo-drop' );
	expect( $styles )->toHaveCount( 1 );
	expect( $styles[0][0] )->toBe( 'common' );
	expect( $styles[0][1] )->toContain( 'margin-top' );
	expect( $styles[0][1] )->toContain( 'kntnt-photo-drop-actions' );
	expect( $scripts )->toHaveCount( 1 );
	expect( implode( "\n", $inline ) )->toContain( 'kntntPhotoDropPathPreview' );
	expect( implode( "\n", $inline ) )->toContain( 'data-kntnt-photo-drop-path-preview' );
	expect( implode( "\n", $inline ) )->toContain( Path_Template::SAMPLE_UPLOADER );
} );

// ---------------------------------------------------------------------------
// List view — an unreadable subdirectory must not white-screen the page
// ---------------------------------------------------------------------------

test( 'the list renders a dash for a collection whose subtree cannot be read', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );

	// Two collections: one healthy with a single main on disk, one holding a
	// subdirectory the walker cannot open (chmod 000 makes the recursive count
	// throw mid-iteration).
	seed_admin_collection( $root, 'open', 'Open', 1920, 80 );
	file_put_contents( $root . 'open/photo.jpg.webp', 'main' );
	seed_admin_collection( $root, 'locked', 'Locked', 1920, 80 );
	mkdir( $root . 'locked/sealed', 0700, true );
	chmod( $root . 'locked/sealed', 0000 );

	// List-view stubs the shared render wiring does not cover.
	Functions\when( 'wp_kses_post' )->returnArg( 1 );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [ 'page' => Admin_Page::MENU_SLUG ];

	// The page must render the whole list — the locked row with an unknown
	// count, the healthy row with its live count — instead of dying on the
	// unreadable directory, and the aborted walk must be logged. Permissions
	// are restored even when the assertions fail, so the temp tree can always
	// be removed.
	$log      = (string) tempnam( sys_get_temp_dir(), 'kntnt-log-' );
	$previous = ini_set( 'error_log', $log );
	try {
		ob_start();
		( new Admin_Page( new Repository() ) )->render_page();
		$html    = (string) ob_get_clean();
		$written = (string) file_get_contents( $log );

		expect( $html )->toContain( '<td>—</td>' );
		expect( $html )->toContain( '<td>1</td>' );
		expect( $written )->toContain( '[WARNING]' )->toContain( 'locked' );
	} finally {
		ini_set( 'error_log', (string) $previous );
		unlink( $log );
		chmod( $root . 'locked/sealed', 0700 );
		$_GET = [];
		admin_remove_tree( $basedir );
	}
} );

// ---------------------------------------------------------------------------
// Menu registration and the capability gate
// ---------------------------------------------------------------------------

test( 'register_menu adds the submenu page gated by manage_options by default', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias( static fn ( string $hook, mixed $value ): mixed => $value );

	// Capture the parent, capability, and slug add_submenu_page is registered with.
	// The arguments arrive positionally: parent, page title, menu title, cap, slug.
	$captured = [];
	Functions\when( 'add_submenu_page' )->alias(
		static function ( ...$args ) use ( &$captured ): string {
			$captured = [
				'parent' => $args[0],
				'cap'    => $args[3],
				'slug'   => $args[4],
			];
			return 'hook';
		}
	);

	( new Admin_Page( new Repository() ) )->register_menu();

	expect( $captured['cap'] )->toBe( 'manage_options' );
	expect( $captured['slug'] )->toBe( Admin_Page::MENU_SLUG );
	expect( $captured['parent'] )->toBe( 'upload.php' );
} );

test( 'the manage capability filter overrides the gate', function (): void {
	Functions\when( '__' )->returnArg( 1 );

	// The filter rewrites the manage capability to a bespoke one.
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed =>
			$hook === 'kntnt_photo_drop_manage_capability' ? 'edit_others_photos' : $value
	);

	$page = new Admin_Page( new Repository() );

	expect( $page->capability() )->toBe( 'edit_others_photos' );
} );

// ---------------------------------------------------------------------------
// create_collection — slug validation, required contract, "No limit", descriptor
// ---------------------------------------------------------------------------

/**
 * Builds the six raw rendition strings the create form submits.
 *
 * Lets a test pass only the fields it cares about; every omitted field takes a
 * sensible valid value so a partial override never trips an unrelated parse.
 *
 * @param array<string,string> $overrides Field-name → raw value overrides.
 * @return array<string,string> The full six-field rendition map.
 */
function admin_renditions( array $overrides = [] ): array {
	return [
		...[
			'upload-width'      => '1920',
			'upload-quality'    => '80',
			'full-width'        => '1920',
			'full-quality'      => '85',
			'thumbnail-width'   => '640',
			'thumbnail-quality' => '75',
		],
		...$overrides,
	];
}

test( 'create writes a valid three-rendition collection.json from the form fields', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$created = $page->create_collection( 'spring-2024', 'Spring 2024', admin_renditions( [
		'upload-width'      => '4000',
		'upload-quality'    => '92',
		'full-width'        => '1600',
		'full-quality'      => '82',
		'thumbnail-width'   => '480',
		'thumbnail-quality' => '70',
	] ) );

	// A descriptor is on disk carrying every rendition verbatim, format WebP
	// implied, and the default path-components template.
	expect( $created )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'spring-2024' );
	expect( $descriptor )->not->toBeNull();
	expect( $descriptor->name )->toBe( 'Spring 2024' );
	expect( $descriptor->upload_width )->toBe( 4000 );
	expect( $descriptor->upload_quality )->toBe( 92 );
	expect( $descriptor->full_width )->toBe( 1600 );
	expect( $descriptor->full_quality )->toBe( 82 );
	expect( $descriptor->thumbnail_width )->toBe( 480 );
	expect( $descriptor->thumbnail_quality )->toBe( 70 );
	expect( $descriptor->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	admin_remove_tree( $basedir );
} );

test( 'create defaults each blank rendition field from its filter', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// Every rendition field blank falls back to its documented default: upload
	// width null (the source's own dimensions), upload 95, full 1920/85, thumbnail
	// 640/75.
	$page->create_collection( 'defaulted', 'Defaulted', [
		'upload-width'      => '',
		'upload-quality'    => '',
		'full-width'        => '',
		'full-quality'      => '',
		'thumbnail-width'   => '',
		'thumbnail-quality' => '',
	] );

	$descriptor = Descriptor::read( $root . 'defaulted' );
	expect( $descriptor->upload_width )->toBeNull();
	expect( $descriptor->upload_quality )->toBe( 95 );
	expect( $descriptor->full_width )->toBe( 1920 );
	expect( $descriptor->full_quality )->toBe( 85 );
	expect( $descriptor->thumbnail_width )->toBe( 640 );
	expect( $descriptor->thumbnail_quality )->toBe( 75 );

	admin_remove_tree( $basedir );
} );

test( 'handle_create writes the descriptor from the six posted rendition fields', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// The handler needs the request guard, nonce, and redirect stubs; it reads the
	// six rendition fields from $_POST and the upload-width radio mode.
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'set_transient' )->justReturn( true );
	Functions\when( 'get_settings_errors' )->justReturn( [] );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url );
	Functions\when( 'wp_safe_redirect' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'              => 'posted',
		'name'              => 'Posted',
		'upload_width_mode' => 'limit',
		'upload_width'      => '3000',
		'upload_quality'    => '90',
		'full_width'        => '1600',
		'full_quality'      => '82',
		'thumbnail_width'   => '480',
		'thumbnail_quality' => '70',
	];

	try {
		$page->handle_create();
	} catch ( Admin_Page_Halt ) {
		// The redirect-then-exit path is the expected end of the handler.
		$noop = true;
	}

	// The posted rendition fields are written verbatim to the descriptor.
	$descriptor = Descriptor::read( $root . 'posted' );
	expect( $descriptor->upload_width )->toBe( 3000 );
	expect( $descriptor->upload_quality )->toBe( 90 );
	expect( $descriptor->full_width )->toBe( 1600 );
	expect( $descriptor->thumbnail_width )->toBe( 480 );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'handle_create maps the "Original dimensions" upload-width radio to null', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'set_transient' )->justReturn( true );
	Functions\when( 'get_settings_errors' )->justReturn( [] );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url );
	Functions\when( 'wp_safe_redirect' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'              => 'sourced',
		'name'              => 'Sourced',
		'upload_width_mode' => 'none',
		'upload_width'      => '1234',
		'upload_quality'    => '95',
		'full_width'        => '1920',
		'full_quality'      => '85',
		'thumbnail_width'   => '640',
		'thumbnail_quality' => '75',
	];

	try {
		$page->handle_create();
	} catch ( Admin_Page_Halt ) {
		$noop = true;
	}

	// With the radio on "Original dimensions" the typed pixel value is ignored and
	// the upload width is stored as null.
	expect( Descriptor::read( $root . 'sourced' )->upload_width )->toBeNull();

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'create defaults the display name to a humanised slug when left blank', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'autumn-walk', '', admin_renditions() );

	expect( Descriptor::read( $root . 'autumn-walk' )->name )->toBe( 'Autumn Walk' );

	admin_remove_tree( $basedir );
} );

test( 'create maps the "Original dimensions" upload width to a null ceiling', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'archive', 'Full Archive', admin_renditions( [ 'upload-width' => 'none' ] ) );

	expect( Descriptor::read( $root . 'archive' )->upload_width )->toBeNull();

	admin_remove_tree( $basedir );
} );

test( 'create rejects an invalid slug and writes nothing', function ( string $hostile ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	$created = $page->create_collection( $hostile, 'X', admin_renditions() );

	expect( $created )->toBeFalse();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	admin_remove_tree( $basedir );
} )->with( [
	'traversal'      => [ '../escape' ],
	'separator'      => [ 'a/b' ],
	'uppercase'      => [ 'Spring' ],
	'leading hyphen' => [ '-bad' ],
	'empty'          => [ '' ],
] );

test( 'create rejects a malformed rendition value', function ( array $overrides ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	$created = $page->create_collection( 'incomplete', 'X', admin_renditions( $overrides ) );

	// A malformed rendition value halts before any directory is made.
	expect( $created )->toBeFalse();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	admin_remove_tree( $basedir );
} )->with( [
	'zero upload width'    => [ [ 'upload-width' => '0' ] ],
	'non-numeric upload'   => [ [ 'upload-width' => 'wide' ] ],
	'upload quality 101'   => [ [ 'upload-quality' => '101' ] ],
	'zero full width'      => [ [ 'full-width' => '0' ] ],
	'negative thumb width' => [ [ 'thumbnail-width' => '-5' ] ],
	'thumb quality over'   => [ [ 'thumbnail-quality' => '200' ] ],
] );

test( 'create refuses a duplicate slug and leaves the first descriptor untouched', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'dupe', 'First', admin_renditions() );
	$first = file_get_contents( $root . 'dupe/' . Descriptor::FILENAME );

	$created = $page->create_collection( 'dupe', 'Second', admin_renditions( [ 'upload-width' => '800' ] ) );

	expect( $created )->toBeFalse();
	expect( file_get_contents( $root . 'dupe/' . Descriptor::FILENAME ) )->toBe( $first );

	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// update_collection — name-only mutation; tampered contract rejected
// ---------------------------------------------------------------------------

test( 'update rewrites only the display name and preserves the contract', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'trip', 'Trip', 1280, 70 );
	$page = new Admin_Page( new Repository() );

	$updated = $page->update_collection( 'trip', 'Field Trip 2024', false );

	// Only the name changed; the upload contract, the derived renditions, and the
	// placement template carry over untouched (a null template means carry over).
	expect( $updated )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'trip' );
	expect( $descriptor->name )->toBe( 'Field Trip 2024' );
	expect( $descriptor->upload_width )->toBe( 1280 );
	expect( $descriptor->upload_quality )->toBe( 70 );
	expect( $descriptor->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	admin_remove_tree( $basedir );
} );

test( 'update mutates the path-components template, normalised', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'placed', 'Placed', 1280, 70 );
	$page = new Admin_Page( new Repository() );

	// The template is mutable (ADR-0014); submitting a new one rewrites it,
	// normalised, while leaving the immutable upload contract untouched.
	$updated = $page->update_collection( 'placed', 'Placed', false, '/events/%year%/' );

	expect( $updated )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'placed' );
	expect( $descriptor->path_components )->toBe( 'events/%year%' );
	expect( $descriptor->upload_width )->toBe( 1280 );

	admin_remove_tree( $basedir );
} );

test( 'update rejects an invalid path-components template and writes nothing', function ( string $template ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'guarded', 'Guarded', 1024, 60 );
	$before = file_get_contents( $root . 'guarded/' . Descriptor::FILENAME );
	$page   = new Admin_Page( new Repository() );

	// A stray `%` or an unsafe template is rejected at save, leaving the descriptor
	// byte-identical (ADR-0014).
	$updated = $page->update_collection( 'guarded', 'Guarded', false, $template );

	expect( $updated )->toBeFalse();
	expect( file_get_contents( $root . 'guarded/' . Descriptor::FILENAME ) )->toBe( $before );

	admin_remove_tree( $basedir );
} )->with( [
	'stray percent' => [ '%year%/%moth%' ],
	'traversal'     => [ '%year%/../../x' ],
] );

test( 'the edit form renders an editable path-components field and a live preview', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'editable', 'Editable', 1440, 65 );
	$page = new Admin_Page( new Repository() );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'editable',
	];

	ob_start();
	$page->render_page();
	$html = (string) ob_get_clean();

	$_GET = [];

	// Path components is editable on the Edit form (it affects only future uploads),
	// pre-filled with the stored template, and accompanied by the live preview; the
	// upload-contract fields remain disabled (ADR-0014).
	expect( $html )->toContain( 'name="path_components"' );
	expect( $html )->toContain( 'value="' . Descriptor::DEFAULT_PATH_COMPONENTS . '"' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-path-preview' );

	admin_remove_tree( $basedir );
} );

test( 'handle_update reads and applies the path-components field', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'posted-edit', 'Posted Edit', 1920, 80 );

	// The handler needs the guard, nonce, and redirect stubs; it reads the
	// path-components field from $_POST alongside the display name.
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'set_transient' )->justReturn( true );
	Functions\when( 'get_settings_errors' )->justReturn( [] );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url );
	Functions\when( 'wp_safe_redirect' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'            => 'posted-edit',
		'name'            => 'Posted Edit',
		'path_components' => '%year%/%uploader%',
	];

	try {
		$page->handle_update();
	} catch ( Admin_Page_Halt ) {
		$noop = true;
	}

	expect( Descriptor::read( $root . 'posted-edit' )->path_components )->toBe( '%year%/%uploader%' );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'update rejects a tampered contract change server-side and writes nothing', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'frozen', 'Frozen', 1024, 60 );
	$before = file_get_contents( $root . 'frozen/' . Descriptor::FILENAME );
	$page   = new Admin_Page( new Repository() );

	// The request carried a contract field (the form renders those disabled, so
	// their presence signals tampering); the update is refused before any write.
	$updated = $page->update_collection( 'frozen', 'New Name', true );

	expect( $updated )->toBeFalse();
	expect( file_get_contents( $root . 'frozen/' . Descriptor::FILENAME ) )->toBe( $before );

	admin_remove_tree( $basedir );
} );

test( 'update rejects an empty display name', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'named', 'Named', 1920, 80 );
	$page = new Admin_Page( new Repository() );

	expect( $page->update_collection( 'named', '', false ) )->toBeFalse();
	expect( Descriptor::read( $root . 'named' )->name )->toBe( 'Named' );

	admin_remove_tree( $basedir );
} );

test( 'update refuses an unknown slug', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	expect( $page->update_collection( 'ghost', 'Whatever', false ) )->toBeFalse();

	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// delete_collection — removes the directory after the resolve check
// ---------------------------------------------------------------------------

test( 'delete removes the whole collection directory', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'gone', 'Gone', 1920, 80 );
	mkdir( $root . 'gone/2024/.kntnt-thumbnails/640', 0700, true );
	file_put_contents( $root . 'gone/2024/photo.jpg.webp', 'main' );
	$page = new Admin_Page( new Repository() );

	$deleted = $page->delete_collection( 'gone' );

	expect( $deleted )->toBeTrue();
	expect( is_dir( $root . 'gone' ) )->toBeFalse();

	admin_remove_tree( $basedir );
} );

test( 'delete refuses an unknown slug', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	expect( $page->delete_collection( 'ghost' ) )->toBeFalse();

	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Capability gate — an un-capable user is refused before any effect
// ---------------------------------------------------------------------------

test( 'an un-capable user is refused before any collection is created', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();

	// The user lacks the manage capability; wp_die() halts the handler, and the
	// $_POST payload that would otherwise create a collection is never reached.
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'wp_die' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page                 = new Admin_Page( new Repository() );
	$_POST                = [
		'slug'              => 'sneaky',
		'name'              => 'Sneaky',
		'upload_width_mode' => 'limit',
		'upload_width'      => '1920',
		'upload_quality'    => '80',
	];
	$_REQUEST['_wpnonce'] = 'x';

	$threw = false;
	try {
		$page->handle_create();
	} catch ( Admin_Page_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	$_POST    = [];
	$_REQUEST = [];
	admin_remove_tree( $basedir );
} );
