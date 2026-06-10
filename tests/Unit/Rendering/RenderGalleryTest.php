<?php
/**
 * Tests for the Photo Gallery render handler — `docs/testing.md`
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
 * content/position/overlay; a dangling collection renders nothing for the public
 * and a notice for an editor; and every image is wrapped in its `<a href>`
 * fallback. Only the WordPress seams are stubbed; the `Repository`,
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
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $args = [] ): string {
			$parts = [];
			foreach ( $args as $key => $value ) {
				$parts[] = sprintf( '%s="%s"', $key, $value );
			}
			return implode( ' ', $parts );
		}
	);

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
// srcset — every thumbnail width plus the main, at real pixel widths
// ---------------------------------------------------------------------------

test( 'the srcset lists every thumbnail width below the main plus the main, at real widths', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320, 640 ] );
	$html       = render_seeded_gallery(
		[],
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

	// Both thumbnail widths are below the main width, so each is a candidate at its
	// real width, and the main is always the final candidate at its own width.
	expect( $html )->toContain( '320w' );
	expect( $html )->toContain( '640w' );
	expect( $html )->toContain( '1200w' );
	expect( $html )->toContain( '/.kntnt-thumbnails/320/' );
	expect( $html )->toContain( '/.kntnt-thumbnails/640/' );

	gallery_remove_tree( $basedir );
} );

test( 'a thumbnail width at or above the main width is dropped from the srcset', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320, 2000 ] );
	$html       = render_seeded_gallery(
		[],
		[
			[
				'path'   => 'small.jpg.webp',
				'width'  => 500,
				'height' => 400,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// 320 is below the 500px main and is a candidate; 2000 is above it and would be
	// an upscale, so it is dropped — the browser never upscales a thumbnail.
	expect( $html )->toContain( '320w' );
	expect( $html )->toContain( '500w' );
	expect( $html )->not->toContain( '2000w' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// sizes — layout-aware hints, never a blanket 100vw
// ---------------------------------------------------------------------------

test( 'the grid layout derives one sizes hint from the minimum column width', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [ 320 ] );
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
			. 'https://example.test/uploads/kntnt-photo-drop/photos/.kntnt-thumbnails/320/a.jpg.webp 320w, '
			. 'https://example.test/uploads/kntnt-photo-drop/photos/a.jpg.webp 800w"'
	);

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Ordering — natural sort by full relative path, folders contiguous, asc/desc
// ---------------------------------------------------------------------------

test( 'recursive ordering is natural sort by full relative path, ascending', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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
	$descriptor            = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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
// Captions — content / position / overlay
// ---------------------------------------------------------------------------

test( 'a filename caption renders the humanised name under the image', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[
			'captionContent'  => 'filename',
			'captionPosition' => 'under',
		],
		[
			[
				'path'   => 'sun_rise.jpg.webp',
				'width'  => 100,
				'height' => 100,
			],
		],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	expect( $html )->toContain( 'kntnt-photo-drop-gallery__caption--under' );
	expect( $html )->toContain( 'sun rise' );

	gallery_remove_tree( $basedir );
} );

test( 'a path caption renders a breadcrumb with the collection name and separator', function (): void {

	$descriptor = new Descriptor( 'Holiday Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[
			'captionContent'               => 'path',
			'captionIncludeCollectionName' => true,
			'captionSeparator'             => '›',
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

	// The breadcrumb prefixes the collection name and joins humanised segments with
	// the separator.
	expect( $html )->toContain( 'Holiday Photos › day one › IMG 5' );

	gallery_remove_tree( $basedir );
} );

test( 'an overlay caption carries its anchor class and colour variables', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[
			'captionContent'       => 'filename',
			'captionPosition'      => 'overlay',
			'captionOverlayAnchor' => 'top-right',
			'captionBackground'    => 'rgba(0,0,0,0.6)',
			'captionTextColor'     => '#fff',
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

	expect( $html )->toContain( 'kntnt-photo-drop-gallery__caption--overlay' );
	expect( $html )->toContain( 'kntnt-photo-drop-gallery__caption--anchor-top-right' );
	expect( $html )->toContain( '--kntnt-photo-drop-caption-bg:rgba(0,0,0,0.6)' );
	expect( $html )->toContain( '--kntnt-photo-drop-caption-color:#fff' );

	gallery_remove_tree( $basedir );
} );

test( 'the none caption content emits no figcaption at all', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[ 'captionContent' => 'none' ],
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

	expect( $html )->not->toContain( 'figcaption' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Dangling / empty collection — nothing public, a notice for an editor
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

	expect( $html )->toContain( 'kntnt-photo-drop-gallery--notice' );
	expect( $html )->toContain( 'no collection selected' );

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

test( 'an empty but valid collection renders nothing for the public', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[],
		[],
		$descriptor,
		can_edit: false,
		basedir_out: $basedir,
	);

	// A collection that resolves but holds no images is the imageless case: the
	// public sees nothing.
	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

test( 'an empty collection attribute renders nothing for the public', function (): void {

	$basedir = fresh_gallery_basedir();
	wire_gallery_stubs( $basedir, can_edit: false );

	$html = Render_Gallery::render( [ 'collection' => '' ], '', gallery_block_stub() );

	expect( $html )->toBe( '' );

	gallery_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Lightbox — overlay + Interactivity hooks gated on enableLightbox (ADR-0007)
// ---------------------------------------------------------------------------

test( 'the lightbox is wired by default: the overlay, init hook, and flag are present', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
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

	// enableLightbox defaults true: the flag is on, the Interactivity init hook
	// and per-block context are bound, and the hidden dialog overlay is emitted
	// with its server-translated load-failure message.
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="true"' );
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

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[ 'enableLightbox' => true ],
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

test( 'with the lightbox off, no overlay or init hook is emitted but the anchors remain', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[ 'enableLightbox' => false ],
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

	// The flag is off and the grid needs no client-side correction: no enhancement
	// markup at all (no overlay, no init hook, no context), yet the anchor still
	// wraps the image so a click navigates to it. The flag attribute itself still
	// reflects the off state.
	expect( $html )->toContain( 'data-kntnt-photo-drop-lightbox="false"' );
	expect( $html )->not->toContain( 'data-wp-init' );
	expect( $html )->not->toContain( 'role="dialog"' );
	expect( $html )->not->toContain( 'class="kntnt-photo-drop-lightbox"' );
	expect( $html )->toContain( '<a class="kntnt-photo-drop-gallery__link"' );
	expect( $html )->toContain( 'href="https://example.test/uploads/kntnt-photo-drop/photos/a.jpg.webp"' );

	gallery_remove_tree( $basedir );
} );

test( 'a justified gallery binds the init hook even with the lightbox off', function (): void {

	$descriptor = new Descriptor( 'Photos', 1920, 80, [] );
	$html       = render_seeded_gallery(
		[
			'layout'         => 'justified',
			'enableLightbox' => false,
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

	// The justified layout's last-row flags are corrected client-side, so the
	// init hook must run regardless of the lightbox flag — but the lightbox
	// overlay and context stay gated on the flag.
	expect( $html )->toContain( 'data-wp-init="callbacks.init"' );
	expect( $html )->not->toContain( 'role="dialog"' );
	expect( $html )->not->toContain( 'counterTemplate' );

	gallery_remove_tree( $basedir );
} );
