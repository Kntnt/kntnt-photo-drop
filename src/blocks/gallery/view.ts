/**
 * Photo Gallery frontend view module — the lightbox mount point.
 *
 * The gallery itself is pure server-rendered HTML; this module progressively
 * enhances it into a lightbox via the WordPress Interactivity API. The baseline
 * is no-JS: every thumbnail is wrapped in an `<a href="<main>.webp">` by
 * `render.php`, so a click navigates to the full image even with this module
 * inert or absent. When the block's `enableLightbox` flag is on (mirrored onto
 * the wrapper as `data-kntnt-photo-drop-lightbox`), the `init` callback wires a
 * {@link GalleryLightbox} controller that turns those anchors into a modal image
 * viewer — open/close, prev/next, keyboard, swipe, neighbour preload, focus
 * trap, and `aria` — and suppresses the navigation so browser history is never
 * touched (ADR-0007). With the flag off, the controller is not created and the
 * anchors keep navigating.
 *
 * All navigation logic lives in the co-located pure reducers
 * (`lightbox-index.ts`, `lightbox-keys.ts`, `lightbox-swipe.ts`); the controller
 * (`lightbox.ts`) is the thin DOM wiring and this module is thinner still — it
 * reads the flag, finds the server markup, and hands off.
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

/**
 * The per-block Interactivity context emitted by `Render_Gallery`.
 *
 * View-script modules cannot import `@wordpress/i18n`, so the one runtime string
 * the lightbox needs — the `%1$d of %2$d` counter announcement — is translated
 * server-side and handed through the context.
 *
 * @since 0.7.0
 */
interface GalleryContext {
	/** The translated counter template, e.g. `"%1$d of %2$d"`. */
	readonly counterTemplate: string;
}

/**
 * Tracks which gallery wrappers already have a lightbox wired.
 *
 * Keyed by the wrapper so the Interactivity API re-running `init` (e.g. on a
 * re-hydration) never wires a second controller over the same gallery.
 *
 * @since 0.7.0
 */
const mountedGalleries = new WeakSet< Element >();

store( 'kntnt-photo-drop/gallery', {
	callbacks: {
		/**
		 * Wires the lightbox onto one gallery wrapper, when enabled.
		 *
		 * Bails before any work when the `enableLightbox` flag is off (the
		 * anchors then keep navigating), when the wrapper is already mounted, or
		 * when the gallery has no thumbnail anchors or the server overlay is
		 * absent — in every bail path the no-JS fallback stands.
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

			// Respect the enableLightbox flag: when off, leave the anchors to
			// navigate to the full image and wire nothing.
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

			mountedGalleries.add( ref );

			// Construct the controller, which resolves the overlay's children and
			// binds the triggers, controls, keyboard, and swipe.
			const { counterTemplate } = getContext< GalleryContext >();
			GalleryLightbox.mount( links, overlay, counterTemplate );
		},
	},
} );
