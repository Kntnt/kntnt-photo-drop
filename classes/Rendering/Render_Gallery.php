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
	 * The render-time-only attribute that flags an editor-preview render.
	 *
	 * Declared in `block.json` (it must be, or the REST block-renderer endpoint —
	 * whose schema sets `additionalProperties: false` — would strip it before the
	 * preview reached this callback), but it is never written into `post_content`:
	 * its default is `false`, the editor passes `true` only on the live
	 * `ServerSideRender` `attributes` prop, and never through `setAttributes`. An
	 * attribute left at its default is not serialised into the block delimiters, so
	 * a frontend render reads `false` and neither the cap nor the lightbox
	 * suppression below can leak past the editor.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const PREVIEW_ATTRIBUTE = 'isEditorPreview';

	/**
	 * The maximum number of figures the editor preview renders.
	 *
	 * A collection can hold thousands of images; the editor must never try to
	 * render them all into the canvas. The frontend has no cap — it walks and
	 * emits the whole set.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const PREVIEW_FIGURE_CAP = 6;

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
	 * In editor-preview mode (the `isEditorPreview` render-time-only attribute the
	 * editor's `ServerSideRender` sends), the walked list is capped to the first
	 * few images and the lightbox is suppressed, so the canvas never tries to
	 * render a thousand-image collection and clicks stay inert. The flag lives only
	 * on the preview request, so the frontend render is unaffected.
	 *
	 * @since 0.6.0
	 * @since 0.5.0 Added the capped, lightbox-suppressed editor-preview mode.
	 *
	 * @param array<string,mixed> $attributes Block attributes (see docs/blocks.md).
	 * @param string              $content    Inner block HTML (unused — no inner blocks).
	 * @param \WP_Block           $block      Block instance (unused).
	 * @return string Escaped HTML for the block, or '' when nothing should render.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

		// Detect editor-preview mode up front; it both caps the figures and changes
		// the empty/dangling output to an empty string so the editor's preview shows
		// its own placeholders instead of the frontend notice.
		$is_preview = self::read_bool( $attributes, self::PREVIEW_ATTRIBUTE, false );

		// Resolve the selected slug to a real collection; an empty or dangling
		// reference is the "nothing for the public, a notice for an editor" case.
		$slug       = self::read_string( $attributes, 'collection' );
		$repository = new Repository();
		$root       = $slug === '' ? null : $repository->resolve_slug( $slug );
		if ( $root === null ) {
			return self::empty_output( $is_preview );
		}

		// Read the descriptor for the thumbnail widths the srcset needs and the
		// display name a breadcrumb caption may prefix; an unreadable descriptor is
		// a degraded collection we decline to render rather than guess at.
		$descriptor = Descriptor::read( $root );
		if ( $descriptor === null ) {
			return self::empty_output( $is_preview );
		}

		// Validate the editor-set start path once against the collection root via
		// the same guard the upload path uses. Any request-time path input is
		// ignored — the start path is a stored attribute, not a query parameter
		// (ADR-0005) — so the gallery has no per-request traversal surface.
		$start_path = self::resolve_start_path( $root, self::read_string( $attributes, 'startPath' ) );
		if ( $start_path === null ) {
			return self::empty_output( $is_preview );
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
			return self::empty_output( $is_preview );
		}

		// In editor-preview mode, keep only the first few images so the canvas never
		// renders a thousand-image collection; the frontend keeps the whole set.
		if ( $is_preview ) {
			$items = array_slice( $items, 0, self::PREVIEW_FIGURE_CAP );
		}

		return self::render_gallery( $attributes, $items, $descriptor, $slug, $root, $is_preview );

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
	 * @since 0.5.0 Added the `$is_preview` flag to suppress the lightbox in the editor.
	 *
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @param array<int,Gallery_Item> $items      The flattened, ordered images.
	 * @param Descriptor              $descriptor The collection's contract and name.
	 * @param string                  $slug       The collection slug.
	 * @param string                  $root       The absolute collection root.
	 * @param bool                    $is_preview Whether this is the capped editor preview.
	 * @return string The gallery HTML.
	 */
	private static function render_gallery(
		array $attributes,
		array $items,
		Descriptor $descriptor,
		string $slug,
		string $root,
		bool $is_preview,
	): string {

		// Resolve the collection's base URL once; every figure's src/srcset/anchor
		// is built from it plus the image's relative path.
		$base_url = self::collection_url( $root, $slug );

		// Read the caption settings once — they apply identically to every figure.
		$caption = self::caption_settings( $attributes );

		// Project the colour/typography supports onto the caption and the
		// border/shadow supports onto each image once, since both apply identically
		// to every figure (the skip-serialization values never reach the wrapper).
		$caption_support = Block_Style_Support::caption( $attributes );
		$image_support   = Block_Style_Support::image( $attributes );

		// Choose the layout and build the figures accordingly: justified rows need
		// per-image flex math, the grid needs only the per-image aspect ratio.
		$layout = self::read_string( $attributes, 'layout' ) === self::LAYOUT_JUSTIFIED
			? self::LAYOUT_JUSTIFIED
			: self::LAYOUT_GRID;
		if ( $layout === self::LAYOUT_JUSTIFIED ) {
			$figures = self::justified_figures(
				$items,
				$descriptor,
				$base_url,
				$caption,
				$caption_support,
				$image_support,
				$attributes,
			);
		} else {
			$figures = self::grid_figures(
				$items,
				$descriptor,
				$base_url,
				$caption,
				$caption_support,
				$image_support,
				$attributes,
			);
		}

		return self::wrap( $attributes, $layout, $figures, $is_preview );

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
	 * @param array<int,Gallery_Item>          $items           The images.
	 * @param Descriptor                       $descriptor      The collection contract.
	 * @param string                           $base_url        The collection base URL.
	 * @param Caption_Settings                 $caption         The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support The figcaption block-support style/class.
	 * @param array{style:string,class:string} $image_support   The image block-support style/class.
	 * @param array<string,mixed>              $attributes      The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function grid_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		array $caption_support,
		array $image_support,
		array $attributes,
	): string {

		// A fixed block aspect-ratio overrides the per-image ratio for every cell;
		// an empty value keeps each image's own stored ratio (zero layout shift).
		$fixed_ratio = self::read_string( $attributes, 'aspectRatio' );
		$image_fit   = self::read_string( $attributes, 'imageFit' ) === 'contain' ? 'contain' : 'cover';

		// Derive one sizes hint for every cell from the minimum column width: below
		// it the grid is single-column (the tile spans the viewport), above it a
		// tile renders near the minimum, so 1.5× the minimum covers wider auto-fill
		// tracks. The leading `auto` lets browsers with lazy-loading auto-sizes use
		// the real rendered width; others skip the invalid first entry — keep it first.
		$min_column = self::pixels( self::read_string( $attributes, 'minimumColumnWidth' ), 320 );
		$sizes      = sprintf( 'auto, (max-width: %dpx) 100vw, %dpx', $min_column, (int) round( $min_column * 1.5 ) );

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
				$caption_support,
				$image_support,
				$sizes,
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
	 * @param array<int,Gallery_Item>          $items           The images.
	 * @param Descriptor                       $descriptor      The collection contract.
	 * @param string                           $base_url        The collection base URL.
	 * @param Caption_Settings                 $caption         The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support The figcaption block-support style/class.
	 * @param array{style:string,class:string} $image_support   The image block-support style/class.
	 * @param array<string,mixed>              $attributes      The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function justified_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		array $caption_support,
		array $image_support,
		array $attributes,
	): string {

		// Compute the per-image flex pair from the stored dimensions and the target
		// row height; the gap only affects how rows are packed for last-row detection.
		// The gap is the block-support `blockGap`, read from the spacing support.
		$dimensions = array_map(
			static fn ( Gallery_Item $item ): array => [
				'width'  => $item->width,
				'height' => $item->height,
			],
			$items,
		);
		$row_height = self::read_int( $attributes, 'targetRowHeight', 240 );
		$gap        = self::pixels( self::block_gap( $attributes ), 12 );
		$flex       = Justified_Layout::compute( $dimensions, $row_height, $gap );

		// Emit one figure per image, applying its flex-grow / flex-basis; the final
		// row's images get grow 0 so they keep their natural width and left-align.
		// Each image's sizes hint is its natural width at the target row height —
		// the tile's rendered width up to viewports narrower than the tile itself.
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
			$tile_width      = (int) round( $descriptor_flex['basis'] );
			$sizes           = sprintf( 'auto, (max-width: %1$dpx) 100vw, %1$dpx', $tile_width );
			$markup .= self::figure(
				$item,
				$descriptor,
				$base_url,
				$caption,
				$caption_support,
				$image_support,
				$sizes,
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
	 * `srcset` (every thumbnail width plus the main, so the browser never upscales
	 * a thumbnail) with a layout-aware `sizes` hint the caller derives from the
	 * tile's rendered width — never a blanket `100vw`, which would make desktop
	 * browsers fetch the full main image for every tile. The image is lazy-loaded
	 * and wrapped in an `<a>` to the main image — the no-JS fallback and the
	 * element the lightbox upgrades; the anchor also carries the same srcset as a
	 * data attribute so the lightbox can show a responsive slide instead of
	 * forcing the full-resolution main onto every device. The border and shadow
	 * block-support panels land on the `<img>` (the core Image-block
	 * skip-serialization pattern), pre-projected into `$image_support`. The
	 * caption, when any, is always an anchored overlay inside the image
	 * (issue #33) and so follows the link. Every URL and attribute is escaped at
	 * the point of output.
	 *
	 * @since 0.7.0
	 * @since 0.2.0 Added the `$sizes` parameter and the anchor's srcset data attribute.
	 *
	 * @param Gallery_Item                     $item            The image.
	 * @param Descriptor                       $descriptor      The collection contract.
	 * @param string                           $base_url        The collection base URL.
	 * @param Caption_Settings                 $caption         The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support The figcaption block-support style/class.
	 * @param array{style:string,class:string} $image_support   The image block-support style/class.
	 * @param string                           $sizes           The layout-aware `sizes` attribute value.
	 * @param string                           $item_style      The inline style for the figure (layout-specific).
	 * @param string                           $item_class      The layout-specific figure class.
	 * @return string The figure markup.
	 */
	private static function figure(
		Gallery_Item $item,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		array $caption_support,
		array $image_support,
		string $sizes,
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
		$caption_html = self::caption_html( $relative, $caption, $descriptor->name, $caption_support );

		// Compose the lazy, dimensioned <img>, carrying the border/shadow block
		// supports, and wrap it in an <a href> to the main image — the no-JS fallback
		// and the lightbox's upgrade hook. The image class folds in any preset
		// classnames the border-colour panel contributed; the inline style carries
		// the panels' declarations. The data attributes hand the main URL and the
		// srcset to the lightbox without re-parsing the href or the thumbnail markup.
		$image_class = 'kntnt-photo-drop-gallery__image';
		if ( $image_support['class'] !== '' ) {
			$image_class .= ' ' . $image_support['class'];
		}
		$image = sprintf(
			'<img class="%1$s" style="%2$s" src="%3$s" srcset="%4$s" sizes="%5$s"'
				. ' width="%6$d" height="%7$d" loading="lazy" decoding="async" alt="%8$s" />',
			esc_attr( $image_class ),
			esc_attr( $image_support['style'] ),
			esc_url( $smallest ),
			esc_attr( $srcset ),
			esc_attr( $sizes ),
			$item->width,
			$item->height,
			esc_attr( $alt ),
		);
		$link = sprintf(
			'<a class="kntnt-photo-drop-gallery__link" href="%1$s" data-kntnt-photo-drop-full="%1$s"'
				. ' data-kntnt-photo-drop-srcset="%2$s">%3$s</a>',
			esc_url( $main_url ),
			esc_attr( $srcset ),
			$image,
		);

		// The caption is always an anchored overlay over the image, so it follows the
		// link inside the figure and is positioned absolutely by its anchor class.
		return sprintf(
			'<figure class="kntnt-photo-drop-gallery__item %1$s" style="%2$s">%3$s%4$s</figure>',
			esc_attr( $item_class ),
			esc_attr( $item_style ),
			$link,
			$caption_html,
		);

	}

	/**
	 * Builds the overlay caption element for one image, or `''` when none is wanted.
	 *
	 * Delegates the text assembly to the pure `Caption_Builder`, then wraps the
	 * escaped text in a `<figcaption>`. Captions are always an anchored overlay
	 * inside the image (issue #33), so the class always carries the nine-point
	 * anchor variant. The figcaption's colour and typography arrive from the
	 * colour/typography block-support panels, pre-projected by
	 * `Block_Style_Support::caption()` into the `$support` style/class pair (the
	 * core Image-block skip-serialization pattern), so this method appends them
	 * verbatim rather than reading any bespoke colour attribute. The `none` content
	 * (and an empty assembled string) yields no element at all.
	 *
	 * @since 0.7.0
	 *
	 * @param string                           $relative_path   The image path relative to the root.
	 * @param Caption_Settings                 $caption         The resolved caption settings.
	 * @param string                           $collection_name The collection display name.
	 * @param array{style:string,class:string} $support        The figcaption block-support style and classes.
	 * @return string The figcaption markup, or '' when none.
	 */
	private static function caption_html(
		string $relative_path,
		Caption_Settings $caption,
		string $collection_name,
		array $support,
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

		// Compose the overlay's classes — the base, the nine-point anchor, and the
		// preset classnames the colour/typography panels contributed — plus the
		// inline declarations the same panels produced (border/shadow go to the image).
		$classes = 'kntnt-photo-drop-gallery__caption kntnt-photo-drop-gallery__caption--anchor-' . $caption->anchor;
		if ( $support['class'] !== '' ) {
			$classes .= ' ' . $support['class'];
		}

		return sprintf(
			'<figcaption class="%1$s" style="%2$s">%3$s</figcaption>',
			esc_attr( $classes ),
			esc_attr( $support['style'] ),
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
	 * flag and the Interactivity directives the view module reads. The
	 * Interactivity `init` hook is bound whenever the view module has work to do:
	 * when the lightbox is enabled, and for the justified layout regardless (the
	 * view module corrects the server's assumed-width last-row flags against the
	 * real container). The per-block context (the counter announcement template)
	 * and the hidden overlay are appended only when the lightbox is enabled, so a
	 * lightbox-off gallery emits no lightbox markup and the anchors navigate.
	 *
	 * The editor preview suppresses the lightbox unconditionally: the flag reads
	 * `false`, no overlay/context/init is emitted, and clicks stay inert in the
	 * canvas. The justified layout still binds its `init` hook in the preview so
	 * the last-row correction runs, but it never emits lightbox chrome there.
	 *
	 * @since 0.6.0
	 * @since 0.2.0 The `init` hook is also bound for the justified layout with the lightbox off.
	 * @since 0.5.0 Added the `$is_preview` flag that suppresses the lightbox in the editor.
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $layout     The resolved layout token.
	 * @param string              $figures    The concatenated figure markup.
	 * @param bool                $is_preview Whether this is the capped editor preview.
	 * @return string The full gallery markup.
	 */
	private static function wrap( array $attributes, string $layout, string $figures, bool $is_preview ): string {

		// Build the inner container's style from the gap (both layouts) and, for the
		// grid, the minimum column width that drives core's auto-fill grid. The gap
		// is the block-support `blockGap`, read from the spacing support and applied
		// to both layout containers (issue #33).
		$gap = self::block_gap( $attributes );
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

		// Compose the block-supports wrapper: the project class, the Interactivity
		// namespace, and the lightbox flag the view module reads. The lightbox is
		// suppressed in the editor preview so clicks stay inert; otherwise the
		// `init` hook is bound when there is anything to enhance — the lightbox, or
		// the justified layout's client-side last-row correction — and the per-block
		// context exists only for the lightbox.
		$enabled        = ! $is_preview && self::read_bool( $attributes, 'enableLightbox', true );
		$wrapper_attrs  = [
			'class'                          => 'kntnt-photo-drop-gallery',
			'data-wp-interactive'            => 'kntnt-photo-drop/gallery',
			'data-kntnt-photo-drop-lightbox' => $enabled ? 'true' : 'false',
		];
		if ( $enabled || $layout === self::LAYOUT_JUSTIFIED ) {
			$wrapper_attrs['data-wp-init'] = 'callbacks.init';
		}
		if ( $enabled ) {
			$wrapper_attrs['data-wp-context'] = self::lightbox_context();
		}
		$wrapper = get_block_wrapper_attributes( $wrapper_attrs );

		// Append the hidden lightbox overlay only when enabled, so a lightbox-off
		// gallery carries no enhancement markup at all.
		$overlay = $enabled ? self::lightbox_overlay() : '';

		return sprintf(
			'<div %1$s><div class="%2$s" style="%3$s">%4$s</div>%5$s</div>',
			$wrapper,
			esc_attr( $container_class ),
			esc_attr( $container_style ),
			$figures,
			$overlay,
		);

	}

	/**
	 * Builds the per-block Interactivity context JSON the view module reads.
	 *
	 * View-script modules cannot translate at runtime, so the one runtime string
	 * the lightbox needs — the `%1$d of %2$d` counter announcement — is translated
	 * here and handed across as context. The view module fills the two
	 * placeholders with the live position and total.
	 *
	 * @since 0.7.0
	 *
	 * @return string The context object encoded as a JSON attribute value.
	 */
	private static function lightbox_context(): string {

		// Translate the counter template once and encode it as the context island;
		// `false` from the encoder degrades to an empty object the view tolerates.
		$context = [
			/* translators: 1: the 1-based position of the shown image, 2: the total number of images. */
			'counterTemplate' => __( '%1$d of %2$d', 'kntnt-photo-drop' ),
		];
		$json    = wp_json_encode( $context );

		return $json === false ? '{}' : $json;

	}

	/**
	 * Builds the hidden lightbox overlay markup the view module drives.
	 *
	 * A single dialog-role overlay per gallery, hidden until a thumbnail is
	 * clicked: a backdrop, the previous/next/close controls, the live image, a
	 * polite live region announcing the position, and a hidden load-failure
	 * message. Every label is translatable and the structure carries the WAI-ARIA
	 * dialog semantics (`role="dialog"`, `aria-modal`, an `aria-label`); the view
	 * module toggles `hidden`, swaps the image `src`/`srcset`/`alt`, updates the
	 * counter, and unhides the failure message when a slide's image errors. The
	 * failure message is translated here because view-script modules cannot
	 * translate at runtime. The overlay reuses each thumbnail's own `<a href>`
	 * data as its slide source, so it adds no image URLs of its own to escape —
	 * only static, translated chrome.
	 *
	 * @since 0.7.0
	 * @since 0.2.0 Added the hidden load-failure message element.
	 *
	 * @return string The escaped overlay markup.
	 */
	private static function lightbox_overlay(): string {

		// Label every control and the dialog itself; these are the only runtime
		// strings the overlay carries, all translated and escaped at output.
		$dialog_label = esc_attr__( 'Image viewer', 'kntnt-photo-drop' );
		$close_label  = esc_attr__( 'Close', 'kntnt-photo-drop' );
		$prev_label   = esc_attr__( 'Previous image', 'kntnt-photo-drop' );
		$next_label   = esc_attr__( 'Next image', 'kntnt-photo-drop' );
		$error_text   = esc_html__( 'The image could not be loaded.', 'kntnt-photo-drop' );

		// Compose the dialog: backdrop, controls, the live image, the polite
		// counter region, and the hidden failure message. The image starts empty;
		// the view module fills it on open.
		return sprintf(
			'<div class="kntnt-photo-drop-lightbox" role="dialog" aria-modal="true" aria-label="%1$s" hidden>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__close" aria-label="%2$s">&times;</button>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__prev" aria-label="%3$s">&lsaquo;</button>'
				. '<figure class="kntnt-photo-drop-lightbox__figure">'
				. '<img class="kntnt-photo-drop-lightbox__image" src="" alt="" />'
				. '</figure>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__next" aria-label="%4$s">&rsaquo;</button>'
				. '<p class="kntnt-photo-drop-lightbox__counter" aria-live="polite"></p>'
				. '<p class="kntnt-photo-drop-lightbox__error" role="alert" hidden>%5$s</p>'
				. '</div>',
			$dialog_label,
			$close_label,
			$prev_label,
			$next_label,
			$error_text,
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
		// Captions are always an overlay (issue #33), so there is no position to read.
		$contents = [ 'none', 'filename', 'path' ];
		$content  = self::one_of( self::read_string( $attributes, 'captionContent' ), $contents, 'none' );
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
		$anchor   = self::one_of( self::read_string( $attributes, 'captionAnchor' ), $anchors, 'bottom-left' );

		return new Caption_Settings(
			$content,
			self::read_bool( $attributes, 'captionHumanize', true ),
			self::read_bool( $attributes, 'captionIncludeCollectionName', false ),
			self::read_string( $attributes, 'captionSeparator' ),
			$anchor,
		);

	}

	/**
	 * Maps a dangling/empty collection to the right empty render for the context.
	 *
	 * On a frontend (non-preview) render this is the editor-only notice — the public
	 * sees nothing, a user who can edit sees the broken-reference notice. In the
	 * editor preview it is an empty string: the edit component's `ServerSideRender`
	 * treats an empty response as its empty case and shows its own grey
	 * placeholders, which is the chosen "no collection / empty / dangling" UI.
	 *
	 * @since 0.5.0
	 *
	 * @param bool $is_preview Whether this is the capped editor preview.
	 * @return string The notice markup, '', or '' for the preview's placeholder case.
	 */
	private static function empty_output( bool $is_preview ): string {

		// Hand the preview an empty response so its placeholders show; otherwise fall
		// through to the frontend notice gated on the edit capability.
		return $is_preview ? '' : self::dangling_output();

	}

	/**
	 * Returns the editor-only notice markup, or `''` for anyone who cannot edit.
	 *
	 * A dangling, empty, or imageless collection renders nothing for the public —
	 * a visitor never learns a collection is gone — but a user who can edit posts
	 * sees an inline notice so the broken reference is visible while building the
	 * page. The gate is the `edit_posts` capability, not mere authentication, so
	 * a logged-in subscriber is treated as the public.
	 *
	 * @since 0.6.0
	 * @since 0.2.0 Gated on `edit_posts` instead of `is_user_logged_in()`.
	 *
	 * @return string The notice markup for a user who can edit, or '' otherwise.
	 */
	private static function dangling_output(): string {

		// Show the broken-reference notice only to a user who can edit posts; the
		// public — including logged-in users without editing rights — sees nothing.
		if ( ! current_user_can( 'edit_posts' ) ) {
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

	/**
	 * Resolves the inter-item gap from the `blockGap` spacing block support.
	 *
	 * The gap lives at `style.spacing.blockGap` once the spacing support's
	 * `blockGap` is enabled (issue #33 replaced the bespoke `blockGap` attribute
	 * with the support). It is either a custom length (`"20px"`) or a spacing
	 * preset token (`"var:preset|spacing|40"`), the latter rewritten to its
	 * `var( --wp--preset--spacing--40 )` reference so the emitted custom property is
	 * a valid CSS length. An absent or empty value falls back to the documented
	 * default so both layouts always have a gap.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return string The resolved CSS gap length.
	 */
	private static function block_gap( array $attributes ): string {

		// Reach into the spacing support's stored blockGap; anything missing or
		// non-string falls back to the documented 12px default.
		$style   = is_array( $attributes['style'] ?? null ) ? $attributes['style'] : [];
		$spacing = is_array( $style['spacing'] ?? null ) ? $style['spacing'] : [];
		$gap     = $spacing['blockGap'] ?? '';
		if ( ! is_string( $gap ) || $gap === '' ) {
			return '12px';
		}

		// Rewrite a spacing preset token to its CSS custom-property reference so the
		// emitted gap is a usable length rather than the raw `var:preset|…` token.
		if ( preg_match( '/^var:preset\|spacing\|(.+)$/', $gap, $matches ) === 1 ) {
			return sprintf( 'var(--wp--preset--spacing--%s)', $matches[1] );
		}

		return $gap;

	}

}
