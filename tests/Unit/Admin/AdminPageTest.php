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

	// wp_strip_all_tags: the path-components read uses it instead of
	// sanitize_text_field so the `%`-placeholders are not stripped. A faithful-enough
	// stub removes tag markup while leaving every percent placeholder intact.
	Functions\when( 'wp_strip_all_tags' )->alias(
		static fn ( string $str ): string => trim( (string) preg_replace( '/<[^>]*>/', '', $str ) )
	);

	// sanitize_title: a faithful-enough ASCII slugifier for the unique-slug default
	// — lowercase, runs of whitespace/underscores to a single hyphen, strip
	// everything but [a-z0-9-], collapse repeated hyphens, and trim edge hyphens.
	// Real WordPress does more (accent folding, entity decoding); the tests exercise
	// ASCII display names where the two agree.
	Functions\when( 'sanitize_title' )->alias(
		static function ( string $title ): string {
			$slug = strtolower( $title );
			$slug = (string) preg_replace( '/[\s_]+/', '-', $slug );
			$slug = (string) preg_replace( '/[^a-z0-9-]+/', '', $slug );
			$slug = (string) preg_replace( '/-+/', '-', $slug );
			return trim( $slug, '-' );
		}
	);

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
 * Replaces the no-op `sanitize_text_field` stub with WordPress's real octet
 * stripping, so a test sees what the live admin POST actually does to a field.
 *
 * The default `wire_admin_stubs` aliases `sanitize_text_field` to a plain
 * `trim`, which hides the very behaviour issue #64 is about: real
 * `sanitize_text_field` strips percent-encoded octets (`/%[a-f0-9]{2}/i`), so
 * `%day%` is mangled into `y%`. This faithful stub reproduces that core — UTF-8
 * collapse of whitespace, then the octet-stripping loop with its trailing
 * single-space collapse — so the path-components read path is exercised against
 * the real corruption rather than a no-op that can never fail.
 */
function wire_real_sanitize_text_field(): void {
	Functions\when( 'sanitize_text_field' )->alias(
		static function ( string $str ): string {

			// Collapse runs of whitespace and trim, mirroring the non-octet half
			// of WordPress's `_sanitize_text_fields()`.
			$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $str ) );

			// Strip every percent-encoded octet to a fixed point — the exact step
			// that mangles `%day%` — collapsing double spaces only when one was
			// removed, as core does.
			$found = false;
			while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
				$filtered = str_replace( $match[0], '', $filtered );
				$found    = true;
			}
			if ( $found ) {
				$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
			}

			return $filtered;
		}
	);
}

/**
 * Wires the request-guard, nonce, and post/redirect/get stubs every admin POST
 * handler needs, halting the redirect so the handler's on-disk effect can be
 * asserted without leaving the test process.
 *
 * Shared by the `handle_create` / `handle_update` POST tests so each one states
 * only its own payload and assertion.
 */
function wire_admin_post_handler_stubs(): void {
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
	// field is a single number input (no mode radio) left blank for the null
	// "original dimensions" default, and the irreversibility warning sits above
	// the upload pair.
	expect( $html )->toContain( 'name="upload_width"' );
	expect( $html )->not->toContain( 'name="upload_width_mode"' );
	expect( $html )->not->toContain( 'Original dimensions' );
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

test( 'the create form renders the upload width as a single blank-able number field', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The upload width is one number input with no mode radio: a blank field means
	// the original dimensions, so the input has no enforced minimum that would block
	// an empty submit and the help text documents the blank-is-original rule (#70).
	expect( $html )->toContain( 'name="upload_width"' );
	expect( $html )->not->toContain( 'name="upload_width_mode"' );
	expect( $html )->not->toContain( 'type="radio"' );
	expect( $html )->toContain( 'Leave blank to keep the original dimensions.' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form upload-quality help recommends 95 and warns about 100', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The upload-quality help text recommends 95 and warns that 100 spends roughly
	// 30 % more bytes for no visible benefit, and that a blank field means maximum
	// quality (#70, ADR-0002).
	expect( $html )->toContain( '95 is recommended' );
	expect( $html )->toContain( '30' );
	expect( $html )->toContain( 'Leave blank for maximum quality (100).' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form upload-quality input enforces a minimum of 1', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The upload quality is part of the immutable contract and 0 is a forbidden,
	// permanent value, so the input's HTML floor matches the help text and the
	// server-side floor: min="1", not the generic 0 (#70, AC "Range 1–100").
	expect( $html )->toMatch( '/<input[^>]*name="upload_quality"[^>]*min="1"[^>]*>/' );
	expect( $html )->not->toMatch( '/<input[^>]*name="upload_quality"[^>]*min="0"[^>]*>/' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// One uniform rendition section — the contract/renditions split is dropped (#67)
// ---------------------------------------------------------------------------

test( 'the create form presents the tiers as one uniform section, not a contract/renditions split', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_render_stubs( $basedir );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The two separately-labelled section headings are gone: once every tier is a
	// width/quality pair, splitting the upload pair from the full/thumbnail pairs
	// into "Upload contract" vs "Renditions" blocks is just visual noise (#67).
	expect( $html )->not->toContain( 'Upload contract (immutable)' );
	expect( $html )->not->toContain( 'Renditions (re-derivable)' );

	// The three tiers sit under one uniform heading, rendered exactly once so the
	// six fields read as a single set rather than two labelled groups.
	expect( substr_count( $html, '<h2>Image settings</h2>' ) )->toBe( 1 );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the edit form presents the tiers as one uniform section, not a contract/renditions split', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'uniform', 'Uniform', 1440, 65 );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'uniform',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The same uniform presentation on Edit: no split headings, one shared heading.
	// Uniform layout is not uniform behaviour — the read-only upload pair and the
	// editable, regenerate-driven full/thumbnail pairs still differ (#67, ADR-0013);
	// only the two separately-labelled section blocks are merged into one.
	expect( $html )->not->toContain( 'Upload contract (immutable)' );
	expect( $html )->not->toContain( 'Renditions (re-derivable)' );
	expect( substr_count( $html, '<h2>Image settings</h2>' ) )->toBe( 1 );

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

test( 'the edit form renders the Display name field above the read-only Slug', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	seed_admin_collection( $root, 'ordered', 'Ordered', 1440, 65 );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'ordered',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	$_GET = [];

	// The two forms must agree on field order (Display name → Slug; docs/blocks.md):
	// the editable Display name label must precede the read-only slug cell, so the
	// editable identity-mirroring field is encountered before its permanent identity.
	$name_position = strpos( $html, 'for="kntnt-photo-drop-name"' );
	$slug_position = strpos( $html, '<code>ordered</code>' );
	expect( $name_position )->not->toBeFalse();
	expect( $slug_position )->not->toBeFalse();
	expect( $name_position )->toBeLessThan( $slug_position );

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

test( 'the edit form renders an unset re-derivable field as an empty input', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );

	// Seed a collection whose Full width and Full quality are unset (the collapse-to-
	// parent state, #71); the edit form must render those inputs empty so the operator
	// sees and can preserve "unset" rather than a fabricated concrete value.
	$path       = (string) ( new Repository() )->create_collection( 'collapsed' );
	$descriptor = new Descriptor( 'Collapsed', 1920, 90, null, null, 320, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$descriptor->write( $path );

	$_GET = [
		'page'       => Admin_Page::MENU_SLUG,
		'action'     => 'edit',
		'collection' => 'collapsed',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The unset Full width and Full quality render with an empty value attribute, while
	// the set Thumbnail width carries its concrete 320 — pinning that an unset field is
	// shown empty (collapse-to-parent) and a set one keeps its value. The regex ties each
	// field's id to its own value, so an empty value is asserted for that specific input.
	expect( $html )->toMatch( '/id="kntnt-photo-drop-full-width"[^>]*value=""/' );
	expect( $html )->toMatch( '/id="kntnt-photo-drop-full-quality"[^>]*value=""/' );
	expect( $html )->toMatch( '/id="kntnt-photo-drop-thumbnail-width"[^>]*value="320"/' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the list shows Auto for a collection whose full width is unset', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );

	// A collection with an unset Full width and Full quality must list as "Auto" rather
	// than a misleading "0 px" or a stale concrete value (#71); the always-concrete
	// thumbnail still shows its pixel width.
	$path       = (string) ( new Repository() )->create_collection( 'auto-full' );
	$descriptor = new Descriptor( 'Auto Full', 1920, 90, null, null, 320, 75, Descriptor::DEFAULT_PATH_COMPONENTS );
	$descriptor->write( $path );

	// Notice replay touches the per-user transient key before the table renders.
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [ 'page' => Admin_Page::MENU_SLUG ];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'Auto, quality auto' );
	expect( $html )->toContain( '320 px' );

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

test( 'the tier-width clamp script is enqueued on the create and edit views but not the list', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias( static fn ( string $hook, mixed $value ): mixed => $value );
	Functions\when( 'add_submenu_page' )->justReturn( 'media_page_kntnt-photo-drop' );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data ): string|false => json_encode( $data )
	);
	Functions\when( 'wp_add_inline_style' )->justReturn( true );
	Functions\when( 'wp_register_script' )->justReturn( true );
	Functions\when( 'wp_add_inline_script' )->justReturn( true );
	Functions\when( 'esc_url_raw' )->returnArg( 1 );
	Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/' );
	Functions\when( 'wp_create_nonce' )->justReturn( 'regen-nonce' );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( string $str ): string => trim( $str ) );

	// Point the built-asset enqueuer at the real worktree, so build/admin/<name>.asset.php
	// resolves and plugins_url yields a recognisable URL; the built width-clamp asset
	// must exist (npm run build) for the enqueue to fire.
	$plugin_root = dirname( __DIR__, 3 ) . '/';
	Functions\when( 'plugin_dir_path' )->justReturn( $plugin_root );
	Functions\when( 'plugins_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/' . $path
	);

	// Record every enqueued script handle so the per-view routing can be asserted.
	$scripts = [];
	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle ) use ( &$scripts ): void {
			$scripts[] = $handle;
		}
	);

	$page = new Admin_Page( new Repository() );
	$page->register_menu();

	// The Create view enqueues the slug default and the tier-width clamp on top of the
	// always-present inline preview handle.
	$_GET = [ 'action' => 'create' ];
	$scripts = [];
	$page->enqueue_styles( 'media_page_kntnt-photo-drop' );
	expect( $scripts )->toContain( 'kntnt-photo-drop-width-clamp' );

	// The Edit view enqueues the regenerate UI and the tier-width clamp.
	$_GET = [ 'action' => 'edit' ];
	$scripts = [];
	$page->enqueue_styles( 'media_page_kntnt-photo-drop' );
	expect( $scripts )->toContain( 'kntnt-photo-drop-width-clamp' );

	// The list view has no editable width fields, so the clamp script is not enqueued.
	$_GET = [];
	$scripts = [];
	$page->enqueue_styles( 'media_page_kntnt-photo-drop' );
	expect( $scripts )->not->toContain( 'kntnt-photo-drop-width-clamp' );
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

test( 'create maps a blank upload pair to original size and max quality and unsets the rest', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// A blank upload width means the source's own dimensions (null, "no max") and a
	// blank upload quality means maximum (100) — full fidelity without an as-is mode
	// (#70, ADR-0002). A blank thumbnail quality still falls back to its filter default
	// (75), but a blank Full width, Full quality, or Thumbnail width means "unset" —
	// collapse-to-parent — not the filter default (#71).
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
	expect( $descriptor->upload_quality )->toBe( 100 );
	expect( $descriptor->full_width )->toBeNull();
	expect( $descriptor->full_quality )->toBeNull();
	expect( $descriptor->thumbnail_width )->toBeNull();
	expect( $descriptor->thumbnail_quality )->toBe( 75 );

	admin_remove_tree( $basedir );
} );

test( 'create may save a collection with an empty full width that produces no separate full', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// A blank Full width is saved as unset; the descriptor's effective renditions then
	// collapse so the main serves the full role and no separate full is ever produced
	// (#71, ADR-0013). The thumbnail still derives from the main at the set width.
	$created = $page->create_collection( 'no-full', 'No Full', admin_renditions( [ 'full-width' => '' ] ) );

	expect( $created )->toBeTrue();
	$descriptor = Descriptor::read( $root . 'no-full' );
	expect( $descriptor->full_width )->toBeNull();
	$effective = $descriptor->effective_renditions();
	expect( $effective['full_width'] )->toBe( PHP_INT_MAX );

	admin_remove_tree( $basedir );
} );

test( 'handle_create writes the descriptor from the six posted rendition fields', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// The handler needs the request guard, nonce, and redirect stubs; it reads the
	// six rendition fields from $_POST, the upload width now a single number field.
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

test( 'handle_create maps a blank upload pair to original dimensions and maximum quality', function (): void {
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
		'upload_width'      => '',
		'upload_quality'    => '',
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

	// A blank upload width stores null (the source's own dimensions) and a blank
	// upload quality stores the maximum 100 — full fidelity with no as-is mode (#70).
	$descriptor = Descriptor::read( $root . 'sourced' );
	expect( $descriptor->upload_width )->toBeNull();
	expect( $descriptor->upload_quality )->toBe( 100 );

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

test( 'create maps a blank upload width to a null ceiling', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	$page    = new Admin_Page( new Repository() );

	// The simplified form has no mode radio and cannot submit any keyword: a blank
	// upload-width field is the one way to say "original dimensions", stored as a
	// null ceiling (#70). Guarding the blank path directly — not the CLI's "none"
	// keyword, which the admin form can never produce — keeps this test honest.
	$page->create_collection( 'archive', 'Full Archive', admin_renditions( [ 'upload-width' => '' ] ) );

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
	'literal none upload'  => [ [ 'upload-width' => 'none' ] ],
	'upload quality 101'   => [ [ 'upload-quality' => '101' ] ],
	'upload quality 0'     => [ [ 'upload-quality' => '0' ] ],
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
// Slug auto-default — unique sanitize_title default with -2/-3 suffixing (#50)
// ---------------------------------------------------------------------------

test( 'unique_slug_default sanitises the display name into a slug', function (): void {
	$basedir = fresh_admin_basedir();
	wire_admin_stubs( $basedir );
	$page = new Admin_Page( new Repository() );

	// With no existing slugs the default is just the sanitised name: lowercased,
	// spaces to hyphens, punctuation dropped (ADR — blocks.md "Create").
	expect( $page->unique_slug_default( 'Spring 2024 Trip!', [] ) )->toBe( 'spring-2024-trip' );

	admin_remove_tree( $basedir );
} );

test( 'unique_slug_default suffixes from -2 against existing', function ( array $existing, string $expected ): void {
	$basedir = fresh_admin_basedir();
	wire_admin_stubs( $basedir );
	$page = new Admin_Page( new Repository() );

	// The base collides, so the default takes the lowest free numeric suffix
	// starting at -2 (never -1), skipping every taken suffix in turn.
	expect( $page->unique_slug_default( 'Spring', $existing ) )->toBe( $expected );

	admin_remove_tree( $basedir );
} )->with( [
	'base taken'               => [ [ 'spring' ], 'spring-2' ],
	'base and -2 taken'        => [ [ 'spring', 'spring-2' ], 'spring-3' ],
	'gap below a taken suffix' => [ [ 'spring', 'spring-3' ], 'spring-2' ],
	'unrelated slugs ignored'  => [ [ 'summer', 'autumn' ], 'spring' ],
] );

test( 'handle_create with a blank slug establishes the collection at the auto-suffixed default', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// A collection already owns the base slug, so a blank slug field must auto-suffix
	// to "field-trip-2" rather than error (only a *typed* collision errors).
	seed_admin_collection( $root, 'field-trip', 'Field Trip', 1920, 80 );

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
		'slug'              => '',
		'name'              => 'Field Trip',
		'upload_width'      => '1920',
		'upload_quality'    => '80',
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

	// The blank slug resolved to the unique default and a real collection now sits at
	// it, while the original is left untouched.
	expect( Descriptor::read( $root . 'field-trip-2' ) )->not->toBeNull();
	expect( Descriptor::read( $root . 'field-trip-2' )->name )->toBe( 'Field Trip' );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test( 'handle_create rejects a typed colliding slug instead of auto-suffixing it', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );

	// A collection already owns the slug; a *typed* collision is a validation error,
	// so no "-2" is invented and the original descriptor is left byte-identical.
	seed_admin_collection( $root, 'field-trip', 'Field Trip', 1920, 80 );
	$before = file_get_contents( $root . 'field-trip/' . Descriptor::FILENAME );

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
		'slug'              => 'field-trip',
		'name'              => 'Field Trip',
		'upload_width'      => '1920',
		'upload_quality'    => '80',
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

	// No "-2" collection was invented and the original is unchanged.
	expect( is_dir( $root . 'field-trip-2' ) )->toBeFalse();
	expect( file_get_contents( $root . 'field-trip/' . Descriptor::FILENAME ) )->toBe( $before );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Create form — slug optional with a live default placeholder + ⚠️ markers (#50)
// ---------------------------------------------------------------------------

test( 'the create form makes the slug optional and exposes the existing slugs', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );

	// An existing collection so the rendered existing-slugs list the JS reads is
	// non-empty, and the on-blur compute can suffix against it.
	seed_admin_collection( $root, 'spring', 'Spring', 1920, 80 );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// The slug input is present but no longer carries a `required` attribute, so a
	// blank slug submits; the name + slug fields carry the data hooks the on-blur
	// script reads, with the existing slugs rendered so the client can compute a
	// unique default without a round-trip.
	expect( $html )->toContain( 'name="slug"' );
	expect( $html )->not->toContain( ' required' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-slug-input' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-name-input' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-existing-slugs' );
	expect( $html )->toContain( 'spring' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

test( 'the create form marks the permanent fields and states the set-once rule by the Save button', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_render_stubs( $basedir );
	Functions\when( 'get_current_user_id' )->justReturn( 1 );
	Functions\when( 'delete_transient' )->justReturn( true );

	$_GET = [
		'page'   => Admin_Page::MENU_SLUG,
		'action' => 'create',
	];

	ob_start();
	( new Admin_Page( new Repository() ) )->render_page();
	$html = (string) ob_get_clean();

	// Each permanent field carries a ⚠️ marker with an accessible label and a
	// per-field tooltip giving the reason, and the markers hang off a shared class so
	// they are styleable and discoverable in the markup.
	expect( substr_count( $html, 'kntnt-photo-drop-permanence' ) )->toBeGreaterThanOrEqual( 3 );
	expect( $html )->toContain( '⚠' );
	expect( $html )->toContain( 'aria-label' );
	expect( $html )->toContain( 'title=' );

	// A line beside the Save button states that ⚠️ fields are set once and cannot be
	// changed after the collection is created.
	expect( $html )->toContain( 'set once' );

	// The re-derivable full/thumbnail fields carry the milder "regenerates images"
	// note rather than the permanence marker.
	expect( $html )->toContain( 'regenerate' );

	$_GET = [];
	admin_remove_tree( $basedir );
} );

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
		'slug'           => 'sneaky',
		'name'           => 'Sneaky',
		'upload_width'   => '1920',
		'upload_quality' => '80',
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

// Real-sanitisation path-components tests (issue #64). These deliberately replace
// the no-op `sanitize_text_field` stub with WordPress's real percent-octet
// stripping, so the `%day%`-mangling that corrupts the legal default template can
// never silently return: a no-op stub passed these inputs through untouched and so
// could not catch the bug.

test( 'handle_create accepts the default path-components template through real sanitisation', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	wire_real_sanitize_text_field();
	wire_admin_post_handler_stubs();

	// The submitted template is the legal default; under real sanitisation `%day%`
	// would be mangled to `y%` and the value false-rejected (#64). It must survive
	// intact and the collection must be created with the default template.
	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'              => 'default-template',
		'name'              => 'Default Template',
		'path_components'   => Descriptor::DEFAULT_PATH_COMPONENTS,
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

	$descriptor = Descriptor::read( $root . 'default-template' );
	expect( $descriptor )->not->toBeNull();
	expect( $descriptor->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test(
	'handle_create preserves every legal placeholder template through real sanitisation',
	function ( string $template ): void {
		$basedir = fresh_admin_basedir();
		$root    = wire_admin_stubs( $basedir );
		wire_real_sanitize_text_field();
		wire_admin_post_handler_stubs();

		// Each template combines only the four legal placeholders; none may be mangled
		// by the percent-octet stripping, so every one stores verbatim.
		$page  = new Admin_Page( new Repository() );
		$_POST = [
			'slug'              => 'legal-template',
			'name'              => 'Legal Template',
			'path_components'   => $template,
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

		$descriptor = Descriptor::read( $root . 'legal-template' );
		expect( $descriptor )->not->toBeNull();
		expect( $descriptor->path_components )->toBe( $template );

		$_POST = [];
		admin_remove_tree( $basedir );
	}
)->with( [
	'day alone'       => [ '%day%' ],
	'month and day'   => [ '%month%/%day%' ],
	'full default'    => [ '%year%/%month%/%day%/%uploader%' ],
	'uploader first'  => [ '%uploader%/%year%/%day%' ],
	'literal segment' => [ 'events/%year%/%day%' ],
] );

test( 'handle_update accepts the default path-components template through real sanitisation', function (): void {
	$basedir = fresh_admin_basedir();
	$root    = wire_admin_stubs( $basedir );
	seed_admin_collection( $root, 'edited-default', 'Edited Default', 1920, 80 );
	wire_real_sanitize_text_field();
	wire_admin_post_handler_stubs();

	// Seed a distinct, non-default template first, so a false-reject of the default
	// leaves this value behind and the assertion below catches it rather than reading
	// back a pre-existing default.
	$seeded = Descriptor::read( $root . 'edited-default' )->with_renditions( 1600, 82, 480, 70 );
	$distinct = new Descriptor(
		$seeded->name,
		$seeded->upload_width,
		$seeded->upload_quality,
		$seeded->full_width,
		$seeded->full_quality,
		$seeded->thumbnail_width,
		$seeded->thumbnail_quality,
		'events/%uploader%',
	);
	$distinct->write( $root . 'edited-default' );

	// Re-saving the Edit form with the legal default template — the exact #64
	// reproduction — must succeed, not false-reject the value `%day%` mangles into.
	$page  = new Admin_Page( new Repository() );
	$_POST = [
		'slug'            => 'edited-default',
		'name'            => 'Edited Default',
		'path_components' => Descriptor::DEFAULT_PATH_COMPONENTS,
	];

	try {
		$page->handle_update();
	} catch ( Admin_Page_Halt ) {
		$noop = true;
	}

	expect( Descriptor::read( $root . 'edited-default' )->path_components )
		->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	$_POST = [];
	admin_remove_tree( $basedir );
} );

test(
	'handle_create still rejects a genuinely invalid template through real sanitisation',
	function ( string $template ): void {
		$basedir = fresh_admin_basedir();
		$root    = wire_admin_stubs( $basedir );
		( new Repository() )->get_root();
		wire_real_sanitize_text_field();
		wire_admin_post_handler_stubs();

		// The fix must not over-correct: a stray `%`, an unknown token, a traversal, a
		// backslash, or a NUL is still rejected before any directory is made (#64
		// out-of-scope guard — the grammar and lexical checks are untouched). A leading
		// slash is not here: `Path_Template::normalise` legitimately drops it to a
		// relative template, so it is not a genuinely invalid input.
		$page  = new Admin_Page( new Repository() );
		$_POST = [
			'slug'              => 'still-rejected',
			'name'              => 'Still Rejected',
			'path_components'   => $template,
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

		expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

		$_POST = [];
		admin_remove_tree( $basedir );
	}
)->with( [
	'stray percent' => [ '%year%/%moth%' ],
	'unknown token' => [ '%year%/%hour%' ],
	'traversal'     => [ '%year%/../../escape' ],
	'backslash'     => [ '%year%\\evil' ],
	'nul byte'      => [ "%year%/x\0y" ],
] );
