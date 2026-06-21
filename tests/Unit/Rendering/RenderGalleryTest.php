<?php
/**
 * Tests for the Photo Gallery render handler — `agents.d/testing.md`
 * § *Gallery rendering: srcset and ordering*.
 *
 * `Render_Gallery` is the public viewing surface: it resolves a collection,
 * validates the editor-set start path once against the root, walks the tree
 * through the self-healing per-folder indexes, and emits the responsive gallery
 * markup. These tests run against a real temp-dir collection with real GD WebP
 * mains and a real index, so the load-bearing claims are exercised end to end:
 * the srcset lists every thumbnail width plus the main at real pixel widths; the
 * markup carries dimensions and `loading="lazy"` for zero layout shift; the
 * flattened order is by full relative path (natural sort, asc/desc) keeping
 * folders contiguous; the start path is validated once and request-time path
 * input is ignored; layout A and B branch; captions assemble across
 * content/position/overlay; a no-collection or broken reference renders nothing
 * for the public and a notice for an editor, while an imageless-but-valid
 * collection renders a configurable message to everyone (ADR-0012); and every
 * image is wrapped in its `<a href>` fallback. Only the WordPress seams are
 * stubbed; the `Repository`,
 * `Descriptor`, `Path_Guard`, `Index_Store`, and the pure helpers run for real.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Rendering\Render_Gallery;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// Harness — real temp uploads root, stubbed WP rendering seams
// ---------------------------------------------------------------------------

/**
 * Wires every WordPress seam the gallery render and its collaborators reach for.
 *
 * The uploads basedir is a real temp directory and the baseurl a recognisable
 * sentinel, so the `Repository`, `Descriptor`, `Path_Guard`, and `Index_Store`
 * all run against the real filesystem while the emitted URLs are assertable. The
 * escapers and i18n are pass-throughs; `current_user_can` is parameterised so a
 * test can render as the public or as a user who can edit.
 *
 * @param string $basedir  Temp directory standing in for the uploads basedir.
 * @param bool   $can_edit What `current_user_can()` should return.
 * @return void
 */
function wire_gallery_stubs( string $basedir, bool $can_edit = false ): void {

	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => $basedir,
			'baseurl' => 'https://example.test/uploads',
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
		static fn ( string $value ): string => trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' )
	);
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_url' )->returnArg( 1 );
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed => $value
	);
	Functions\when( 'current_user_can' )->justReturn( $can_edit );
	Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
	Functions\when( 'rest_url' )->alias(
		static fn ( string $path = '' ): string => 'https://example.test/wp-json/' . ltrim( $path, '/' )
	);
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $args = [] ): string {
			$parts = [];
			foreach ( $args as $key => $value ) {
				$parts[] = sprintf( '%s="%s"', $key, $value );
			}
			return implode( ' ', $parts );
		}
	);

	// The style engine is the block-support projection seam. The stub reproduces
	// just enough of it for the render tests: a `prop:value;` declaration per
	// recognised colour / typography / border / shadow value (resolving a
	// `var:preset|…` token to its custom property) and the standard preset
	// classnames, so a render can assert that a custom or preset support reaches
	// the figcaption / image without depending on core's CSS machinery.
	Functions\when( 'wp_style_engine_get_styles' )->alias(
		static fn ( array $block_styles ): array => gallery_fake_style_engine( $block_styles )
	);

}

/**
 * A faithful-enough stand-in for `wp_style_engine_get_styles` in unit tests.
 *
 * Walks the documented colour / typography / border / shadow keys, emits one CSS
 * declaration per custom value (converting a `var:preset|type|slug` token to its
 * CSS custom property), and emits the standard preset classnames for the preset
 * tokens. Returns the same `{ css, declarations, classnames }` shape core does.
 *
 * @param array<string,mixed> $block_styles The style subtree for one sub-element.
 * @return array{css:string,declarations:array<int,string>,classnames:string} The projected styles.
 */
function gallery_fake_style_engine( array $block_styles ): array {

	$declarations = [];
	$classnames   = [];

	// Colour: text → color, background → background-color, gradient → background.
	$color = is_array( $block_styles['color'] ?? null ) ? $block_styles['color'] : [];
	foreach ( [
		'text'       => 'color',
		'background' => 'background-color',
		'gradient'   => 'background',
	] as $key => $prop ) {
		$value = $color[ $key ] ?? null;
		if ( is_string( $value ) && $value !== '' ) {
			$class = gallery_fake_preset_class( $value, $key );
			if ( $class !== null ) {
				$classnames[] = $class;
			}
			$declarations[] = $prop . ':' . gallery_fake_resolve( $value ) . ';';
		}
	}

	// Typography: a representative subset keyed exactly as core.
	$typography = is_array( $block_styles['typography'] ?? null ) ? $block_styles['typography'] : [];
	foreach ( [
		'fontSize'   => 'font-size',
		'lineHeight' => 'line-height',
		'fontFamily' => 'font-family',
	] as $key => $prop ) {
		$value = $typography[ $key ] ?? null;
		if ( is_string( $value ) && $value !== '' ) {
			$class = gallery_fake_preset_class( $value, $key );
			if ( $class !== null ) {
				$classnames[] = $class;
			}
			$declarations[] = $prop . ':' . gallery_fake_resolve( $value ) . ';';
		}
	}

	// Border: the four flat properties plus radius.
	$border = is_array( $block_styles['border'] ?? null ) ? $block_styles['border'] : [];
	foreach ( [
		'color'  => 'border-color',
		'width'  => 'border-width',
		'style'  => 'border-style',
		'radius' => 'border-radius',
	] as $key => $prop ) {
		$value = $border[ $key ] ?? null;
		if ( is_string( $value ) && $value !== '' ) {
			$declarations[] = $prop . ':' . gallery_fake_resolve( $value ) . ';';
		}
	}

	// Shadow: a single box-shadow declaration.
	$shadow = $block_styles['shadow'] ?? null;
	if ( is_string( $shadow ) && $shadow !== '' ) {
		$declarations[] = 'box-shadow:' . gallery_fake_resolve( $shadow ) . ';';
	}

	return [
		'css'          => implode( '', $declarations ),
		'declarations' => $declarations,
		'classnames'   => implode( ' ', $classnames ),
	];

}

/**
 * Resolves a `var:preset|type|slug` token to its CSS custom property reference.
 *
 * @param string $value The style value, possibly a preset token.
 * @return string The resolved CSS value.
 */
function gallery_fake_resolve( string $value ): string {
	if ( preg_match( '/^var:preset\|([a-z-]+)\|(.+)$/', $value, $m ) === 1 ) {
		$type = $m[1] === 'fontSize' ? 'font-size' : $m[1];
		return sprintf( 'var(--wp--preset--%s--%s)', $type, $m[2] );
	}
	return $value;
}

/**
 * Returns the standard preset classname for a preset token, or null otherwise.
 *
 * @param string $value The style value, possibly a preset token.
 * @param string $key   The style key the value sits under.
 * @return string|null The classname, or null when the value is not a preset.
 */
function gallery_fake_preset_class( string $value, string $key ): ?string {
	if ( preg_match( '/^var:preset\|([a-z-]+)\|(.+)$/', $value, $m ) !== 1 ) {
		return null;
	}
	$slug = $m[2];
	return match ( $key ) {
		'text'       => "has-text-color has-{$slug}-color",
		'background' => "has-background has-{$slug}-background-color",
		'gradient'   => "has-background has-{$slug}-gradient-background",
		'fontSize'   => "has-{$slug}-font-size",
		'fontFamily' => "has-{$slug}-font-family",
		default      => null,
	};
}

/**
 * Allocates a fresh temp directory standing in for the uploads basedir.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_gallery_basedir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-gallery-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Creates a real collection directory and writes its descriptor.
 *
 * @param string     $basedir    The temp uploads basedir.
 * @param string     $slug       The collection slug.
 * @param Descriptor $descriptor The contract to persist.
 * @return string The absolute collection directory path.
 */
function seed_gallery_collection( string $basedir, string $slug, Descriptor $descriptor ): string {
	$path = rtrim( $basedir, '/' ) . '/kntnt-photo-drop/' . $slug;
	mkdir( $path, 0700, true );
	$descriptor->write( $path );
	return $path;
}

/**
 * Builds a three-rendition descriptor for the render tests, overriding fields.
 *
 * The render tests care about the upload ceiling (the main width) and the
 * full/thumbnail widths the srcset is built from. The defaults — upload 1920,
 * full 1280, thumbnail 320 — keep a typical main producing a thumbnail and a
 * full candidate (ADR-0013); a test that exercises the tier boundaries overrides
 * the relevant widths.
 *
 * @param array<string,mixed> $overrides Field overrides keyed by constructor parameter name.
 * @return Descriptor The descriptor under test.
 */
function gallery_descriptor( array $overrides = [] ): Descriptor {
	$fields = array_merge(
		[
			'name'              => 'Photos',
			'upload_width'      => 1920,
			'upload_quality'    => 80,
			'full_width'        => 1280,
			'full_quality'      => 80,
			'thumbnail_width'   => 320,
			'thumbnail_quality' => 75,
		],
		$overrides,
	);
	return new Descriptor(
		$fields['name'],
		$fields['upload_width'],
		$fields['upload_quality'],
		$fields['full_width'],
		$fields['full_quality'],
		$fields['thumbnail_width'],
		$fields['thumbnail_quality'],
		'%year%',
	);
}

/**
 * Writes a real WebP main image of given dimensions into a collection folder.
 *
 * @param string $collection_path The absolute collection root.
 * @param string $relative_path   The image path relative to the root.
 * @param int    $width           The image width in pixels.
 * @param int    $height          The image height in pixels.
 * @return void
 */
function write_gallery_image( string $collection_path, string $relative_path, int $width, int $height ): void {
	$absolute = rtrim( $collection_path, '/' ) . '/' . $relative_path;
	$dir      = dirname( $absolute );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	$image = imagecreatetruecolor( $width, $height );
	imagewebp( $image, $absolute );
}

/**
 * Recursively removes a temp directory tree.
 *
 * @param string $dir The directory to remove.
 * @return void
 */
function gallery_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		gallery_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds a minimal WP_Block stand-in for the render-callback third argument.
 *
 * `Render_Gallery` reads nothing off the block, so a bare shell satisfies the
 * `\WP_Block` signature in the unit runtime.
 *
 * @return \WP_Block The stub block instance.
 */
function gallery_block_stub(): \WP_Block {
	return new \WP_Block();
}

/**
 * Re-points one capability filter at a bespoke capability, passing the rest through.
 *
 * The default harness stubs `apply_filters` as a pass-through; this overrides it
 * so a single named capability hook returns `$capability` while every other
 * filter still returns its value unchanged — the seam a test uses to prove a
 * capability gate consults its filter rather than a hardcoded default.
 *
 * @param string $hook       The capability filter hook to override.
 * @param string $capability The capability the hook should return.
 * @return void
 */
function stub_capability_filter( string $hook, string $capability ): void {
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $name, mixed $value ): mixed => $name === $hook ? $capability : $value
	);
}

/**
 * Renders a gallery against a freshly seeded collection, returning the markup.
 *
 * @param array<string,mixed>                                $attributes The block attributes (merged over defaults).
 * @param array<int,array{path:string,width:int,height:int}> $images The images to seed.
 * @param Descriptor                                         $descriptor The collection contract.
 * @param bool                                               $can_edit   Whether to render as a user who can edit.
 * @param string|null                                        $basedir_out Receives the temp basedir for cleanup.
 * @return string The rendered HTML.
 */
function render_seeded_gallery(
	array $attributes,
	array $images,
	Descriptor $descriptor,
	bool $can_edit,
	?string &$basedir_out,
): string {

	$basedir     = fresh_gallery_basedir();
	$basedir_out = $basedir;
	wire_gallery_stubs( $basedir, $can_edit );
	$collection = seed_gallery_collection( $basedir, 'photos', $descriptor );
	foreach ( $images as $image ) {
		write_gallery_image( $collection, $image['path'], $image['width'], $image['height'] );
	}

	$attributes = array_merge( [ 'collection' => 'photos' ], $attributes );

	return Render_Gallery::render( $attributes, '', gallery_block_stub() );

}

// ---------------------------------------------------------------------------
// srcset — the thumbnail and the bounded full; the wider main is download-only
// ---------------------------------------------------------------------------

test( 'the srcset lists the thumbnail and the bounded full, not the wider main', function (): void {

	// A 4000px main bounded by a 1280 full: the srcset candidates are the 320
	// thumbnail and the 1280 full, both from the hidden width directories, and the
	// download-only 4000px main never appears (ADR-0013).
	$descriptor = gallery_descriptor( [
		'upload_width'    => null,
		'full_width'      => 1280,
		'thumbnail_width' => 320,
	] );
	$html = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'wide.jpg.webp',
				'width'  => 4000,
				'height' => 2400,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '320w' );
	expect( $html )->toContain( '1280w' );
	expect( $html )->not->toContain( '4000w' );
	expect( $html )->toContain( '/kntnt-thumbnails/320/' );
	expect( $html )->toContain( '/kntnt-thumbnails/1280/' );

	gallery_remove_tree( $basedir );
} );

test( 'a main no wider than the full is the full candidate, served from the main url', function (): void {

	// A 900px main no wider than the 1920 full is itself the full rendition, so it
	// is a candidate at its own width served from the main URL (no separate 900px
	// width directory), alongside the 320 thumbnail (ADR-0013).
	$descriptor = gallery_descriptor( [
		'full_width'      => 1920,
		'thumbnail_width' => 320,
	] );
	$html = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'mid.jpg.webp',
				'width'  => 900,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '320w' );
	expect( $html )->toContain( '900w' );
	expect( $html )->toContain( '/kntnt-thumbnails/320/' );
	expect( $html )->not->toContain( '/kntnt-thumbnails/900/' );

	gallery_remove_tree( $basedir );
} );

test( 'an unset full width makes the wide main the full candidate at its own width', function (): void {

	// With the Full width unset, the main serves the full role at any size: a 4000px
	// main is a srcset candidate at its own 4000w from the main URL (no separate full
	// directory), alongside the 320 thumbnail (#71, ADR-0013).
	$descriptor = gallery_descriptor( [
		'upload_width'    => null,
		'full_width'      => null,
		'full_quality'    => null,
		'thumbnail_width' => 320,
	] );
	$html = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'wide.jpg.webp',
				'width'  => 4000,
				'height' => 2400,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '320w' );
	expect( $html )->toContain( '4000w' );
	expect( $html )->toContain( '/kntnt-thumbnails/320/' );
	expect( $html )->not->toContain( '/kntnt-thumbnails/4000/' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// sizes — layout-aware hints, never a blanket 100vw
// ---------------------------------------------------------------------------

test( 'the grid layout derives one sizes hint from the minimum column width', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'             => 'grid',
			'minimumColumnWidth' => '300px',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 1200,
				'height' => 800,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Below the minimum column width the grid is single-column (full viewport);
	// above it a tile renders near the minimum, so the cap is 1.5× the minimum.
	// The leading `auto` is the lazy-loading auto-sizes entry and must come first.
	expect( $html )->toContain( 'sizes="auto, (max-width: 300px) 100vw, 450px"' );
	expect( $html )->not->toContain( 'sizes="100vw"' );

	gallery_remove_tree( $basedir );
} );

test( 'the justified layout derives a per-image sizes hint from the natural tile width', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'          => 'justified',
			'targetRowHeight' => 200,
		],
		[
			[
				'path'   => 'wide.jpg.webp',
				'width'  => 1200,
				'height' => 800,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A 3:2 image at a 200px row height renders ~300px wide, so the hint caps at
	// that natural width while a narrower viewport still gets the full-width case.
	expect( $html )->toContain( 'sizes="auto, (max-width: 300px) 100vw, 300px"' );
	expect( $html )->not->toContain( 'sizes="100vw"' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Zero layout shift — stored dimensions and lazy loading on every image
// ---------------------------------------------------------------------------

test( 'each image carries its stored dimensions and loads lazily', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The stored dimensions and lazy loading are what give zero layout shift.
	expect( $html )->toContain( 'width="800"' );
	expect( $html )->toContain( 'height="600"' );
	expect( $html )->toContain( 'loading="lazy"' );

	gallery_remove_tree( $basedir );
} );

test( 'the grid layout sets an aspect-ratio from the stored dimensions by default', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'layout' => 'grid' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// With no fixed aspectRatio attribute, each grid cell uses the image's own
	// stored ratio so the cell never reshapes on load.
	expect( $html )->toContain( 'aspect-ratio:800 / 600' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// The no-JS fallback — every image is wrapped in an <a href> to the main
// ---------------------------------------------------------------------------

test( 'every image is wrapped in an anchor to its full main image', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
			[
				'path'   => 'b.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The anchor is the no-JS fallback and the lightbox upgrade hook; both images
	// carry one to their own main URL, plus the slide srcset the lightbox reads so
	// the overlay image is responsive rather than always full-resolution.
	expect( substr_count( $html, '<a class="kntnt-photo-drop-gallery__link"' ) )->toBe( 2 );
	expect( $html )->toContain( 'href="https://example.test/uploads/kntnt-photo-drop/photos/a.jpg.webp"' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-full=' );
	expect( $html )->toContain(
		'data-kntnt-photo-drop-srcset="'
			. 'https://example.test/uploads/kntnt-photo-drop/photos/kntnt-thumbnails/320/a.jpg.webp 320w, '
			. 'https://example.test/uploads/kntnt-photo-drop/photos/a.jpg.webp 800w"'
	);

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Ordering — natural sort by full relative path, folders contiguous, asc/desc
// ---------------------------------------------------------------------------

test( 'recursive ordering is natural sort by full relative path, ascending', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'recursive' => true,
			'order'     => 'asc',
		],
		[
			[
				'path'   => 'b-folder/img2.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
			[
				'path'   => 'b-folder/img10.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
			[
				'path'   => 'a-folder/img1.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Natural sort orders a-folder before b-folder (folders contiguous) and img2
	// before img10 within b-folder.
	$pos_a   = strpos( $html, 'a-folder/img1.jpg.webp' );
	$pos_b2  = strpos( $html, 'b-folder/img2.jpg.webp' );
	$pos_b10 = strpos( $html, 'b-folder/img10.jpg.webp' );
	expect( $pos_a )->toBeLessThan( $pos_b2 );
	expect( $pos_b2 )->toBeLessThan( $pos_b10 );

	gallery_remove_tree( $basedir );
} );

test( 'descending order reverses the natural sort', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'recursive' => true,
			'order'     => 'desc',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
			[
				'path'   => 'b.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Descending puts b before a.
	expect( strpos( $html, 'b.jpg.webp' ) )->toBeLessThan( strpos( $html, 'a.jpg.webp' ) );

	gallery_remove_tree( $basedir );
} );

test( 'this-folder-only excludes images in sub-folders', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'recursive' => false ],
		[
			[
				'path'   => 'top.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
			[
				'path'   => 'sub/deep.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// With recursion off, only the root-level image renders; the sub-folder one is
	// excluded entirely.
	expect( $html )->toContain( 'top.jpg.webp' );
	expect( $html )->not->toContain( 'sub/deep.jpg.webp' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Start path — validated once; request-time path input is ignored (ADR-0005)
// ---------------------------------------------------------------------------

test( 'the start path scopes the gallery to a sub-folder', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'startPath' => 'morning',
			'recursive' => true,
		],
		[
			[
				'path'   => 'morning/sunrise.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
			[
				'path'   => 'evening/sunset.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Only the start-path sub-tree renders; the sibling folder is out of scope.
	expect( $html )->toContain( 'morning/sunrise.jpg.webp' );
	expect( $html )->not->toContain( 'evening/sunset.jpg.webp' );

	gallery_remove_tree( $basedir );
} );

test( 'a traversing start path is rejected and renders nothing for the public', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'startPath' => '../../etc' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The guard rejects the traversal once, against the root; the public sees
	// nothing rather than an escaped listing.
	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'a request-time path superglobal is ignored — only the attribute is read', function (): void {

	// Plant a hostile path in the request superglobals; the renderer must ignore
	// them entirely and use only the stored startPath attribute (ADR-0005).
	$_GET['startPath']     = '../../etc';
	$_REQUEST['startPath'] = '../../etc';
	$descriptor            = gallery_descriptor();
	$html                  = render_seeded_gallery(
		[ 'startPath' => '' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The stored startPath is the root, so the image renders; the request-time path
	// had no effect at all.
	expect( $html )->toContain( 'a.jpg.webp' );
	unset( $_GET['startPath'], $_REQUEST['startPath'] );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Layout — A (grid) vs B (justified) branch
// ---------------------------------------------------------------------------

test( 'the grid layout emits the grid container and a min-column variable', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'             => 'grid',
			'minimumColumnWidth' => '300px',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'kntnt-photo-drop-gallery__layout--grid' );
	expect( $html )->toContain( '--kntnt-photo-drop-min-column:300px' );

	gallery_remove_tree( $basedir );
} );

test( 'the justified layout emits per-image flex-grow and flex-basis', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'          => 'justified',
			'targetRowHeight' => 200,
		],
		[
			[
				'path'   => 'wide.jpg.webp',
				'width'  => 300,
				'height' => 200,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A 3:2 image at a 200px row height has basis 300px; the single image is the
	// last row, so its grow is pinned to 0 and it keeps its natural width.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__layout--justified' );
	expect( $html )->toContain( 'flex-basis:300px' );
	expect( $html )->toContain( 'flex-grow:0' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Block spacing — the blockGap support drives the gap in both layouts (issue #33)
// ---------------------------------------------------------------------------

test( 'the grid layout reads the blockGap spacing support into its gap variable', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout' => 'grid',
			'style'  => [ 'spacing' => [ 'blockGap' => '28px' ] ],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The gap is no longer a bespoke attribute; the grid container's gap variable
	// comes from the spacing support's blockGap.
	expect( $html )->toContain( '--kntnt-photo-drop-gap:28px' );

	gallery_remove_tree( $basedir );
} );

test( 'the justified layout reads the blockGap spacing support into its gap variable', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout' => 'justified',
			'style'  => [ 'spacing' => [ 'blockGap' => '36px' ] ],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 300,
				'height' => 200,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '--kntnt-photo-drop-gap:36px' );

	gallery_remove_tree( $basedir );
} );

test( 'a blockGap spacing preset is rewritten to its custom-property reference', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout' => 'grid',
			'style'  => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|40' ] ],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A spacing preset token must become a usable CSS length, not leak the raw
	// `var:preset|…` form into the emitted custom property.
	expect( $html )->toContain( '--kntnt-photo-drop-gap:var(--wp--preset--spacing--40)' );

	gallery_remove_tree( $basedir );
} );

test( 'with no blockGap set, both layouts fall back to the default gap', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'layout' => 'grid' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '--kntnt-photo-drop-gap:12px' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Overlays — breadcrumbs (the collection-name-first, hide-count, anchor, mirror)
// ---------------------------------------------------------------------------

test( 'a thumbnail breadcrumb renders the collection name, the humanised path, and the file', function (): void {

	$descriptor = gallery_descriptor( [ 'name' => 'Holiday Photos' ] );
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'breadcrumbsSeparator'  => '›',
		],
		[
			[
				'path'   => 'day-one/IMG_5.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The breadcrumb leads with the collection display name, then each humanised
	// folder, then the humanised filename, joined by the separator (WordPress fills
	// the `›` default before the render callback; the unit test passes it directly).
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__breadcrumbs' );
	expect( $html )->toContain( 'Holiday Photos › day one › IMG 5' );

	gallery_remove_tree( $basedir );
} );

test( 'the breadcrumb hide-count drops that many leading crumbs, the collection first', function (): void {

	$descriptor = gallery_descriptor( [ 'name' => 'Holiday Photos' ] );
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'breadcrumbsHideCount'  => 1,
			'breadcrumbsSeparator'  => '›',
		],
		[
			[
				'path'   => 'day-one/IMG_5.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Hide-count 1 drops the collection name; the breadcrumb starts at the first
	// folder and the collection name no longer appears.
	expect( $html )->toContain( 'day one › IMG 5' );
	expect( $html )->not->toContain( 'Holiday Photos' );

	gallery_remove_tree( $basedir );
} );

test( 'a custom breadcrumb separator replaces the default', function (): void {

	$descriptor = gallery_descriptor( [ 'name' => 'C' ] );
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'breadcrumbsSeparator'  => '/',
		],
		[
			[
				'path'   => 'sub/a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'C / sub / a' );

	gallery_remove_tree( $basedir );
} );

test( 'the breadcrumb overlay carries the chosen nine-point position anchor', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'breadcrumbsPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'kntnt-photo-drop-gallery__breadcrumbs--anchor-top-right' );

	gallery_remove_tree( $basedir );
} );

test( 'the breadcrumb text is mirrored onto the anchor so the lightbox shows the same overlay', function (): void {

	$descriptor = gallery_descriptor( [ 'name' => 'Trip' ] );
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'breadcrumbsSeparator'  => '›',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The anchor carries the breadcrumb text as a data attribute so the lightbox and
	// any other surface read it without re-deriving the path.
	expect( $html )->toContain( 'data-kntnt-photo-drop-breadcrumbs="Trip › a"' );

	gallery_remove_tree( $basedir );
} );

test( 'breadcrumbs off emits no breadcrumb element and no mirrored data attribute', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'breadcrumbsVisibility' => 'off' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__breadcrumbs' );
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-breadcrumbs' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Overlays — shared appearance via skip-serialization (Colour / Typography /
// Border / Shadow projected onto the sub-elements, never the wrapper)
// ---------------------------------------------------------------------------

test( 'custom overlay colour and typography land on the breadcrumb, not the wrapper', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'style'                 => [
				'color'      => [
					'text'       => '#112233',
					'background' => 'rgba(0,0,0,0.6)',
				],
				'typography' => [ 'lineHeight' => '1.8' ],
			],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The Colour and Typography panels are skip-serialized onto the breadcrumb's
	// inline style; the block wrapper carries none of them.
	expect( $html )->toMatch( '/<figcaption[^>]*__breadcrumbs[^>]*style="[^"]*color:#112233;/' );
	expect( $html )->toMatch( '/<figcaption[^>]*style="[^"]*background-color:rgba\(0,0,0,0\.6\);/' );
	expect( $html )->toMatch( '/<figcaption[^>]*style="[^"]*line-height:1\.8;/' );
	expect( $html )->not->toMatch( '/<div class="kntnt-photo-drop-gallery"[^>]*color:#112233/' );

	gallery_remove_tree( $basedir );
} );

test( 'a preset overlay colour adds the preset classname to the breadcrumb', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'textColor'             => 'vivid-red',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A palette choice projects to the breadcrumb as the preset classname and the
	// custom-property declaration.
	expect( $html )->toMatch( '/<figcaption class="[^"]*has-vivid-red-color/' );
	expect( $html )->toMatch( '/<figcaption[^>]*style="[^"]*color:var\(--wp--preset--color--vivid-red\);/' );

	gallery_remove_tree( $basedir );
} );

test( 'the shared overlay foreground and background reach the icon cluster as custom properties', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'downloadVisibility' => 'thumbnail',
			'style'              => [
				'color' => [
					'text'       => '#ffeedd',
					'background' => '#10203040',
				],
			],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The icons share the Colour support's foreground and background, projected onto
	// the icon cluster as custom properties the stylesheet reads to paint the glyphs.
	expect( $html )->toContain( '--kntnt-photo-drop-overlay-fg:#ffeedd' );
	expect( $html )->toContain( '--kntnt-photo-drop-overlay-bg:#10203040' );

	gallery_remove_tree( $basedir );
} );

test( 'the shared icon size reaches the icon cluster as a custom property', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'downloadVisibility' => 'thumbnail',
			'iconSize'           => '3rem',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '--kntnt-photo-drop-icon-size:3rem' );

	gallery_remove_tree( $basedir );
} );

test( 'border and shadow block supports land on each image, not the breadcrumb', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'breadcrumbsVisibility' => 'thumbnail',
			'style'                 => [
				'border' => [
					'width' => '3px',
					'color' => '#0000ff',
				],
				'shadow' => 'var:preset|shadow|deep',
			],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The Border & Shadow panel is skip-serialized onto each image; the breadcrumb
	// must not pick up the border or shadow.
	expect( $html )->toMatch( '/<img[^>]*style="[^"]*border-width:3px;/' );
	expect( $html )->toMatch( '/<img[^>]*style="[^"]*border-color:#0000ff;/' );
	expect( $html )->toMatch( '/<img[^>]*style="[^"]*box-shadow:var\(--wp--preset--shadow--deep\);/' );
	expect( $html )->not->toMatch( '/<figcaption[^>]*border-width/' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// No-collection / broken reference — nothing public, an editor notice (ADR-0012)
// ---------------------------------------------------------------------------

test( 'a dangling collection renders nothing for the public', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: false );

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'a dangling collection renders an editor-only notice for a user who can edit', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: true );

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	// A dangling reference is the broken-reference notice, not the imageless
	// message — the public must never learn a collection is gone.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery--notice' );
	expect( $html )->toContain( 'no collection selected' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery--empty' );

	gallery_remove_tree( $basedir );
} );

test( 'the dangling notice is gated on the edit_posts capability specifically', function (): void {

	// Pin the capability check itself: grant every capability except edit_posts,
	// so the notice can only vanish because the gate asks for edit_posts — a
	// logged-in user without editing rights is treated as the public.
	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $capability ): bool => $capability !== 'edit_posts'
	);

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor notice capability is filterable: a custom capability opens the notice', function (): void {

	// Re-gate the notice through its dedicated filter to a bespoke capability the
	// user holds, while denying every WordPress-standard one (edit_posts
	// included). The notice can therefore only appear because the gate consulted
	// the filtered capability rather than the hardcoded default.
	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir );
	stub_capability_filter( 'kntnt_photo_drop_editor_notice_capability', 'manage_collections' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $capability ): bool => $capability === 'manage_collections'
	);

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	// The bespoke-capability holder sees the broken-reference notice.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery--notice' );
	expect( $html )->toContain( 'no collection selected' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor notice capability is filterable: edit_posts is shut out once re-gated', function (): void {

	// Re-gate the notice to a bespoke capability the user lacks, while granting
	// the default edit_posts. If the gate still honoured the old hardcoded
	// capability the notice would leak; the filter must shut it.
	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir );
	stub_capability_filter( 'kntnt_photo_drop_editor_notice_capability', 'manage_collections' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $capability ): bool => $capability === 'edit_posts'
	);

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'an unset collection renders nothing for the public', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: false );

	$html = Render_Gallery::render( [ 'collection' => '' ], '', gallery_block_stub() );

	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'an unset collection renders the no-collection notice for a user who can edit', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: true );

	$html = Render_Gallery::render( [ 'collection' => '' ], '', gallery_block_stub() );

	// An unset slug is the no-collection case: the editor notice prompts selection,
	// and it is never the public imageless message.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery--notice' );
	expect( $html )->toContain( 'no collection selected' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery--empty' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Imageless collection — a configurable message shown to everyone (ADR-0012)
// ---------------------------------------------------------------------------

test( 'an imageless but valid collection shows the default message to the public', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		[],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A collection that resolves but holds no images is a legitimate visitor state:
	// the public sees the default message in the --empty wrapper, not the diagnostic
	// notice.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery--empty' );
	expect( $html )->toContain( 'There are currently no images in the gallery. Please try again later.' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery--notice' );

	gallery_remove_tree( $basedir );
} );

test( 'an imageless collection honours a custom emptyMessage for the public', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'emptyMessage' => 'No snaps yet — check back after the race.' ],
		[],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The editor-set text wins over the translated default, and it reaches the
	// public.
	expect( $html )->toContain( 'No snaps yet — check back after the race.' );
	expect( $html )->not->toContain( 'Please try again later.' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Overlays — surfaces (thumbnail / lightbox / never the slideshow) and the
// lightbox base click (ADR-0007, ADR-0015)
// ---------------------------------------------------------------------------

test( 'the lightbox is wired by default: the overlay, init hook, and flag are present', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// lightbox defaults true: the flag is emitted, the Interactivity init hook and
	// per-block context are bound, and the hidden dialog overlay is emitted with its
	// server-translated load-failure message. There is no longer a download wrapper
	// flag — overlays are per-figure, not a wrapper-level click matrix.
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="true"' );
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-download=' );
	expect( $html )->toContain( 'data-wp-init="callbacks.init"' );
	expect( $html )->toContain( 'counterTemplate' );
	expect( $html )->toContain( 'class="kntnt-photo-drop-lightbox"' );
	expect( $html )->toContain( 'role="dialog"' );
	expect( $html )->toContain( 'aria-modal="true"' );
	expect( $html )->toContain( 'kntnt-photo-drop-lightbox__error' );
	expect( $html )->toContain( 'The image could not be loaded.' );

	gallery_remove_tree( $basedir );
} );

test( 'the no-JS anchor fallback still wraps every image when the lightbox is on', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'lightbox' => true ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
			[
				'path'   => 'b.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The lightbox progressively enhances the same anchors it does not replace, so
	// both images keep their full-image <a href> for the no-JS path.
	expect( substr_count( $html, '<a class="kntnt-photo-drop-gallery__link"' ) )->toBe( 2 );
	expect( $html )->toContain( 'href="https://example.test/uploads/kntnt-photo-drop/photos/a.jpg.webp"' );

	gallery_remove_tree( $basedir );
} );

test( 'with the lightbox off and no overlays, the init hook binds but no overlay or icon shows', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'lightbox' => false ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The flag reads off; the init hook is still bound (the view module suppresses
	// the otherwise-navigating click so the gallery is inert with JS). No lightbox
	// overlay, no context, no overlay icons — yet the anchor still wraps the image so
	// a no-JS click navigates to the main image.
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="false"' );
	expect( $html )->toContain( 'data-wp-init="callbacks.init"' );
	expect( $html )->not->toContain( 'role="dialog"' );
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-lightbox"' );
	expect( $html )->not->toContain( 'counterTemplate' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__icon' );
	expect( $html )->toContain( '<a class="kntnt-photo-drop-gallery__link"' );

	gallery_remove_tree( $basedir );
} );

test( 'a thumbnail download overlay is an <a download> anchor at its main image', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'           => false,
			'downloadVisibility' => 'thumbnail',
			'downloadPosition'   => 'bottom-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Download is the one overlay shipped fully in #47: an <a download> anchor at the
	// main image, placed by its position's icon cluster, with a translated label.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icons--anchor-bottom-right' );
	expect( $html )->toMatch(
		'/<a class="kntnt-photo-drop-gallery__icon kntnt-photo-drop-gallery__icon--download"'
		. '[^>]* href="https:\/\/example\.test\/uploads\/kntnt-photo-drop\/photos\/a\.jpg\.webp"'
		. ' download aria-label="Download image">/'
	);
	expect( $html )->not->toMatch( '/<a class="kntnt-photo-drop-gallery__link"[^>]* download/' );

	gallery_remove_tree( $basedir );
} );

test( 'the anchor mirrors the main image as a dedicated download target for the lightbox', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'           => true,
			'downloadVisibility' => 'full',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The download is always the main image (ADR-0013), so the figure anchor carries
	// the main URL as its own `data-kntnt-photo-drop-main`, independent of the display
	// rendition. The lightbox download icon ships hrefless and the view module points
	// it at this attribute, so a full-rendition display split never drags the download
	// target off the main.
	expect( $html )->toMatch(
		'/<a class="kntnt-photo-drop-gallery__link"[^>]*'
		. ' data-kntnt-photo-drop-main='
		. '"https:\/\/example\.test\/uploads\/kntnt-photo-drop\/photos\/a\.jpg\.webp"/'
	);

	gallery_remove_tree( $basedir );
} );

test( 'icons sharing a position auto-cluster in the fixed order download, add-to-media, trash', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'             => false,
			'downloadVisibility'   => 'thumbnail',
			'downloadPosition'     => 'top-right',
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'top-right',
			'trashVisibility'      => 'thumbnail',
			'trashPosition'        => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// All three icons share one position, so they cluster into one anchored row in the
	// documented fixed order: download, then add-to-media, then trash. Add-to-media and
	// trash are capability-gated (ADR-0015), so this renders as a capable user.
	expect( substr_count( $html, 'kntnt-photo-drop-gallery__icons--anchor-top-right' ) )->toBe( 1 );
	$download    = strpos( $html, 'kntnt-photo-drop-gallery__icon--download' );
	$add_to_media = strpos( $html, 'kntnt-photo-drop-gallery__icon--add-to-media' );
	$trash       = strpos( $html, 'kntnt-photo-drop-gallery__icon--trash' );
	expect( $download )->toBeLessThan( $add_to_media );
	expect( $add_to_media )->toBeLessThan( $trash );

	gallery_remove_tree( $basedir );
} );

test( 'overlays split across positions emit one cluster per occupied position', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'             => false,
			'downloadVisibility'   => 'thumbnail',
			'downloadPosition'     => 'top-left',
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'bottom-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// Two icons at two positions yield two separate clusters (add-to-media is
	// capability-gated, so this renders as a capable user).
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icons--anchor-top-left' );
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icons--anchor-bottom-right' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Add-to-media — capability-gated icon and nonce (ADR-0015)
// ---------------------------------------------------------------------------

test( 'the add-to-media icon renders for a capable user', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'             => false,
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// A user holding the add_to_media capability sees the icon, and its anchor
	// carries the image's collection-relative path so the view module can POST it.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icon--add-to-media' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-path="a.jpg.webp"' );

	gallery_remove_tree( $basedir );
} );

test( 'the add-to-media icon is absent for an un-capable user', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'             => false,
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Defence in depth (ADR-0015): an un-capable user sees no add-to-media icon at
	// all — and, since no other overlay is on, no path attribute either.
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__icon--add-to-media' );
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-path' );

	gallery_remove_tree( $basedir );
} );

test( 'the wp_rest nonce and the media REST URL render only for a capable user', function (): void {

	$descriptor = gallery_descriptor();
	$capable    = render_seeded_gallery(
		[
			'lightbox'             => false,
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// A capable user's wrapper carries the nonce and the collection's media endpoint
	// URL, the two things the view module needs to POST the copy.
	expect( $capable )->toContain( 'data-kntnt-photo-drop-nonce' );
	expect( $capable )->toContain( '/wp-json/kntnt-photo-drop/v1/collections/photos/media' );

	gallery_remove_tree( $basedir );
	$incapable = render_seeded_gallery(
		[
			'lightbox'             => false,
			'addToMediaVisibility' => 'thumbnail',
			'addToMediaPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// An un-capable user's wrapper carries neither: a page a visitor can read never
	// holds an add-to-media credential (defence in depth, ADR-0015).
	expect( $incapable )->not->toContain( 'data-kntnt-photo-drop-nonce' );
	expect( $incapable )->not->toContain( '/media' );

	gallery_remove_tree( $basedir );
} );

test( 'a gallery with no capability-gated overlay emits no nonce', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'downloadVisibility' => 'thumbnail',
			'downloadPosition'   => 'top-left',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// Even for a capable user, a gallery whose only overlay is the SSR-only download
	// needs no REST surface, so no nonce is emitted — the credential rides only when
	// a write-path overlay is actually on.
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-nonce' );

	gallery_remove_tree( $basedir );
} );

test( 'the trash icon renders for a capable user and carries the per-image path', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'        => false,
			'trashVisibility' => 'thumbnail',
			'trashPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// A user holding the delete capability sees the trash icon, and its button carries
	// the image's collection-relative path so the view module can DELETE it (ADR-0015).
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icon--trash' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-path="a.jpg.webp"' );

	gallery_remove_tree( $basedir );
} );

test( 'the trash icon is absent for an un-capable user', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'        => false,
			'trashVisibility' => 'thumbnail',
			'trashPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Defence in depth (ADR-0015): an un-capable user sees no trash icon at all — and,
	// since no other overlay is on, no path attribute either, so a visitor's markup
	// carries no destructive control.
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__icon--trash' );
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-path' );

	gallery_remove_tree( $basedir );
} );

test( 'the wp_rest nonce and the images DELETE URL render only for a capable user', function (): void {

	$descriptor = gallery_descriptor();
	$capable    = render_seeded_gallery(
		[
			'lightbox'        => false,
			'trashVisibility' => 'thumbnail',
			'trashPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// A capable user's wrapper carries the nonce and the collection's images endpoint
	// URL, the two things the view module needs to DELETE the image. The popover copy
	// is mirrored too so the view module's confirm reads in the site language.
	expect( $capable )->toContain( 'data-kntnt-photo-drop-nonce' );
	expect( $capable )->toContain( '/wp-json/kntnt-photo-drop/v1/collections/photos/images' );
	expect( $capable )->toContain( 'data-kntnt-photo-drop-delete-prompt' );

	gallery_remove_tree( $basedir );
	$incapable = render_seeded_gallery(
		[
			'lightbox'        => false,
			'trashVisibility' => 'thumbnail',
			'trashPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// An un-capable user's wrapper carries neither: a page a visitor can read never
	// holds a delete credential (defence in depth, ADR-0015).
	expect( $incapable )->not->toContain( 'data-kntnt-photo-drop-nonce' );
	expect( $incapable )->not->toContain( 'data-kntnt-photo-drop-delete-url' );

	gallery_remove_tree( $basedir );
} );

test( 'a full-only download overlay shows in the lightbox, not on the thumbnail', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'           => true,
			'downloadVisibility' => 'full',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The full surface is the lightbox: the icon cluster sits inside the lightbox
	// overlay, not on the gallery figures. Split on the overlay boundary so the
	// thumbnail half is asserted to carry no icon.
	$overlay_start = strpos( $html, 'class="kntnt-photo-drop-lightbox"' );
	$figures_part  = $overlay_start === false ? $html : substr( $html, 0, $overlay_start );
	$overlay_part  = $overlay_start === false ? '' : substr( $html, $overlay_start );
	expect( $figures_part )->not->toContain( 'kntnt-photo-drop-gallery__icon--download' );
	expect( $overlay_part )->toContain( 'kntnt-photo-drop-gallery__icon--download' );

	gallery_remove_tree( $basedir );
} );

test( 'a full overlay requires the lightbox on: with it off nothing renders', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'              => false,
			'breadcrumbsVisibility' => 'full',
			'downloadVisibility'    => 'full',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// "Full" is the lightbox surface, so a full-only overlay with the lightbox off
	// shows nowhere — neither on the thumbnail nor in a (non-existent) lightbox.
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__breadcrumbs' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__icon--download' );
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-lightbox"' );

	gallery_remove_tree( $basedir );
} );

test( 'a justified gallery binds the init hook even with the lightbox off', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'   => 'justified',
			'lightbox' => false,
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The justified layout's last-row flags are corrected client-side, so the init
	// hook runs regardless of the lightbox flag — but the lightbox overlay and context
	// stay gated on the lightbox flag.
	expect( $html )->toContain( 'data-wp-init="callbacks.init"' );
	expect( $html )->not->toContain( 'role="dialog"' );
	expect( $html )->not->toContain( 'counterTemplate' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Overlays — the lightbox carries the breadcrumb when its visibility is full
// ---------------------------------------------------------------------------

test( 'the lightbox carries a breadcrumb element when the breadcrumb visibility includes full', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'              => true,
			'breadcrumbsVisibility' => 'both',
			'breadcrumbsPosition'   => 'top-right',
		],
		[
			[
				'path'   => 'sun_rise.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The lightbox overlay carries an empty breadcrumb element — the same overlay
	// element and anchor the gallery figures use, with its bidi-isolating `<bdi>`
	// empty — for the view module to fill per slide from the mirrored anchor data.
	expect( $html )->toMatch(
		'/<figcaption class="kntnt-photo-drop-gallery__breadcrumbs kntnt-photo-drop-lightbox__breadcrumbs'
			. ' kntnt-photo-drop-gallery__breadcrumbs--anchor-top-right[^"]*"[^>]*><bdi><\/bdi><\/figcaption>/'
	);
	expect( $html )->toContain( 'data-kntnt-photo-drop-breadcrumbs="' );

	gallery_remove_tree( $basedir );
} );

test( 'the lightbox breadcrumb mirrors the colour and typography block-support projection', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'              => true,
			'breadcrumbsVisibility' => 'full',
			'style'                 => [
				'color'      => [ 'text' => '#445566' ],
				'typography' => [ 'lineHeight' => '1.7' ],
			],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The same colour/typography projection that lands on the gallery breadcrumb
	// lands on the lightbox breadcrumb, so the enlarged overlay matches the gallery.
	$crumb_open = '<figcaption class="[^"]*kntnt-photo-drop-lightbox__breadcrumbs[^"]*"[^>]*style="[^"]*';
	expect( $html )->toMatch( '/' . $crumb_open . 'color:#445566;/' );
	expect( $html )->toMatch( '/' . $crumb_open . 'line-height:1\.7;/' );

	gallery_remove_tree( $basedir );
} );

test( 'the lightbox carries no breadcrumb element when the breadcrumb is thumbnail-only', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'              => true,
			'breadcrumbsVisibility' => 'thumbnail',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A thumbnail-only breadcrumb shows on the grid but not inside the lightbox, so
	// the lightbox figure carries no breadcrumb element.
	expect( $html )->not->toContain( 'kntnt-photo-drop-lightbox__breadcrumbs' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Editor preview — capped figures, suppressed lightbox, empty → '' (issue #32)
// ---------------------------------------------------------------------------

/**
 * Builds a list of N seeded-image descriptors with sortable, distinct names.
 *
 * @param int $count How many images to describe.
 * @return array<int,array{path:string,width:int,height:int}> The image specs.
 */
function gallery_image_specs( int $count ): array {
	$images = [];
	for ( $position = 1; $position <= $count; $position++ ) {
		$images[] = [
			'path'   => sprintf( 'img%03d.jpg.webp', $position ),
			'width'  => 800,
			'height' => 600,
		];
	}
	return $images;
}

test( 'the editor preview caps the gallery at six figures even with more images present', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'isEditorPreview' => true ],
		gallery_image_specs( 20 ),
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// Twenty images are seeded, but the preview renders only the first six figures
	// so the canvas never tries to draw a thousand-image collection.
	expect( substr_count( $html, '<figure class="kntnt-photo-drop-gallery__item' ) )->toBe( 6 );
	expect( $html )->toContain( 'img001.jpg.webp' );
	expect( $html )->toContain( 'img006.jpg.webp' );
	expect( $html )->not->toContain( 'img007.jpg.webp' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview suppresses the lightbox entirely, even with it enabled', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'isEditorPreview' => true,
			'lightbox'        => true,
		],
		gallery_image_specs( 3 ),
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// The lightbox flag reads false and no overlay, init hook, or context is
	// emitted, so clicks stay inert in the canvas — yet the anchors remain as the
	// structural fallback the frontend lightbox would otherwise enhance.
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="false"' );
	expect( $html )->not->toContain( 'data-wp-init' );
	expect( $html )->not->toContain( 'role="dialog"' );
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-lightbox"' );
	expect( $html )->not->toContain( 'counterTemplate' );
	expect( $html )->toContain( '<a class="kntnt-photo-drop-gallery__link"' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview shows thumbnail overlays inertly so it matches the published page', function (): void {

	$descriptor = gallery_descriptor( [ 'name' => 'Trip' ] );
	$html       = render_seeded_gallery(
		[
			'isEditorPreview'       => true,
			'lightbox'              => true,
			'breadcrumbsVisibility' => 'thumbnail',
			'downloadVisibility'    => 'thumbnail',
		],
		gallery_image_specs( 3 ),
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// The preview suppresses interactivity (no init hook, so clicks stay inert), but
	// thumbnail overlays still appear on the figures because they appear on the
	// frontend — the preview matches the published page's chrome.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__breadcrumbs' );
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__icon--download' );
	expect( $html )->not->toContain( 'data-wp-init' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="false"' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview suppresses the lightbox overlay even when a full overlay is set', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'isEditorPreview'    => true,
			'lightbox'           => true,
			'downloadVisibility' => 'full',
		],
		gallery_image_specs( 3 ),
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// The preview emits no lightbox overlay at all (clicks stay inert), so a
	// full-only overlay has no lightbox to attach to and the figures carry no icon.
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-lightbox"' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__icon--download' );
	expect( $html )->not->toContain( 'data-wp-init' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview of an imageless collection renders an empty string for its placeholders', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'isEditorPreview' => true ],
		[],
		$descriptor,
		can_edit: true,
		basedir_out: $basedir,
	);

	// In the preview the empty case is an empty response, not the frontend notice,
	// so the editor's own grey placeholders stand in for the gallery.
	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview of a dangling collection renders an empty string, not the notice', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: true );

	$html = Render_Gallery::render(
		[
			'collection'      => 'ghost',
			'isEditorPreview' => true,
		],
		'',
		gallery_block_stub(),
	);

	// A dangling slug in preview mode yields the empty placeholder response rather
	// than the editor notice the frontend would show a logged-in editor.
	expect( $html )->toBe( '' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery--notice' );

	gallery_remove_tree( $basedir );
} );

test( 'the frontend render is unaffected by the preview cap: all images and the lightbox remain', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		gallery_image_specs( 20 ),
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Without the preview flag the full set renders and the lightbox is wired, so
	// the cap and suppression genuinely never leak into a frontend request.
	expect( substr_count( $html, '<figure class="kntnt-photo-drop-gallery__item' ) )->toBe( 20 );
	expect( $html )->toContain( 'img020.jpg.webp' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="true"' );
	expect( $html )->toContain( 'class="kntnt-photo-drop-lightbox"' );

	gallery_remove_tree( $basedir );
} );

test( 'a dangling collection on the frontend still shows the editor notice (preview flag absent)', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: true );

	$html = Render_Gallery::render( [ 'collection' => 'ghost' ], '', gallery_block_stub() );

	// The frontend (non-preview) path is unchanged: a user who can edit still sees
	// the broken-reference notice on the live page.
	expect( $html )->toContain( 'kntnt-photo-drop-gallery--notice' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// CSS injection — bespoke style values are strictly shape-validated (F3)
// ---------------------------------------------------------------------------

test( 'a malicious icon size falls back to the default length', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'lightbox'           => false,
			'downloadVisibility' => 'thumbnail',
			'iconSize'           => '2rem;position:fixed;inset:0;z-index:99999',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// `iconSize` is the one bespoke length the overlays carry into an inline style, so
	// a hostile value cannot inject `position:fixed`; it falls back to the 2rem default.
	expect( $html )->not->toContain( 'position:fixed' );
	expect( $html )->toContain( '--kntnt-photo-drop-icon-size:2rem' );

	gallery_remove_tree( $basedir );
} );

test( 'a malicious minimum column width falls back to the default length', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'             => 'grid',
			'minimumColumnWidth' => '300px;position:fixed;inset:0',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The injected declaration is stripped by falling back to the 320px default.
	expect( $html )->not->toContain( 'position:fixed' );
	expect( $html )->toContain( '--kntnt-photo-drop-min-column:320px' );

	gallery_remove_tree( $basedir );
} );

test( 'a malicious blockGap falls back to the default gap', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout' => 'grid',
			'style'  => [ 'spacing' => [ 'blockGap' => '20px;position:fixed;inset:0' ] ],
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->not->toContain( 'position:fixed' );
	expect( $html )->toContain( '--kntnt-photo-drop-gap:12px' );

	gallery_remove_tree( $basedir );
} );

test( 'a malicious aspect ratio falls back to the stored per-image ratio', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'      => 'grid',
			'aspectRatio' => '1;position:fixed;inset:0',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A malformed ratio is dropped (empty), so the figure uses the image's own
	// stored ratio and no declaration is injected.
	expect( $html )->not->toContain( 'position:fixed' );
	expect( $html )->toContain( 'aspect-ratio:800 / 600' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Justified packing accepts rem/em gaps (F6)
// ---------------------------------------------------------------------------

test( 'the justified packing accepts a rem blockGap (converted at 16px) rather than the fallback', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'layout'          => 'justified',
			'targetRowHeight' => 200,
			'style'           => [ 'spacing' => [ 'blockGap' => '1rem' ] ],
		],
		[
			[
				'path'   => 'wide.jpg.webp',
				'width'  => 300,
				'height' => 200,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The gap variable still carries the rem length verbatim, and the packing math
	// accepts it (1rem → 16px) rather than silently using the 12px fallback. A 3:2
	// image at a 200px row height still bases at 300px.
	expect( $html )->toContain( '--kntnt-photo-drop-gap:1rem' );
	expect( $html )->toContain( 'flex-basis:300px' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Slideshow — wrapper flags, button, overlay, anchor (ADR-0009)
// ---------------------------------------------------------------------------

test( 'the default render carries no slideshow markup at all', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Off is the default: no button, no overlay, no wrapper flags.
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__slideshow-button' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-slideshow' );
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-slideshow-mode' );

	gallery_remove_tree( $basedir );
} );

test( 'button mode emits the hidden default-labelled button, the wrapper flags, and the overlay', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'slideshow' => 'button' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The quiet button ships hidden (the view module reveals it once the
	// slideshow is wired) with the translated default label.
	expect( $html )->toContain(
		'<button type="button" class="kntnt-photo-drop-gallery__slideshow-button" hidden>Slideshow</button>'
	);

	// The wrapper carries the mode and the documented default seconds.
	expect( $html )->toContain( 'data-kntnt-photo-drop-slideshow-mode="button"' );
	expect( $html )->toContain( 'data-kntnt-photo-drop-slideshow-seconds="5"' );

	// The overlay holds the two stacked slide images, the close control, and —
	// with the caption content at its "none" default — no caption element.
	expect( $html )->toContain( 'class="kntnt-photo-drop-slideshow"' );
	expect( substr_count( $html, 'kntnt-photo-drop-slideshow__image' ) )->toBe( 2 );
	expect( $html )->toContain( 'kntnt-photo-drop-slideshow__close' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-slideshow__caption' );

	gallery_remove_tree( $basedir );
} );

test( 'the button label attribute replaces the default label', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'slideshow'            => 'button',
			'slideshowButtonLabel' => 'Bildspel',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( '>Bildspel</button>' );

	gallery_remove_tree( $basedir );
} );

test( 'custom mode emits the overlay and flags but no built-in button', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'slideshow' => 'custom' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'data-kntnt-photo-drop-slideshow-mode="custom"' );
	expect( $html )->toContain( 'class="kntnt-photo-drop-slideshow"' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-gallery__slideshow-button' );

	gallery_remove_tree( $basedir );
} );

test( 'an unrecognised slideshow mode renders as off', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[ 'slideshow' => 'autoplay' ],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->not->toContain( 'data-kntnt-photo-drop-slideshow-mode' );
	expect( $html )->not->toContain( 'kntnt-photo-drop-slideshow' );

	gallery_remove_tree( $basedir );
} );

test( 'the per-slide seconds are clamped to at least one and default when malformed', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'slideshow'        => 'custom',
			'slideshowSeconds' => 0,
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// Zero clamps to the one-second floor.
	expect( $html )->toContain( 'data-kntnt-photo-drop-slideshow-seconds="1"' );

	gallery_remove_tree( $basedir );

	$html = render_seeded_gallery(
		[
			'slideshow'        => 'custom',
			'slideshowSeconds' => 'fast',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A malformed value falls back to the documented default.
	expect( $html )->toContain( 'data-kntnt-photo-drop-slideshow-seconds="5"' );

	gallery_remove_tree( $basedir );
} );

test( 'the editor preview shows the button visible but wires no slideshow', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'slideshow'       => 'button',
			'isEditorPreview' => true,
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The builder sees the button's placement (visible, inert), but the preview
	// carries no playback chrome: no overlay and no wrapper flags.
	expect( $html )->toContain(
		'<button type="button" class="kntnt-photo-drop-gallery__slideshow-button">Slideshow</button>'
	);
	expect( $html )->not->toContain( 'data-kntnt-photo-drop-slideshow-mode' );
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-slideshow"' );

	gallery_remove_tree( $basedir );
} );

test( 'the slideshow shows no overlays — no breadcrumb, no icons — even when both are configured', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'slideshow'             => 'button',
			'breadcrumbsVisibility' => 'both',
			'downloadVisibility'    => 'both',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// The slideshow is overlay-free (ADR-0009, ADR-0015): its overlay carries neither
	// a breadcrumb element nor an icon cluster, even though both overlays are on for
	// the grid and the lightbox. Split on the slideshow boundary so the assertion is
	// scoped to the slideshow overlay, not the gallery figures.
	$slideshow_start = strpos( $html, 'class="kntnt-photo-drop-slideshow"' );
	$slideshow_part  = $slideshow_start === false ? '' : substr( $html, $slideshow_start );
	expect( $slideshow_part )->not->toBe( '' );
	expect( $slideshow_part )->not->toContain( 'kntnt-photo-drop-slideshow__breadcrumbs' );
	expect( $slideshow_part )->not->toContain( 'kntnt-photo-drop-gallery__breadcrumbs' );
	expect( $slideshow_part )->not->toContain( 'kntnt-photo-drop-gallery__icon' );

	gallery_remove_tree( $basedir );
} );

test( 'the block HTML anchor is mirrored onto the wrapper id', function (): void {

	$descriptor = gallery_descriptor();
	$html       = render_seeded_gallery(
		[
			'slideshow' => 'custom',
			'anchor'    => 'field-day',
		],
		[
			[
				'path'   => 'a.jpg.webp',
				'width'  => 800,
				'height' => 600,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'id="field-day"' );

	gallery_remove_tree( $basedir );
} );
