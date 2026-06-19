/**
 * The per-image slide data shared by the Gallery's lightbox and slideshow.
 *
 * `Render_Gallery` mirrors everything a full-screen surface needs onto each
 * thumbnail anchor — the display (full-rendition) URL, the responsive srcset,
 * the main-image download target, and the breadcrumb overlay text — so neither
 * surface re-parses the page. This module is the one reader of that anchor data
 * contract: the lightbox and the slideshow both consume the same flattened,
 * ordered slide list (ADR-0009 — the slideshow plays exactly the gallery's
 * view), so the attribute names and fallbacks live here once. The display URL
 * and the download target are read separately: the lightbox shows the full
 * rendition but its download icon always saves the main image (ADR-0013). The
 * slideshow shows no overlays (ADR-0015), so it ignores both the download target
 * and the breadcrumb field; only the lightbox uses them.
 *
 * @since 0.7.0
 */

/**
 * The selector every consumer of the anchor data contract collects slides by.
 *
 * One definition serves the lightbox mount, the slideshow mount, and the
 * slideshow's cycle-boundary resync (ADR-0011), so the live and the refetched
 * document are always read with the same rule.
 *
 * @since 0.9.0
 */
export const SLIDE_LINK_SELECTOR = '.kntnt-photo-drop-gallery__link';

/**
 * The per-image data read off one thumbnail anchor: the display (full-rendition)
 * URL it points at, the main-image download target, the responsive srcset the
 * server mirrored onto the anchor, the accessible label to announce when shown,
 * the breadcrumb overlay text (empty when the gallery has no breadcrumbs), and
 * the collection-relative path the lightbox's add-to-media icon copies (empty
 * when the gallery has no add-to-media overlay or the user is un-capable).
 *
 * @since 0.7.0
 * @since 0.11.0 Added the `main` download target, distinct from the display `url`.
 * @since 0.11.0 Added the `path` add-to-media target.
 */
export interface GallerySlide {
	/** The display image URL — the full rendition the lightbox shows (the anchor's `href`). */
	readonly url: string;
	/** The download target — always the main image (ADR-0013), independent of `url`. */
	readonly main: string;
	/** The slide's responsive srcset (the anchor's srcset data attribute). */
	readonly srcset: string;
	/** The accessible label for the image (the thumbnail's `alt`). */
	readonly label: string;
	/** The breadcrumb overlay text mirrored from the gallery figure, or `''`. */
	readonly breadcrumbs: string;
	/** The collection-relative add-to-media path (ADR-0015), or `''` when off/un-capable. */
	readonly path: string;
}

/**
 * Reads the slide list off the gallery's thumbnail anchors, in gallery order.
 *
 * Each missing data attribute degrades independently: the display URL and the
 * main download target both fall back to the anchor's own `href` (the no-JS
 * fallback navigates to, and downloads, the main image), the srcset and
 * breadcrumbs to empty, and the label to the thumbnail's `alt` or empty.
 *
 * @since 0.7.0
 * @since 0.11.0 Reads the `main` download target from its own data attribute.
 * @since 0.11.0 Reads the `path` add-to-media target, empty when the anchor has none.
 *
 * @param links - The thumbnail anchors, in gallery order.
 * @return One slide per anchor, in the same order.
 */
export function readSlides(
	links: readonly HTMLAnchorElement[]
): GallerySlide[] {
	return links.map( ( link ) => ( {
		url: link.dataset.kntntPhotoDropFull ?? link.href,
		main: link.dataset.kntntPhotoDropMain ?? link.href,
		srcset: link.dataset.kntntPhotoDropSrcset ?? '',
		label: link.querySelector< HTMLImageElement >( 'img' )?.alt ?? '',
		breadcrumbs: link.dataset.kntntPhotoDropBreadcrumbs ?? '',
		path: link.dataset.kntntPhotoDropPath ?? '',
	} ) );
}
