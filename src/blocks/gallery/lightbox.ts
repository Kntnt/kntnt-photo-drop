/**
 * DOM controller for the Gallery lightbox — the thin wiring around the pure
 * reducers.
 *
 * One controller is created per gallery wrapper whose `lightbox` flag is on. It
 * progressively enhances the server-rendered `<a href="full.webp">`
 * thumbnails into a modal image viewer: a plain primary click opens the overlay
 * on the clicked image instead of navigating (so browser history is never
 * touched — the deciding flaw of a CSS `:target` lightbox, ADR-0007; a modified
 * click — new tab, new window — is left to the browser), and prev/next,
 * keyboard, swipe, and the close affordances page or dismiss it. While open,
 * the page behind the modal is scroll-locked and the keyboard is handled at the
 * document level, so focus drifting to a non-focusable click target never
 * deadens Escape or the arrows. With the flag off, or with JavaScript disabled,
 * no controller is created and the anchors navigate to the full image
 * unchanged.
 *
 * All navigation state lives in the pure {@link LightboxState} reducer; this
 * module reads the DOM, asks the reducers (`open`/`next`/`prev`/`first`/`last`,
 * `actionForKey`, `actionForSwipe`) what to do, and reflects the result back
 * onto the overlay. The overlay markup itself is emitted server-side by
 * `Render_Gallery` and escaped there; the controller only fills in the live
 * `src`/`srcset`, caption, counter, loading/error state, and `aria` state.
 *
 * When download is on (issue #34), the enlarged image is wrapped server-side in a
 * `download` anchor and the controller points its `href` at the current slide's
 * full image, so a click on the enlarged image saves it. When the gallery has a
 * caption, the controller mirrors each slide's caption text onto the lightbox's
 * caption figcaption (the same overlay element, anchor, and styling the gallery
 * figures use).
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
 * How long after the last transition the neighbour preload waits, in
 * milliseconds.
 *
 * Holding an arrow key fires transitions far faster than this, so a held key
 * schedules no downloads at all until the visitor settles on an image —
 * without the debounce, paging a thousand-image gallery starts hundreds of
 * uncancellable full-image requests.
 *
 * @since 0.2.0
 */
const PRELOAD_DELAY = 150;

/**
 * The overlay class toggled while the current slide's image is loading.
 *
 * @since 0.2.0
 */
const LOADING_CLASS = 'kntnt-photo-drop-lightbox--loading';

/**
 * The overlay class toggled when the current slide's image failed to load.
 *
 * @since 0.2.0
 */
const ERROR_CLASS = 'kntnt-photo-drop-lightbox--error';

/**
 * The per-image data the controller reads off each thumbnail anchor: the full
 * image URL it points at, the responsive srcset the server mirrored onto the
 * anchor, the accessible label to announce when shown, and the overlay caption
 * text the server mirrored onto the anchor (empty when the gallery has no
 * caption).
 *
 * @since 0.7.0
 */
interface LightboxSlide {
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
 * The overlay elements the controller drives, resolved once on construction.
 *
 * The download anchor and the caption figcaption are optional: the server emits
 * them only when download / a caption is on, so they are `null` otherwise and the
 * controller simply skips updating them.
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
	readonly failure: HTMLElement;
	/** The download anchor wrapping the image, or `null` when download is off. */
	readonly download: HTMLAnchorElement | null;
	/** The mirrored caption figcaption, or `null` when the gallery has no caption. */
	readonly caption: HTMLElement | null;
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
	const failure = overlay.querySelector< HTMLElement >(
		'.kntnt-photo-drop-lightbox__error'
	);
	if (
		! image ||
		! counter ||
		! previous ||
		! forward ||
		! dismiss ||
		! failure
	) {
		return null;
	}

	// The download anchor and the caption figcaption are optional chrome; resolve
	// them when present and leave them null otherwise.
	const download = overlay.querySelector< HTMLAnchorElement >(
		'.kntnt-photo-drop-lightbox__download'
	);
	const caption = overlay.querySelector< HTMLElement >(
		'.kntnt-photo-drop-lightbox__caption'
	);
	return {
		overlay,
		image,
		counter,
		previous,
		forward,
		dismiss,
		failure,
		download,
		caption,
	};
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

	/** Whether the enlarged image carries a download affordance. */
	readonly #download: boolean;

	/**
	 * The document keydown listener, bound while the lightbox is open.
	 *
	 * @param event - The keyboard event from the document.
	 */
	readonly #documentKeydown = ( event: KeyboardEvent ): void =>
		this.#onKeydown( event );

	/** The root element's inline `overflow` before the scroll lock, restored on close. */
	#previousOverflow = '';

	/** The pending neighbour-preload timer, or `null` when none is scheduled. */
	#preloadTimer: ReturnType< typeof setTimeout > | null = null;

	/** The slide URLs already handed to the browser for preloading. */
	readonly #warmed = new Set< string >();

	/** The URL of the slide currently in the overlay image, or `null` before the first. */
	#currentUrl: string | null = null;

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
	 * @param download        - Whether the enlarged image carries a download affordance.
	 * @return The wired controller, or `null` when the overlay markup is incomplete.
	 */
	static mount(
		links: readonly HTMLAnchorElement[],
		overlay: HTMLElement,
		counterTemplate: string,
		download: boolean
	): GalleryLightbox | null {
		const refs = resolveOverlay( overlay );
		if ( ! refs ) {
			return null;
		}
		return new GalleryLightbox( links, refs, counterTemplate, download );
	}

	/**
	 * Wires a controller for one gallery wrapper.
	 *
	 * @since 0.7.0
	 *
	 * @param links           - The thumbnail anchors, in gallery order.
	 * @param refs            - The resolved overlay elements.
	 * @param counterTemplate - The `%1$d of %2$d` counter template (already translated).
	 * @param download        - Whether the enlarged image carries a download affordance.
	 */
	private constructor(
		links: readonly HTMLAnchorElement[],
		refs: OverlayRefs,
		counterTemplate: string,
		download: boolean
	) {
		this.#links = links;
		this.#refs = refs;
		this.#counterTemplate = counterTemplate;
		this.#download = download;
		this.#slides = links.map( ( link ) => ( {
			url: link.dataset.kntntPhotoDropFull ?? link.href,
			srcset: link.dataset.kntntPhotoDropSrcset ?? '',
			label: link.querySelector< HTMLImageElement >( 'img' )?.alt ?? '',
			caption: link.dataset.kntntPhotoDropCaption ?? '',
		} ) );
		this.#state = createLightboxState( links.length );
		this.#bind();
	}

	/**
	 * Binds the trigger, control, and image-state listeners.
	 *
	 * Each plain primary thumbnail click opens the lightbox on that image and
	 * suppresses the navigation; a modified click (new tab, new window, download)
	 * is left to the browser. The overlay's own controls and the swipe gestures
	 * page or dismiss it; a backdrop click closes. The keyboard listener is *not*
	 * bound here: clicking the enlarged image or counter blurs focus to `body`,
	 * which would deaden an overlay-scoped listener, so `#open` binds it at the
	 * document level and `#close` removes it again.
	 *
	 * @since 0.7.0
	 */
	#bind(): void {
		// Turn each thumbnail into a lightbox trigger: a plain primary click opens
		// on the clicked index and calls preventDefault so the anchor never
		// navigates (no history entry); any modifier or non-primary button keeps
		// the browser's own behaviour (open in new tab/window, download, …).
		this.#links.forEach( ( link, index ) => {
			link.addEventListener( 'click', ( event ) => {
				if (
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey ||
					event.button !== 0
				) {
					return;
				}
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

		// Reflect the slide image's network state: clear the loading veil when it
		// arrives, or swap it for the server-translated failure message.
		this.#refs.image.addEventListener( 'load', () =>
			this.#setLoading( false )
		);
		this.#refs.image.addEventListener( 'error', () => this.#showError() );

		// Touch paging while open: record the start point, decide on release.
		this.#bindSwipe();
	}

	/**
	 * Records touch endpoints and pages the lightbox on a qualifying swipe.
	 *
	 * A gesture that ever involves a second finger is a pinch, not a swipe: the
	 * later touchstart would overwrite the recorded start point and produce a
	 * garbage delta, so the whole gesture is flagged multi-touch and the pure
	 * decision discards it. The decision runs only when the last finger lifts.
	 *
	 * @since 0.7.0
	 */
	#bindSwipe(): void {
		let startX = 0;
		let startY = 0;
		let multiTouch = false;
		this.#refs.overlay.addEventListener(
			'touchstart',
			( event ) => {
				// A second finger marks the whole gesture multi-touch; only a fresh
				// single-finger gesture resets the flag and records a start point.
				if ( event.touches.length > 1 ) {
					multiTouch = true;
					return;
				}
				multiTouch = false;
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
				// Decide only when the last finger has lifted, handing the pure
				// decision the delta and whether the gesture stayed single-touch.
				if ( event.touches.length !== 0 ) {
					return;
				}
				const touch = event.changedTouches[ 0 ];
				if ( ! touch ) {
					return;
				}
				const action = actionForSwipe(
					touch.clientX - startX,
					touch.clientY - startY,
					{ multiTouch }
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
	 * keeps owning it, and a held Alt/Ctrl/Meta leaves the key to the browser
	 * (Alt/Cmd+ArrowLeft is browser back); every other recognised key is
	 * consumed.
	 *
	 * @since 0.7.0
	 *
	 * @param event - The keyboard event from the document.
	 */
	#onKeydown( event: KeyboardEvent ): void {
		if ( ! this.#state.open ) {
			return;
		}
		const action: LightboxKeyAction = actionForKey( event.key, {
			alt: event.altKey,
			ctrl: event.ctrlKey,
			meta: event.metaKey,
		} );
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
	 * While open, the page behind the modal is scroll-locked (the root element's
	 * prior inline `overflow` is remembered and restored on close) and the
	 * keyboard is handled at the document level — clicking the enlarged image or
	 * the counter blurs focus to `body`, where an overlay-scoped listener would
	 * never hear Escape or the arrows again.
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

		// Lock the page scroll behind the modal and take over the keyboard at the
		// document level for as long as the lightbox stays open.
		const doc = this.#refs.overlay.ownerDocument;
		this.#previousOverflow = doc.documentElement.style.overflow;
		doc.documentElement.style.overflow = 'hidden';
		doc.addEventListener( 'keydown', this.#documentKeydown );

		this.#render();
		this.#releaseTrap = trapFocus( this.#refs.overlay );
		this.#refs.dismiss.focus();
	}

	/**
	 * Closes the lightbox, undoes the open-state side effects, and restores
	 * focus to the trigger.
	 *
	 * @since 0.7.0
	 */
	#close(): void {
		this.#state = close( this.#state );
		this.#refs.overlay.hidden = true;

		// Undo the open-state side effects: the scroll lock, the document-level
		// keyboard listener, and any preload still waiting on the debounce.
		const doc = this.#refs.overlay.ownerDocument;
		doc.documentElement.style.overflow = this.#previousOverflow;
		doc.removeEventListener( 'keydown', this.#documentKeydown );
		if ( this.#preloadTimer !== null ) {
			clearTimeout( this.#preloadTimer );
			this.#preloadTimer = null;
		}

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
	 * prev/next availability, the loading/error state, and the debounced
	 * neighbour preload.
	 *
	 * @since 0.7.0
	 */
	#render(): void {
		const slide = this.#slides[ this.#state.index ];
		if ( ! slide ) {
			return;
		}

		// Swap in the current image — responsive via the mirrored srcset, so a
		// phone never downloads the full-resolution main — raising the loading
		// veil and clearing any failure from the previous slide. A re-render of
		// the same slide skips the swap so the veil cannot stick without a
		// pending load event.
		if ( slide.url !== this.#currentUrl ) {
			this.#currentUrl = slide.url;
			this.#setLoading( true );
			this.#refs.overlay.classList.remove( ERROR_CLASS );
			this.#refs.failure.hidden = true;
			if ( slide.srcset !== '' ) {
				this.#refs.image.srcset = slide.srcset;
				this.#refs.image.sizes = '100vw';
			}
			this.#refs.image.src = slide.url;
		}

		// The alt is the image's accessible label.
		this.#refs.image.alt = slide.label;

		// Point the download affordance at the current slide's full image, so a click
		// on the enlarged image saves it; absent when download is off.
		if ( this.#download && this.#refs.download ) {
			this.#refs.download.href = slide.url;
		}

		// Mirror the gallery caption onto the lightbox figure when a caption element
		// exists; the text comes from the slide's mirrored caption data.
		if ( this.#refs.caption ) {
			this.#refs.caption.textContent = slide.caption;
			this.#refs.caption.hidden = slide.caption === '';
		}

		// Announce the position via the live-region counter (1-based for humans).
		this.#refs.counter.textContent = this.#counterTemplate
			.replace( '%1$d', String( this.#state.index + 1 ) )
			.replace( '%2$d', String( this.#state.count ) );

		// Hide the paging controls on a single-image gallery, where they are inert.
		const single = this.#state.count < 2;
		this.#refs.previous.hidden = single;
		this.#refs.forward.hidden = single;

		this.#schedulePreload();
	}

	/**
	 * Toggles the loading veil over the slide image.
	 *
	 * @since 0.2.0
	 *
	 * @param loading - Whether the current slide's image is still loading.
	 */
	#setLoading( loading: boolean ): void {
		this.#refs.overlay.classList.toggle( LOADING_CLASS, loading );
	}

	/**
	 * Shows the load-failure state: the veil drops, the broken image hides, and
	 * the server-translated failure message appears in its place.
	 *
	 * @since 0.2.0
	 */
	#showError(): void {
		this.#setLoading( false );
		this.#refs.overlay.classList.add( ERROR_CLASS );
		this.#refs.failure.hidden = false;
	}

	/**
	 * Schedules the neighbour preload, debounced behind {@link PRELOAD_DELAY}.
	 *
	 * Every transition resets the timer, so a held arrow key pages freely and
	 * only the image the visitor settles on gets its neighbours warmed —
	 * without this, holding ArrowRight on a large gallery starts hundreds of
	 * uncancellable downloads.
	 *
	 * @since 0.2.0
	 */
	#schedulePreload(): void {
		if ( this.#preloadTimer !== null ) {
			clearTimeout( this.#preloadTimer );
		}
		this.#preloadTimer = setTimeout( () => {
			this.#preloadTimer = null;
			this.#preloadNeighbours();
		}, PRELOAD_DELAY );
	}

	/**
	 * Warms the browser cache for the images adjacent to the current one, so a
	 * prev/next press shows the neighbour without a visible load.
	 *
	 * Each slide is handed to the browser at most once per page view (the warmed
	 * set), and the preload carries the slide's srcset and the overlay's sizes
	 * so the browser warms the same rendition the overlay will actually show.
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
			if ( ! slide || this.#warmed.has( slide.url ) ) {
				continue;
			}
			this.#warmed.add( slide.url );
			const preload = new Image();
			if ( slide.srcset !== '' ) {
				preload.srcset = slide.srcset;
				preload.sizes = '100vw';
			}
			preload.src = slide.url;
		}
	}
}
