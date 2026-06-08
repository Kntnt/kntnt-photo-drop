/**
 * DOM controller for the Gallery lightbox — the thin wiring around the pure
 * reducers.
 *
 * One controller is created per gallery wrapper whose `enableLightbox` flag is
 * on. It progressively enhances the server-rendered `<a href="full.webp">`
 * thumbnails into a modal image viewer: a click opens the overlay on the clicked
 * image instead of navigating (so browser history is never touched — the
 * deciding flaw of a CSS `:target` lightbox, ADR-0007), and prev/next, keyboard,
 * swipe, and the close affordances page or dismiss it. With the flag off, or
 * with JavaScript disabled, no controller is created and the anchors navigate to
 * the full image unchanged.
 *
 * All navigation state lives in the pure {@link LightboxState} reducer; this
 * module reads the DOM, asks the reducers (`open`/`next`/`prev`/`first`/`last`,
 * `actionForKey`, `actionForSwipe`) what to do, and reflects the result back
 * onto the overlay. The overlay markup itself is emitted server-side by
 * `Render_Gallery` and escaped there; the controller only fills in the live
 * `src`, caption, counter, and `aria` state.
 *
 * @since 0.7.0
 */

import { trapFocus } from './focus-trap';
import { actionForKey, type LightboxKeyAction } from './lightbox-keys';
import {
	close,
	createLightboxState,
	first,
	last,
	neighbours,
	next,
	open,
	prev,
	type LightboxState,
} from './lightbox-index';
import { actionForSwipe } from './lightbox-swipe';

/**
 * The per-image data the controller reads off each thumbnail anchor: the full
 * image URL it points at and the accessible label to announce when shown.
 *
 * @since 0.7.0
 */
interface LightboxSlide {
	/** The full-resolution image URL (the anchor's `href`). */
	readonly url: string;
	/** The accessible label for the image (the thumbnail's `alt`). */
	readonly label: string;
}

/**
 * The overlay elements the controller drives, resolved once on construction.
 *
 * @since 0.7.0
 */
interface OverlayRefs {
	readonly overlay: HTMLElement;
	readonly image: HTMLImageElement;
	readonly counter: HTMLElement;
	readonly previous: HTMLButtonElement;
	readonly forward: HTMLButtonElement;
	readonly dismiss: HTMLButtonElement;
}

/**
 * Resolves the overlay's child elements, or `null` when the server markup is
 * incomplete (e.g. the overlay was stripped by an optimisation plugin).
 *
 * @since 0.7.0
 *
 * @param overlay - The overlay container emitted by `Render_Gallery`.
 * @return The resolved element refs, or `null` when any required child is missing.
 */
function resolveOverlay( overlay: HTMLElement ): OverlayRefs | null {
	const image = overlay.querySelector< HTMLImageElement >(
		'.kntnt-photo-drop-lightbox__image'
	);
	const counter = overlay.querySelector< HTMLElement >(
		'.kntnt-photo-drop-lightbox__counter'
	);
	const previous = overlay.querySelector< HTMLButtonElement >(
		'.kntnt-photo-drop-lightbox__prev'
	);
	const forward = overlay.querySelector< HTMLButtonElement >(
		'.kntnt-photo-drop-lightbox__next'
	);
	const dismiss = overlay.querySelector< HTMLButtonElement >(
		'.kntnt-photo-drop-lightbox__close'
	);
	if ( ! image || ! counter || ! previous || ! forward || ! dismiss ) {
		return null;
	}
	return { overlay, image, counter, previous, forward, dismiss };
}

/**
 * The lightbox controller: owns the reducer state for one gallery and wires the
 * overlay to it.
 *
 * @since 0.7.0
 */
export class GalleryLightbox {
	/** The thumbnail anchors, in gallery order — the triggers and the slides. */
	readonly #links: readonly HTMLAnchorElement[];

	/** The per-image data read off the anchors once on construction. */
	readonly #slides: readonly LightboxSlide[];

	/** The resolved overlay elements. */
	readonly #refs: OverlayRefs;

	/** The pure navigation state, replaced wholesale on every transition. */
	#state: LightboxState;

	/** The anchor that opened the lightbox, for focus restoration on close. */
	#trigger: HTMLElement | null = null;

	/** The active focus-trap teardown, or `null` while closed. */
	#releaseTrap: ( () => void ) | null = null;

	/** The counter announcement template, e.g. `"%1$d of %2$d"`. */
	readonly #counterTemplate: string;

	/**
	 * Wires a lightbox onto a gallery's anchors and overlay, when the markup is
	 * complete.
	 *
	 * The single public entry point: it resolves the overlay's children and
	 * returns a wired controller, or `null` when a required overlay element is
	 * missing (e.g. an optimisation plugin stripped it) — in which case the
	 * caller leaves the no-JS anchors to navigate.
	 *
	 * @since 0.7.0
	 *
	 * @param links           - The thumbnail anchors, in gallery order.
	 * @param overlay         - The overlay container emitted by `Render_Gallery`.
	 * @param counterTemplate - The `%1$d of %2$d` counter template (already translated).
	 * @return The wired controller, or `null` when the overlay markup is incomplete.
	 */
	static mount(
		links: readonly HTMLAnchorElement[],
		overlay: HTMLElement,
		counterTemplate: string
	): GalleryLightbox | null {
		const refs = resolveOverlay( overlay );
		if ( ! refs ) {
			return null;
		}
		return new GalleryLightbox( links, refs, counterTemplate );
	}

	/**
	 * Wires a controller for one gallery wrapper.
	 *
	 * @since 0.7.0
	 *
	 * @param links           - The thumbnail anchors, in gallery order.
	 * @param refs            - The resolved overlay elements.
	 * @param counterTemplate - The `%1$d of %2$d` counter template (already translated).
	 */
	private constructor(
		links: readonly HTMLAnchorElement[],
		refs: OverlayRefs,
		counterTemplate: string
	) {
		this.#links = links;
		this.#refs = refs;
		this.#counterTemplate = counterTemplate;
		this.#slides = links.map( ( link ) => ( {
			url: link.dataset.kntntPhotoDropFull ?? link.href,
			label: link.querySelector< HTMLImageElement >( 'img' )?.alt ?? '',
		} ) );
		this.#state = createLightboxState( links.length );
		this.#bind();
	}

	/**
	 * Binds the trigger, control, and global listeners.
	 *
	 * Each thumbnail click opens the lightbox on that image and suppresses the
	 * navigation; the overlay's own controls and the keyboard/swipe gestures page
	 * or dismiss it; a backdrop click closes. The keyboard and swipe listeners are
	 * scoped to the overlay so they engage only while it is open.
	 *
	 * @since 0.7.0
	 */
	#bind(): void {
		// Turn each thumbnail into a lightbox trigger: open on the clicked index
		// and call preventDefault so the anchor never navigates (no history entry).
		this.#links.forEach( ( link, index ) => {
			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				this.#open( index, link );
			} );
		} );

		// Wire the three control buttons to their reducer transitions.
		this.#refs.previous.addEventListener( 'click', () =>
			this.#apply( prev )
		);
		this.#refs.forward.addEventListener( 'click', () =>
			this.#apply( next )
		);
		this.#refs.dismiss.addEventListener( 'click', () => this.#close() );

		// A click on the backdrop (the overlay itself, not a child control or the
		// image) dismisses the lightbox, matching the close button.
		this.#refs.overlay.addEventListener( 'click', ( event ) => {
			if ( event.target === this.#refs.overlay ) {
				this.#close();
			}
		} );

		// Keyboard control while open: the pure mapper decides the action, then the
		// recognised keys are consumed so the page beneath does not also react.
		this.#refs.overlay.addEventListener( 'keydown', ( event ) => {
			this.#onKeydown( event );
		} );

		// Touch paging while open: record the start point, decide on release.
		this.#bindSwipe();
	}

	/**
	 * Records touch endpoints and pages the lightbox on a qualifying swipe.
	 *
	 * @since 0.7.0
	 */
	#bindSwipe(): void {
		let startX = 0;
		let startY = 0;
		this.#refs.overlay.addEventListener(
			'touchstart',
			( event ) => {
				const touch = event.changedTouches[ 0 ];
				if ( touch ) {
					startX = touch.clientX;
					startY = touch.clientY;
				}
			},
			{ passive: true }
		);
		this.#refs.overlay.addEventListener(
			'touchend',
			( event ) => {
				const touch = event.changedTouches[ 0 ];
				if ( ! touch ) {
					return;
				}
				const action = actionForSwipe(
					touch.clientX - startX,
					touch.clientY - startY
				);
				if ( action === 'next' ) {
					this.#apply( next );
				} else if ( action === 'prev' ) {
					this.#apply( prev );
				}
			},
			{ passive: true }
		);
	}

	/**
	 * Maps a key press to a reducer transition while the lightbox is open.
	 *
	 * Tab is deliberately left unmapped so the focus trap (installed on open)
	 * keeps owning it; every other recognised key is consumed.
	 *
	 * @since 0.7.0
	 *
	 * @param event - The keyboard event from the overlay.
	 */
	#onKeydown( event: KeyboardEvent ): void {
		if ( ! this.#state.open ) {
			return;
		}
		const action: LightboxKeyAction = actionForKey( event.key );
		if ( action === 'none' ) {
			return;
		}
		event.preventDefault();
		if ( action === 'close' ) {
			this.#close();
		} else {
			const reducer = { prev, next, first, last }[ action ];
			this.#apply( reducer );
		}
	}

	/**
	 * Opens the lightbox at an index, remembering the trigger for focus return.
	 *
	 * @since 0.7.0
	 *
	 * @param index   - The image index to show.
	 * @param trigger - The anchor that opened it, focused again on close.
	 */
	#open( index: number, trigger: HTMLElement ): void {
		this.#trigger = trigger;
		this.#state = open( this.#state, index );
		this.#refs.overlay.hidden = false;
		this.#render();
		this.#releaseTrap = trapFocus( this.#refs.overlay );
		this.#refs.dismiss.focus();
	}

	/**
	 * Closes the lightbox, releases the focus trap, and restores focus to the
	 * trigger.
	 *
	 * @since 0.7.0
	 */
	#close(): void {
		this.#state = close( this.#state );
		this.#refs.overlay.hidden = true;
		this.#releaseTrap?.();
		this.#releaseTrap = null;
		this.#trigger?.focus();
		this.#trigger = null;
	}

	/**
	 * Applies a pure index reducer and re-renders the overlay.
	 *
	 * @since 0.7.0
	 *
	 * @param reducer - The transition to apply to the current state.
	 */
	#apply( reducer: ( state: LightboxState ) => LightboxState ): void {
		this.#state = reducer( this.#state );
		this.#render();
	}

	/**
	 * Reflects the current state onto the overlay: image, caption, counter, the
	 * prev/next availability, and the neighbour preload.
	 *
	 * @since 0.7.0
	 */
	#render(): void {
		const slide = this.#slides[ this.#state.index ];
		if ( ! slide ) {
			return;
		}

		// Swap in the current image and its label; the alt doubles as the caption.
		this.#refs.image.src = slide.url;
		this.#refs.image.alt = slide.label;

		// Announce the position via the live-region counter (1-based for humans).
		this.#refs.counter.textContent = this.#counterTemplate
			.replace( '%1$d', String( this.#state.index + 1 ) )
			.replace( '%2$d', String( this.#state.count ) );

		// Hide the paging controls on a single-image gallery, where they are inert.
		const single = this.#state.count < 2;
		this.#refs.previous.hidden = single;
		this.#refs.forward.hidden = single;

		this.#preloadNeighbours();
	}

	/**
	 * Warms the browser cache for the images adjacent to the current one, so a
	 * prev/next press shows the neighbour without a visible load.
	 *
	 * @since 0.7.0
	 */
	#preloadNeighbours(): void {
		const adjacent = neighbours( this.#state );
		for ( const position of [ adjacent.prev, adjacent.next ] ) {
			if ( position === null ) {
				continue;
			}
			const slide = this.#slides[ position ];
			if ( slide ) {
				const preload = new Image();
				preload.src = slide.url;
			}
		}
	}
}
