/**
 * DOM controller for the Gallery slideshow — the thin wiring around the pure
 * reducers (ADR-0009).
 *
 * One controller is created per gallery wrapper whose slideshow mode is on. It
 * drives the server-emitted slideshow overlay: a visitor-started, automatically
 * advancing, endlessly looping fullscreen playback of exactly the gallery's
 * flattened, ordered image list — the same slides the lightbox pages through.
 * The surface is deliberately passive: the only interaction is ending it, via
 * Escape, the browser's native fullscreen exit, or the overlay's close button
 * (the touch path); every other key, click, and tap is inert.
 *
 * Fullscreen uses the Fullscreen API where available and silently degrades to
 * the overlay's own fixed, viewport-filling layer where it is not (notably
 * iPhone Safari) — both paths end identically and return the visitor to the
 * gallery. While playing, a screen wake lock is held (best-effort, re-acquired
 * when the tab becomes visible again), since endless playback implies
 * photo-frame use; where the API is missing the slideshow simply runs without
 * it.
 *
 * The dissolve is two stacked `<img>` elements crossfaded by toggling a front
 * class; the CSS owns the opacity transition, and `prefers-reduced-motion`
 * collapses it to a hard cut (both in CSS and in this controller's swap
 * timing). The advance decision lives in the pure gate in
 * `slideshow-advance.ts`: a slide advances only when its visible-time timer
 * has fired *and* the next image has loaded — a slow image extends the current
 * slide rather than dissolving to a blank. A slide whose image fails to load
 * is skipped; when every slide in a full cycle has failed, the slideshow ends
 * rather than spinning on a dead set.
 *
 * @since 0.7.0
 */

import { trapFocus } from './focus-trap';
import { readSlides, type GallerySlide } from './slides';
import {
	createAdvanceGate,
	imageLoaded,
	nextIndex,
	shouldAdvance,
	timerFired,
	type AdvanceGate,
} from './slideshow-advance';

/**
 * How long the dissolve crossfade runs, in milliseconds.
 *
 * Must match the `transition` duration on `.kntnt-photo-drop-slideshow__image`
 * in `view.scss` — the controller finalises the slide swap on this timer, not
 * on `transitionend`, so a reduced-motion cut (where no transition fires)
 * behaves identically.
 *
 * @since 0.7.0
 */
const DISSOLVE_MS = 1000;

/**
 * The class that brings one of the two stacked slide images to the front.
 *
 * @since 0.7.0
 */
const FRONT_CLASS = 'kntnt-photo-drop-slideshow__image--front';

/**
 * The overlay elements the controller drives, resolved once on construction.
 *
 * The caption is optional: the server emits it only when the gallery's shared
 * Caption content is not "none", so it is `null` otherwise and the controller
 * skips updating it.
 *
 * @since 0.7.0
 */
interface SlideshowRefs {
	readonly overlay: HTMLElement;
	/** The two stacked slide images the dissolve crossfades between. */
	readonly images: readonly [ HTMLImageElement, HTMLImageElement ];
	readonly close: HTMLButtonElement;
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
function resolveOverlay( overlay: HTMLElement ): SlideshowRefs | null {
	const images = overlay.querySelectorAll< HTMLImageElement >(
		'.kntnt-photo-drop-slideshow__image'
	);
	const close = overlay.querySelector< HTMLButtonElement >(
		'.kntnt-photo-drop-slideshow__close'
	);
	const first = images[ 0 ];
	const second = images[ 1 ];
	if ( ! first || ! second || ! close ) {
		return null;
	}

	// The caption is optional chrome; resolve it when present and leave it null
	// otherwise.
	const caption = overlay.querySelector< HTMLElement >(
		'.kntnt-photo-drop-slideshow__caption'
	);
	return { overlay, images: [ first, second ], close, caption };
}

/**
 * The slideshow controller: owns the playback state for one gallery and wires
 * the overlay to it.
 *
 * The external interface is deliberately narrow — `mount()` and `start()`; the
 * triggers in `view.ts` (the built-in button or a designer's custom element)
 * only ever start it, and the visitor's exit affordances are wired internally.
 *
 * @since 0.7.0
 */
export class GallerySlideshow {
	/** The per-image data read off the anchors once on construction. */
	readonly #slides: readonly GallerySlide[];

	/** The resolved overlay elements. */
	readonly #refs: SlideshowRefs;

	/** How long each slide stands fully visible, in seconds. */
	readonly #seconds: number;

	/** Whether the slideshow is currently playing. */
	#playing = false;

	/** The zero-based index of the slide currently (or about to be) in front. */
	#index = 0;

	/** The index loading in the back image, awaiting its turn. */
	#pending = 0;

	/** Which of the two stacked images is currently the front. */
	#front: HTMLImageElement;

	/** The other stacked image, preloading the next slide behind the front. */
	#back: HTMLImageElement;

	/** Whether the first slide of this playback is still loading into the front. */
	#awaitingFirst = false;

	/** The pure two-event gate guarding the next advance. */
	#gate: AdvanceGate = createAdvanceGate();

	/** Consecutive load failures, for the all-slides-broken bail. */
	#failures = 0;

	/** The visible-time timer, or `null` when none is running. */
	#timer: ReturnType< typeof setTimeout > | null = null;

	/** The dissolve-finalising timer, or `null` while not dissolving. */
	#dissolveTimer: ReturnType< typeof setTimeout > | null = null;

	/** The dissolve duration for this playback; `0` under reduced motion. */
	#dissolveMs = DISSOLVE_MS;

	/** The element that started the slideshow, for focus restoration on stop. */
	#trigger: HTMLElement | null = null;

	/** The active focus-trap teardown, or `null` while stopped. */
	#releaseTrap: ( () => void ) | null = null;

	/** The root element's inline `overflow` before the scroll lock, restored on stop. */
	#previousOverflow = '';

	/** The held screen wake lock, or `null` when none (missing API or released). */
	#wakeLock: WakeLockSentinel | null = null;

	/**
	 * The document keydown listener, bound while the slideshow is playing.
	 *
	 * @param event - The keyboard event from the document.
	 */
	readonly #documentKeydown = ( event: KeyboardEvent ): void =>
		this.#onKeydown( event );

	/** The document fullscreenchange listener, bound while playing. */
	readonly #documentFullscreenChange = (): void => this.#onFullscreenChange();

	/** The document visibilitychange listener, bound while playing. */
	readonly #documentVisibilityChange = (): void => this.#onVisibilityChange();

	/**
	 * Wires a slideshow onto a gallery's anchors and overlay, when the markup is
	 * complete.
	 *
	 * The single public entry point besides `start()`: it resolves the
	 * overlay's children and returns a wired controller, or `null` when a
	 * required overlay element is missing (e.g. an optimisation plugin stripped
	 * it) — in which case the caller leaves the slideshow unwired and the
	 * gallery stands as-is.
	 *
	 * @since 0.7.0
	 *
	 * @param links   - The thumbnail anchors, in gallery order.
	 * @param overlay - The overlay container emitted by `Render_Gallery`.
	 * @param seconds - How long each slide stands fully visible, in seconds (≥ 1).
	 * @return The wired controller, or `null` when the overlay markup is incomplete.
	 */
	static mount(
		links: readonly HTMLAnchorElement[],
		overlay: HTMLElement,
		seconds: number
	): GallerySlideshow | null {
		const refs = resolveOverlay( overlay );
		if ( ! refs ) {
			return null;
		}
		return new GallerySlideshow( readSlides( links ), refs, seconds );
	}

	/**
	 * Wires a controller for one gallery wrapper.
	 *
	 * @since 0.7.0
	 *
	 * @param slides  - The per-image slide data, in gallery order.
	 * @param refs    - The resolved overlay elements.
	 * @param seconds - How long each slide stands fully visible, in seconds.
	 */
	private constructor(
		slides: readonly GallerySlide[],
		refs: SlideshowRefs,
		seconds: number
	) {
		this.#slides = slides;
		this.#refs = refs;
		this.#seconds = Math.max( 1, Math.trunc( seconds ) );
		this.#front = refs.images[ 0 ];
		this.#back = refs.images[ 1 ];
		this.#bind();
	}

	/**
	 * Binds the controller's per-element listeners: the close button and the two
	 * slide images' load/error routing.
	 *
	 * The document-level listeners (Escape, fullscreenchange, visibilitychange)
	 * are *not* bound here — `start()` binds them and `#stop()` removes
	 * them, so a page full of idle slideshows costs nothing at the document.
	 *
	 * @since 0.7.0
	 */
	#bind(): void {
		// The close button is the touch path's exit affordance; Escape and the
		// native fullscreen exit are wired per playback.
		this.#refs.close.addEventListener( 'click', () => this.#stop() );

		// Route each stacked image's network outcome through one handler; which
		// role the element currently plays (front or back) decides what the event
		// means, so the roles can swap freely between slides.
		for ( const image of this.#refs.images ) {
			image.addEventListener( 'load', () =>
				this.#onImageEvent( image, true )
			);
			image.addEventListener( 'error', () =>
				this.#onImageEvent( image, false )
			);
		}
	}

	/**
	 * Starts the slideshow from the first slide.
	 *
	 * Idempotent while playing. Shows the overlay, locks the page scroll,
	 * requests fullscreen (silently degrading to the fixed overlay where the API
	 * is missing or refuses), acquires the wake lock, traps focus on the close
	 * button, and begins loading the first slide; playback proper starts when
	 * that image arrives.
	 *
	 * @since 0.7.0
	 *
	 * @param trigger - The element that started it, focused again on stop.
	 */
	start( trigger: HTMLElement ): void {
		if ( this.#playing || this.#slides.length === 0 ) {
			return;
		}
		this.#playing = true;
		this.#trigger = trigger;
		this.#index = 0;
		this.#failures = 0;

		// A reduced-motion visitor gets a hard cut instead of the dissolve; the
		// preference is read per playback so a mid-session OS change is honoured.
		const reduced =
			this.#refs.overlay.ownerDocument.defaultView?.matchMedia(
				'(prefers-reduced-motion: reduce)'
			).matches;
		this.#dissolveMs = reduced ? 0 : DISSOLVE_MS;

		// Show the overlay, lock the page scroll behind it, and take over Escape,
		// the fullscreen-exit signal, and the visibility signal at the document
		// level for as long as the playback lasts.
		this.#refs.overlay.hidden = false;
		const doc = this.#refs.overlay.ownerDocument;
		this.#previousOverflow = doc.documentElement.style.overflow;
		doc.documentElement.style.overflow = 'hidden';
		doc.addEventListener( 'keydown', this.#documentKeydown );
		doc.addEventListener(
			'fullscreenchange',
			this.#documentFullscreenChange
		);
		doc.addEventListener(
			'visibilitychange',
			this.#documentVisibilityChange
		);

		// Go fullscreen where the API exists; a missing API or a refusal (user
		// agent policy) leaves the fixed, viewport-filling overlay as the surface.
		if ( typeof this.#refs.overlay.requestFullscreen === 'function' ) {
			this.#refs.overlay.requestFullscreen().catch( () => {
				// The overlay itself is the fallback surface; nothing to do.
			} );
		}

		// Keep the screen awake while playing — endless playback implies
		// photo-frame use; missing API degrades silently.
		void this.#acquireWakeLock();

		this.#releaseTrap = trapFocus( this.#refs.overlay );
		this.#refs.close.focus();

		// Load the first slide into the front image; the load routing fades it in
		// and begins the visible-time cycle.
		this.#awaitingFirst = true;
		this.#load( this.#front, this.#slides[ this.#index ] );
	}

	/**
	 * Ends the playback, undoes every start-time side effect, and restores focus
	 * to the trigger.
	 *
	 * Safe to call from any exit path — the close button, Escape, or the native
	 * fullscreen exit — and idempotent, since the paths can overlap (Escape in
	 * fullscreen both delivers a keydown and fires `fullscreenchange`).
	 *
	 * @since 0.7.0
	 */
	#stop(): void {
		if ( ! this.#playing ) {
			return;
		}
		this.#playing = false;

		// Cancel whichever timers are in flight; no advance may outlive the
		// playback.
		if ( this.#timer !== null ) {
			clearTimeout( this.#timer );
			this.#timer = null;
		}
		if ( this.#dissolveTimer !== null ) {
			clearTimeout( this.#dissolveTimer );
			this.#dissolveTimer = null;
		}

		// Release the wake lock and leave fullscreen when this overlay still owns
		// it (an Escape-driven exit has already left it by the time we run). The
		// exit is asynchronous and the browser resets focus when the fullscreen
		// collapses, so the trigger-focus restoration below must wait for it to
		// settle or an immediate focus() would be silently undone.
		this.#wakeLock?.release().catch( () => {
			// A lock the browser already released rejects; that is fine.
		} );
		this.#wakeLock = null;
		const doc = this.#refs.overlay.ownerDocument;
		let exitingFullscreen: Promise< void > | null = null;
		if ( doc.fullscreenElement === this.#refs.overlay ) {
			exitingFullscreen = doc.exitFullscreen().catch( () => {
				// Losing fullscreen is best-effort; the overlay hides regardless.
			} );
		}

		// Hide the overlay and reset both stacked images, clearing their sources
		// so the next playback's `src` assignment always re-fires a load event.
		this.#refs.overlay.hidden = true;
		for ( const image of this.#refs.images ) {
			image.classList.remove( FRONT_CLASS );
			image.removeAttribute( 'srcset' );
			image.removeAttribute( 'sizes' );
			image.removeAttribute( 'src' );
			image.alt = '';
		}

		// Undo the document-level side effects: the scroll lock and the three
		// listeners.
		doc.documentElement.style.overflow = this.#previousOverflow;
		doc.removeEventListener( 'keydown', this.#documentKeydown );
		doc.removeEventListener(
			'fullscreenchange',
			this.#documentFullscreenChange
		);
		doc.removeEventListener(
			'visibilitychange',
			this.#documentVisibilityChange
		);

		// Hand focus back to the trigger — after the fullscreen exit settles
		// when one is in flight, immediately otherwise.
		this.#releaseTrap?.();
		this.#releaseTrap = null;
		const trigger = this.#trigger;
		this.#trigger = null;
		if ( exitingFullscreen ) {
			void exitingFullscreen.finally( () => trigger?.focus() );
		} else {
			trigger?.focus();
		}
	}

	/**
	 * Ends the playback on Escape; every other key is inert (the surface is
	 * passive).
	 *
	 * In fullscreen mode the browser may exit fullscreen on Escape before or
	 * after delivering the keydown; `#stop()` is idempotent, so both orders
	 * end the playback exactly once.
	 *
	 * @since 0.7.0
	 *
	 * @param event - The keyboard event from the document.
	 */
	#onKeydown( event: KeyboardEvent ): void {
		if ( ! this.#playing || event.key !== 'Escape' ) {
			return;
		}
		event.preventDefault();
		this.#stop();
	}

	/**
	 * Ends the playback when the native fullscreen exit (Escape, system UI)
	 * dismisses the overlay's fullscreen.
	 *
	 * Entering fullscreen fires the same event with the overlay as the
	 * fullscreen element; only the exit — no fullscreen element left — ends the
	 * show.
	 *
	 * @since 0.7.0
	 */
	#onFullscreenChange(): void {
		const doc = this.#refs.overlay.ownerDocument;
		if ( this.#playing && doc.fullscreenElement === null ) {
			this.#stop();
		}
	}

	/**
	 * Re-acquires the wake lock when the tab becomes visible again.
	 *
	 * Browsers release a wake lock automatically when the page is hidden; coming
	 * back to a still-playing slideshow should keep the screen awake again.
	 *
	 * @since 0.7.0
	 */
	#onVisibilityChange(): void {
		const doc = this.#refs.overlay.ownerDocument;
		if ( this.#playing && doc.visibilityState === 'visible' ) {
			void this.#acquireWakeLock();
		}
	}

	/**
	 * Acquires the screen wake lock, best-effort.
	 *
	 * A missing API, an insecure context, or a policy refusal all degrade
	 * silently — the slideshow plays on and the OS owns the screen.
	 *
	 * @since 0.7.0
	 */
	async #acquireWakeLock(): Promise< void > {
		try {
			this.#wakeLock =
				( await this.#refs.overlay.ownerDocument.defaultView?.navigator.wakeLock?.request(
					'screen'
				) ) ?? null;
		} catch {
			this.#wakeLock = null;
		}
	}

	/**
	 * Routes a stacked image's network outcome by the role it currently plays.
	 *
	 * The front image only matters while the playback's first slide is loading
	 * (every later slide enters through the back); the back image's outcome
	 * feeds the advance gate or the failure-skip path.
	 *
	 * @since 0.7.0
	 *
	 * @param image - The stacked image the event fired on.
	 * @param ok    - Whether the image loaded (`true`) or errored (`false`).
	 */
	#onImageEvent( image: HTMLImageElement, ok: boolean ): void {
		if ( ! this.#playing ) {
			return;
		}
		if ( image === this.#front && this.#awaitingFirst ) {
			this.#onFirstSlide( ok );
		} else if ( image === this.#back ) {
			this.#onNextSlide( ok );
		}
	}

	/**
	 * Shows the playback's first slide when it arrives, or skips past a broken
	 * one.
	 *
	 * The first slide fades in from the black backdrop (the same front-class
	 * transition the dissolve uses) and starts the visible-time cycle. A failed
	 * first slide advances the start position instead, ending the playback when
	 * a whole cycle of slides has failed.
	 *
	 * @since 0.7.0
	 *
	 * @param ok - Whether the first slide's image loaded.
	 */
	#onFirstSlide( ok: boolean ): void {
		if ( ! ok ) {
			this.#failures += 1;
			if ( this.#failures >= this.#slides.length ) {
				this.#stop();
				return;
			}
			this.#index = nextIndex( this.#index, this.#slides.length );
			this.#load( this.#front, this.#slides[ this.#index ] );
			return;
		}
		this.#awaitingFirst = false;
		this.#front.classList.add( FRONT_CLASS );
		this.#setCaption( this.#slides[ this.#index ] );
		this.#beginVisible();
	}

	/**
	 * Feeds the back image's outcome to the advance gate, or skips a broken
	 * slide.
	 *
	 * A loaded image raises the gate's image flag (advancing immediately when
	 * the timer already fired — the late-image rule). A failed image tries the
	 * slide after the pending one, ending the playback when a whole cycle has
	 * failed.
	 *
	 * @since 0.7.0
	 *
	 * @param ok - Whether the pending slide's image loaded.
	 */
	#onNextSlide( ok: boolean ): void {
		if ( ok ) {
			this.#gate = imageLoaded( this.#gate );
			if ( shouldAdvance( this.#gate ) ) {
				this.#dissolve();
			}
			return;
		}
		this.#failures += 1;
		if ( this.#failures >= this.#slides.length ) {
			this.#stop();
			return;
		}
		this.#pending = nextIndex( this.#pending, this.#slides.length );
		this.#load( this.#back, this.#slides[ this.#pending ] );
	}

	/**
	 * Begins one slide's fully-visible phase: a fresh gate, the visible-time
	 * timer, and the next slide preloading into the back image.
	 *
	 * A single-image gallery has nothing to advance to — the one slide simply
	 * stands until the visitor ends the playback.
	 *
	 * @since 0.7.0
	 */
	#beginVisible(): void {
		this.#failures = 0;
		if ( this.#slides.length < 2 ) {
			return;
		}

		// Arm the two-event gate: the timer measures the fully-visible seconds
		// (the dissolve comes on top of them), and the back image starts loading
		// the next slide now so it is usually ready when the timer fires.
		this.#gate = createAdvanceGate();
		this.#pending = nextIndex( this.#index, this.#slides.length );
		this.#load( this.#back, this.#slides[ this.#pending ] );
		this.#timer = setTimeout( () => {
			this.#timer = null;
			this.#gate = timerFired( this.#gate );
			if ( shouldAdvance( this.#gate ) ) {
				this.#dissolve();
			}
		}, this.#seconds * 1000 );
	}

	/**
	 * Crossfades to the pending slide and schedules the swap's finalisation.
	 *
	 * The caption switches at dissolve start (a single overlay cannot crossfade
	 * its text), and the swap completes on {@link DISSOLVE_MS} — or instantly
	 * under reduced motion, where the CSS transition is also collapsed.
	 *
	 * @since 0.7.0
	 */
	#dissolve(): void {
		this.#setCaption( this.#slides[ this.#pending ] );
		this.#back.classList.add( FRONT_CLASS );
		this.#front.classList.remove( FRONT_CLASS );
		this.#dissolveTimer = setTimeout( () => {
			this.#dissolveTimer = null;

			// The incoming slide is now the front; the freed image becomes the
			// back and the next visible phase begins.
			this.#index = this.#pending;
			const freed = this.#front;
			this.#front = this.#back;
			this.#back = freed;
			this.#beginVisible();
		}, this.#dissolveMs );
	}

	/**
	 * Points a stacked image at a slide: responsive srcset (the overlay spans
	 * the viewport), the URL, and the accessible label.
	 *
	 * @since 0.7.0
	 *
	 * @param image - The stacked image to load into.
	 * @param slide - The slide to load, or `undefined` on an impossible index.
	 */
	#load( image: HTMLImageElement, slide: GallerySlide | undefined ): void {
		if ( ! slide ) {
			return;
		}
		if ( slide.srcset !== '' ) {
			image.srcset = slide.srcset;
			image.sizes = '100vw';
		} else {
			image.removeAttribute( 'srcset' );
			image.removeAttribute( 'sizes' );
		}
		image.alt = slide.label;
		image.src = slide.url;
	}

	/**
	 * Mirrors a slide's caption onto the overlay's figcaption, when the gallery
	 * has one.
	 *
	 * @since 0.7.0
	 *
	 * @param slide - The slide whose caption to show, or `undefined` to skip.
	 */
	#setCaption( slide: GallerySlide | undefined ): void {
		if ( ! this.#refs.caption || ! slide ) {
			return;
		}
		this.#refs.caption.textContent = slide.caption;
		this.#refs.caption.hidden = slide.caption === '';
	}
}
