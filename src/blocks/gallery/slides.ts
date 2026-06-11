/**
 * The per-image slide data shared by the Gallery's lightbox and slideshow.
 *
 * `Render_Gallery` mirrors everything a full-screen surface needs onto each
 * thumbnail anchor — the main-image URL, the responsive srcset, and the caption
 * text — so neither surface re-parses the page. This module is the one reader
 * of that anchor data contract: the lightbox and the slideshow both consume the
 * same flattened, ordered slide list (ADR-0009 — the slideshow plays exactly
 * the gallery's view), so the attribute names and fallbacks live here once.
 *
 * @since 0.7.0
 */

/**
 * The per-image data read off one thumbnail anchor: the full image URL it
 * points at, the responsive srcset the server mirrored onto the anchor, the
 * accessible label to announce when shown, and the overlay caption text
 * (empty when the gallery has no caption).
 *
 * @since 0.7.0
 */
export interface GallerySlide {
	/** The full-resolution image URL (the anchor's `href`). */
	readonly url: string;
	/** The slide's responsive srcset (the anchor's srcset data attribute). */
	readonly srcset: string;
	/** The accessible label for the image (the thumbnail's `alt`). */
	readonly label: string;
	/** The overlay caption text mirrored from the gallery figure, or `''`. */
	readonly caption: string;
}

/**
 * Reads the slide list off the gallery's thumbnail anchors, in gallery order.
 *
 * Each missing data attribute degrades independently: the URL falls back to the
 * anchor's own `href` (the no-JS fallback target), the srcset and caption to
 * empty, and the label to the thumbnail's `alt` or empty.
 *
 * @since 0.7.0
 *
 * @param links - The thumbnail anchors, in gallery order.
 * @return One slide per anchor, in the same order.
 */
export function readSlides(
	links: readonly HTMLAnchorElement[]
): GallerySlide[] {
	return links.map( ( link ) => ( {
		url: link.dataset.kntntPhotoDropFull ?? link.href,
		srcset: link.dataset.kntntPhotoDropSrcset ?? '',
		label: link.querySelector< HTMLImageElement >( 'img' )?.alt ?? '',
		caption: link.dataset.kntntPhotoDropCaption ?? '',
	} ) );
}
