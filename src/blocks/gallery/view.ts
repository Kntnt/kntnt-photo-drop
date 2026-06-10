/**
 * Photo Gallery frontend view module — the lightbox mount point and the
 * justified layout's last-row correction.
 *
 * The gallery itself is pure server-rendered HTML; this module progressively
 * enhances it via the WordPress Interactivity API. The baseline is no-JS: every
 * thumbnail is wrapped in an `<a href="<main>.webp">` by `render.php`, so a
 * click navigates to the full image even with this module inert or absent. When
 * the block's `enableLightbox` flag is on (mirrored onto the wrapper as
 * `data-kntnt-photo-drop-lightbox`), the `init` callback wires a
 * {@link GalleryLightbox} controller that turns those anchors into a modal image
 * viewer — open/close, prev/next, keyboard, swipe, neighbour preload, focus
 * trap, and `aria` — and suppresses the navigation so browser history is never
 * touched (ADR-0007). With the flag off, the controller is not created and the
 * anchors keep navigating.
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
		 * Enhances one gallery wrapper: the justified last-row correction and,
		 * when enabled, the lightbox.
		 *
		 * The correction is wired whenever the gallery uses the justified
		 * layout, independent of the lightbox flag. The lightbox mounts only
		 * when the `enableLightbox` flag is on and the gallery has thumbnail
		 * anchors plus the server overlay — in every bail path the no-JS
		 * fallback stands and the anchors keep navigating.
		 *
		 * @since 0.7.0
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

			// Respect the enableLightbox flag: when off, leave the anchors to
			// navigate to the full image and wire nothing further.
			if ( ref.dataset.kntntPhotoDropLightbox !== 'true' ) {
				return;
			}

			// Locate the thumbnail anchors (the triggers and slides) and the
			// server-emitted overlay; without either there is nothing to enhance,
			// so the no-JS fallback stands.
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

			// Construct the controller, which resolves the overlay's children and
			// binds the triggers, controls, keyboard, and swipe. The context can
			// have degraded to `{}` server-side, so the template falls back to a
			// neutral numeric form rather than crashing mid-open.
			const context = getContext< GalleryContext >();
			GalleryLightbox.mount(
				links,
				overlay,
				context?.counterTemplate ?? FALLBACK_COUNTER_TEMPLATE
			);
		},
	},
} );
