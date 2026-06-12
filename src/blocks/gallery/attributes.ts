/**
 * Typed attribute shape for the Photo Gallery block.
 *
 * Mirrors the canonical schema in `block.json` and `docs/blocks.md`. The slug is
 * the only durable reference to a collection; everything else is presentation —
 * the start path and recursion that select which images render, the ordering, the
 * two layout modes and their knobs, the click behaviour (lightbox + download) and
 * the download-icon styling, and the caption settings. The interface is shared by
 * the edit component and the inspector panels so a single source of truth pins
 * every attribute's type.
 *
 * @since 0.6.0
 */

/**
 * The caption content modes: no caption, the filename, or a folder breadcrumb.
 *
 * @since 0.6.0
 */
export type CaptionContent = 'none' | 'filename' | 'path';

/**
 * The nine anchor points for the always-overlay caption.
 *
 * @since 0.6.0
 */
export type CaptionAnchor =
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
 * The nine anchor points for the overlay download icon.
 *
 * The same nine-point vocabulary as {@link CaptionAnchor}; kept a distinct alias
 * so the download icon's anchor reads as its own concept at every call site.
 *
 * @since 0.4.0
 */
export type DownloadIconAnchor = CaptionAnchor;

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
	/** Whether clicking an image downloads the full main image (lightbox image when both are on). */
	download: boolean;
	/** The overlay download icon's size (a CSS length, e.g. `2rem`). */
	downloadIconSize: string;
	/** The overlay download icon's background colour (a CSS colour). */
	downloadIconBackground: string;
	/** The overlay download icon's foreground (glyph) colour (a CSS colour). */
	downloadIconForeground: string;
	/** The nine-point anchor that places the overlay download icon inside the image. */
	downloadIconAnchor: DownloadIconAnchor;
	/** The slideshow trigger mode: off, the built-in button, or a custom element (ADR-0009). */
	slideshow: SlideshowTrigger;
	/** The built-in slideshow button's label; `''` = the translated default. */
	slideshowButtonLabel: string;
	/** How long each slide stands fully visible, in whole seconds (≥ 1). */
	slideshowSeconds: number;
	/**
	 * The block's HTML anchor, injected by core's `supports.anchor` rather than
	 * declared in `block.json`. A custom slideshow trigger targets the gallery
	 * by this id (ADR-0009).
	 */
	anchor?: string;
	/** The caption content mode. */
	captionContent: CaptionContent;
	/** Whether to humanise filenames and path segments. */
	captionHumanize: boolean;
	/** Whether a path breadcrumb is prefixed with the collection name. */
	captionIncludeCollectionName: boolean;
	/** The breadcrumb separator (free text). */
	captionSeparator: string;
	/** The nine-point anchor of the always-overlay caption. */
	captionAnchor: CaptionAnchor;
	/**
	 * Render-time-only flag the editor sets on the `ServerSideRender` preview to
	 * cap the figures and suppress the lightbox. It is never written through
	 * `setAttributes`, so — left at its `false` default — it is never serialised
	 * into `post_content` and cannot reach a frontend render.
	 */
	isEditorPreview: boolean;
	[ key: string ]: unknown;
}
