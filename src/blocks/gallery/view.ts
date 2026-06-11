/**
 * Photo Gallery frontend view module — the lightbox mount point and the
 * justified layout's last-row correction.
 *
 * The gallery itself is pure server-rendered HTML; this module progressively
 * enhances it via the WordPress Interactivity API. The baseline is no-JS: every
 * thumbnail is wrapped in an `<a href="<main>.webp">` by `render.php`, so a
 * click navigates to the full image even with this module inert or absent. The
 * wrapper carries two flags the `init` callback reads to apply the click matrix
 * (issue #34): `data-kntnt-photo-drop-lightbox` and
 * `data-kntnt-photo-drop-download`. The download trigger is always the overlay
 * download-icon anchor alone — a click on the image outside the icon never
 * downloads.
 *
 * - Lightbox on → a {@link GalleryLightbox} controller turns the anchors into a
 *   modal image viewer (open/close, prev/next, keyboard, swipe, neighbour
 *   preload, focus trap, `aria`) and suppresses the navigation so browser history
 *   is never touched (ADR-0007). When download is also on, the lightbox carries
 *   the download-icon anchor and only a click on it saves the current slide.
 * - Lightbox off + download on → the thumbnail click is suppressed (the image
 *   does nothing) and each figure's icon anchor saves its image programmatically
 *   ({@link saveFile} — a blob download no environment can turn into a new tab).
 * - Lightbox off + download off → the click is suppressed so it does nothing
 *   (the no-JS fallback would navigate, but with JS the gallery is inert).
 *
 * For the justified layout, `init` additionally corrects the server's last-row
 * flags: the server packs rows against an assumed container width, so the
 * inline `flex-grow: 0` can land on the wrong figures when the real container
 * wraps differently. On init and on a debounced window resize this module reads
 * every figure's rendered offset, asks the pure {@link lastRowFlags} which
 * figures form the actual last row, and overrides the inline grow — reads
 * first, then writes, so no layout thrash and no observer feedback loop. The
 * server's flags remain the no-JS/first-paint fallback.
 *
 * All decision logic lives in the co-located pure modules (`lightbox-index.ts`,
 * `lightbox-keys.ts`, `lightbox-swipe.ts`, `justified-rows.ts`); the controller
 * (`lightbox.ts`) is the thin DOM wiring and this module is thinner still — it
 * reads the flags, finds the server markup, and hands off.
 *
 * The lightbox styles are imported here so `@wordpress/scripts` bundles them
 * into the view-side asset declared as `viewStyle` in block.json, keeping them
 * off the editor where there is no lightbox.
 *
 * @since 0.7.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import './view.scss';
import { GalleryLightbox } from './lightbox';
import { lastRowFlags } from './justified-rows';
import { saveFile } from './save-file';

/**
 * The per-block Interactivity context emitted by `Render_Gallery`.
 *
 * View-script modules cannot import `@wordpress/i18n`, so the one runtime string
 * the lightbox needs — the `%1$d of %2$d` counter announcement — is translated
 * server-side and handed through the context. The property is optional because
 * the server deliberately degrades the context to `{}` when JSON encoding
 * fails; the read site falls back to a neutral numeric template.
 *
 * @since 0.7.0
 */
interface GalleryContext {
	/** The translated counter template, e.g. `"%1$d of %2$d"`. */
	readonly counterTemplate?: string;
}

/**
 * The neutral counter template used when the server context degraded to `{}`.
 *
 * Purely numeric — `"3 / 12"` — so nothing language-specific is hardcoded here;
 * the translated template always comes from the server when available.
 *
 * @since 0.2.0
 */
const FALLBACK_COUNTER_TEMPLATE = '%1$d / %2$d';

/**
 * How long after the last window resize the justified correction re-runs, in
 * milliseconds.
 *
 * @since 0.2.0
 */
const RESIZE_DEBOUNCE = 200;

/**
 * Tracks which gallery wrappers are already enhanced.
 *
 * Keyed by the wrapper so the Interactivity API re-running `init` (e.g. on a
 * re-hydration) never wires a second controller — or a second resize
 * listener — over the same gallery.
 *
 * @since 0.7.0
 */
const mountedGalleries = new WeakSet< Element >();

/**
 * Suppresses plain navigation on the gallery's thumbnail anchors.
 *
 * In both lightbox-off cells a thumbnail click should do nothing — but the
 * anchor still points at the main image (the no-JS fallback), so with
 * JavaScript a plain click would navigate. A single delegated listener on the
 * wrapper cancels that for a plain primary click on (or inside) any thumbnail
 * anchor, while leaving modified clicks (new tab/window, save-as) to the browser,
 * so the gallery is inert without breaking the visitor's own intentions — and
 * without one listener per anchor in a thousand-image gallery. The download-icon
 * anchor is a sibling of the thumbnail anchor, never inside it, so its clicks
 * pass this suppression untouched.
 *
 * @since 0.4.0
 * @since 0.5.0 Applies to the download-on cell too; only the icon downloads.
 *
 * @param wrapper - The gallery wrapper the thumbnail anchors live in.
 */
function suppressNavigation( wrapper: HTMLElement ): void {
	wrapper.addEventListener( 'click', ( event ) => {
		if (
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			event.button !== 0
		) {
			return;
		}
		const target = event.target;
		if (
			target instanceof Element &&
			target.closest( '.kntnt-photo-drop-gallery__link' )
		) {
			event.preventDefault();
		}
	} );
}

/**
 * Wires the figures' download-icon anchors to the programmatic blob download.
 *
 * One delegated listener on the wrapper intercepts a plain primary click on any
 * icon anchor and saves its image via {@link saveFile} instead of the anchor's
 * own `download` navigation — a blob download cannot be turned into a new tab
 * by a link-rewriting theme or a cross-origin media host, which native
 * `<a download>` can. Modified clicks are left to the browser, and without
 * JavaScript the anchor's `download` attribute is the fallback.
 *
 * @since 0.5.0
 *
 * @param wrapper - The gallery wrapper the icon anchors live in.
 */
function wireIconDownloads( wrapper: HTMLElement ): void {
	wrapper.addEventListener( 'click', ( event ) => {
		if (
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			event.button !== 0
		) {
			return;
		}
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const icon = target.closest< HTMLAnchorElement >(
			'.kntnt-photo-drop-gallery__download'
		);
		if ( ! icon ) {
			return;
		}
		event.preventDefault();
		void saveFile( icon.href );
	} );
}

/**
 * Corrects the justified layout's last-row flags against the real container.
 *
 * Reads every figure's rendered `offsetTop` first, then writes every
 * `flex-grow` — the actual last row gets `0` (left-aligned at natural width),
 * every other row gets `1` (stretched to fill) — so reads and writes never
 * interleave into layout thrash. Changing `flex-grow` cannot re-wrap the rows
 * (wrapping follows `flex-basis`), so the correction cannot feed back into
 * itself.
 *
 * @since 0.2.0
 *
 * @param layout - The justified layout container.
 */
function correctLastRow( layout: HTMLElement ): void {
	// Read phase: collect the figures and their rendered offsets.
	const figures = Array.from(
		layout.querySelectorAll< HTMLElement >(
			'.kntnt-photo-drop-gallery__item--justified'
		)
	);
	const flags = lastRowFlags( figures.map( ( figure ) => figure.offsetTop ) );

	// Write phase: override the server's assumed-width grow with the real one.
	figures.forEach( ( figure, index ) => {
		figure.style.flexGrow = flags[ index ] ? '0' : '1';
	} );
}

/**
 * Runs the last-row correction now and again on a debounced window resize.
 *
 * The resize listener lives for the page's lifetime, like the gallery markup
 * it corrects; the debounce keeps a drag-resize from re-reading a
 * thousand-figure gallery on every frame.
 *
 * @since 0.2.0
 *
 * @param layout - The justified layout container.
 */
function wireLastRowCorrection( layout: HTMLElement ): void {
	correctLastRow( layout );

	// Re-run after the window settles at a new size; every resize event within
	// the debounce window pushes the work further out.
	let timer: ReturnType< typeof setTimeout > | null = null;
	layout.ownerDocument.defaultView?.addEventListener(
		'resize',
		() => {
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => {
				timer = null;
				correctLastRow( layout );
			}, RESIZE_DEBOUNCE );
		},
		{ passive: true }
	);
}

store( 'kntnt-photo-drop/gallery', {
	callbacks: {
		/**
		 * Enhances one gallery wrapper: the justified last-row correction and
		 * the click matrix (lightbox, native download, or inert).
		 *
		 * The correction is wired whenever the gallery uses the justified
		 * layout, independent of the click flags. The click matrix then
		 * branches on the two wrapper flags (issue #34): with the lightbox on,
		 * a {@link GalleryLightbox} controller mounts (the overlay carries the
		 * download-icon anchor when download is on); with the lightbox off,
		 * the thumbnail clicks are suppressed so a click on the image does
		 * nothing, and — when download is on — the figures' icon anchors are
		 * wired to the programmatic blob download. In every lightbox bail path
		 * the no-JS fallback markup stands.
		 *
		 * @since 0.7.0
		 * @since 0.4.0 Branches on the lightbox + download click matrix.
		 * @since 0.5.0 The icon anchor is the sole download trigger.
		 */
		init(): void {
			// Resolve the wrapper and guard against a double-init re-hydration.
			const { ref } = getElement();
			if ( ! ref || ! ( ref instanceof HTMLElement ) ) {
				return;
			}
			if ( mountedGalleries.has( ref ) ) {
				return;
			}
			mountedGalleries.add( ref );

			// Correct the justified layout's last row against the real container
			// width; the server's inline flags are only the first-paint guess.
			const justified = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-gallery__layout--justified'
			);
			if ( justified ) {
				wireLastRowCorrection( justified );
			}

			const lightbox = ref.dataset.kntntPhotoDropLightbox === 'true';
			const download = ref.dataset.kntntPhotoDropDownload === 'true';

			// Lightbox off: suppress the plain thumbnail click via one delegated
			// listener so a click on the image does nothing rather than navigate;
			// with download on, additionally wire the figures' icon anchors to the
			// programmatic blob download. Neither branch needs the anchors
			// materialised.
			if ( ! lightbox ) {
				suppressNavigation( ref );
				if ( download ) {
					wireIconDownloads( ref );
				}
				return;
			}

			// Lightbox on: collect the thumbnail anchors and locate the server-emitted
			// overlay, then mount the controller. Without the anchors or the overlay
			// there is nothing to enhance, so the no-JS fallback stands. The context can
			// have degraded to `{}` server-side, so the counter template falls back to a
			// neutral numeric form rather than crashing mid-open.
			const links = Array.from(
				ref.querySelectorAll< HTMLAnchorElement >(
					'.kntnt-photo-drop-gallery__link'
				)
			);
			const overlay = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-lightbox'
			);
			if ( links.length === 0 || ! overlay ) {
				return;
			}
			const context = getContext< GalleryContext >();
			GalleryLightbox.mount(
				links,
				overlay,
				context?.counterTemplate ?? FALLBACK_COUNTER_TEMPLATE
			);
		},
	},
} );
