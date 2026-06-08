<?php
/**
 * Server-side render for the Photo Gallery block — the public viewing surface.
 *
 * The Gallery is a public, server-rendered view of one collection: all images
 * under an editor-set start path, flattened into one ordered set with no
 * in-gallery folder navigation (ADR-0005). This handler owns the whole render.
 * It resolves the collection from its slug, validates the start path **once**
 * against the collection root (there is no visitor path parameter, so no
 * per-request traversal surface), walks the tree through the self-healing
 * per-folder indexes, and emits a `<figure>` per image carrying the stored
 * dimensions (zero layout shift), a responsive `srcset` of every thumbnail width
 * plus the main (so the browser never upscales a thumbnail), a `loading="lazy"`
 * thumbnail wrapped in `<a href="<main>.webp">` (the no-JS fallback and the clean
 * hook the Interactivity-API lightbox upgrades), and an optional caption. Two
 * layouts are supported: mode A is core's Grid layout plus a bespoke
 * aspect-ratio/fit, mode B is bespoke justified rows. A dangling or empty
 * collection renders nothing for the public and an editor-only notice for a
 * logged-in editor. The gallery needs no REST — it is pure SSR plus the view
 * module.
 *
 * The justified-row math, the srcset assembly, the caption assembly, and the URL
 * arithmetic live in the pure helper classes beside this one, so the load-bearing
 * logic is unit-testable without a browser; this class is the orchestration and
 * the escaping boundary.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Renders the Photo Gallery block's front-end markup.
 *
 * The single public method `render()` matches the dynamic-block render-callback
 * contract. Collaborators are resolved per render because the filesystem is the
 * source of truth: a fresh `Repository` and a fresh `Index_Store` reflect the
 * directory as it is at request time, and the indexes self-heal in the walk.
 *
 * @since 0.6.0
 */
final class Render_Gallery {

	/**
	 * The grid-layout token (mode A): core Grid plus bespoke aspect-ratio/fit.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	private const LAYOUT_GRID = 'grid';

	/**
	 * The justified-layout token (mode B): bespoke justified rows.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	private const LAYOUT_JUSTIFIED = 'justified';

	/**
	 * Returns the gallery's front-end HTML, or an empty string when nothing renders.
	 *
	 * Resolves the collection and reads its descriptor; an empty or dangling
	 * reference renders nothing for the public and an editor-only notice for a
	 * logged-in editor (the public never learns a collection is gone). Validates
	 * the start path once against the root, walks the tree, and — when there is at
	 * least one image — emits the gallery markup. An empty walk (a valid but
	 * imageless start path) also yields the editor notice / public nothing.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes Block attributes (see docs/blocks.md).
	 * @param string              $content    Inner block HTML (unused — no inner blocks).
	 * @param \WP_Block           $block      Block instance (unused).
	 * @return string Escaped HTML for the block, or '' when nothing should render.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

		// Resolve the selected slug to a real collection; an empty or dangling
		// reference is the "nothing for the public, a notice for an editor" case.
		$slug       = self::read_string( $attributes, 'collection' );
		$repository = new Repository();
		$root       = $slug === '' ? null : $repository->resolve_slug( $slug );
		if ( $root === null ) {
			return self::dangling_output();
		}

		// Read the descriptor for the thumbnail widths the srcset needs and the
		// display name a breadcrumb caption may prefix; an unreadable descriptor is
		// a degraded collection we decline to render rather than guess at.
		$descriptor = Descriptor::read( $root );
		if ( $descriptor === null ) {
			return self::dangling_output();
		}

		// Validate the editor-set start path once against the collection root via
		// the same guard the upload path uses. Any request-time path input is
		// ignored — the start path is a stored attribute, not a query parameter
		// (ADR-0005) — so the gallery has no per-request traversal surface.
		$start_path = self::resolve_start_path( $root, self::read_string( $attributes, 'startPath' ) );
		if ( $start_path === null ) {
			return self::dangling_output();
		}

		// Walk the tree (recursive or single-folder) into the flattened, ordered
		// image list; an empty result renders as the dangling case so an imageless
		// gallery shows the editor a notice rather than an empty frame.
		$recursive = self::read_bool( $attributes, 'recursive', true );
		$order     = self::read_string( $attributes, 'order' ) === Gallery_Walker::ORDER_DESC
			? Gallery_Walker::ORDER_DESC
			: Gallery_Walker::ORDER_ASC;
		$walker = new Gallery_Walker( new Index_Store() );
		$items  = $walker->walk( $root, self::relative_to_root( $root, $start_path ), $recursive, $order );
		if ( $items === [] ) {
			return self::dangling_output();
		}

		return self::render_gallery( $attributes, $items, $descriptor, $slug, $root );

	}

	/**
	 * Assembles the gallery wrapper and its figures from the walked image list.
	 *
	 * Resolves the collection's base URL once, builds each image's figure (with its
	 * srcset, dimensions, lazy thumbnail, anchor fallback, and caption), and wraps
	 * the figures in a layout-appropriate container: core's Grid layout for mode A
	 * or the bespoke justified-rows container for mode B. The wrapper carries the
	 * standard block-supports attributes plus a lightbox flag the view module reads.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @param array<int,Gallery_Item> $items      The flattened, ordered images.
	 * @param Descriptor              $descriptor The collection's contract and name.
	 * @param string                  $slug       The collection slug.
	 * @param string                  $root       The absolute collection root.
	 * @return string The gallery HTML.
	 */
	private static function render_gallery(
		array $attributes,
		array $items,
		Descriptor $descriptor,
		string $slug,
		string $root,
	): string {

		// Resolve the collection's base URL once; every figure's src/srcset/anchor
		// is built from it plus the image's relative path.
		$base_url = self::collection_url( $root, $slug );

		// Read the caption settings once — they apply identically to every figure.
		$caption = self::caption_settings( $attributes );

		// Choose the layout and build the figures accordingly: justified rows need
		// per-image flex math, the grid needs only the per-image aspect ratio.
		$layout = self::read_string( $attributes, 'layout' ) === self::LAYOUT_JUSTIFIED
			? self::LAYOUT_JUSTIFIED
			: self::LAYOUT_GRID;
		$figures = $layout === self::LAYOUT_JUSTIFIED
			? self::justified_figures( $items, $descriptor, $base_url, $caption, $attributes )
			: self::grid_figures( $items, $descriptor, $base_url, $caption, $attributes );

		return self::wrap( $attributes, $layout, $figures );

	}

	/**
	 * Builds the figures for the uniform-grid layout (mode A).
	 *
	 * Each figure carries the image's stored dimensions and an `aspect-ratio` (the
	 * stored ratio by default, or the block's fixed ratio) so the grid cells never
	 * shift on load, plus the `object-fit` the block's image-fit attribute sets.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int,Gallery_Item> $items      The images.
	 * @param Descriptor              $descriptor The collection contract.
	 * @param string                  $base_url   The collection base URL.
	 * @param Caption_Settings        $caption    The resolved caption settings.
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function grid_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		array $attributes,
	): string {

		// A fixed block aspect-ratio overrides the per-image ratio for every cell;
		// an empty value keeps each image's own stored ratio (zero layout shift).
		$fixed_ratio = self::read_string( $attributes, 'aspectRatio' );
		$image_fit   = self::read_string( $attributes, 'imageFit' ) === 'contain' ? 'contain' : 'cover';

		// Build one figure per image, giving each a wrapper style that fixes its
		// aspect ratio and the object-fit so the grid cell is stable before load.
		$markup = '';
		foreach ( $items as $item ) {
			$ratio   = $fixed_ratio !== '' ? $fixed_ratio : $item->width . ' / ' . $item->height;
			$style   = sprintf( 'aspect-ratio:%s;--kntnt-photo-drop-fit:%s;', $ratio, $image_fit );
			$markup .= self::figure(
				$item,
				$descriptor,
				$base_url,
				$caption,
				$style,
				'kntnt-photo-drop-gallery__item--grid',
			);
		}

		return $markup;

	}

	/**
	 * Builds the figures for the justified-rows layout (mode B).
	 *
	 * Runs the pure justified-layout math to get each image's `flex-grow` and
	 * `flex-basis`, then emits a figure per image carrying those as inline flex
	 * properties. Images in the final row get a zero grow so the incomplete row is
	 * left-aligned rather than stretched.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int,Gallery_Item> $items      The images.
	 * @param Descriptor              $descriptor The collection contract.
	 * @param string                  $base_url   The collection base URL.
	 * @param Caption_Settings        $caption    The resolved caption settings.
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function justified_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		array $attributes,
	): string {

		// Compute the per-image flex pair from the stored dimensions and the target
		// row height; the gap only affects how rows are packed for last-row detection.
		$dimensions = array_map(
			static fn ( Gallery_Item $item ): array => [
				'width'  => $item->width,
				'height' => $item->height,
			],
			$items,
		);
		$row_height = self::read_int( $attributes, 'targetRowHeight', 240 );
		$gap        = self::pixels( self::read_string( $attributes, 'blockGap' ), 12 );
		$flex       = Justified_Layout::compute( $dimensions, $row_height, $gap );

		// Emit one figure per image, applying its flex-grow / flex-basis; the final
		// row's images get grow 0 so they keep their natural width and left-align.
		$markup = '';
		foreach ( $items as $position => $item ) {
			$descriptor_flex = $flex[ $position ] ?? [
				'grow'     => 1.0,
				'basis'    => (float) $row_height,
				'last_row' => false,
			];
			$grow            = $descriptor_flex['last_row'] ? 0 : self::format_float( $descriptor_flex['grow'] );
			$style           = sprintf(
				'flex-grow:%s;flex-basis:%spx;height:%dpx;',
				$grow,
				self::format_float( $descriptor_flex['basis'] ),
				$row_height,
			);
			$markup .= self::figure(
				$item,
				$descriptor,
				$base_url,
				$caption,
				$style,
				'kntnt-photo-drop-gallery__item--justified',
			);
		}

		return $markup;

	}

	/**
	 * Builds one `<figure>` for an image, with srcset, anchor fallback, and caption.
	 *
	 * The thumbnail `<img>` carries the stored `width`/`height` and a responsive
	 * `srcset`/`sizes` (every thumbnail width plus the main, so the browser never
	 * upscales a thumbnail), is lazy-loaded, and is wrapped in an `<a>` to the main
	 * image — the no-JS fallback and the element the lightbox upgrades. The caption,
	 * when any, is placed above, below, or as an anchored overlay per the settings.
	 * Every URL and attribute is escaped at the point of output.
	 *
	 * @since 0.6.0
	 *
	 * @param Gallery_Item     $item       The image.
	 * @param Descriptor       $descriptor The collection contract.
	 * @param string           $base_url   The collection base URL.
	 * @param Caption_Settings $caption    The resolved caption settings.
	 * @param string           $item_style The inline style for the figure (layout-specific).
	 * @param string           $item_class The layout-specific figure class.
	 * @return string The figure markup.
	 */
	private static function figure(
		Gallery_Item $item,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		string $item_style,
		string $item_class,
	): string {

		// Build the main URL and the responsive srcset candidates (each thumbnail
		// width plus the main, at real widths) for this image's relative path.
		$relative   = $item->relative_path();
		$main_url   = Image_Url::main( $base_url, $relative );
		$candidates = Srcset_Builder::candidates(
			$item->width,
			$descriptor->thumbnail_widths,
			$main_url,
			static fn ( int $width ): string => Image_Url::thumbnail( $base_url, $relative, $width ),
		);
		$srcset = Srcset_Builder::to_attribute( $candidates );

		// Pick the smallest candidate as the <img> src (a sensible default the
		// srcset refines) and derive a sizes hint from the largest candidate width.
		$smallest  = $candidates[0]['url'] ?? $main_url;
		$alt       = Caption_Builder::build( $relative, Caption_Builder::CONTENT_FILENAME, true, false, '', '' );
		$caption_html = self::caption_html( $relative, $caption, $descriptor->name );

		// Compose the lazy, dimensioned <img>, wrapped in an <a href> to the main
		// image — the no-JS fallback and the lightbox's upgrade hook. The figure
		// carries the layout-specific class and inline style; the data attribute
		// hands the main URL to the lightbox without re-parsing the href.
		$image = sprintf(
			'<img class="kntnt-photo-drop-gallery__image" src="%1$s" srcset="%2$s" sizes="100vw"'
				. ' width="%3$d" height="%4$d" loading="lazy" decoding="async" alt="%5$s" />',
			esc_url( $smallest ),
			esc_attr( $srcset ),
			$item->width,
			$item->height,
			esc_attr( $alt ),
		);
		$link = sprintf(
			'<a class="kntnt-photo-drop-gallery__link" href="%1$s" data-kntnt-photo-drop-full="%1$s">%2$s</a>',
			esc_url( $main_url ),
			$image,
		);

		// Order the caption relative to the image by its position: above goes before
		// the link, overlay and under go after (overlay is absolutely placed by CSS).
		$before = $caption->position === 'above' ? $caption_html : '';
		$after  = $caption->position === 'above' ? '' : $caption_html;

		return sprintf(
			'<figure class="kntnt-photo-drop-gallery__item %1$s" style="%2$s">%3$s%4$s%5$s</figure>',
			esc_attr( $item_class ),
			esc_attr( $item_style ),
			$before,
			$link,
			$after,
		);

	}

	/**
	 * Builds the caption element for one image, or `''` when no caption is wanted.
	 *
	 * Delegates the text assembly to the pure `Caption_Builder`, then wraps the
	 * escaped text in a `<figcaption>` whose class encodes the position and whose
	 * inline style carries the overlay anchor, background, and text colour when the
	 * position is `overlay`. The `none` content (and an empty assembled string)
	 * yields no element at all.
	 *
	 * @since 0.6.0
	 *
	 * @param string           $relative_path   The image path relative to the root.
	 * @param Caption_Settings $caption         The resolved caption settings.
	 * @param string           $collection_name The collection display name.
	 * @return string The figcaption markup, or '' when none.
	 */
	private static function caption_html(
		string $relative_path,
		Caption_Settings $caption,
		string $collection_name,
	): string {

		// Assemble the caption text from the path; an empty result (content "none"
		// or an empty breadcrumb) means no caption element is emitted at all.
		$text = Caption_Builder::build(
			$relative_path,
			$caption->content,
			$caption->humanize,
			$caption->include_name,
			$caption->separator,
			$collection_name,
		);
		if ( $text === '' ) {
			return '';
		}

		// Build the position-specific class and, for an overlay, the inline style
		// carrying the nine-point anchor plus the optional background and text colour.
		$classes = 'kntnt-photo-drop-gallery__caption kntnt-photo-drop-gallery__caption--' . $caption->position;
		$style   = '';
		if ( $caption->position === 'overlay' ) {
			$classes .= ' kntnt-photo-drop-gallery__caption--anchor-' . $caption->anchor;
			$style    = self::overlay_style( $caption->background, $caption->text_color );
		}

		return sprintf(
			'<figcaption class="%1$s" style="%2$s">%3$s</figcaption>',
			esc_attr( $classes ),
			esc_attr( $style ),
			esc_html( $text ),
		);

	}

	/**
	 * Wraps the figures in the layout container plus the block-supports wrapper.
	 *
	 * Mode A applies core's Grid layout via the `minimumColumnWidth` and `gap`
	 * style variables on the inner container; mode B applies the justified flex
	 * container. The outer wrapper is core's block-supports wrapper (alignment,
	 * colour, typography, spacing, anchor) plus the project class and a lightbox
	 * flag and the Interactivity directive the view module reads.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $layout     The resolved layout token.
	 * @param string              $figures    The concatenated figure markup.
	 * @return string The full gallery markup.
	 */
	private static function wrap( array $attributes, string $layout, string $figures ): string {

		// Build the inner container's style from the gap (both layouts) and, for the
		// grid, the minimum column width that drives core's auto-fill grid.
		$gap = self::read_string( $attributes, 'blockGap' );
		$gap = $gap === '' ? '12px' : $gap;
		if ( $layout === self::LAYOUT_JUSTIFIED ) {
			$container_class = 'kntnt-photo-drop-gallery__layout kntnt-photo-drop-gallery__layout--justified';
			$container_style = sprintf( '--kntnt-photo-drop-gap:%s;', $gap );
		} else {
			$min_column      = self::read_string( $attributes, 'minimumColumnWidth' );
			$min_column      = $min_column === '' ? '320px' : $min_column;
			$container_class = 'kntnt-photo-drop-gallery__layout kntnt-photo-drop-gallery__layout--grid';
			$container_style = sprintf(
				'--kntnt-photo-drop-gap:%s;--kntnt-photo-drop-min-column:%s;',
				$gap,
				$min_column,
			);
		}

		// Compose the block-supports wrapper with the project class and a lightbox
		// flag the view module reads, and bind the Interactivity namespace so the
		// lightbox (#11) mounts without further server wiring.
		$lightbox = self::read_bool( $attributes, 'enableLightbox', true ) ? 'true' : 'false';
		$wrapper  = get_block_wrapper_attributes(
			[
				'class'                          => 'kntnt-photo-drop-gallery',
				'data-wp-interactive'            => 'kntnt-photo-drop/gallery',
				'data-kntnt-photo-drop-lightbox' => $lightbox,
			],
		);

		return sprintf(
			'<div %1$s><div class="%2$s" style="%3$s">%4$s</div></div>',
			$wrapper,
			esc_attr( $container_class ),
			esc_attr( $container_style ),
			$figures,
		);

	}

	/**
	 * Validates the editor-set start path once against the collection root.
	 *
	 * Confines the stored start path with the same `Path_Guard` the upload path
	 * uses, so a malformed or escaping path is rejected. The path is the editor's
	 * stored attribute, never a request parameter, so this runs once per render and
	 * the gallery carries no per-request traversal surface (ADR-0005). Returns the
	 * confined absolute path, or `null` when the path is rejected or not a directory.
	 *
	 * @since 0.6.0
	 *
	 * @param string $root       The absolute collection root.
	 * @param string $start_path The editor-set start path relative to the root.
	 * @return string|null The confined absolute start directory, or null when invalid.
	 */
	private static function resolve_start_path( string $root, string $start_path ): ?string {

		// Anchor the guard at the root and resolve the stored path; a hostile or
		// escaping path yields null, which the caller maps to the dangling output.
		$guard    = new Path_Guard( $root );
		$resolved = $guard->resolve( $start_path );
		if ( $resolved === null || ! is_dir( $resolved ) ) {
			return null;
		}

		return $resolved;

	}

	/**
	 * Returns the start directory's path relative to the collection root.
	 *
	 * The walker takes a root-relative start path; the guard returns an absolute
	 * one, so this strips the root prefix back off. A start path equal to the root
	 * yields the empty string (the root itself).
	 *
	 * @since 0.6.0
	 *
	 * @param string $root      The absolute collection root.
	 * @param string $absolute  The confined absolute start directory.
	 * @return string The start directory relative to the root; `''` at the root.
	 */
	private static function relative_to_root( string $root, string $absolute ): string {

		// Strip the canonical root prefix; the guard already confined the path
		// inside the root, so what remains is the root-relative start directory.
		$canonical_root = realpath( $root );
		$prefix         = $canonical_root === false ? rtrim( $root, '/' ) : $canonical_root;

		return trim( substr( $absolute, strlen( $prefix ) ), '/' );

	}

	/**
	 * Resolves the absolute URL of a collection's root directory.
	 *
	 * Images are served directly by URL (ADR-0001), so the base URL mirrors the
	 * collection's on-disk location: the path of the collection relative to the
	 * uploads basedir, appended to the uploads baseurl. This keeps the URL correct
	 * even when the `kntnt_photo_drop_root` filter relocates the root, as long as
	 * the root stays under (or maps onto) the web-served uploads directory.
	 *
	 * @since 0.6.0
	 *
	 * @param string $root The absolute collection root.
	 * @param string $slug The collection slug.
	 * @return string The collection root URL, without a trailing slash.
	 */
	private static function collection_url( string $root, string $slug ): string {

		// Map the collection's path under the uploads basedir onto the baseurl, so a
		// filtered root still resolves to the correct web URL.
		$upload  = wp_upload_dir();
		$basedir = is_string( $upload['basedir'] ?? null ) ? rtrim( $upload['basedir'], '/' ) : '';
		$baseurl = is_string( $upload['baseurl'] ?? null ) ? rtrim( $upload['baseurl'], '/' ) : '';
		$canonical_root = realpath( $root );
		$absolute       = $canonical_root === false ? rtrim( $root, '/' ) : $canonical_root;
		if ( $basedir !== '' && str_starts_with( $absolute, $basedir ) ) {
			return $baseurl . substr( $absolute, strlen( $basedir ) );
		}

		// Fall back to the default layout (baseurl/kntnt-photo-drop/<slug>) when the
		// root is not under the basedir, which a custom filter could cause.
		return $baseurl . '/kntnt-photo-drop/' . $slug;

	}

	/**
	 * Collects the caption settings from the attributes into one value object.
	 *
	 * Reads all caption attributes once so each figure reuses the same settings,
	 * narrowing the free-text enum-style attributes (content, position, anchor) to
	 * the documented values with a safe default.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return Caption_Settings The resolved caption settings.
	 */
	private static function caption_settings( array $attributes ): Caption_Settings {

		// Narrow the enum-style attributes to their allowed values, defaulting an
		// unexpected value to the safe choice; pass the free-text ones through.
		$contents = [ 'none', 'filename', 'path' ];
		$content  = self::one_of( self::read_string( $attributes, 'captionContent' ), $contents, 'none' );
		$positions = [ 'under', 'above', 'overlay' ];
		$position  = self::one_of( self::read_string( $attributes, 'captionPosition' ), $positions, 'under' );
		$anchors  = [
			'top-left',
			'top-center',
			'top-right',
			'middle-left',
			'middle-center',
			'middle-right',
			'bottom-left',
			'bottom-center',
			'bottom-right',
		];
		$anchor   = self::one_of( self::read_string( $attributes, 'captionOverlayAnchor' ), $anchors, 'bottom-left' );

		return new Caption_Settings(
			$content,
			self::read_bool( $attributes, 'captionHumanize', true ),
			self::read_bool( $attributes, 'captionIncludeCollectionName', false ),
			self::read_string( $attributes, 'captionSeparator' ),
			$position,
			$anchor,
			self::read_string( $attributes, 'captionBackground' ),
			self::read_string( $attributes, 'captionTextColor' ),
		);

	}

	/**
	 * Builds the inline style for an overlay caption's background and text colour.
	 *
	 * Both are optional free-text colour values; an empty value contributes no
	 * declaration so the stylesheet's default (or inheritance) stands. The values
	 * are emitted as CSS custom properties the stylesheet consumes, keeping the
	 * declaration list short and escapable.
	 *
	 * @since 0.6.0
	 *
	 * @param string $background The overlay background colour, or `''`.
	 * @param string $text_color The caption text colour, or `''`.
	 * @return string The inline style string (possibly empty).
	 */
	private static function overlay_style( string $background, string $text_color ): string {

		// Emit only the declarations that have a value, so an unset colour leaves the
		// stylesheet default in place rather than overriding it with an empty value.
		$style = '';
		if ( $background !== '' ) {
			$style .= '--kntnt-photo-drop-caption-bg:' . $background . ';';
		}
		if ( $text_color !== '' ) {
			$style .= '--kntnt-photo-drop-caption-color:' . $text_color . ';';
		}

		return $style;

	}

	/**
	 * Returns the editor-only notice markup, or `''` for a logged-out visitor.
	 *
	 * A dangling, empty, or imageless collection renders nothing for the public —
	 * a visitor never learns a collection is gone — but a logged-in editor sees an
	 * inline notice so the broken reference is visible while building the page.
	 *
	 * @since 0.6.0
	 *
	 * @return string The notice markup for an editor, or '' for the public.
	 */
	private static function dangling_output(): string {

		// Show the broken-reference notice only to a logged-in user who can edit;
		// the public sees nothing at all.
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$notice_class = 'kntnt-photo-drop-gallery kntnt-photo-drop-gallery--notice';
		$wrapper      = get_block_wrapper_attributes( [ 'class' => $notice_class ] );
		$notice       = esc_html__(
			// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
			'This gallery has no collection selected, or the collection has no images. Choose a collection in the block settings.',
			'kntnt-photo-drop',
		);

		return sprintf( '<div %1$s><p>%2$s</p></div>', $wrapper, $notice );

	}

	/**
	 * Reads a string attribute, sanitised, defaulting to the empty string.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $key        The attribute key.
	 * @return string The sanitised value, or '' when absent or non-string.
	 */
	private static function read_string( array $attributes, string $key ): string {
		$raw = $attributes[ $key ] ?? '';
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Reads a boolean attribute, defaulting when absent or not a bool.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $key        The attribute key.
	 * @param bool                $fallback   The value to use when absent or non-bool.
	 * @return bool The attribute value or the fallback.
	 */
	private static function read_bool( array $attributes, string $key, bool $fallback ): bool {
		$raw = $attributes[ $key ] ?? null;
		return is_bool( $raw ) ? $raw : $fallback;
	}

	/**
	 * Reads an integer attribute, defaulting when absent or not numeric.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $key        The attribute key.
	 * @param int                 $fallback   The value to use when absent or non-numeric.
	 * @return int The attribute value or the fallback.
	 */
	private static function read_int( array $attributes, string $key, int $fallback ): int {
		$raw = $attributes[ $key ] ?? null;
		return is_int( $raw ) ? $raw : ( is_numeric( $raw ) ? (int) $raw : $fallback );
	}

	/**
	 * Returns the value when it is one of the allowed set, else the fallback.
	 *
	 * @since 0.6.0
	 *
	 * @param string            $value    The candidate value.
	 * @param array<int,string> $allowed  The allowed values.
	 * @param string            $fallback The fallback when the value is not allowed.
	 * @return string The narrowed value.
	 */
	private static function one_of( string $value, array $allowed, string $fallback ): string {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Extracts a leading pixel count from a CSS length, defaulting when absent.
	 *
	 * The gap attribute is a CSS length such as `12px`; the justified math needs a
	 * plain pixel number for row packing. A non-`px` value (a `var()`, an `em`)
	 * falls back to the default so the packing still runs.
	 *
	 * @since 0.6.0
	 *
	 * @param string $length   The CSS length value.
	 * @param int    $fallback The fallback pixel count.
	 * @return int The pixel count.
	 */
	private static function pixels( string $length, int $fallback ): int {
		return preg_match( '/^(\d+)\s*px$/', trim( $length ), $matches ) === 1 ? (int) $matches[1] : $fallback;
	}

	/**
	 * Formats a float compactly for an inline CSS value.
	 *
	 * Rounds to three decimals and trims trailing zeros so the emitted style reads
	 * `1.5` rather than `1.500000`, keeping the markup small and deterministic.
	 *
	 * @since 0.6.0
	 *
	 * @param float $value The value to format.
	 * @return string The compact decimal string.
	 */
	private static function format_float( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}

}
