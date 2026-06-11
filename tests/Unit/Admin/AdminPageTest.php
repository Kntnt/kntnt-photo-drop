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
	// default-contract pre-fills therefore use their built-in defaults, and the
	// thumbnail width defaults to a single 640 inside Descriptor::from_filter().
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
 * @param string   $root      The trailing-slashed collection root.
 * @param string   $slug      The collection slug.
 * @param string   $name      The display name.
 * @param int|null $max_width The contract ceiling, or null for no limit.
 * @param int      $quality   The WebP quality.
 */
function seed_admin_collection( string $root, string $slug, string $name, ?int $max_width, int $quality ): void {
	$path = ( new Repository() )->create_collection( $slug );
	Descriptor::from_filter( $name, $max_width, $quality )->write( (string) $path );
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
	// are no-ops, so the markup under test is only what the page itself echoes.
	Functions\when( 'wp_nonce_field' )->justReturn( '' );
	Functions\when( 'submit_button' )->justReturn( '' );
	Functions\when( 'settings_errors' )->justReturn( null );
	Functions\when( 'get_transient' )->justReturn( false );

	return $root;

}

// ---------------------------------------------------------------------------
// Create form — pre-fills from the default filters; no format/thumbnail field
// ---------------------------------------------------------------------------

test( 'the create form pre-fills width and quality from the default filters', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	// The default filters return their built-in defaults (1920, 80) through the
	// pass-through apply_filters stub.
	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// Both contract inputs carry the default values, and the irreversibility
	// warning is present.
	expect( $html )->toContain( 'name="max_width"' );
	expect( $html )->toContain( 'value="1920"' );
	expect( $html )->toContain( 'name="quality"' );
	expect( $html )->toContain( 'value="80"' );
	expect( $html )->toContain( 'notice-warning' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form offers an uploader-folders checkbox checked by default', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The placement choice is a checkbox that opens ticked, so a create that
	// leaves it alone namespaces per uploader (ADR-0008).
	expect( $html )->toContain( 'name="uploader_folders"' );
	expect( $html )->toContain( 'type="checkbox"' );
	expect( $html )->toContain( 'checked' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form has no format field and no thumbnail-width field', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The contract never exposes a format choice (always WebP) or a thumbnail-width
	// field (filter-driven), so neither input name appears in the create form.
	expect( $html )->not->toContain( 'name="format"' );
	expect( $html )->not->toContain( 'name="thumbnail' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the edit form shows the contract disabled and submits only the name', function (): void {
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

	// The display name is editable; the contract values are rendered as disabled
	// inputs, and the contract has no editable max_width/quality field name.
	expect( $html )->toContain( 'name="name"' );
	expect( $html )->toContain( 'disabled' );
	expect( $html )->toContain( '1440' );
	expect( $html )->not->toContain( 'name="max_width"' );
	expect( $html )->not->toContain( 'name="quality"' );

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

test( 'the page stylesheet is added on this admin page only', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias( static fn ( string $hook, mixed $value ): mixed => $value );
	Functions\when( 'add_submenu_page' )->justReturn( 'media_page_kntnt-photo-drop' );

	// Record every wp_add_inline_style call so the scoping can be asserted.
	$captured = [];
	Functions\when( 'wp_add_inline_style' )->alias(
		static function ( string $handle, string $css ) use ( &$captured ): bool {
			$captured[] = [ $handle, $css ];
			return true;
		}
	);

	$page = new Admin_Page( new Repository() );
	$page->register_menu();

	// A foreign screen gets nothing; the page's own hook suffix gets the rules
	// for the header gap and the right-aligned actions column.
	$page->enqueue_styles( 'edit.php' );
	expect( $captured )->toBe( [] );

	$page->enqueue_styles( 'media_page_kntnt-photo-drop' );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0][0] )->toBe( 'common' );
	expect( $captured[0][1] )->toContain( 'margin-top' );
	expect( $captured[0][1] )->toContain( 'kntnt-photo-drop-actions' );
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

test( 'create writes a valid collection.json from the form fields', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$created = $page->create_collection( 'spring-2024', 'Spring 2024', '1920', '80' );

	// A descriptor is on disk carrying the contract verbatim, format WebP implied,
	// and the filter-derived thumbnail width.
	expect( $created )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'spring-2024' );
	expect( $descriptor )->not->toBeNull();
	expect( $descriptor->name )->toBe( 'Spring 2024' );
	expect( $descriptor->max_width )->toBe( 1920 );
	expect( $descriptor->quality )->toBe( 80 );
	expect( $descriptor->thumbnail_widths )->toBe( [ 640 ] );

	admin_remove_tree( $basedir );
} );

test( 'create defaults the uploader-folders placement rule to on', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// The handler passes the checkbox-present boolean; create_collection's default
	// keeps a caller that omits it namespacing per uploader (ADR-0008).
	$page->create_collection( 'on-by-default', 'On', '1920', '80' );

	expect( Descriptor::read( $root . 'on-by-default' )->uploader_folders )->toBeTrue();

	admin_remove_tree( $basedir );
} );

test( 'create persists the chosen uploader-folders placement rule', function ( bool $choice ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// An unchecked box reaches create_collection as false (no $_POST key), a
	// checked one as true; both must be written verbatim to the descriptor.
	$page->create_collection( 'chosen', 'Chosen', '1920', '80', $choice );

	expect( Descriptor::read( $root . 'chosen' )->uploader_folders )->toBe( $choice );

	admin_remove_tree( $basedir );
} )->with( [
	'checked'   => [ true ],
	'unchecked' => [ false ],
] );

test( 'handle_create persists uploader-folders off when the box is unchecked', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// The handler needs the request guard, nonce, and redirect stubs; an
	// unchecked checkbox submits no uploader_folders key, so its absence is "off".
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
	Functions\when( 'wp_safe_redirect' )->justReturn( true );

	// wp_safe_redirect is followed by exit in the handler; stub exit by throwing.
	Functions\when( 'wp_safe_redirect' )->alias(
		static function (): void {
			throw new Admin_Page_Halt();
		}
	);

	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'           => 'bare',
		'name'           => 'Bare',
		'max_width_mode' => 'limit',
		'max_width'      => '1920',
		'quality'        => '80',
	];

	try {
		$page->handle_create();
	} catch ( Admin_Page_Halt ) {
		// The redirect-then-exit path is the expected end of the handler.
		$noop = true;
	}

	// With no uploader_folders key in $_POST the placement rule is written off.
	expect( Descriptor::read( $root . 'bare' )->uploader_folders )->toBeFalse();

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'create defaults the display name to a humanised slug when left blank', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'autumn-walk', '', '1600', '75' );

	expect( Descriptor::read( $root . 'autumn-walk' )->name )->toBe( 'Autumn Walk' );

	admin_remove_tree( $basedir );
} );

test( 'create maps the "No limit" choice to a null ceiling', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'archive', 'Full Archive', 'none', '90' );

	expect( Descriptor::read( $root . 'archive' )->max_width )->toBeNull();

	admin_remove_tree( $basedir );
} );

test( 'create rejects an invalid slug and writes nothing', function ( string $hostile ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	$created = $page->create_collection( $hostile, 'X', '1920', '80' );

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

test( 'create rejects a malformed contract value', function ( string $width, string $quality ): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	( new Repository() )->get_root();
	$page = new Admin_Page( new Repository() );

	$created = $page->create_collection( 'incomplete', 'X', $width, $quality );

	// A malformed contract value halts before any directory is made.
	expect( $created )->toBeFalse();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	admin_remove_tree( $basedir );
} )->with( [
	'empty width'       => [ '', '80' ],
	'zero width'        => [ '0', '80' ],
	'non-numeric width' => [ 'wide', '80' ],
	'empty quality'     => [ '1920', '' ],
	'quality over 100'  => [ '1920', '101' ],
] );

test( 'create refuses a duplicate slug and leaves the first descriptor untouched', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	$page->create_collection( 'dupe', 'First', '1920', '80' );
	$first = file_get_contents( $root . 'dupe/' . Descriptor::FILENAME );

	$created = $page->create_collection( 'dupe', 'Second', '800', '50' );

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

	// Only the name changed; max-width, quality and thumbnail widths carry over.
	expect( $updated )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'trip' );
	expect( $descriptor->name )->toBe( 'Field Trip 2024' );
	expect( $descriptor->max_width )->toBe( 1280 );
	expect( $descriptor->quality )->toBe( 70 );

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
		'slug'           => 'sneaky',
		'name'           => 'Sneaky',
		'max_width_mode' => 'limit',
		'max_width'      => '1920',
		'quality'        => '80',
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
