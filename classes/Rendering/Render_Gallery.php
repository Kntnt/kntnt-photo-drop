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
	 * The nine-point anchor vocabulary, shared by the caption and the download icon.
	 *
	 * Both the caption overlay and the download-icon overlay are placed by the same
	 * nine anchors, so the allowed-value set and the default-narrowing live in one
	 * place rather than being duplicated per overlay.
	 *
	 * @since 0.4.0
	 * @var array<int,string>
	 */
	private const NINE_POINT_ANCHORS = [
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

	/**
	 * The literal href placeholder inside the pre-composed download-icon template.
	 *
	 * The icon anchor is render-constant except for its per-image href, so the
	 * template is composed and escaped once per render with this token as the href
	 * and the figure hot loop substitutes each image's escaped main URL. A plain
	 * token replacement avoids `sprintf` here because the icon's inline style may
	 * legitimately contain `%` (e.g. a percentage icon size), which would corrupt a
	 * format string.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const ICON_HREF_PLACEHOLDER = '{kntnt-photo-drop-download-href}';

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
	 * @since 0.4.0 Added the `$is_preview` flag to suppress the lightbox in the editor.
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

		// Resolve the click behaviour once — the lightbox and download toggles drive
		// the whole matrix (issue #34). The gallery thumbnail carries the download
		// icon — the sole download trigger — only when download is on and the
		// lightbox is off; with the lightbox on, the icon moves into the lightbox.
		// The `$lightbox_enabled` flag is the editor-set toggle as authored; `$lightbox`
		// is that toggle gated by the preview (a preview never wires the lightbox). The
		// thumbnail cell keys off the authored toggle, not the gated one, so an editor
		// preview with lightbox+download on still moves the download into the lightbox
		// rather than wrongly painting thumbnail icons.
		$lightbox_enabled  = self::read_bool( $attributes, 'lightbox', true );
		$lightbox          = ! $is_preview && $lightbox_enabled;
		$download          = self::read_bool( $attributes, 'download', false );
		$download_settings = self::download_settings( $attributes );
		$on_thumbnail      = $download && ! $lightbox_enabled;
		$figure_behaviour  = new Click_Behaviour( $on_thumbnail, $download_settings );

		// Pre-compose every render-constant string the figure loop would otherwise
		// rebuild per image — the download icon, the image class/style, and the caption
		// class prefix/style — so a thousand-image gallery escapes each only once.
		$chrome = self::figure_chrome( $figure_behaviour, $caption, $caption_support, $image_support );

		// Choose the layout and build the figures accordingly: justified rows need
		// per-image flex math, the grid needs only the per-image aspect ratio.
		$layout = self::read_string( $attributes, 'layout' ) === self::LAYOUT_JUSTIFIED
			? self::LAYOUT_JUSTIFIED
			: self::LAYOUT_GRID;
		if ( $layout === self::LAYOUT_JUSTIFIED ) {
			$figures = self::justified_figures( $items, $descriptor, $base_url, $caption, $chrome, $attributes );
		} else {
			$figures = self::grid_figures( $items, $descriptor, $base_url, $caption, $chrome, $attributes );
		}

		return self::wrap(
			$attributes,
			$layout,
			$figures,
			$lightbox,
			$download,
			$is_preview,
			$caption,
			$caption_support,
			$download_settings,
		);

	}

	/**
	 * Pre-composes the render-constant figure chrome once per gallery render.
	 *
	 * Everything a figure carries that does not vary from image to image — the
	 * overlay download-icon template, the `<img>` class and style, and the caption
	 * class prefix and style — is composed and escaped here, so the per-figure hot
	 * loop (a gallery can hold thousands of images) interpolates the finished
	 * strings instead of rebuilding and re-escaping them on every iteration. The
	 * icon varies only in its href, so its template carries the href placeholder
	 * the figure builder substitutes per image. The image and caption styles come
	 * back empty when their block-support panels contributed nothing, so the
	 * figure builder can omit the `style` attribute entirely.
	 *
	 * @since 0.4.0
	 * @since 0.5.0 The icon became a per-figure download anchor template; the
	 *              thumbnail anchor's ` download` attribute is gone.
	 *
	 * @param Click_Behaviour                  $behaviour       The resolved per-figure click behaviour.
	 * @param Caption_Settings                 $caption         The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support The figcaption block-support style/class.
	 * @param array{style:string,class:string} $image_support   The image block-support style/class.
	 * @return Figure_Chrome The render-constant chrome the figure loop reuses.
	 */
	private static function figure_chrome(
		Click_Behaviour $behaviour,
		Caption_Settings $caption,
		array $caption_support,
		array $image_support,
	): Figure_Chrome {

		// Compose the overlay download-icon anchor only in the download-on /
		// lightbox-off cell — the icon is the sole download trigger, so the template
		// carries the href placeholder the figure builder fills with each image's
		// main URL; in every other cell the thumbnail has no icon.
		$icon_template = $behaviour->on_thumbnail
			? self::download_icon( $behaviour->settings, self::ICON_HREF_PLACEHOLDER )
			: '';

		// Compose the <img> class (base plus any border-colour preset classnames) and
		// fold in the panels' inline declarations as the style.
		$image_class = 'kntnt-photo-drop-gallery__image';
		if ( $image_support['class'] !== '' ) {
			$image_class .= ' ' . $image_support['class'];
		}

		// Compose the caption class prefix (base, nine-point anchor, and any
		// colour/typography preset classnames); the per-figure text is appended later.
		$caption_class = 'kntnt-photo-drop-gallery__caption'
			. ' kntnt-photo-drop-gallery__caption--anchor-' . $caption->anchor;
		if ( $caption_support['class'] !== '' ) {
			$caption_class .= ' ' . $caption_support['class'];
		}

		return new Figure_Chrome(
			$icon_template,
			esc_attr( $image_class ),
			esc_attr( $image_support['style'] ),
			esc_attr( $caption_class ),
			esc_attr( $caption_support['style'] ),
		);

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
	 * @param Figure_Chrome           $chrome     The render-constant figure chrome.
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function grid_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		Figure_Chrome $chrome,
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
				$caption,
				$chrome,
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
	 * @param array<int,Gallery_Item> $items      The images.
	 * @param Descriptor              $descriptor The collection contract.
	 * @param string                  $base_url   The collection base URL.
	 * @param Caption_Settings        $caption    The resolved caption settings.
	 * @param Figure_Chrome           $chrome     The render-constant figure chrome.
	 * @param array<string,mixed>     $attributes The block attributes.
	 * @return string The concatenated figure markup.
	 */
	private static function justified_figures(
		array $items,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		Figure_Chrome $chrome,
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
				$chrome,
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
	 * (issue #33) and so follows the link; its text is also mirrored onto the
	 * anchor as a data attribute so the lightbox slide can show the same caption.
	 * The render-constant chrome (issue #34) — the overlay download-icon template
	 * and the image/caption classes and styles — is pre-composed once per render
	 * and threaded in via `$chrome`, so this loop only fills in the per-image URL,
	 * dimensions, srcset, caption text, and the icon's href. Every URL and
	 * attribute is escaped at the point of output (the `$chrome` strings were
	 * escaped on construction).
	 *
	 * @since 0.4.0
	 * @since 0.5.0 The icon anchor is the sole download trigger; the thumbnail
	 *              anchor never carries the `download` attribute.
	 *
	 * @param Gallery_Item     $item       The image.
	 * @param Descriptor       $descriptor The collection contract.
	 * @param string           $base_url   The collection base URL.
	 * @param Caption_Settings $caption The resolved caption settings.
	 * @param Figure_Chrome    $chrome     The render-constant figure chrome.
	 * @param string           $sizes      The layout-aware `sizes` attribute value.
	 * @param string           $item_style The inline style for the figure (layout-specific).
	 * @param string           $item_class The layout-specific figure class.
	 * @return string The figure markup.
	 */
	private static function figure(
		Gallery_Item $item,
		Descriptor $descriptor,
		string $base_url,
		Caption_Settings $caption,
		Figure_Chrome $chrome,
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
		// srcset refines), derive the alt from the filename, and assemble the caption
		// text once — it feeds both the overlay figcaption and the anchor's caption
		// data attribute the lightbox mirrors.
		$smallest     = $candidates[0]['url'] ?? $main_url;
		$alt          = Caption_Builder::build( $relative, Caption_Builder::CONTENT_FILENAME, true, false, '', '' );
		$caption_text = self::caption_text( $relative, $caption, $descriptor->name );
		$caption_html = self::caption_html( $caption_text, $chrome->caption_class, $chrome->caption_style );

		// Compose the lazy, dimensioned <img>, carrying the pre-escaped border/shadow
		// block-support class and style; the style attribute is omitted entirely when
		// the panels contributed nothing rather than shipping an empty `style=""`.
		$image = sprintf(
			'<img class="%1$s"%2$s src="%3$s" srcset="%4$s" sizes="%5$s"'
				. ' width="%6$d" height="%7$d" loading="lazy" decoding="async" alt="%8$s" />',
			$chrome->image_class,
			$chrome->image_style === '' ? '' : sprintf( ' style="%s"', $chrome->image_style ),
			esc_url( $smallest ),
			esc_attr( $srcset ),
			esc_attr( $sizes ),
			$item->width,
			$item->height,
			esc_attr( $alt ),
		);

		// Wrap the image in an <a href> to the main image — the no-JS fallback and the
		// lightbox's upgrade hook. The data attributes hand the main URL, the srcset,
		// and the caption text to the lightbox without re-parsing the markup. The
		// anchor never downloads; in the download-on / lightbox-off cell the view
		// module suppresses its plain click so only the icon anchor saves the image.
		$caption_attr = $caption_text !== ''
			? sprintf( ' data-kntnt-photo-drop-caption="%s"', esc_attr( $caption_text ) )
			: '';
		$link         = sprintf(
			'<a class="kntnt-photo-drop-gallery__link" href="%1$s" data-kntnt-photo-drop-full="%1$s"'
				. ' data-kntnt-photo-drop-srcset="%2$s"%3$s>%4$s</a>',
			esc_url( $main_url ),
			esc_attr( $srcset ),
			$caption_attr,
			$image,
		);

		// Fill the icon template's href with this image's main URL — the icon anchor
		// is the figure's only download trigger; an empty template means no icon.
		$icon = $chrome->icon_template === ''
			? ''
			: str_replace( self::ICON_HREF_PLACEHOLDER, esc_url( $main_url ), $chrome->icon_template );

		// The caption and the icon are both anchored overlays over the image, so they
		// follow the link inside the figure and are positioned absolutely by their
		// anchor classes.
		return sprintf(
			'<figure class="kntnt-photo-drop-gallery__item %1$s" style="%2$s">%3$s%4$s%5$s</figure>',
			esc_attr( $item_class ),
			esc_attr( $item_style ),
			$link,
			$icon,
			$caption_html,
		);

	}

	/**
	 * Builds the overlay download-icon anchor — the sole download trigger.
	 *
	 * A small `<a download>` badge anchored inside the image by the nine-point
	 * anchor class and styled by the bespoke download-icon controls — size,
	 * background, foreground — projected as inline custom properties the stylesheet
	 * reads. The glyph itself is an inline SVG data URI painted through a CSS mask,
	 * so there is no SVG element in the markup, icon font, or extra request. Only a
	 * click on this anchor downloads; the view module intercepts the plain click
	 * and saves the image programmatically (so no environment can turn it into
	 * navigation or a new tab), while the anchor's own `download` semantics remain
	 * the no-JS fallback. The translated `aria-label` is the accessible name of
	 * the otherwise text-free anchor.
	 *
	 * @since 0.4.0
	 * @since 0.5.0 Became an `<a download>` anchor (was a decorative `<span>`).
	 *
	 * @param Download_Settings $download    The resolved download-icon styling.
	 * @param string            $href        The href attribute value — pre-escaped by the caller,
	 *                                       the literal href placeholder, or '' when the view
	 *                                       module sets it per slide.
	 * @param string            $extra_class An additional class for the anchor, or ''.
	 * @return string The icon anchor markup.
	 */
	private static function download_icon(
		Download_Settings $download,
		string $href,
		string $extra_class = '',
	): string {

		// Place the icon by its anchor class and carry its size/colours as inline
		// custom properties; the stylesheet draws the glyph from those properties.
		$class = 'kntnt-photo-drop-gallery__download'
			. ' kntnt-photo-drop-gallery__download--anchor-' . $download->anchor
			. ( $extra_class === '' ? '' : ' ' . $extra_class );
		$style = sprintf(
			'--kntnt-photo-drop-download-size:%1$s;'
				. '--kntnt-photo-drop-download-bg:%2$s;'
				. '--kntnt-photo-drop-download-fg:%3$s;',
			$download->size,
			$download->background,
			$download->foreground,
		);

		return sprintf(
			'<a class="%1$s" style="%2$s" href="%3$s" download aria-label="%4$s"></a>',
			esc_attr( $class ),
			esc_attr( $style ),
			$href,
			esc_attr__( 'Download image', 'kntnt-photo-drop' ),
		);

	}

	/**
	 * Assembles the caption text for one image from its path and the settings.
	 *
	 * A thin pass-through to the pure `Caption_Builder`, kept so both the overlay
	 * figcaption and the anchor's caption data attribute (which the lightbox mirrors)
	 * draw on the same single assembly per figure. An empty result (content "none"
	 * or an empty breadcrumb) means no caption is shown anywhere for that image.
	 *
	 * @since 0.4.0
	 *
	 * @param string           $relative_path   The image path relative to the root.
	 * @param Caption_Settings $caption         The resolved caption settings.
	 * @param string           $collection_name The collection display name.
	 * @return string The caption text, or '' when none.
	 */
	private static function caption_text(
		string $relative_path,
		Caption_Settings $caption,
		string $collection_name,
	): string {
		return Caption_Builder::build(
			$relative_path,
			$caption->content,
			$caption->humanize,
			$caption->include_name,
			$caption->separator,
			$collection_name,
		);
	}

	/**
	 * Wraps already-assembled caption text in an anchored overlay `<figcaption>`.
	 *
	 * Captions are always an anchored overlay inside the image (issue #33), so the
	 * class always carries the nine-point anchor variant. The figcaption's colour and
	 * typography arrive from the colour/typography block-support panels, pre-projected
	 * into the `$class`/`$style` pair the caller passes (the core Image-block
	 * skip-serialization pattern), so this method emits them verbatim rather than
	 * reading any bespoke colour attribute. Empty text yields no element at all — the
	 * lightbox's always-present empty caption uses the shared `figcaption()` composer
	 * directly instead.
	 *
	 * @since 0.4.0
	 *
	 * @param string $text       The assembled caption text (escaped here).
	 * @param string $class_attr The pre-escaped figcaption class attribute value.
	 * @param string $style_attr The pre-escaped figcaption style attribute value.
	 * @return string The figcaption markup, or '' when the text is empty.
	 */
	private static function caption_html( string $text, string $class_attr, string $style_attr ): string {

		// Empty text means no caption element at all (content "none" or an empty
		// breadcrumb).
		if ( $text === '' ) {
			return '';
		}

		return self::figcaption( $class_attr, $style_attr, esc_html( $text ) );

	}

	/**
	 * Emits the single `<figcaption>` markup both the gallery and the lightbox use.
	 *
	 * The one source of the overlay caption element, so the gallery figures and the
	 * lightbox slide share identical structure (issue #34). The class and style are
	 * already escaped by the caller; the style attribute is omitted entirely when the
	 * block-support panels contributed nothing.
	 *
	 * @since 0.4.0
	 *
	 * @param string $class_attr The pre-escaped figcaption class attribute value.
	 * @param string $style_attr The pre-escaped figcaption style attribute value.
	 * @param string $inner      The figcaption's inner HTML (already escaped, or '' for the lightbox).
	 * @return string The figcaption markup.
	 */
	private static function figcaption( string $class_attr, string $style_attr, string $inner ): string {
		return sprintf(
			'<figcaption class="%1$s"%2$s>%3$s</figcaption>',
			$class_attr,
			$style_attr === '' ? '' : sprintf( ' style="%s"', $style_attr ),
			$inner,
		);
	}

	/**
	 * Wraps the figures in the layout container plus the block-supports wrapper.
	 *
	 * Mode A applies core's Grid layout via the `minimumColumnWidth` and `gap`
	 * style variables on the inner container; mode B applies the justified flex
	 * container. The outer wrapper is core's block-supports wrapper (alignment,
	 * colour, typography, spacing, anchor) plus the project class, the lightbox and
	 * download flags, and the Interactivity directives the view module reads.
	 *
	 * The two flags drive the whole click matrix (issue #34): the view module reads
	 * `data-kntnt-photo-drop-lightbox` and `data-kntnt-photo-drop-download` to decide
	 * whether a thumbnail click opens the lightbox or is suppressed entirely (the
	 * lightbox-off cells — only the icon anchor downloads, the image itself does
	 * nothing). The `init` hook is bound on every frontend render — for the lightbox
	 * wiring, the justified layout's last-row correction, or the click suppression —
	 * and the per-block context and the hidden overlay are appended only when the
	 * lightbox is on, so a lightbox-off gallery carries no overlay chrome. When the
	 * lightbox is on, the overlay also carries a download-icon anchor (only when
	 * download is on) and a caption element (filled only when the shared Caption
	 * content is not "none").
	 *
	 * The editor preview suppresses interactivity unconditionally: the lightbox flag
	 * reads `false`, no overlay/context is emitted, and no `init` is bound, so clicks
	 * stay inert in the canvas — yet the download icon may still appear on a figure
	 * (the download-on / lightbox-off cell) so the preview matches the frontend.
	 *
	 * @since 0.6.0
	 * @since 0.2.0 The `init` hook is also bound for the justified layout with the lightbox off.
	 * @since 0.4.0 Replaced the single lightbox flag with the lightbox + download click matrix.
	 * @since 0.5.0 The icon anchor is the sole download trigger in every cell.
	 *
	 * @param array<string,mixed>              $attributes        The block attributes.
	 * @param string                           $layout            The resolved layout token.
	 * @param string                           $figures           The concatenated figure markup.
	 * @param bool                             $lightbox          Whether the lightbox is wired (false in preview).
	 * @param bool                             $download          Whether the download behaviour is on.
	 * @param bool                             $is_preview        Whether this is the capped editor preview.
	 * @param Caption_Settings                 $caption           The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support   The figcaption block-support style/class.
	 * @param Download_Settings                $download_settings The resolved download-icon styling.
	 * @return string The full gallery markup.
	 */
	private static function wrap(
		array $attributes,
		string $layout,
		string $figures,
		bool $lightbox,
		bool $download,
		bool $is_preview,
		Caption_Settings $caption,
		array $caption_support,
		Download_Settings $download_settings,
	): string {

		// Build the inner container's style from the gap (both layouts) and, for the
		// grid, the minimum column width that drives core's auto-fill grid. The gap
		// is the block-support `blockGap`, read from the spacing support and applied
		// to both layout containers (issue #33).
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
		// namespace, and the lightbox/download flags the view module reads. On the
		// frontend the `init` hook always runs — the view module wires the lightbox,
		// corrects the justified last row, and/or suppresses inert clicks — while the
		// per-block context exists only for the lightbox. The editor preview binds no
		// `init` at all so the canvas stays inert.
		$wrapper_attrs = [
			'class'                          => 'kntnt-photo-drop-gallery',
			'data-wp-interactive'            => 'kntnt-photo-drop/gallery',
			'data-kntnt-photo-drop-lightbox' => $lightbox ? 'true' : 'false',
			'data-kntnt-photo-drop-download' => $download ? 'true' : 'false',
		];
		if ( ! $is_preview ) {
			$wrapper_attrs['data-wp-init'] = 'callbacks.init';
		}
		if ( $lightbox ) {
			$wrapper_attrs['data-wp-context'] = self::lightbox_context();
		}
		$wrapper = get_block_wrapper_attributes( $wrapper_attrs );

		// Append the hidden lightbox overlay only when the lightbox is on, carrying its
		// own download affordance and caption element so the enlarged image mirrors the
		// gallery (issue #34); a lightbox-off gallery carries no overlay chrome.
		$overlay = $lightbox
			? self::lightbox_overlay( $download, $caption, $caption_support, $download_settings )
			: '';

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
	 * When download is on (issue #34), an overlay download-icon anchor — the sole
	 * download trigger, whose `href` the view module sets to the current slide's
	 * main image; styled by the bespoke download-icon controls, placed by its
	 * nine-point anchor — sits inside the image's box. A click on the enlarged
	 * image outside the icon does nothing. When download is off the image is a
	 * bare `<img>` and a click does nothing. When the shared Caption content is
	 * not "none", a caption `<figcaption>` (the identical overlay element the
	 * gallery figures use — same anchor and colour/typography projection) sits
	 * inside the figure for the view module to fill per slide.
	 *
	 * @since 0.7.0
	 * @since 0.2.0 Added the hidden load-failure message element.
	 * @since 0.4.0 Added the in-lightbox download affordance and the mirrored caption.
	 * @since 0.5.0 The icon anchor is the sole download trigger; the enlarged image
	 *              itself no longer downloads.
	 *
	 * @param bool                             $download          Whether the in-lightbox download is on.
	 * @param Caption_Settings                 $caption           The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support   The figcaption block-support style/class.
	 * @param Download_Settings                $download_settings The resolved download-icon styling.
	 * @return string The escaped overlay markup.
	 */
	private static function lightbox_overlay(
		bool $download,
		Caption_Settings $caption,
		array $caption_support,
		Download_Settings $download_settings,
	): string {

		// Label every control and the dialog itself; these are the only runtime
		// strings the overlay carries, all translated and escaped at output.
		$dialog_label = esc_attr__( 'Image viewer', 'kntnt-photo-drop' );
		$close_label  = esc_attr__( 'Close', 'kntnt-photo-drop' );
		$prev_label   = esc_attr__( 'Previous image', 'kntnt-photo-drop' );
		$next_label   = esc_attr__( 'Next image', 'kntnt-photo-drop' );
		$error_text   = esc_html__( 'The image could not be loaded.', 'kntnt-photo-drop' );

		// Build the figure's inner markup: the live image, wrapped in a download
		// anchor with the overlay icon when download is on, and the mirrored caption
		// element when the caption content is not "none". The view module fills the
		// image, the anchor href, and the caption text on open and on each page.
		$figure_inner = self::lightbox_figure_inner( $download, $caption, $caption_support, $download_settings );

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
	 * Builds the lightbox figure's inner markup: the image, the download affordance,
	 * and the mirrored caption.
	 *
	 * With download on the image and the overlay download-icon anchor — the sole
	 * download trigger, its `href` set by the view module per slide — sit inside a
	 * positioning wrapper that shrink-wraps the image, so the icon is anchored
	 * inside the image's own box. A click on the enlarged image outside the icon
	 * does nothing. With download off the image stands bare. The caption, when the
	 * content is not "none", is the same anchored overlay `<figcaption>` the
	 * gallery figures carry — same anchor, same colour/typography projection — so
	 * the lightbox caption mirrors the gallery; the view module fills its text per
	 * slide.
	 *
	 * @since 0.4.0
	 * @since 0.5.0 The icon anchor replaced the image-wrapping download anchor.
	 *
	 * @param bool                             $download          Whether the in-lightbox download is on.
	 * @param Caption_Settings                 $caption           The resolved caption settings.
	 * @param array{style:string,class:string} $caption_support   The figcaption block-support style/class.
	 * @param Download_Settings                $download_settings The resolved download-icon styling.
	 * @return string The figure's inner markup.
	 */
	private static function lightbox_figure_inner(
		bool $download,
		Caption_Settings $caption,
		array $caption_support,
		Download_Settings $download_settings,
	): string {

		// The live image the view module swaps per slide; it starts empty.
		$image = '<img class="kntnt-photo-drop-lightbox__image" src="" alt="" />';

		// With download on, put the image and the icon anchor — the sole download
		// trigger, href set per slide by the view module — inside a wrapper that
		// shrink-wraps the image so the icon anchors within the image's own box;
		// with download off the bare image makes a click do nothing.
		if ( $download ) {
			$icon  = self::download_icon( $download_settings, '', 'kntnt-photo-drop-lightbox__download' );
			$image = sprintf(
				'<span class="kntnt-photo-drop-lightbox__media">%1$s%2$s</span>',
				$image,
				$icon,
			);
		}

		// Mirror the gallery caption inside the lightbox figure when the content is not
		// "none": the same overlay element, anchor, and block-support projection, with
		// the text left empty for the view module to fill per slide.
		$caption_element = $caption->content === Caption_Builder::CONTENT_NONE
			? ''
			: self::lightbox_caption( $caption->anchor, $caption_support );

		return $image . $caption_element;

	}

	/**
	 * Builds the empty mirrored caption element for the lightbox figure.
	 *
	 * The identical overlay `<figcaption>` the gallery figures carry — the base
	 * class, the nine-point anchor variant, and the colour/typography block-support
	 * preset classnames and inline declarations — but with empty text the view module
	 * fills per slide from each thumbnail's caption data attribute.
	 *
	 * @since 0.4.0
	 *
	 * @param string                           $anchor  The nine-point overlay anchor.
	 * @param array{style:string,class:string} $support The figcaption block-support style/class.
	 * @return string The empty figcaption markup.
	 */
	private static function lightbox_caption( string $anchor, array $support ): string {

		// Compose the same caption classes the gallery figures use plus the lightbox
		// marker, so the lightbox caption is styled and placed identically; the view
		// module supplies the text, so this figcaption ships empty (always present).
		$classes = 'kntnt-photo-drop-gallery__caption kntnt-photo-drop-lightbox__caption'
			. ' kntnt-photo-drop-gallery__caption--anchor-' . $anchor;
		if ( $support['class'] !== '' ) {
			$classes .= ' ' . $support['class'];
		}

		return self::figcaption( esc_attr( $classes ), esc_attr( $support['style'] ), '' );

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
		$anchor   = self::one_of(
			self::read_string( $attributes, 'captionAnchor' ),
			self::NINE_POINT_ANCHORS,
			'bottom-left',
		);

		return new Caption_Settings(
			$content,
			self::read_bool( $attributes, 'captionHumanize', true ),
			self::read_bool( $attributes, 'captionIncludeCollectionName', false ),
			self::read_string( $attributes, 'captionSeparator' ),
			$anchor,
		);

	}

	/**
	 * Collects the download-icon styling from the attributes into one value object.
	 *
	 * Reads the four custom download-icon controls once — size, background,
	 * foreground, and the nine-point anchor — defaulting each to the documented
	 * value when absent or empty, and narrowing the anchor to the allowed set. The
	 * block-support colour panel is claimed by the caption, so the icon's colours
	 * are bespoke attributes resolved here, not block supports (issue #34).
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return Download_Settings The resolved download-icon settings.
	 */
	private static function download_settings( array $attributes ): Download_Settings {

		// Default each control to its documented value when unset, and strictly
		// shape-validate the free-text size and colours so a hostile value cannot
		// inject extra declarations into the icon's inline style (these values are
		// interpolated, and esc_attr does not strip `;`/`:`). Narrow the anchor to the
		// nine points.
		$size       = self::css_length( self::read_string( $attributes, 'downloadIconSize' ), '2rem' );
		$background = self::css_color( self::read_string( $attributes, 'downloadIconBackground' ), '#00000080' );
		$foreground = self::css_color( self::read_string( $attributes, 'downloadIconForeground' ), '#ffffff' );
		$anchor     = self::one_of(
			self::read_string( $attributes, 'downloadIconAnchor' ),
			self::NINE_POINT_ANCHORS,
			'top-left',
		);

		return new Download_Settings( $size, $background, $foreground, $anchor );

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
	 * @since 0.4.0
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
	 * Bespoke length attributes (the download-icon size, the grid's minimum column
	 * width, the non-preset block gap) are interpolated straight into inline `style`
	 * attributes, where `esc_attr` does not strip `;`/`:` — so a hostile value such as
	 * `"4px;position:fixed;inset:0"` would inject extra declarations onto the public
	 * page (block-comment JSON escapes KSES). Only a single numeric length with a
	 * known unit is accepted; anything else falls back to the attribute default.
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
	 * Validates a free-text CSS colour, falling back when the shape is unexpected.
	 *
	 * The download-icon background and foreground are bespoke attributes interpolated
	 * into an inline `style`, so the same injection surface as {@see css_length}
	 * applies. A hex colour (3/4/6/8 digits), an `rgb()/rgba()/hsl()/hsla()` function
	 * whose argument list holds only digits, separators, and `%`, or a bare CSS ident
	 * keyword (e.g. `red`, `transparent`) is accepted; anything else — anything that
	 * could carry a `;` or `:` and inject a declaration — falls back to the default.
	 *
	 * @since 0.4.0
	 *
	 * @param string $value    The candidate colour.
	 * @param string $fallback The default to use when the value is not a clean colour.
	 * @return string The validated colour, or the fallback.
	 */
	private static function css_color( string $value, string $fallback ): string {
		$value = trim( $value );
		$is_hex     = preg_match( '/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) === 1;
		$is_func    = preg_match( '/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/', $value ) === 1;
		$is_keyword = preg_match( '/^[a-zA-Z]+$/', $value ) === 1;
		return $is_hex || $is_func || $is_keyword ? $value : $fallback;
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
	 * `blockGap` is enabled (issue #33 replaced the bespoke `blockGap` attribute
	 * with the support). It is either a custom length (`"20px"`) or a spacing
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
