<?php
/**
 * Tests for the Photo Drop Zone render handler — the capability gate.
 *
 * `Render_Drop_Zone` is the first of ADR-0006's two defences in depth: it emits
 * the uploader and the `wp_rest` nonce only for a user who holds the upload
 * capability. These tests prove the load-bearing invariant — an un-capable user
 * gets nothing and, crucially, no nonce — and that a capable user with a real
 * temp-dir collection gets the native drop surface plus a nonce configured from
 * the descriptor, with the `{kntnt-drop-zone-collection}` placeholder in the
 * inner-block markup replaced by the collection's display name. Only the
 * WordPress seams are stubbed (`current_user_can`, `apply_filters`, the escapers,
 * `rest_url`, `admin_url`, `wp_create_nonce`, `get_block_wrapper_attributes`); the
 * `Repository` and `Descriptor` run against a real collection on disk, the same
 * harness pattern the upload tests use.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Rendering\Render_Drop_Zone;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, stubbed WP rendering seams
// ---------------------------------------------------------------------------

/**
 * Wires every WordPress seam the render handler and its collaborators reach for.
 *
 * The uploads basedir is a real temp directory so the `Repository` and
 * `Descriptor` exercise the real filesystem. The capability verdict is
 * parameterised so a test can render as a capable or an un-capable user. The
 * escapers and the i18n function are pass-throughs; `rest_url` and
 * `wp_create_nonce` return recognisable sentinels the assertions look for.
 *
 * @param string $basedir Temp directory standing in for the uploads basedir.
 * @param bool   $cap_ok  What `current_user_can()` should return.
 * @return void
 */
function wire_render_stubs( string $basedir, bool $cap_ok ): void {

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
	Functions\when( 'sanitize_text_field' )->alias(
		static fn ( string $value ): string => trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' )
	);
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed => $value
	);
	Functions\when( 'current_user_can' )->justReturn( $cap_ok );

	// The render path builds an absolute upload URL, the admin-ajax URL the
	// nonce refresh targets, and a nonce; the stubs return recognisable
	// sentinels the assertions search the markup for.
	Functions\when( 'rest_url' )->alias(
		static fn ( string $path ): string => 'https://example.test/wp-json/' . $path
	);
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce-abc123' );
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $args = [] ): string {
			$class = $args['class'] ?? '';
			return sprintf( 'class="%s"', $class );
		}
	);

}

/**
 * Creates a real collection on disk under the temp uploads root.
 *
 * @param string     $basedir    The temp uploads basedir.
 * @param string     $slug       The collection slug.
 * @param Descriptor $descriptor The contract to persist.
 * @return string The absolute collection directory path.
 */
function seed_render_collection( string $basedir, string $slug, Descriptor $descriptor ): string {
	$path = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/' . $slug;
	mkdir( $path, 0700, true );
	$descriptor->write( $path );
	return $path;
}

/**
 * Allocates a fresh temp directory standing in for the uploads basedir.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_render_basedir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-render-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Recursively removes a temp directory tree.
 *
 * @param string $dir The directory to remove.
 * @return void
 */
function render_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		render_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds a minimal WP_Block stand-in for the render-callback third argument.
 *
 * `Render_Drop_Zone` reads nothing off the block, so a bare anonymous object
 * typed loosely is sufficient; the signature requires a `\WP_Block`, which the
 * unit-test stubs declare as an empty shell.
 *
 * @return \WP_Block The stub block instance.
 */
function render_block_stub(): \WP_Block {
	return new \WP_Block();
}

// ---------------------------------------------------------------------------
// The capability gate — an un-capable user gets nothing and no nonce
// ---------------------------------------------------------------------------

test( 'an un-capable user gets an empty render and no nonce', function (): void {

	// The user lacks the upload capability: the block must render the empty
	// string and, the load-bearing invariant, never emit a nonce.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: false );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '', render_block_stub() );

	expect( $html )->toBe( '' );
	expect( $html )->not->toContain( 'test-nonce-abc123' );

	render_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The capable user — uploader plus a nonce, configured from the descriptor
// ---------------------------------------------------------------------------

test( 'a capable user gets the drop-surface markup and a nonce', function (): void {

	// The user holds the capability and the collection resolves: the wrapper is
	// itself the drop surface, so the inner-block markup is a direct child (no
	// surface div), alongside the hidden loose-file input, the folder picker, the
	// Interactivity directive, and the nonce.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '<p>inner</p>', render_block_stub() );

	expect( $html )->toContain( 'test-nonce-abc123' );
	expect( $html )->toContain( '<p>inner</p>' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-drop-zone__surface' );
	expect( $html )->toContain( 'kntnt-photo-drop-drop-zone__file-input' );
	expect( $html )->toContain( 'data-wp-interactive' );
	expect( $html )->toContain( 'webkitdirectory' );

	render_remove_tree( $basedir );
} );

test( 'the wrapper carries no role or tabindex; an "Add photos" button is the keyboard path', function (): void {

	// The wrapper is the layout container and the pointer click-to-browse surface,
	// but the keyboard/AT browse path is a real <button> rendered next to "Select
	// folder"; the wrapper itself carries neither role="button" nor tabindex
	// (issue #35 accessibility).
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '<p>inner</p>', render_block_stub() );

	expect( $html )->toMatch( '/<button type="button" class="kntnt-photo-drop-drop-zone__browse">[^<]+<\/button>/' );
	expect( $html )->not->toContain( 'role="button"' );
	expect( $html )->not->toContain( 'tabindex' );

	render_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The collection placeholder — replaced with the display name at render
// ---------------------------------------------------------------------------

test( 'the collection placeholder is replaced with the display name', function (): void {

	// The inner-block markup carries the literal placeholder; the render handler
	// must substitute the collection's display name and emit no placeholder.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Field Trip', 1920, 80, [ 320 ] ) );

	$content = '<p>Uploads go into the “{kntnt-drop-zone-collection}” collection.</p>';
	$html    = Render_Drop_Zone::render( [ 'collection' => 'photos' ], $content, render_block_stub() );

	expect( $html )->toContain( 'Uploads go into the “Field Trip” collection.' );
	expect( $html )->not->toContain( '{kntnt-drop-zone-collection}' );

	render_remove_tree( $basedir );
} );

test( 'inner markup without the placeholder passes through unchanged', function (): void {

	// A builder who removed or edited the token leaves no placeholder to
	// replace; the inner markup must reach the surface verbatim.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$content = '<h4>Drop your shots here</h4>';
	$html    = Render_Drop_Zone::render( [ 'collection' => 'photos' ], $content, render_block_stub() );

	expect( $html )->toContain( '<h4>Drop your shots here</h4>' );

	render_remove_tree( $basedir );
} );

test( 'the emitted context carries the contract and upload URL for the slug', function (): void {

	// The data-wp-context island must carry the slug, the contract values that
	// configure the client downscale and WebP encode, and the per-slug REST URL.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1600, 75, [ 320, 640 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '', render_block_stub() );

	expect( $html )->toContain( '"slug":"photos"' );
	expect( $html )->toContain( '"maxWidth":1600' );
	expect( $html )->toContain( '"quality":75' );
	// The URL rides inside the JSON context, where wp_json_encode escapes slashes
	// by default — so the route segments are split by escaped slashes in markup.
	expect( $html )->toContain( 'collections' );
	expect( $html )->toContain( 'photos' );
	expect( $html )->toContain( 'images' );

	render_remove_tree( $basedir );
} );

test( 'the emitted context carries the ajax URL for nonce refresh', function (): void {

	// The view module recovers from an expired nonce via core's `rest-nonce`
	// admin-ajax action, so the context must carry the admin-ajax URL.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '', render_block_stub() );

	expect( $html )->toContain( '"ajaxUrl"' );
	expect( $html )->toContain( 'admin-ajax.php' );

	render_remove_tree( $basedir );
} );

test( 'the markup carries the summary line and the translated status strings', function (): void {

	// The keyed status report needs the summary element, and the i18n map must
	// carry the runtime status strings so the visible uploader UI is
	// translatable; FilePond's own labels are gone with the native surface.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'photos', new Descriptor( 'Photos', 1920, 80, [ 320 ] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'photos' ], '', render_block_stub() );

	expect( $html )->toContain( 'kntnt-photo-drop-drop-zone__summary' );
	expect( $html )->toContain( '"folderWarningBody"' );
	expect( $html )->toContain( '"statusUploading"' );
	expect( $html )->toContain( '"summaryTemplate"' );

	render_remove_tree( $basedir );
} );

test( 'a null max-width contract is emitted as JSON null', function (): void {

	// A "no limit" collection (maxWidth null) must serialise as JSON null so the
	// view module's downscale leaves the source width untouched.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );
	seed_render_collection( $basedir, 'unbounded', new Descriptor( 'Unbounded', null, 80, [] ) );

	$html = Render_Drop_Zone::render( [ 'collection' => 'unbounded' ], '', render_block_stub() );

	expect( $html )->toContain( '"maxWidth":null' );

	render_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Empty and dangling collection — nothing renders for the public
// ---------------------------------------------------------------------------

test( 'an empty collection attribute renders nothing for a capable user', function (): void {

	// No collection chosen: even a capable user sees nothing (the editor notice is
	// the editor component's job, not the front end's).
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );

	$html = Render_Drop_Zone::render( [ 'collection' => '' ], '', render_block_stub() );

	expect( $html )->toBe( '' );

	render_remove_tree( $basedir );
} );

test( 'a dangling collection slug renders nothing and emits no nonce', function (): void {

	// The slug points at a collection that does not exist (renamed or removed):
	// the public sees nothing and no nonce is emitted.
	$basedir = fresh_render_basedir();
	wire_render_stubs( $basedir, cap_ok: true );

	$html = Render_Drop_Zone::render( [ 'collection' => 'ghost' ], '', render_block_stub() );

	expect( $html )->toBe( '' );
	expect( $html )->not->toContain( 'test-nonce-abc123' );

	render_remove_tree( $basedir );
} );
