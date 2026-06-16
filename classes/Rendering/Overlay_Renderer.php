<?php
/**
 * Emits the gallery's unified overlay markup for one surface (ADR-0015).
 *
 * The Gallery layers four overlays over each image — breadcrumbs, download,
 * add-to-media, trash — each with one visibility and one nine-point position,
 * sharing one appearance: the Colour support gives every overlay one foreground
 * and background, Typography gives the breadcrumb its font, and one `iconSize`
 * sizes the three action icons. This renderer is the markup side of that model.
 * It resolves the four placements and the shared appearance once (from the block
 * attributes), then emits the overlay chrome for a surface: the breadcrumb
 * `<figcaption>` and the action-icon clusters that show there. Icons sharing a
 * position auto-cluster into a fixed-order row (download → add-to-media →
 * trash). "Thumbnail" is the grid tile and "full" is the lightbox; the slideshow
 * is overlay-free, so it never calls this. The breadcrumb's leading ellipsis and
 * its alignment are pure CSS (driven by the position anchor class), so no JS
 * fitter is needed.
 *
 * Download is the one action overlay shipped whole in #47 — an `<a download>`
 * anchor the view module upgrades to a programmatic save. Add-to-media and trash
 * are emitted as inert positioned stubs here; their gated REST write-path is
 * #52/#53 (and the capability-gated rendering, ADR-0015).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Resolves and emits the gallery overlays for the thumbnail and lightbox surfaces.
 *
 * Built once per render through {@see from_attributes()} and consumed by
 * `Render_Gallery` per figure (thumbnail) and once for the lightbox. It owns its
 * own escaping (a rendering collaborator, like `Render_Gallery` itself), keeping
 * the overlay markup — the breadcrumb element, the icon clusters, the shared
 * custom properties — out of the orchestrator. The breadcrumb text is assembled
 * by the pure `Breadcrumb_Builder`; the placement decisions by the pure
 * `Overlay_Placement`; the appearance projection by `Block_Style_Support`.
 *
 * @since 0.11.0
 */
final readonly class Overlay_Renderer {

	/**
	 * The surface token for the grid thumbnail.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const SURFACE_THUMBNAIL = 'thumbnail';

	/**
	 * The surface token for the lightbox ("full") image.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const SURFACE_FULL = 'full';

	/**
	 * The action icons in their fixed cluster order.
	 *
	 * Icons sharing a position cluster into one row in this order — download, then
	 * add-to-media, then trash — so the row reads identically wherever it appears.
	 *
	 * @since 0.11.0
	 * @var array<int,string>
	 */
	private const ICON_ORDER = [ 'download', 'add-to-media', 'trash' ];

	/**
	 * Constructs the resolved overlay renderer.
	 *
	 * @since 0.11.0
	 *
	 * @param bool                            $lightbox Whether the lightbox is on (gates the full surface).
	 * @param Overlay_Placement               $breadcrumbs The breadcrumb overlay placement.
	 * @param array<string,Overlay_Placement> $icons The action-icon placements, keyed by name.
	 * @param int                             $hide_count The breadcrumb leading-crumb hide count.
	 * @param string                          $separator The breadcrumb separator (free text).
	 * @param string                          $breadcrumb_class The pre-escaped breadcrumb class.
	 * @param string                          $breadcrumb_style The pre-escaped breadcrumb style, or ''.
	 * @param string                          $cluster_style The pre-escaped icon-cluster style, or ''.
	 */
	private function __construct(
		private bool $lightbox,
		private Overlay_Placement $breadcrumbs,
		private array $icons,
		private int $hide_count,
		private string $separator,
		private string $breadcrumb_class,
		private string $breadcrumb_style,
		private string $cluster_style,
	) {}

	/**
	 * Resolves the renderer from the block attributes and the lightbox flag.
	 *
	 * Narrows the four placements (each with its documented default position),
	 * reads the breadcrumb hide-count and separator, and projects the shared
	 * appearance once: the Colour + Typography support onto the breadcrumb, and
	 * the Colour foreground/background plus `iconSize` into the icon cluster's
	 * custom properties. The projections are escaped here so the per-figure loop
	 * interpolates finished strings.
	 *
	 * @since 0.11.0
	 *
	 * @param array<string,mixed> $attributes  The block attributes.
	 * @param bool                $lightbox    Whether the gallery's lightbox is on.
	 * @return self The resolved overlay renderer.
	 */
	public static function from_attributes( array $attributes, bool $lightbox ): self {

		// Narrow each overlay's visibility and position; the action icons share the
		// nine-point vocabulary but each keeps its own documented default position.
		$breadcrumbs = Overlay_Placement::from(
			self::read_string( $attributes, 'breadcrumbsVisibility' ),
			self::read_string( $attributes, 'breadcrumbsPosition' ),
			'bottom-left',
		);
		$icons = [
			'download'     => Overlay_Placement::from(
				self::read_string( $attributes, 'downloadVisibility' ),
				self::read_string( $attributes, 'downloadPosition' ),
				'top-left',
			),
			'add-to-media' => Overlay_Placement::from(
				self::read_string( $attributes, 'addToMediaVisibility' ),
				self::read_string( $attributes, 'addToMediaPosition' ),
				'top-right',
			),
			'trash'        => Overlay_Placement::from(
				self::read_string( $attributes, 'trashVisibility' ),
				self::read_string( $attributes, 'trashPosition' ),
				'top-right',
			),
		];

		// Read the breadcrumb hide-count and separator (free text, used verbatim by
		// the pure builder).
		$hide_count = self::read_int( $attributes, 'breadcrumbsHideCount' );
		$separator  = self::read_string( $attributes, 'breadcrumbsSeparator' );

		// Project the shared appearance once: the breadcrumb's colour + typography,
		// and the icon cluster's foreground/background + size as custom properties.
		$breadcrumb_support = Block_Style_Support::overlay( $attributes );
		$breadcrumb_class   = 'kntnt-photo-drop-gallery__breadcrumbs';
		if ( $breadcrumb_support['class'] !== '' ) {
			$breadcrumb_class .= ' ' . $breadcrumb_support['class'];
		}
		$cluster_style = self::cluster_style( $attributes );

		return new self(
			$lightbox,
			$breadcrumbs,
			$icons,
			$hide_count,
			$separator,
			esc_attr( $breadcrumb_class ),
			esc_attr( $breadcrumb_support['style'] ),
			esc_attr( $cluster_style ),
		);

	}

	/**
	 * Whether the breadcrumb overlay is visible on any surface.
	 *
	 * Used by the figure builder to decide whether to assemble and mirror the
	 * breadcrumb text onto the anchor (so the lightbox can read it without
	 * re-deriving the path); an off breadcrumb contributes nothing.
	 *
	 * @since 0.11.0
	 *
	 * @return bool True when the breadcrumb visibility is not off.
	 */
	public function breadcrumbs_visible(): bool {
		return $this->breadcrumbs->visibility !== Overlay_Placement::OFF;
	}

	/**
	 * Assembles the breadcrumb text for one image (the collection name first).
	 *
	 * A thin pass-through to the pure `Breadcrumb_Builder` so the figure builder,
	 * the anchor data attribute, and any surface draw on one assembly per image.
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $collection_name The collection's display name.
	 * @return string The breadcrumb text, or '' when breadcrumbs are off.
	 */
	public function breadcrumbs_text( string $relative_path, string $collection_name ): string {
		return $this->breadcrumbs_visible()
			? Breadcrumb_Builder::text( $relative_path, $collection_name, $this->hide_count, $this->separator )
			: '';
	}

	/**
	 * Emits the thumbnail-surface overlays for one figure.
	 *
	 * The breadcrumb element (filled with the image's text) when the breadcrumb
	 * shows on the thumbnail, plus the action-icon clusters that show there. The
	 * download icon's `href` is the image's main URL; the inert add-to-media and
	 * trash stubs carry no target. Empty when no overlay shows on the thumbnail.
	 *
	 * @since 0.11.0
	 *
	 * @param string $breadcrumb_text The pre-assembled breadcrumb text for this image.
	 * @param string $main_url        The image's main URL (the download target).
	 * @return string The thumbnail overlay markup.
	 */
	public function thumbnail( string $breadcrumb_text, string $main_url ): string {

		// The breadcrumb element carries the image's text when it shows on the
		// thumbnail; the icon clusters carry the download href for this image.
		$breadcrumb = $this->breadcrumbs->on_thumbnail()
			? $this->breadcrumb_element( '', esc_html( $breadcrumb_text ) )
			: '';

		return $breadcrumb . $this->icon_clusters( self::SURFACE_THUMBNAIL, esc_url( $main_url ) );

	}

	/**
	 * Emits the lightbox-surface overlays once for the gallery's lightbox figure.
	 *
	 * The breadcrumb element (empty — the view module fills its text per slide
	 * from the mirrored anchor data) when the breadcrumb shows on the lightbox,
	 * plus the action-icon clusters that show there. The download icon's `href` is
	 * empty; the view module points it at the current slide per open. Empty when
	 * no overlay shows on the lightbox (which includes the lightbox being off).
	 *
	 * @since 0.11.0
	 *
	 * @return string The lightbox overlay markup.
	 */
	public function lightbox(): string {

		// The breadcrumb element ships empty for the view module to fill per slide;
		// the icon clusters carry an empty download href the view module sets.
		$breadcrumb = $this->breadcrumbs->on_full( $this->lightbox )
			? $this->breadcrumb_element( 'kntnt-photo-drop-lightbox__breadcrumbs', '' )
			: '';

		return $breadcrumb . $this->icon_clusters( self::SURFACE_FULL, '' );

	}

	/**
	 * Builds one breadcrumb `<figcaption>` for a surface.
	 *
	 * The base breadcrumb class plus the nine-point anchor variant and the
	 * colour/typography preset classes, with the colour/typography declarations as
	 * the inline style, and an optional surface marker (the lightbox's
	 * `__breadcrumbs` so the view module finds it). The text is already escaped (or
	 * empty for a surface the view fills). The leading-ellipsis overflow and the
	 * text alignment are pure CSS keyed off the anchor class.
	 *
	 * @since 0.11.0
	 *
	 * @param string $marker The surface marker class, or '' for the gallery figure.
	 * @param string $inner  The already-escaped breadcrumb text, or '' for the view to fill.
	 * @return string The breadcrumb figcaption markup.
	 */
	private function breadcrumb_element( string $marker, string $inner ): string {

		// Compose the class: the (pre-escaped) base + preset classes, the optional
		// surface marker, and the nine-point anchor variant.
		$class = $this->breadcrumb_class
			. ( $marker === '' ? '' : ' ' . esc_attr( $marker ) )
			. ' kntnt-photo-drop-gallery__breadcrumbs--anchor-' . esc_attr( $this->breadcrumbs->position );

		return sprintf(
			'<figcaption class="%1$s"%2$s>%3$s</figcaption>',
			$class,
			$this->breadcrumb_style === '' ? '' : sprintf( ' style="%s"', $this->breadcrumb_style ),
			$inner,
		);

	}

	/**
	 * Emits one icon cluster per position the action icons occupy on a surface.
	 *
	 * Groups the visible icons (for the given surface) by position, then emits a
	 * cluster `<span>` per occupied position holding its icons in the fixed order
	 * (download → add-to-media → trash). Each cluster carries the shared
	 * foreground/background/size custom properties; the anchor class places it.
	 *
	 * @since 0.11.0
	 *
	 * @param string $surface  One of SURFACE_THUMBNAIL or SURFACE_FULL.
	 * @param string $main_url The pre-escaped download target (empty for the lightbox).
	 * @return string The concatenated icon-cluster markup.
	 */
	private function icon_clusters( string $surface, string $main_url ): string {

		// Bucket the icons that show on this surface by their position, preserving
		// the fixed cluster order so a shared position reads download → add → trash.
		$by_position = [];
		foreach ( self::ICON_ORDER as $name ) {
			$placement = $this->icons[ $name ];
			if ( $this->shows( $placement, $surface ) ) {
				$by_position[ $placement->position ][] = $name;
			}
		}

		// Emit one anchored cluster per occupied position, each holding its icons in
		// the bucketed (fixed) order and carrying the shared custom properties.
		$markup = '';
		foreach ( $by_position as $position => $names ) {
			$icons = '';
			foreach ( $names as $name ) {
				$icons .= $this->icon( $name, $main_url, $surface );
			}
			$markup .= sprintf(
				'<span class="kntnt-photo-drop-gallery__icons kntnt-photo-drop-gallery__icons--anchor-%1$s"%2$s>'
					. '%3$s</span>',
				esc_attr( $position ),
				$this->cluster_style === '' ? '' : sprintf( ' style="%s"', $this->cluster_style ),
				$icons,
			);
		}

		return $markup;

	}

	/**
	 * Builds one action-icon control.
	 *
	 * The download icon is an `<a download>` anchor at the main image — the one
	 * action shipped whole in #47, which the view module upgrades to a
	 * programmatic save (the `download` attribute is the no-JS fallback). The
	 * add-to-media and trash icons are inert `<button>` stubs here: their gated
	 * REST write-path lands in #52/#53. Every icon shares the cluster's
	 * foreground/background/size; its glyph is a CSS mask the stylesheet draws
	 * from the icon's modifier class, so the markup carries no SVG.
	 *
	 * @since 0.11.0
	 *
	 * @param string $name     The icon name (one of ICON_ORDER).
	 * @param string $main_url The pre-escaped download target (empty for the lightbox).
	 * @param string $surface  One of SURFACE_THUMBNAIL or SURFACE_FULL.
	 * @return string The icon control markup.
	 */
	private function icon( string $name, string $main_url, string $surface ): string {

		// The download icon is a real <a download> at the main image; the lightbox's
		// per-slide href is set client-side, so it ships empty there and carries the
		// `lightbox__download` marker the view module finds it by.
		if ( $name === 'download' ) {
			$class = 'kntnt-photo-drop-gallery__icon kntnt-photo-drop-gallery__icon--download'
				. ( $surface === self::SURFACE_FULL ? ' kntnt-photo-drop-lightbox__download' : '' );
			return sprintf(
				'<a class="%1$s" href="%2$s" download aria-label="%3$s"></a>',
				$class,
				$main_url,
				esc_attr__( 'Download image', 'kntnt-photo-drop' ),
			);
		}

		// Add-to-media and trash are inert positioned stubs (the gated REST
		// write-path is #52/#53); each is a labelled button the framework places.
		$label = $name === 'add-to-media'
			? esc_attr__( 'Add to Media Library', 'kntnt-photo-drop' )
			: esc_attr__( 'Delete image', 'kntnt-photo-drop' );

		return sprintf(
			'<button type="button" class="kntnt-photo-drop-gallery__icon kntnt-photo-drop-gallery__icon--%1$s"'
				. ' aria-label="%2$s"></button>',
			esc_attr( $name ),
			$label,
		);

	}

	/**
	 * Whether an icon placement shows on the given surface.
	 *
	 * @since 0.11.0
	 *
	 * @param Overlay_Placement $placement The icon's placement.
	 * @param string            $surface   One of SURFACE_THUMBNAIL or SURFACE_FULL.
	 * @return bool True when the placement shows on the surface.
	 */
	private function shows( Overlay_Placement $placement, string $surface ): bool {
		return $surface === self::SURFACE_THUMBNAIL
			? $placement->on_thumbnail()
			: $placement->on_full( $this->lightbox );
	}

	/**
	 * Builds the icon cluster's inline style from the shared appearance.
	 *
	 * Reads the shared foreground/background (the Colour support) and the
	 * `iconSize` (a bespoke length, strictly shape-validated so a hostile value
	 * cannot inject extra declarations into the inline style), emitting only the
	 * custom properties that have a value — an unset colour falls through to the
	 * stylesheet's own default.
	 *
	 * @since 0.11.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return string The cluster inline style declarations (custom properties).
	 */
	private static function cluster_style( array $attributes ): string {

		// Resolve the shared colours and the validated icon size into custom
		// properties; only the set values are emitted.
		$colors = Block_Style_Support::overlay_colors( $attributes );
		$size   = self::css_length( self::read_string( $attributes, 'iconSize' ), '2rem' );
		$style  = sprintf( '--kntnt-photo-drop-icon-size:%s;', $size );
		if ( $colors['fg'] !== '' ) {
			$style .= sprintf( '--kntnt-photo-drop-overlay-fg:%s;', $colors['fg'] );
		}
		if ( $colors['bg'] !== '' ) {
			$style .= sprintf( '--kntnt-photo-drop-overlay-bg:%s;', $colors['bg'] );
		}

		return $style;

	}

	/**
	 * Validates a free-text CSS length, falling back when the shape is unexpected.
	 *
	 * `iconSize` is interpolated into an inline `style` where `esc_attr` does not
	 * strip `;`/`:`, so only a single numeric length with a known unit is accepted;
	 * anything else falls back to the default.
	 *
	 * @since 0.11.0
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
	 * Reads a string attribute, sanitised, defaulting to the empty string.
	 *
	 * @since 0.11.0
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
	 * Reads an integer attribute, defaulting to zero when absent or not numeric.
	 *
	 * @since 0.11.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $key        The attribute key.
	 * @return int The attribute value, or 0 when absent or non-numeric.
	 */
	private static function read_int( array $attributes, string $key ): int {
		$raw = $attributes[ $key ] ?? null;
		return is_int( $raw ) ? $raw : ( is_numeric( $raw ) ? (int) $raw : 0 );
	}

}
