/**
 * Typed attribute shape for the Photo Gallery block.
 *
 * Mirrors the canonical schema in `block.json` and `docs/blocks.md`. The slug is
 * the only durable reference to a collection; everything else is presentation —
 * the start path and recursion that select which images render, the ordering, the
 * two layout modes and their knobs, the lightbox toggle, and the caption settings.
 * The interface is shared by the edit component and the inspector panels so a
 * single source of truth pins every attribute's type.
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
 * The caption position relative to its image.
 *
 * @since 0.6.0
 */
export type CaptionPosition = 'under' | 'above' | 'overlay';

/**
 * The nine overlay anchor points for an overlay caption.
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
	/** The layout mode: uniform grid or justified rows. */
	layout: GalleryLayout;
	/** Mode A: the minimum grid column width (a CSS length). */
	minimumColumnWidth: string;
	/** The gap between items (a CSS length), shared by both modes. */
	blockGap: string;
	/** Mode A: how each image fills its cell. */
	imageFit: 'cover' | 'contain';
	/** Mode A: a fixed CSS aspect ratio, or `''` to use each image's stored ratio. */
	aspectRatio: string;
	/** Mode B: the target row height in pixels. */
	targetRowHeight: number;
	/** Whether the Interactivity-API lightbox is wired (the no-JS fallback is always present). */
	enableLightbox: boolean;
	/** The caption content mode. */
	captionContent: CaptionContent;
	/** Whether to humanise filenames and path segments. */
	captionHumanize: boolean;
	/** Whether a path breadcrumb is prefixed with the collection name. */
	captionIncludeCollectionName: boolean;
	/** The breadcrumb separator (free text). */
	captionSeparator: string;
	/** The caption position relative to its image. */
	captionPosition: CaptionPosition;
	/** The overlay anchor (overlay position only). */
	captionOverlayAnchor: CaptionAnchor;
	/** The overlay caption background colour, or `''` for none. */
	captionBackground: string;
	/** The caption text colour, or `''` to inherit. */
	captionTextColor: string;
	/**
	 * Render-time-only flag the editor sets on the `ServerSideRender` preview to
	 * cap the figures and suppress the lightbox. It is never written through
	 * `setAttributes`, so — left at its `false` default — it is never serialised
	 * into `post_content` and cannot reach a frontend render.
	 */
	isEditorPreview: boolean;
	[ key: string ]: unknown;
}
