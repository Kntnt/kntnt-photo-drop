/**
 * DOM wiring for the gallery's add-to-media overlay — the single-click copy and
 * its duplicate-only overwrite confirm.
 *
 * The gallery is server-rendered; this module progressively enhances the
 * capability-gated add-to-media `<button>` icons (ADR-0015). The wrapper carries
 * the `wp_rest` nonce and the collection's media endpoint URL only for a capable
 * user, so the absence of either leaves the icons inert — a defence-in-depth
 * match for the server, which emits no icon for anyone else. Copying into the
 * Library is additive, not destructive, so a plain click on an icon fires the
 * {@link addToMedia} POST immediately — a single click adds (ADR-0015 amendment).
 * Safety against accidental re-adds is the server's de-duplication, not a blanket
 * confirm: when the image is already in the Library the POST returns `exists`, and
 * this module raises an inline **Overwrite / Cancel** popover (modelled on the
 * trash popover); confirming Overwrite re-sends the copy with `overwrite: true`,
 * replacing the existing attachment's file in place. While a copy is in flight the
 * icon dims; on success it briefly swaps to a checkmark, then returns to the plus.
 *
 * One delegated click listener on the wrapper serves every icon — the gallery
 * figures' and the lightbox's — so a thousand-image gallery wires no per-icon
 * handler. The thumbnail icons carry their image's collection-relative path
 * directly; the lightbox icon is path-less in the markup and the lightbox
 * controller sets its `data-kntnt-photo-drop-path` per slide, so this module
 * reads the path off whichever icon was clicked.
 *
 * The copy request lives in the pure {@link addToMedia} module; this module is the
 * thin DOM wiring — the single-click flow and the overwrite popover — around it.
 *
 * @since 0.12.0
 * @since 0.15.0 Single click adds; a duplicate raises an overwrite confirm.
 */

import { addToMedia } from './add-to-media';
import { shouldInterceptClick } from './save-file';

/**
 * The overwrite popover's translatable copy, with neutral English fallbacks.
 *
 * View-script modules cannot translate at runtime, so `Render_Gallery` mirrors the
 * translated confirm strings onto the wrapper's data attributes (the same pattern
 * the trash popover uses); this module reads them and falls back to English when a
 * string is absent, so a stripped attribute degrades to a working (if untranslated)
 * popover rather than an empty one.
 *
 * @since 0.15.0
 */
interface OverwriteCopy {
	/** The duplicate-stating prompt above the buttons. */
	readonly message: string;
	/** The affirmative (overwrite) button's label. */
	readonly confirm: string;
	/** The dismiss button's label. */
	readonly cancel: string;
	/** The popover's accessible dialog label. */
	readonly dialogLabel: string;
}

/**
 * The icon class that marks an add-to-media control on any surface.
 *
 * @since 0.12.0
 */
const ADD_TO_MEDIA_SELECTOR = '.kntnt-photo-drop-gallery__icon--add-to-media';

/**
 * The class on the inline confirm popover this module creates (shared with trash).
 *
 * @since 0.15.0
 */
const POPOVER_CLASS = 'kntnt-photo-drop-gallery__confirm';

/**
 * The modifier toggled on an icon while its overwrite popover is open.
 *
 * @since 0.15.0
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
 * Wires every add-to-media icon under a gallery wrapper to the copy flow.
 *
 * Reads the wrapper's add-to-media credential (the `wp_rest` nonce and the
 * collection's media URL); when either is missing the user is not capable, so the
 * icons stay inert and no listener is installed. Otherwise one delegated click
 * listener serves every icon: a plain primary click on an icon fires its copy
 * immediately (single click adds), while a click elsewhere dismisses any open
 * overwrite popover. Modified clicks are left to the browser. Only one overwrite
 * popover is open at a time, tracked here so a click elsewhere or a new one
 * dismisses the prior.
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

	// Resolve the overwrite popover copy the server mirrored onto the wrapper,
	// falling back to neutral English so a stripped attribute still yields a working
	// popover.
	const copy: OverwriteCopy = {
		message:
			wrapper.dataset.kntntPhotoDropOverwritePrompt ||
			'This image is already in the Media Library. Overwrite it?',
		confirm: wrapper.dataset.kntntPhotoDropOverwriteConfirm || 'Overwrite',
		cancel: wrapper.dataset.kntntPhotoDropOverwriteCancel || 'Cancel',
		dialogLabel:
			wrapper.dataset.kntntPhotoDropOverwriteLabel || 'Confirm overwrite',
	};

	// Track the single open overwrite popover and its owning icon so a new open, a
	// cancel, or a click elsewhere can dismiss it; the teardown also unarms the icon.
	let open: { icon: HTMLElement; dismiss: () => void } | null = null;
	const closePopover = (): void => {
		if ( open ) {
			open.dismiss();
			open = null;
		}
	};

	// One delegated listener serves every icon on every surface. A click on the open
	// popover's own buttons is the popover's business (its listeners own it); here a
	// click resolves the add-to-media icon and fires its copy, and a plain click
	// anywhere else dismisses an open popover.
	wrapper.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		if ( open && target.closest( '.' + POPOVER_CLASS ) ) {
			return;
		}
		if ( ! shouldInterceptClick( event ) ) {
			return;
		}
		const icon = target.closest< HTMLElement >( ADD_TO_MEDIA_SELECTOR );
		if ( ! icon ) {
			closePopover();
			return;
		}

		// The icon owns this click: stop it opening the lightbox or navigating, dismiss
		// any open popover, then fire the copy. A busy icon ignores further clicks until
		// its copy settles.
		event.preventDefault();
		if ( icon.classList.contains( BUSY_CLASS ) ) {
			return;
		}
		closePopover();
		void runCopy( icon, mediaUrl, nonce, false, {
			onExists: () => {
				open = openOverwriteConfirm( icon, copy, () => {
					closePopover();
					void runCopy( icon, mediaUrl, nonce, true, {} );
				} );
			},
		} );
	} );
}

/**
 * Posts one copy and reflects its outcome on the icon.
 *
 * Reads the icon's collection-relative path (set directly on the thumbnail icon,
 * or per slide by the lightbox controller), dims the icon while the copy is in
 * flight, then reflects the outcome: a created or replaced attachment flashes a
 * brief success checkmark (cleared after {@link DONE_DURATION}); an already-present
 * image (without overwrite) invokes the supplied `onExists` so the caller can
 * raise its overwrite popover; any error silently returns the icon to plain so the
 * visitor can retry.
 *
 * @since 0.15.0
 *
 * @param icon              - The add-to-media icon that was clicked.
 * @param mediaUrl          - The collection's media endpoint URL.
 * @param nonce             - The `wp_rest` nonce.
 * @param overwrite         - Whether to ask the server to overwrite an existing copy.
 * @param handlers          - Outcome handlers passed by the caller.
 * @param handlers.onExists - Opens the overwrite popover when the image is already present.
 */
async function runCopy(
	icon: HTMLElement,
	mediaUrl: string,
	nonce: string,
	overwrite: boolean,
	handlers: { onExists?: () => void }
): Promise< void > {
	// Resolve the target path off the icon; an icon with no path (a misconfigured
	// lightbox icon before its first slide) is left untouched.
	const path = icon.dataset.kntntPhotoDropPath;
	if ( ! path ) {
		return;
	}

	// Dim the icon while the copy POSTs, then reflect the outcome: a brief checkmark
	// on a created/replaced attachment, the overwrite confirm on a duplicate, or a
	// silent return to plain on an error so the visitor can retry.
	icon.classList.add( BUSY_CLASS );
	const result = await addToMedia( mediaUrl, path, nonce, { overwrite } );
	icon.classList.remove( BUSY_CLASS );
	if ( result.outcome === 'created' || result.outcome === 'replaced' ) {
		icon.classList.add( DONE_CLASS );
		setTimeout( () => icon.classList.remove( DONE_CLASS ), DONE_DURATION );
		return;
	}
	if ( result.outcome === 'exists' ) {
		handlers.onExists?.();
	}
}

/**
 * Opens an inline overwrite-confirm popover anchored to an add-to-media icon.
 *
 * Models the trash popover (ADR-0015): a `role="dialog"` panel with a
 * duplicate-stating message and an explicit **Overwrite** / **Cancel** pair,
 * reusing the shared `…__confirm` classes. The affirmative button is additive (not
 * the destructive red), so it uses the `--overwrite` modifier; Cancel uses
 * `--cancel` and receives focus, so a stray Enter cancels and a keyboard user
 * lands on the safe choice. Overwrite fires the supplied callback; Cancel, Escape,
 * or a click elsewhere dismisses it. Returns a handle carrying the icon and a
 * dismiss function that removes the popover and unarms the icon.
 *
 * @since 0.15.0
 *
 * @param icon      - The add-to-media icon the popover anchors to.
 * @param copy      - The translatable popover copy (mirrored from the wrapper).
 * @param onConfirm - Fired when the deliberate Overwrite button is pressed.
 * @return The open-popover handle.
 */
function openOverwriteConfirm(
	icon: HTMLElement,
	copy: OverwriteCopy,
	onConfirm: () => void
): { icon: HTMLElement; dismiss: () => void } {
	const doc = icon.ownerDocument;

	// Build the popover: a duplicate-stating message and the explicit Overwrite /
	// Cancel pair. The affirmative button is additive, so it carries the neutral
	// `--overwrite` colour, not the trash popover's destructive red.
	const popover = doc.createElement( 'div' );
	popover.className = POPOVER_CLASS;
	popover.setAttribute( 'role', 'dialog' );
	popover.setAttribute( 'aria-label', copy.dialogLabel );
	const message = doc.createElement( 'p' );
	message.className = POPOVER_CLASS + '__message';
	message.textContent = copy.message;
	const confirm = doc.createElement( 'button' );
	confirm.type = 'button';
	confirm.className =
		POPOVER_CLASS + '__button ' + POPOVER_CLASS + '__button--overwrite';
	confirm.textContent = copy.confirm;
	const cancel = doc.createElement( 'button' );
	cancel.type = 'button';
	cancel.className =
		POPOVER_CLASS + '__button ' + POPOVER_CLASS + '__button--cancel';
	cancel.textContent = copy.cancel;
	popover.append( message, confirm, cancel );

	// Anchor the popover to the icon and arm the icon while it is open; the icon is
	// positioned, so an absolutely-positioned popover sits over it.
	icon.classList.add( ARMED_CLASS );
	icon.append( popover );

	// Dismiss removes the popover, unarms the icon, and drops the Escape listener; it
	// is idempotent so the delegated wrapper handler and Cancel can both call it.
	const onKeydown = ( event: KeyboardEvent ): void => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			dismiss();
			icon.focus();
		}
	};
	const dismiss = (): void => {
		doc.removeEventListener( 'keydown', onKeydown );
		popover.remove();
		icon.classList.remove( ARMED_CLASS );
	};

	// Wire the two deliberate choices and take over Escape; Overwrite fires the
	// caller's copy, Cancel just dismisses. Focus the safe choice (Cancel) so a stray
	// Enter cancels and a keyboard user is not one keystroke from overwriting.
	confirm.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		onConfirm();
	} );
	cancel.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		dismiss();
		icon.focus();
	} );
	doc.addEventListener( 'keydown', onKeydown );
	cancel.focus();

	return { icon, dismiss };
}
