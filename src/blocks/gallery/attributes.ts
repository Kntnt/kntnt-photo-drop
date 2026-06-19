/**
 * Typed attribute shape for the Photo Gallery block.
 *
 * Mirrors the canonical schema in `block.json` and `docs/blocks.md`. The slug is
 * the only durable reference to a collection; everything else is presentation —
 * the start path and recursion that select which images render, the ordering, the
 * two layout modes and their knobs, the lightbox toggle, the slideshow trigger,
 * and the unified overlay framework (ADR-0015): four overlays — breadcrumbs,
 * download, add-to-media, trash — each with a visibility and a nine-point
 * position, plus the breadcrumb's hide-count and separator and one shared icon
 * size. The overlays' shared appearance (foreground/background, the breadcrumb
 * font, per-image border/shadow) comes from the block-support panels via
 * skip-serialization, not from bespoke attributes. The interface is shared by the
 * edit component and the inspector panels so a single source of truth pins every
 * attribute's type.
 *
 * @since 0.6.0
 */

/**
 * The two layout modes: a uniform grid (A) or justified rows (B).
 *
 * @since 0.6.0
 */
export type GalleryLayout = 'grid' | 'justified';

/**
 * The three slideshow trigger modes (ADR-0009): no slideshow, the built-in
 * quiet button above the gallery, or a designer-supplied element anywhere on
 * the page carrying the documented `data-kntnt-photo-drop-slideshow` attribute.
 *
 * @since 0.7.0
 */
export type SlideshowTrigger = 'off' | 'button' | 'custom';

/**
 * The four visibilities of an overlay (ADR-0015): nowhere, on the grid
 * thumbnail, on the lightbox ("full") image, or on both. "Full" requires the
 * lightbox to be on, and the slideshow never shows overlays.
 *
 * @since 0.15.0
 */
export type OverlayVisibility = 'off' | 'thumbnail' | 'full' | 'both';

/**
 * The nine anchor points an overlay can be positioned at.
 *
 * @since 0.15.0
 */
export type OverlayPosition =
	| 'top-left'
	| 'top-center'
	| 'top-right'
	| 'middle-left'
	| 'middle-center'
	| 'middle-right'
	| 'bottom-left'
	| 'bottom-center'
	| 'bottom-right';

/**
 * The persisted attributes of the Photo Gallery block.
 *
 * @since 0.6.0
 */
export interface GalleryAttributes {
	/** The collection slug the gallery renders; `''` until one is chosen. */
	collection: string;
	/** The editor-set start path relative to the collection root; `''` = root. */
	startPath: string;
	/** Whether to render every image under the start path recursively (flattened). */
	recursive: boolean;
	/** The ordering of the flattened list: `'asc'` or `'desc'`. */
	order: 'asc' | 'desc';
	/**
	 * The message shown to everyone when the chosen collection resolves but holds
	 * no images; `''` = the translated default (ADR-0012). A broken reference (no
	 * collection, dangling slug) shows an editor-only notice instead, not this.
	 */
	emptyMessage: string;
	/** The layout mode: uniform grid or justified rows. */
	layout: GalleryLayout;
	/** Mode A: the minimum grid column width (a CSS length). */
	minimumColumnWidth: string;
	/** Mode A: how each image fills its cell. */
	imageFit: 'cover' | 'contain';
	/** Mode A: a fixed CSS aspect ratio, or `''` to use each image's stored ratio. */
	aspectRatio: string;
	/** Mode B: the target row height in pixels. */
	targetRowHeight: number;
	/** Whether the Interactivity-API lightbox is wired (the no-JS fallback is always present). */
	lightbox: boolean;
	/** The slideshow trigger mode: off, the built-in button, or a custom element (ADR-0009). */
	slideshow: SlideshowTrigger;
	/** The built-in slideshow button's label; `''` = the translated default. */
	slideshowButtonLabel: string;
	/** How long each slide stands fully visible, in whole seconds (≥ 1). */
	slideshowSeconds: number;
	/** Where the breadcrumbs overlay shows. */
	breadcrumbsVisibility: OverlayVisibility;
	/** The breadcrumbs overlay's nine-point position; its horizontal half also sets text alignment. */
	breadcrumbsPosition: OverlayPosition;
	/** How many leading crumbs to hide (`0` = all, the collection name first). */
	breadcrumbsHideCount: number;
	/** The breadcrumb separator (free text). */
	breadcrumbsSeparator: string;
	/** Where the download overlay shows. */
	downloadVisibility: OverlayVisibility;
	/** The download overlay's nine-point position. */
	downloadPosition: OverlayPosition;
	/** Where the add-to-media overlay shows (rendered front-end only for capable users). */
	addToMediaVisibility: OverlayVisibility;
	/** The add-to-media overlay's nine-point position. */
	addToMediaPosition: OverlayPosition;
	/** Where the trash overlay shows (rendered front-end only for capable users). */
	trashVisibility: OverlayVisibility;
	/** The trash overlay's nine-point position. */
	trashPosition: OverlayPosition;
	/** The shared size (a CSS length) of all three action icons, independent of the breadcrumb font. */
	iconSize: string;
	/**
	 * The block's HTML anchor, injected by core's `supports.anchor` rather than
	 * declared in `block.json`. A custom slideshow trigger targets the gallery
	 * by this id (ADR-0009).
	 */
	anchor?: string;
	/**
	 * Render-time-only flag the editor sets on the `ServerSideRender` preview to
	 * cap the figures and suppress the lightbox. It is never written through
	 * `setAttributes`, so — left at its `false` default — it is never serialised
	 * into `post_content` and cannot reach a frontend render.
	 */
	isEditorPreview: boolean;
	[ key: string ]: unknown;
}
