/**
 * DOM wiring for the gallery's add-to-media overlay — the confirm callout and
 * the copy POST.
 *
 * The gallery is server-rendered; this module progressively enhances the
 * capability-gated add-to-media `<button>` icons (ADR-0015). The wrapper carries
 * the `wp_rest` nonce and the collection's media endpoint URL only for a capable
 * user, so the absence of either leaves the icons inert — a defence-in-depth
 * match for the server, which emits no icon for anyone else. A copy into the
 * Media Library is a deliberate act, so each icon is gated by a two-click
 * {@link confirmGate}: the first click arms the icon (a visible ring), a second
 * within the armed window fires the {@link addToMedia} POST, and any click on
 * another icon disarms it. While the copy is in flight the icon dims; on success
 * it briefly swaps to a checkmark, then returns to the plus so another copy can
 * be made (each confirmed click adds another attachment — no dedup, ADR-0015).
 *
 * One delegated click listener on the wrapper serves every icon — the gallery
 * figures' and the lightbox's — so a thousand-image gallery wires no per-icon
 * handler. The thumbnail icons carry their image's collection-relative path
 * directly; the lightbox icon is path-less in the markup and the lightbox
 * controller sets its `data-kntnt-photo-drop-path` per slide, so this module
 * reads the path off whichever icon was clicked.
 *
 * All decision logic lives in the pure {@link confirmGate} and {@link addToMedia}
 * modules; this module is the thin DOM wiring around them.
 *
 * @since 0.12.0
 */

import { addToMedia, confirmGate, type ConfirmGate } from './add-to-media';
import { shouldInterceptClick } from './save-file';

/**
 * The icon class that marks an add-to-media control on any surface.
 *
 * @since 0.12.0
 */
const ADD_TO_MEDIA_SELECTOR = '.kntnt-photo-drop-gallery__icon--add-to-media';

/**
 * The modifier toggled while the confirm gate is armed (awaiting the second click).
 *
 * @since 0.12.0
 */
const ARMED_CLASS = 'kntnt-photo-drop-gallery__icon--armed';

/**
 * The modifier toggled while the copy POST is in flight.
 *
 * @since 0.12.0
 */
const BUSY_CLASS = 'kntnt-photo-drop-gallery__icon--busy';

/**
 * The modifier toggled briefly on a successful copy (the checkmark glyph).
 *
 * @since 0.12.0
 */
const DONE_CLASS = 'kntnt-photo-drop-gallery__icon--done';

/**
 * How long the success checkmark stays before the icon returns to the plus, in
 * milliseconds.
 *
 * @since 0.12.0
 */
const DONE_DURATION = 1500;

/**
 * Wires every add-to-media icon under a gallery wrapper to the confirm-and-copy flow.
 *
 * Reads the wrapper's add-to-media credential (the `wp_rest` nonce and the
 * collection's media URL); when either is missing the user is not capable, so the
 * icons stay inert and no listener is installed. Otherwise one delegated click
 * listener serves every icon: a plain primary click on an icon runs its confirm
 * gate (arming on the first click, firing the copy on a confirming second), while
 * a click elsewhere disarms whichever icon was armed. Modified clicks are left to
 * the browser. The per-icon gates live in a `WeakMap`, so the arming state is
 * per-icon and a thousand-image gallery still installs exactly one listener.
 *
 * @since 0.12.0
 *
 * @param wrapper - The gallery wrapper the add-to-media icons live in.
 */
export function wireAddToMedia( wrapper: HTMLElement ): void {
	// Read the credential the server emits only for a capable user; without both
	// the nonce and the URL there is nothing to wire, so the icons stay inert.
	const nonce = wrapper.dataset.kntntPhotoDropNonce;
	const mediaUrl = wrapper.dataset.kntntPhotoDropMediaUrl;
	if ( ! nonce || ! mediaUrl ) {
		return;
	}

	// One confirm gate per icon, created on first interaction; the WeakMap lets a
	// removed icon's gate be collected without bookkeeping.
	const gates = new WeakMap< HTMLElement, ConfirmGate >();
	let armedIcon: HTMLElement | null = null;

	// Disarms the currently-armed icon, if any — used when the visitor clicks away
	// or after a copy settles, so a stale armed ring never lingers.
	const disarm = (): void => {
		if ( armedIcon ) {
			armedIcon.classList.remove( ARMED_CLASS );
			gates.get( armedIcon )?.cancel();
			armedIcon = null;
		}
	};

	// One delegated listener serves every icon on every surface; it resolves the
	// clicked icon, runs its gate, and either arms it (first click) or fires the
	// copy (confirming second click). A plain click elsewhere disarms.
	wrapper.addEventListener( 'click', ( event ) => {
		if ( ! shouldInterceptClick( event ) ) {
			return;
		}
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const icon = target.closest< HTMLElement >( ADD_TO_MEDIA_SELECTOR );
		if ( ! icon ) {
			disarm();
			return;
		}

		// The icon owns this click: stop it opening the lightbox or navigating, then
		// run the gate. A busy icon ignores further clicks until its copy settles.
		event.preventDefault();
		if ( icon.classList.contains( BUSY_CLASS ) ) {
			return;
		}
		if ( armedIcon && armedIcon !== icon ) {
			disarm();
		}
		const gate = gates.get( icon ) ?? confirmGate();
		gates.set( icon, gate );

		// The first activation only arms (the view shows the ring and waits); a
		// confirming second activation fires the copy.
		if ( ! gate.activate() ) {
			armedIcon = icon;
			icon.classList.add( ARMED_CLASS );
			return;
		}
		armedIcon = null;
		void runCopy( icon, mediaUrl, nonce );
	} );
}

/**
 * Posts one confirmed copy and reflects its outcome on the icon.
 *
 * Reads the icon's collection-relative path (set directly on the thumbnail icon,
 * or per slide by the lightbox controller), dims the icon while the copy is in
 * flight, then shows a brief success checkmark or — on any failure — silently
 * returns the icon to its plain state so the visitor can retry. The success
 * checkmark clears after {@link DONE_DURATION} so the icon is ready for another
 * copy (no dedup; each confirmed copy adds another attachment).
 *
 * @since 0.12.0
 *
 * @param icon     - The add-to-media icon that was confirmed.
 * @param mediaUrl - The collection's media endpoint URL.
 * @param nonce    - The `wp_rest` nonce.
 */
async function runCopy(
	icon: HTMLElement,
	mediaUrl: string,
	nonce: string
): Promise< void > {
	// Resolve the target path off the icon; an icon with no path (a misconfigured
	// lightbox icon before its first slide) is left untouched.
	const path = icon.dataset.kntntPhotoDropPath;
	if ( ! path ) {
		icon.classList.remove( ARMED_CLASS );
		return;
	}

	// Dim the icon while the copy POSTs, then reflect the outcome: a brief
	// checkmark on success, or a silent return to plain on failure so the visitor
	// can retry. The arming ring is cleared either way.
	icon.classList.remove( ARMED_CLASS );
	icon.classList.add( BUSY_CLASS );
	const result = await addToMedia( mediaUrl, path, nonce );
	icon.classList.remove( BUSY_CLASS );
	if ( result.ok ) {
		icon.classList.add( DONE_CLASS );
		setTimeout( () => icon.classList.remove( DONE_CLASS ), DONE_DURATION );
	}
}
