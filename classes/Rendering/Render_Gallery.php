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
 * dimensions (zero layout shift), a responsive `srcset` of the thumbnail and the
 * full rendition, a `loading="lazy"` thumbnail wrapped in `<a href="<main>.webp">`
 * (the no-JS fallback and the clean hook the Interactivity-API lightbox upgrades),
 * and the unified overlays (ADR-0015). Two layouts are supported: mode A is
 * core's Grid layout plus a bespoke aspect-ratio/fit, mode B is bespoke justified
 * rows. A no-collection or broken reference (unset slug, dangling slug,
 * unreadable descriptor, invalid start path) renders nothing for the public and
 * an editor-only notice; a collection that resolves cleanly but holds no images
 * renders a configurable public message instead (ADR-0012).
 *
 * The justified-row math, the srcset assembly, the breadcrumb assembly, the
 * overlay placement, and the URL arithmetic live in the pure helper classes
 * beside this one, and the overlay markup in `Overlay_Renderer`, so the
 * load-bearing logic is unit-testable without a browser; this class is the
 * orchestration and the escaping boundary for the figures and the wrappers.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Rest\Images_Controller;
use Kntnt\Photo_Drop\Rest\Media_Controller;
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
	 * @since 0.4.0
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
	 * @since 0.4.0
	 * @var int
	 */
	private const PREVIEW_FIGURE_CAP = 6;

	/**
	 * The default capability that gates the editor-only broken-reference notice.
	 *
	 * The notice prompts a logged-in editor to (re)select a collection when the
	 * reference is unset or dangling, and must never reach the public. It defaults
	 * to `edit_posts` — the capability for placing the block in the first place —
	 * and is resolved through `kntnt_photo_drop_editor_notice_capability` (ADR-0015,
	 * "The capability-filter rule"). It has its own filter rather than reusing
	 * `kntnt_photo_drop_list_capability`: who may see a broken-gallery diagnostic
	 * and who may enumerate collections are different concerns that merely share a
	 * default.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	private const DEFAULT_EDITOR_NOTICE_CAPABILITY = 'edit_posts';

	/**
	 * Returns the gallery's front-end HTML, or an empty string when nothing renders.
	 *
	 * Resolves the collection and reads its descriptor; an unset or dangling
	 * reference renders nothing for the public and an editor-only notice for a
	 * logged-in editor (the public never learns a collection is gone). Validates
	 * the start path once against the root, walks the tree, and — when there is at
	 * least one image — emits the gallery markup. An empty walk (a present
	 * collection with no images) instead renders the configurable empty message to
	 * everyone (ADR-0012), since an imageless gallery is a legitimate visitor state.
	 *
	 * In editor-preview mode (the `isEditorPreview` render-time-only attribute the
	 * editor's `ServerSideRender` sends), the walked list is capped to the first
	 * few images and the lightbox is suppressed, so the canvas never tries to
	 * render a thousand-image collection and clicks stay inert. The flag lives only
	 * on the preview request, so the frontend render is unaffected.
	 *
	 * @since 0.6.0
	 * @since 0.4.0 Added the capped, lightbox-suppressed editor-preview mode.
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

		// Resolve the selected slug to a real collection; an unset or dangling
		// reference is the no-collection case (nothing for the public, an editor
		// notice). A dangling slug stays here, not in the imageless case, so the
		// public never learns a collection is gone.
		$slug       = self::read_string( $attributes, 'collection' );
		$repository = new Repository();
		$root       = $slug === '' ? null : $repository->resolve_slug( $slug );
		if ( $root === null ) {
			return self::no_collection_output( $is_preview );
		}

		// Read the descriptor for the full/thumbnail widths the srcset needs and the
		// display name a breadcrumb leads with; an unreadable descriptor is a
		// degraded collection we decline to render rather than guess at.
		$descriptor = Descriptor::read( $root );
		if ( $descriptor === null ) {
			return self::no_collection_output( $is_preview );
		}

		// Validate the editor-set start path once against the collection root via
		// the same guard the upload path uses. Any request-time path input is
		// ignored — the start path is a stored attribute, not a query parameter
		// (ADR-0005) — so the gallery has no per-request traversal surface.
		$start_path = self::resolve_start_path( $root, self::read_string( $attributes, 'startPath' ) );
		if ( $start_path === null ) {
			return self::no_collection_output( $is_preview );
		}

		// Walk the tree (recursive or single-folder) into the flattened, ordered
		// image list; an empty result is the imageless case — a present collection
		// with no images, shown to everyone as the configurable empty message.
		$recursive = self::read_bool( $attributes, 'recursive', true );
		$order     = self::read_string( $attributes, 'order' ) === Gallery_Walker::ORDER_DESC
			? Gallery_Walker::ORDER_DESC
			: Gallery_Walker::ORDER_ASC;
		$walker = new Gallery_Walker( new Index_Store() );
		$items  = $walker->walk( $root, self::relative_to_root( $root, $start_path ), $recursive, $order );
		if ( $items === [] ) {
			return self::empty_collection_output( $is_preview, $attributes );
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
	 * Resolves the collection's base URL once, builds the overlay renderer once
	 * (the four placements and the shared appearance apply identically to every
	 * figure), builds each image's figure (with its srcset, dimensions, lazy
	 * thumbnail, anchor fallback, and overlays), and wraps the figures in a
	 * layout-appropriate container: core's Grid layout for mode A or the bespoke
	 * justified-rows container for mode B. The wrapper carries the standard
	 * block-supports attributes plus a lightbox flag the view module reads.
	 *
	 * @since 0.6.0
	 * @since 0.4.0 Added the `$is_preview` flag to suppress the lightbox in the editor.
	 * @since 0.11.0 Replaced the caption + download click-matrix with the unified
	 *               overlay framework (ADR-0015).
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

		// Resolve the lightbox toggle as authored and as gated by the preview (a
		// preview never wires the lightbox). The overlay renderer keys "full" off the
		// authored toggle so the preview's thumbnail overlays match the published page.
		$lightbox_enabled = self::read_bool( $attributes, 'lightbox', true );
		$lightbox         = ! $is_preview && $lightbox_enabled;

		// Resolve whether the two write-path icons render for this user: a real
		// capability check on the front end (defence in depth, ADR-0015) — add-to-media
		// behind its capability and trash behind the delete capability — but always
		// true in the editor preview so the builder sees each icon's placement inertly
		// regardless of their own capability. The nonces are still gated on the real
		// frontend visibility below, so the preview never carries a credential.
		$add_to_media_capable = $is_preview || current_user_can( Media_Controller::required_capability() );
		$delete_capable       = $is_preview || current_user_can( Images_Controller::required_capability() );

		// Build the overlay renderer once — the four placements and the shared
		// appearance (Colour/Typography/iconSize) apply identically to every figure
		// and to the lightbox. The image's border/shadow are a separate per-image
		// projection.
		$overlays      = Overlay_Renderer::from_attributes(
			$attributes,
			$lightbox_enabled,
			$add_to_media_capable,
			$delete_capable,
		);
		$image_support = Block_Style_Support::image( $attributes );

		// Pre-compose the render-constant image class/style once — the per-figure hot
		// loop (a gallery can hold thousands of images) reuses them rather than
		// rebuilding and re-escaping per iteration.
		$image_class = 'kntnt-photo-drop-gallery__image';
		if ( $image_support['class'] !== '' ) {
			$image_class .= ' ' . $image_support['class'];
		}
		$image_class = esc_attr( $image_class );
		$image_style = esc_attr( $image_support['style'] );

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
				$overlays,
				$image_class,
				$image_style,
				$attributes,
			);
		} else {
			$figures = self::grid_figures(
				$items,
				$descriptor,
				$base_url,
				$overlays,
				$image_class,
				$image_style,
				$attributes,
			);
		}

		// Resolve the slideshow settings — a third surface orthogonal to the overlays
		// (ADR-0009), overlay-free (ADR-0015), consumed by the wrapper flags, the
		// built-in button, and the overlay.
		$slideshow = self::slideshow_settings( $attributes );

		return self::wrap( $attributes, $layout, $figures, $lightbox, $is_preview, $overlays, $slideshow, $slug );

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
	 * @param array<int,Gallery_Item> $items       The images.
	 * @param Descriptor              $descriptor  The collection contract.
	 * @param string                  $base_url    The collection base URL.
	 * @param Overlay_Renderer        $overlays    The resolved overlay renderer.
	 * @param string                  $image_class The pre-escaped `<img>` class.
	 * @param string                  $image_style The pre-escaped `<img>` style, or ''.
	 * @param array<string,mixed>     $attributes  The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function grid_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Overlay_Renderer $overlays,
		string $image_class,
		string $image_style,
		array $attributes,
	): string {

		// A fixed block aspect-ratio overrides the per-image ratio for every cell; a
		// malformed or empty value keeps each image's own stored ratio (zero layout
		// shift). The ratio is shape-validated because it lands in an inline style.
		$fixed_ratio = self::css_aspect_ratio( self::read_string( $attributes, 'aspectRatio' ) );
		$image_fit   = self::read_string( $attributes, 'imageFit' ) === 'contain' ? 'contain' : 'cover';

		// Derive one sizes hint for every cell from the minimum column width: below
		// it the grid is single-column (the tile spans the viewport), above it a
		// tile renders near the minimum, so 1.5× the minimum covers wider auto-fill
		// tracks. The leading `auto` lets browsers with lazy-loading auto-sizes use
		// the real rendered width; others skip the invalid first entry — keep it first.
		$min_column = self::pixels( self::read_string( $attributes, 'minimumColumnWidth' ), 320 );
		$sizes      = sprintf( 'auto, (max-width: %dpx) 100vw, %dpx', $min_column, (int) round( $min_column * 1.5 ) );

		// Build one figure per image, giving each a wrapper style that fixes its
		// aspect ratio and the object-fit so the grid cell is stable before load. The
		// per-image aspect ratio is the only render-varying part of the grid style; a
		// fixed block ratio (validated once above) overrides it for every cell.
		$markup = '';
		foreach ( $items as $item ) {
			$ratio   = $fixed_ratio !== '' ? $fixed_ratio : $item->width . ' / ' . $item->height;
			$style   = sprintf( 'aspect-ratio:%s;--kntnt-photo-drop-fit:%s;', $ratio, $image_fit );
			$markup .= self::figure(
				$item,
				$descriptor,
				$base_url,
				$overlays,
				$image_class,
				$image_style,
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
	 * @param array<int,Gallery_Item> $items       The images.
	 * @param Descriptor              $descriptor  The collection contract.
	 * @param string                  $base_url    The collection base URL.
	 * @param Overlay_Renderer        $overlays    The resolved overlay renderer.
	 * @param string                  $image_class The pre-escaped `<img>` class.
	 * @param string                  $image_style The pre-escaped `<img>` style, or ''.
	 * @param array<string,mixed>     $attributes  The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function justified_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Overlay_Renderer $overlays,
		string $image_class,
		string $image_style,
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
				$overlays,
				$image_class,
				$image_style,
				$sizes,
				$style,
				'kntnt-photo-drop-gallery__item--justified',
			);
		}

		return $markup;

	}

	/**
	 * Builds one `<figure>` for an image, with srcset, anchor fallback, and overlays.
	 *
	 * The thumbnail `<img>` carries the stored `width`/`height` and a responsive
	 * `srcset` (the thumbnail and the bounded full, so a tile never downloads more
	 * than the full image) with a layout-aware `sizes` hint. The image is
	 * lazy-loaded and wrapped in an `<a>` to the main image — the no-JS fallback
	 * and the element the lightbox upgrades; the anchor also carries the same
	 * srcset and, when breadcrumbs are visible, the breadcrumb text as data
	 * attributes so the lightbox shows a responsive slide and the same overlay
	 * without re-parsing the page. The border and shadow block-support panels land
	 * on the `<img>` (the core Image-block skip-serialization pattern), pre-
	 * projected into `$image_class`/`$image_style`. The thumbnail-surface overlays
	 * (the breadcrumb element and the action-icon clusters) follow the link inside
	 * the figure, positioned absolutely by their anchor classes.
	 *
	 * @since 0.4.0
	 * @since 0.11.0 Replaced the caption + download icon with the overlay renderer.
	 *
	 * @param Gallery_Item     $item        The image.
	 * @param Descriptor       $descriptor  The collection contract.
	 * @param string           $base_url    The collection base URL.
	 * @param Overlay_Renderer $overlays    The resolved overlay renderer.
	 * @param string           $image_class The pre-escaped `<img>` class.
	 * @param string           $image_style The pre-escaped `<img>` style, or ''.
	 * @param string           $sizes       The layout-aware `sizes` attribute value.
	 * @param string           $item_style  The inline style for the figure (layout-specific).
	 * @param string           $item_class  The layout-specific figure class.
	 * @return string The figure markup.
	 */
	private static function figure(
		Gallery_Item $item,
		Descriptor $descriptor,
		string $base_url,
		Overlay_Renderer $overlays,
		string $image_class,
		string $image_style,
		string $sizes,
		string $item_style,
		string $item_class,
	): string {

		// Build the main URL and the responsive srcset candidates — the thumbnail and
		// the full rendition, at real widths; the full image is the display ceiling
		// and the (possibly unbounded) main is download-only (ADR-0013). Each derived
		// rendition is served from the hidden width directory.
		$relative   = $item->relative_path();
		$main_url   = Image_Url::main( $base_url, $relative );
		$renditions = $descriptor->effective_renditions();
		$candidates = Srcset_Builder::candidates(
			$item->width,
			$renditions['full_width'],
			$renditions['thumbnail_width'],
			$main_url,
			static fn ( int $width ): string => Image_Url::thumbnail( $base_url, $relative, $width ),
		);
		$srcset = Srcset_Builder::to_attribute( $candidates );

		// Pick the smallest candidate as the <img> src (a sensible default the srcset
		// refines), derive the alt from the humanised filename, and assemble the
		// breadcrumb text once — it feeds both the thumbnail overlay and the anchor's
		// breadcrumb data attribute the lightbox mirrors.
		$smallest        = $candidates[0]['url'] ?? $main_url;
		$alt             = Breadcrumb_Builder::humanise_filename( $relative );
		$breadcrumb_text = $overlays->breadcrumbs_text( $relative, $descriptor->name );

		// Compose the lazy, dimensioned <img>, carrying the pre-escaped border/shadow
		// block-support class and style; the style attribute is omitted entirely when
		// the panels contributed nothing rather than shipping an empty `style=""`.
		$image = sprintf(
			'<img class="%1$s"%2$s src="%3$s" srcset="%4$s" sizes="%5$s"'
				. ' width="%6$d" height="%7$d" loading="lazy" decoding="async" alt="%8$s" />',
			$image_class,
			$image_style === '' ? '' : sprintf( ' style="%s"', $image_style ),
			esc_url( $smallest ),
			esc_attr( $srcset ),
			esc_attr( $sizes ),
			$item->width,
			$item->height,
			esc_attr( $alt ),
		);

		// Wrap the image in an <a href> to the main image — the no-JS fallback and the
		// lightbox's upgrade hook. The data attributes hand the lightbox its slide
		// data without re-parsing the markup: the display rendition (`-full`), the
		// srcset, the breadcrumb text (when visible), and — distinct from the display
		// rendition — the main-image download target (`-main`), which the lightbox
		// download icon always saves (ADR-0013), so a future bounded full rendition
		// never drags the download off the main. The anchor never downloads; the
		// download overlay icon is the sole download trigger.
		$breadcrumb_attr = $breadcrumb_text !== ''
			? sprintf( ' data-kntnt-photo-drop-breadcrumbs="%s"', esc_attr( $breadcrumb_text ) )
			: '';

		// Mirror the image's collection-relative path onto the anchor when either
		// write-path overlay actually renders (capable user, visibility on): it is the
		// lightbox add-to-media/trash target the view module reads per slide, and the
		// trash view module also finds the tile to remove by this anchor path. An
		// un-capable user's anchor carries no path at all (defence in depth, ADR-0015).
		$path_attr = $overlays->add_to_media_visible() || $overlays->delete_visible()
			? sprintf( ' data-kntnt-photo-drop-path="%s"', esc_attr( $relative ) )
			: '';
		$link      = sprintf(
			'<a class="kntnt-photo-drop-gallery__link" href="%1$s" data-kntnt-photo-drop-full="%1$s"'
				. ' data-kntnt-photo-drop-main="%1$s" data-kntnt-photo-drop-srcset="%2$s"%3$s%4$s>%5$s</a>',
			esc_url( $main_url ),
			esc_attr( $srcset ),
			$breadcrumb_attr,
			$path_attr,
			$image,
		);

		// The thumbnail-surface overlays (the breadcrumb element and any action-icon
		// clusters) follow the link inside the figure, positioned absolutely by their
		// anchor classes. The add-to-media icon carries the image's collection-relative
		// path the view module POSTs.
		return sprintf(
			'<figure class="kntnt-photo-drop-gallery__item %1$s" style="%2$s">%3$s%4$s</figure>',
			esc_attr( $item_class ),
			esc_attr( $item_style ),
			$link,
			$overlays->thumbnail( $breadcrumb_text, $main_url, $relative ),
		);

	}

	/**
	 * Wraps the figures in the layout container plus the block-supports wrapper.
	 *
	 * Mode A applies core's Grid layout via the `minimumColumnWidth` and `gap`
	 * style variables on the inner container; mode B applies the justified flex
	 * container. The outer wrapper is core's block-supports wrapper (alignment,
	 * spacing, anchor) plus the project class, the lightbox flag, and the
	 * Interactivity directives the view module reads. (The Colour/Typography/
	 * Border/Shadow supports are skip-serialized onto the overlays and images, not
	 * the wrapper.)
	 *
	 * The view module reads `data-kntnt-photo-drop-lightbox` to decide whether a
	 * thumbnail click opens the lightbox or is suppressed entirely (the inert
	 * lightbox-off case). The `init` hook is bound on every frontend render — for
	 * the lightbox wiring, the justified layout's last-row correction, the overlay
	 * icon downloads, or the click suppression — and the per-block context and the
	 * hidden overlay are appended only when the lightbox is on, so a lightbox-off
	 * gallery carries no lightbox chrome.
	 *
	 * The editor preview suppresses interactivity unconditionally: the lightbox
	 * flag reads `false`, no overlay/context is emitted, and no `init` is bound, so
	 * clicks stay inert in the canvas — yet the figures may still carry their
	 * thumbnail overlays so the preview matches the frontend.
	 *
	 * @since 0.6.0
	 * @since 0.7.0 Added the slideshow surface (ADR-0009).
	 * @since 0.11.0 Replaced the download wrapper flag and the caption/download
	 *               overlay chrome with the unified overlay framework (ADR-0015).
	 * @since 0.12.0 Emits the add-to-media REST nonce and URL only for a capable user
	 *               who will see the icon (ADR-0015).
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $layout     The resolved layout token.
	 * @param string              $figures    The concatenated figure markup.
	 * @param bool                $lightbox   Whether the lightbox is wired (false in preview).
	 * @param bool                $is_preview Whether this is the capped editor preview.
	 * @param Overlay_Renderer    $overlays   The resolved overlay renderer.
	 * @param Slideshow_Settings  $slideshow  The resolved slideshow settings.
	 * @param string              $slug       The collection slug (for the add-to-media REST URL).
	 * @return string The full gallery markup.
	 */
	private static function wrap(
		array $attributes,
		string $layout,
		string $figures,
		bool $lightbox,
		bool $is_preview,
		Overlay_Renderer $overlays,
		Slideshow_Settings $slideshow,
		string $slug,
	): string {

		// Build the inner container's style from the gap (both layouts) and, for the
		// grid, the minimum column width that drives core's auto-fill grid. The gap
		// is the block-support `blockGap`, read from the spacing support and applied
		// to both layout containers.
		$gap = self::block_gap( $attributes );
		if ( $layout === self::LAYOUT_JUSTIFIED ) {
			$container_class = 'kntnt-photo-drop-gallery__layout kntnt-photo-drop-gallery__layout--justified';
			$container_style = sprintf( '--kntnt-photo-drop-gap:%s;', $gap );
		} else {
			$min_column      = self::css_length( self::read_string( $attributes, 'minimumColumnWidth' ), '320px' );
			$container_class = 'kntnt-photo-drop-gallery__layout kntnt-photo-drop-gallery__layout--grid';
			$container_style = sprintf(
				'--kntnt-photo-drop-gap:%s;--kntnt-photo-drop-min-column:%s;',
				$gap,
				$min_column,
			);
		}

		// Compose the block-supports wrapper: the project class, the Interactivity
		// namespace, and the lightbox flag the view module reads. On the frontend the
		// `init` hook always runs — the view module wires the lightbox, corrects the
		// justified last row, wires the overlay icon downloads, and/or suppresses
		// inert clicks — while the per-block context exists only for the lightbox. The
		// editor preview binds no `init` at all so the canvas stays inert.
		$wrapper_attrs = [
			'class'                          => 'kntnt-photo-drop-gallery',
			'data-wp-interactive'            => 'kntnt-photo-drop/gallery',
			'data-kntnt-photo-drop-lightbox' => $lightbox ? 'true' : 'false',
		];
		if ( ! $is_preview ) {
			$wrapper_attrs['data-wp-init'] = 'callbacks.init';
		}
		if ( $lightbox ) {
			$wrapper_attrs['data-wp-context'] = self::lightbox_context();
		}

		// The slideshow is wired only on the frontend — the editor preview never
		// plays — and only when a trigger mode is chosen; an off-mode gallery
		// carries no slideshow flags at all (ADR-0009).
		$slideshow_active = $slideshow->mode !== Slideshow_Settings::MODE_OFF && ! $is_preview;
		if ( $slideshow_active ) {
			$wrapper_attrs['data-kntnt-photo-drop-slideshow-mode']    = $slideshow->mode;
			$wrapper_attrs['data-kntnt-photo-drop-slideshow-seconds'] = (string) $slideshow->seconds;
		}

		// Emit the gallery's REST write-path credentials only on the frontend and only
		// when a capable user will actually see a write-path icon (ADR-0015). This ends
		// the gallery's pure-SSR property for the two write-path overlays alone; a page a
		// visitor reads, or one with no write-path icon, carries no credential. The
		// `wp_rest` nonce is shared by both write-paths and emitted when either icon
		// shows; each icon's own endpoint URL is emitted only when that icon shows, so
		// the add-to-media (copy) and trash (permanent delete) actions are independently
		// gated by their separate capabilities.
		$add_to_media_visible = $overlays->add_to_media_visible();
		$delete_visible       = $overlays->delete_visible();
		if ( ! $is_preview && ( $add_to_media_visible || $delete_visible ) ) {
			$wrapper_attrs['data-kntnt-photo-drop-nonce'] = wp_create_nonce( 'wp_rest' );
		}
		if ( ! $is_preview && $add_to_media_visible ) {
			$wrapper_attrs['data-kntnt-photo-drop-media-url'] = rest_url(
				sprintf( 'kntnt-photo-drop/v1/collections/%s/media', $slug ),
			);
			// Mirror the overwrite confirm-popover copy onto the wrapper: a view-script
			// module cannot translate at runtime, so the duplicate prompt and its
			// Overwrite/Cancel labels are translated here and read by the add-to-media
			// view module (the same pattern the trash popover uses).
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-prompt']  =
				__( 'This image is already in the Media Library. Overwrite it?', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-confirm'] = __( 'Overwrite', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-cancel']  = __( 'Cancel', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-label']   = __( 'Confirm overwrite', 'kntnt-photo-drop' );
		}
		if ( ! $is_preview && $delete_visible ) {
			$wrapper_attrs['data-kntnt-photo-drop-delete-url'] = rest_url(
				sprintf( 'kntnt-photo-drop/v1/collections/%s/images', $slug ),
			);
			// Mirror the trash confirm-popover copy onto the wrapper: a view-script
			// module cannot translate at runtime, so the popover's permanence-stating
			// prompt and its Delete/Cancel labels are translated here and read by the
			// trash view module (the same pattern the lightbox counter uses).
			$wrapper_attrs['data-kntnt-photo-drop-delete-prompt']  =
				__( 'Delete this image permanently? This cannot be undone.', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-confirm'] = __( 'Delete', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-cancel']  = __( 'Cancel', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-label']   = __( 'Confirm deletion', 'kntnt-photo-drop' );
		}

		// Mirror the block's HTML anchor onto the wrapper id — core does not emit
		// the anchor for dynamic blocks, and a custom slideshow trigger targets
		// the gallery by this id (ADR-0009).
		$anchor = self::read_string( $attributes, 'anchor' );
		if ( $anchor !== '' ) {
			$wrapper_attrs['id'] = $anchor;
		}
		$wrapper = get_block_wrapper_attributes( $wrapper_attrs );

		// The built-in button renders in button mode only: hidden on the frontend
		// until the view module proves the slideshow can run (a no-JS visitor never
		// sees a dead control), visible and inert in the editor preview so the
		// builder sees its placement.
		$slideshow_button = $slideshow->mode === Slideshow_Settings::MODE_BUTTON
			? self::slideshow_button( $slideshow->label, $is_preview )
			: '';

		// Append the hidden lightbox overlay only when the lightbox is on, carrying
		// its full-surface overlays (breadcrumb and/or icons) so the enlarged image
		// mirrors the gallery; a lightbox-off gallery carries no overlay chrome.
		$overlay = $lightbox ? self::lightbox_overlay( $overlays ) : '';

		// Append the hidden slideshow overlay only when the slideshow is wired, so
		// an off-mode gallery (and every editor preview) carries no playback chrome.
		// The slideshow is overlay-free (ADR-0015).
		$slideshow_overlay = $slideshow_active ? self::slideshow_overlay() : '';

		return sprintf(
			'<div %1$s>%2$s<div class="%3$s" style="%4$s">%5$s</div>%6$s%7$s</div>',
			$wrapper,
			$slideshow_button,
			esc_attr( $container_class ),
			esc_attr( $container_style ),
			$figures,
			$overlay,
			$slideshow_overlay,
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
	 * dialog semantics; the view module toggles `hidden`, swaps the image, updates
	 * the counter, and unhides the failure message when a slide errors. The failure
	 * message is translated here because view-script modules cannot translate at
	 * runtime. The overlay reuses each thumbnail's own `<a href>` data as its slide
	 * source, so it adds no image URLs of its own to escape — only static,
	 * translated chrome plus the full-surface overlays (ADR-0015): the breadcrumb
	 * element (when its visibility includes full) and the action-icon clusters
	 * (likewise), sitting inside the media wrapper so a click on the enlarged image
	 * outside an icon does nothing.
	 *
	 * @since 0.7.0
	 * @since 0.11.0 Carries the unified full-surface overlays instead of the caption
	 *               + download click-matrix.
	 *
	 * @param Overlay_Renderer $overlays The resolved overlay renderer.
	 * @return string The escaped overlay markup.
	 */
	private static function lightbox_overlay( Overlay_Renderer $overlays ): string {

		// Label every control and the dialog itself; these are the only runtime
		// strings the overlay carries, all translated and escaped at output.
		$dialog_label = esc_attr__( 'Image viewer', 'kntnt-photo-drop' );
		$close_label  = esc_attr__( 'Close', 'kntnt-photo-drop' );
		$prev_label   = esc_attr__( 'Previous image', 'kntnt-photo-drop' );
		$next_label   = esc_attr__( 'Next image', 'kntnt-photo-drop' );
		$error_text   = esc_html__( 'The image could not be loaded.', 'kntnt-photo-drop' );

		// The figure holds the live image inside a media wrapper that shrink-wraps it,
		// so the full-surface overlays (the empty breadcrumb the view fills per slide,
		// and the action-icon clusters whose download href the view sets) anchor
		// inside the image's own box rather than the whole viewport.
		$figure_inner = sprintf(
			'<span class="kntnt-photo-drop-lightbox__media">'
				. '<img class="kntnt-photo-drop-lightbox__image" src="" alt="" />%1$s</span>',
			$overlays->lightbox(),
		);

		// Compose the dialog: backdrop, controls, the live figure, the polite counter
		// region, and the hidden failure message. The image starts empty; the view
		// module fills it on open.
		return sprintf(
			'<div class="kntnt-photo-drop-lightbox" role="dialog" aria-modal="true" aria-label="%1$s" hidden>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__close" aria-label="%2$s">&times;</button>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__prev" aria-label="%3$s">&lsaquo;</button>'
				. '<figure class="kntnt-photo-drop-lightbox__figure">%5$s</figure>'
				. '<button type="button" class="kntnt-photo-drop-lightbox__next" aria-label="%4$s">&rsaquo;</button>'
				. '<p class="kntnt-photo-drop-lightbox__counter" aria-live="polite"></p>'
				. '<p class="kntnt-photo-drop-lightbox__error" role="alert" hidden>%6$s</p>'
				. '</div>',
			$dialog_label,
			$close_label,
			$prev_label,
			$next_label,
			$figure_inner,
			$error_text,
		);

	}

	/**
	 * Collects the slideshow settings from the attributes into one value object.
	 *
	 * Narrows the trigger mode to the three documented values (defaulting an
	 * unexpected value to off), clamps the per-slide seconds to at least one, and
	 * resolves an empty button label to its translated default so the built-in
	 * button is never blank.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return Slideshow_Settings The resolved slideshow settings.
	 */
	private static function slideshow_settings( array $attributes ): Slideshow_Settings {

		// Narrow the mode and clamp the seconds; a malformed seconds value falls
		// back to the documented default before the clamp.
		$mode = self::one_of(
			self::read_string( $attributes, 'slideshow' ),
			[ Slideshow_Settings::MODE_OFF, Slideshow_Settings::MODE_BUTTON, Slideshow_Settings::MODE_CUSTOM ],
			Slideshow_Settings::MODE_OFF,
		);
		$seconds = max( 1, self::read_int( $attributes, 'slideshowSeconds', 5 ) );

		// Resolve the label: the editor-set text, or the translated default when
		// empty.
		$label = self::read_string( $attributes, 'slideshowButtonLabel' );
		if ( $label === '' ) {
			$label = __( 'Slideshow', 'kntnt-photo-drop' );
		}

		return new Slideshow_Settings( $mode, $seconds, $label );

	}

	/**
	 * Builds the built-in slideshow button — the quiet trigger above the gallery.
	 *
	 * The button ships `hidden` on the frontend: the slideshow needs JavaScript,
	 * so the view module reveals it only once a controller is wired and a no-JS
	 * visitor never sees a dead control. The editor preview renders it visible
	 * (and inert, like everything else in the preview) so the builder sees its
	 * placement.
	 *
	 * @since 0.7.0
	 *
	 * @param string $label      The button label (already resolved, never empty).
	 * @param bool   $is_preview Whether this is the editor preview.
	 * @return string The button markup.
	 */
	private static function slideshow_button( string $label, bool $is_preview ): string {
		return sprintf(
			'<button type="button" class="kntnt-photo-drop-gallery__slideshow-button"%1$s>%2$s</button>',
			$is_preview ? '' : ' hidden',
			esc_html( $label ),
		);
	}

	/**
	 * Builds the hidden slideshow overlay markup the view module drives.
	 *
	 * A single dialog-role overlay per gallery, hidden until a trigger starts the
	 * playback (ADR-0009): two stacked slide images the controller crossfades
	 * between, and a close button — the touch path's exit affordance, beside Escape
	 * and the native fullscreen exit. The surface is passive and overlay-free
	 * (ADR-0015), so there is no counter, no paging, no overlay, and no download
	 * affordance. The images start empty; the view module fills them per slide from
	 * the thumbnails' own anchor data.
	 *
	 * @since 0.7.0
	 * @since 0.11.0 Dropped the mirrored caption — the slideshow shows no overlays.
	 *
	 * @return string The escaped overlay markup.
	 */
	private static function slideshow_overlay(): string {

		// Label the dialog and its close control — the only runtime strings the
		// overlay carries, translated and escaped at output.
		$dialog_label = esc_attr__( 'Slideshow', 'kntnt-photo-drop' );
		$close_label  = esc_attr__( 'End slideshow', 'kntnt-photo-drop' );

		return sprintf(
			'<div class="kntnt-photo-drop-slideshow" role="dialog" aria-modal="true" aria-label="%1$s" hidden>'
				. '<figure class="kntnt-photo-drop-slideshow__figure">'
				. '<img class="kntnt-photo-drop-slideshow__image" src="" alt="" />'
				. '<img class="kntnt-photo-drop-slideshow__image" src="" alt="" />'
				. '</figure>'
				. '<button type="button" class="kntnt-photo-drop-slideshow__close" aria-label="%2$s">&times;</button>'
				. '</div>',
			$dialog_label,
			$close_label,
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
	 * Renders the "no usable collection" case: an editor-only notice, else nothing.
	 *
	 * This is the no-collection / broken-reference branch — an unset slug, a
	 * dangling slug, an unreadable descriptor, or an invalid start path. The public
	 * (including logged-in users without `edit_posts`) sees nothing, so a visitor
	 * never learns a collection is gone; a user who can edit sees an inline notice
	 * prompting them to (re)select a collection. In the editor preview the response
	 * is an empty string, which the edit component's `ServerSideRender` treats as
	 * its empty case and replaces with its own grey placeholders.
	 *
	 * @since 0.10.0
	 *
	 * @param bool $is_preview Whether this is the capped editor preview.
	 * @return string The notice markup for an editor, or '' for the public/preview.
	 */
	private static function no_collection_output( bool $is_preview ): string {

		// Hand the preview an empty response (grey placeholders) and show the notice
		// only to a user who holds the filtered editor-notice capability; everyone
		// else — the public — sees nothing.
		if ( $is_preview || ! current_user_can( self::editor_notice_capability() ) ) {
			return '';
		}

		return self::message_markup(
			'notice',
			esc_html__(
				'This gallery has no collection selected. Choose a collection in the block settings.',
				'kntnt-photo-drop',
			),
		);

	}

	/**
	 * Resolves the editor-notice capability through its dedicated filter.
	 *
	 * Defaults to `edit_posts` and is passed through
	 * `kntnt_photo_drop_editor_notice_capability` (ADR-0015) so a site can re-gate
	 * the broken-reference notice without touching code. A filter that returns a
	 * non-string or empty value is a misuse and falls back to the default rather
	 * than showing the diagnostic behind an empty capability check.
	 *
	 * @since 0.11.0
	 *
	 * @return string The capability a user must hold to see the editor notice.
	 */
	private static function editor_notice_capability(): string {

		// Harden the filter's return: a non-string or empty result falls back to
		// the default so a buggy filter can never open the gate to the public.
		$filtered = apply_filters(
			'kntnt_photo_drop_editor_notice_capability',
			self::DEFAULT_EDITOR_NOTICE_CAPABILITY,
		);
		return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_EDITOR_NOTICE_CAPABILITY;

	}

	/**
	 * Renders the "present collection, no images" case as a public message.
	 *
	 * Unlike the broken-reference branch, a collection that resolves cleanly but
	 * holds no images is a legitimate visitor-facing state (a photographer who has
	 * not uploaded yet), not a leaked deletion — so the message is shown to
	 * **everyone**, not gated on `edit_posts`. Its text is the editor-set
	 * `emptyMessage`, or a translated default when that is empty, mirroring the
	 * `slideshowButtonLabel` pattern so the default stays translatable. The editor
	 * preview still returns an empty string so the canvas shows its grey
	 * placeholders rather than the message.
	 *
	 * @since 0.10.0
	 *
	 * @param bool                $is_preview Whether this is the capped editor preview.
	 * @param array<string,mixed> $attributes The block attributes (for `emptyMessage`).
	 * @return string The message markup for everyone, or '' for the preview.
	 */
	private static function empty_collection_output( bool $is_preview, array $attributes ): string {

		// The editor preview shows its own placeholders, so hand it an empty response.
		if ( $is_preview ) {
			return '';
		}

		// Resolve the message: the editor-set text, or the translated default when
		// empty.
		$message = self::read_string( $attributes, 'emptyMessage' );
		if ( $message === '' ) {
			$message = __(
				'There are currently no images in the gallery. Please try again later.',
				'kntnt-photo-drop',
			);
		}

		return self::message_markup( 'empty', esc_html( $message ) );

	}

	/**
	 * Wraps a single message line in the gallery block wrapper with a modifier.
	 *
	 * Both empty-state messages — the editor-only `--notice` and the public
	 * `--empty` — share the wrapper-attributes-plus-`<p>` shape; only the modifier
	 * class and the (already-escaped) text differ, so the markup lives in one place.
	 *
	 * @since 0.10.0
	 *
	 * @param string $modifier     The wrapper modifier suffix ('notice' or 'empty').
	 * @param string $escaped_text The message text, already escaped for HTML.
	 * @return string The wrapped message markup.
	 */
	private static function message_markup( string $modifier, string $escaped_text ): string {

		$class   = 'kntnt-photo-drop-gallery kntnt-photo-drop-gallery--' . $modifier;
		$wrapper = get_block_wrapper_attributes( [ 'class' => $class ] );

		return sprintf( '<div %1$s><p>%2$s</p></div>', $wrapper, $escaped_text );

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
	 * The CSS pixels assumed per `rem`/`em` when packing a non-`px` gap.
	 *
	 * The justified row-packing math needs a plain pixel number; `rem`/`em` values
	 * are converted at the browser default of 16px per unit. It is only the packing
	 * input for last-row detection, which the view module re-flags against the real
	 * container at runtime, so an off-by-a-few-pixels assumption is harmless.
	 *
	 * @since 0.4.0
	 * @var int
	 */
	private const PIXELS_PER_EM = 16;

	/**
	 * Resolves a CSS length to a pixel count for the justified packing math.
	 *
	 * Accepts a `px` length verbatim and a `rem`/`em` length converted at
	 * {@see PIXELS_PER_EM}; any other form (a `var()` preset token, a `%`, a unitless
	 * value) falls back to the default. The packing is only the no-JS/first-paint
	 * last-row guess — the view module re-flags the actual last row at runtime — so a
	 * preset token falling back here is acceptable.
	 *
	 * @since 0.6.0
	 * @since 0.4.0 Also accepts `rem`/`em`, converted at 16px per unit.
	 *
	 * @param string $length   The CSS length value.
	 * @param int    $fallback The fallback pixel count.
	 * @return int The pixel count.
	 */
	private static function pixels( string $length, int $fallback ): int {
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*(px|rem|em)$/', trim( $length ), $matches ) !== 1 ) {
			return $fallback;
		}
		$value = (float) $matches[1];
		return (int) round( $matches[2] === 'px' ? $value : $value * self::PIXELS_PER_EM );
	}

	/**
	 * Validates a free-text CSS length, falling back when the shape is unexpected.
	 *
	 * The grid's minimum column width is interpolated straight into an inline
	 * `style` attribute, where `esc_attr` does not strip `;`/`:` — so a hostile value
	 * such as `"4px;position:fixed;inset:0"` would inject extra declarations onto the
	 * public page (block-comment JSON escapes KSES). Only a single numeric length
	 * with a known unit is accepted; anything else falls back to the attribute default.
	 *
	 * @since 0.4.0
	 *
	 * @param string $value    The candidate length.
	 * @param string $fallback The default to use when the value is not a clean length.
	 * @return string The validated length, or the fallback.
	 */
	private static function css_length( string $value, string $fallback ): string {
		return preg_match( '/^\d+(\.\d+)?(px|rem|em|%|vw|vh|ch|ex|vmin|vmax)$/', trim( $value ) ) === 1
			? trim( $value )
			: $fallback;
	}

	/**
	 * Validates a free-text CSS aspect ratio, falling back to empty when malformed.
	 *
	 * The grid's `aspectRatio` attribute is interpolated into each figure's inline
	 * `style`, so the injection surface in {@see css_length} applies. A bare ratio
	 * (`1.5`) or a slash ratio (`16 / 9`) is accepted; anything else falls back to the
	 * empty string, which the caller reads as "use each image's own stored ratio".
	 *
	 * @since 0.4.0
	 *
	 * @param string $value The candidate aspect ratio.
	 * @return string The validated ratio, or '' when malformed or empty.
	 */
	private static function css_aspect_ratio( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^\d+(\.\d+)?(\s*\/\s*\d+(\.\d+)?)?$/', $value ) === 1 ? $value : '';
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
	 * `blockGap` is enabled. It is either a custom length (`"20px"`) or a spacing
	 * preset token (`"var:preset|spacing|40"`), the latter rewritten to its
	 * `var( --wp--preset--spacing--40 )` reference so the emitted custom property is
	 * a valid CSS length. An absent or empty value falls back to the documented
	 * default so both layouts always have a gap. Both the preset slug and the custom
	 * length are strictly shape-validated, because the result lands in an inline
	 * `style` where `esc_attr` does not strip `;`/`:` — a hostile blockGap (which KSES
	 * does not filter inside block-comment JSON) would otherwise inject declarations.
	 *
	 * @since 0.4.0
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
		// emitted gap is a usable length rather than the raw `var:preset|…` token; only
		// a clean preset slug is accepted, else the documented default.
		if ( preg_match( '/^var:preset\|spacing\|(.+)$/', $gap, $matches ) === 1 ) {
			return preg_match( '/^[a-zA-Z0-9-]+$/', $matches[1] ) === 1
				? sprintf( 'var(--wp--preset--spacing--%s)', $matches[1] )
				: '12px';
		}

		// A custom length must be a single clean CSS length; anything else falls back.
		return self::css_length( $gap, '12px' );

	}

}
