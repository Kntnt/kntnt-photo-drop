/**
 * DOM wiring for the gallery's trash overlay — the inline-popover confirm and
 * the permanent-delete request.
 *
 * The gallery is server-rendered; this module progressively enhances the
 * capability-gated trash `<button>` icons (ADR-0015). The wrapper carries the
 * `wp_rest` nonce and the collection's images endpoint URL only for a user holding
 * the delete capability, so the absence of either leaves the icons inert — a
 * defence-in-depth match for the server, which emits no icon for anyone else. A
 * delete is permanent and has no recycle bin, so a single click never deletes:
 * clicking the icon opens an **inline popover** with an explicit **Delete** /
 * **Cancel** pair whose copy makes the permanence plain, and only the deliberate
 * Delete button fires the {@link deleteImage} request. Cancel, a click elsewhere,
 * or Escape dismisses the popover with nothing removed.
 *
 * On success the image's tile is removed from the DOM live (found by the
 * collection-relative path the server mirrors onto each figure's anchor), and —
 * when the lightbox is open — the open viewer advances to the next image or closes
 * when the set empties (the lightbox owns that transition via
 * {@link GalleryLightbox.removeImage}). The per-folder index self-heals on the
 * next server render, so a reload reflects the deletion regardless.
 *
 * One delegated click listener on the wrapper serves every icon — the gallery
 * figures' and the lightbox's. The thumbnail icons carry their image's
 * collection-relative path directly; the lightbox icon is path-less in the markup
 * and the lightbox controller sets its `data-kntnt-photo-drop-path` per slide, so
 * this module reads the path off whichever icon was clicked.
 *
 * The delete request lives in the pure {@link deleteImage} module; this module is
 * the DOM wiring — the popover and the live removal — around it.
 *
 * @since 0.15.0
 */

import { deleteImage } from './delete-image';
import type { GalleryLightbox } from './lightbox';
import { SLIDE_LINK_SELECTOR } from './slides';
import { shouldInterceptClick } from './save-file';

/**
 * The popover's translatable copy, with neutral English fallbacks.
 *
 * View-script modules cannot translate at runtime, so `Render_Gallery` mirrors the
 * translated confirm strings onto the wrapper's data attributes (the same pattern
 * the lightbox counter uses); this module reads them and falls back to English when
 * a string is absent, so a stripped attribute degrades to a working (if untranslated)
 * popover rather than an empty one.
 *
 * @since 0.15.0
 */
interface ConfirmCopy {
	/** The permanence-stating prompt above the buttons. */
	readonly message: string;
	/** The destructive confirm button's label. */
	readonly confirm: string;
	/** The dismiss button's label. */
	readonly cancel: string;
	/** The popover's accessible dialog label. */
	readonly dialogLabel: string;
}

/**
 * The icon class that marks a trash control on any surface.
 *
 * @since 0.15.0
 */
const TRASH_SELECTOR = '.kntnt-photo-drop-gallery__icon--trash';

/**
 * The class on the inline confirm popover this module creates.
 *
 * @since 0.15.0
 */
const POPOVER_CLASS = 'kntnt-photo-drop-gallery__confirm';

/**
 * The modifier toggled on a trash icon while its confirm popover is open.
 *
 * @since 0.15.0
 */
const ARMED_CLASS = 'kntnt-photo-drop-gallery__icon--armed';

/**
 * The modifier toggled on a trash icon while its delete request is in flight.
 *
 * @since 0.15.0
 */
const BUSY_CLASS = 'kntnt-photo-drop-gallery__icon--busy';

/**
 * Wires every trash icon under a gallery wrapper to the confirm-and-delete flow.
 *
 * Reads the wrapper's trash credential (the `wp_rest` nonce and the collection's
 * images URL); when either is missing the user is not capable, so the icons stay
 * inert and no listener is installed. Otherwise one delegated click listener
 * serves every icon: a plain primary click on an icon opens its inline confirm
 * popover (closing any other open one first), and a Delete inside that popover
 * fires the permanent delete. A modified click is left to the browser. Only one
 * popover is open at a time, tracked here so a click elsewhere can dismiss it.
 *
 * @since 0.15.0
 *
 * @param wrapper     - The gallery wrapper the trash icons live in.
 * @param getLightbox - Returns the gallery's lightbox controller, or null when none is mounted.
 */
export function wireTrash(
	wrapper: HTMLElement,
	getLightbox: () => GalleryLightbox | null
): void {
	// Read the credential the server emits only for a capable user; without both the
	// nonce and the URL there is nothing to wire, so the icons stay inert.
	const nonce = wrapper.dataset.kntntPhotoDropNonce;
	const deleteUrl = wrapper.dataset.kntntPhotoDropDeleteUrl;
	if ( ! nonce || ! deleteUrl ) {
		return;
	}

	// Resolve the popover copy the server mirrored onto the wrapper, falling back to
	// neutral English so a stripped attribute still yields a working popover.
	const copy: ConfirmCopy = {
		message:
			wrapper.dataset.kntntPhotoDropDeletePrompt ||
			'Delete this image permanently? This cannot be undone.',
		confirm: wrapper.dataset.kntntPhotoDropDeleteConfirm || 'Delete',
		cancel: wrapper.dataset.kntntPhotoDropDeleteCancel || 'Cancel',
		dialogLabel:
			wrapper.dataset.kntntPhotoDropDeleteLabel || 'Confirm deletion',
	};

	// Track the single open popover and its owning icon so a new open, a cancel, or
	// a click elsewhere can dismiss it; the teardown also unarms the icon.
	let open: {
		popover: HTMLElement;
		icon: HTMLElement;
		dismiss: () => void;
	} | null = null;
	const closePopover = (): void => {
		if ( open ) {
			open.dismiss();
			open = null;
		}
	};

	// One delegated listener serves every icon on every surface. A click on the
	// open popover's own buttons is handled by the popover's own listeners (below),
	// so here a click resolves the trash icon: a click on an icon opens its confirm,
	// and a plain click anywhere else dismisses an open popover.
	wrapper.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		// A click inside the open popover is the popover's business (its Delete/Cancel
		// buttons own it); leave it entirely alone here.
		if ( open && target.closest( '.' + POPOVER_CLASS ) ) {
			return;
		}
		if ( ! shouldInterceptClick( event ) ) {
			return;
		}
		const icon = target.closest< HTMLElement >( TRASH_SELECTOR );
		if ( ! icon ) {
			closePopover();
			return;
		}

		// The icon owns this click: stop it opening the lightbox or navigating. A busy
		// icon (its delete already in flight) ignores further clicks; clicking the icon
		// that already owns the open popover toggles it shut; otherwise open a fresh one.
		event.preventDefault();
		if ( icon.classList.contains( BUSY_CLASS ) ) {
			return;
		}
		if ( open?.icon === icon ) {
			closePopover();
			return;
		}
		closePopover();
		open = openConfirm( icon, copy, () => {
			closePopover();
			void runDelete( wrapper, icon, deleteUrl, nonce, getLightbox );
		} );
	} );
}

/**
 * Opens an inline confirm popover anchored to a trash icon.
 *
 * Builds an explicit two-button confirm — a destructive **Delete** and a
 * **Cancel** — whose heading makes the permanence plain (there is no recycle bin,
 * ADR-0015). The Delete button fires the supplied callback; Cancel and the Escape
 * key dismiss it. The icon is marked armed while the popover is open, and focus
 * moves to the Cancel button so a stray Enter cancels rather than deletes and a
 * keyboard user lands on the safe choice. Returns a handle carrying the popover,
 * its icon, and a dismiss function that removes the popover and unarms the icon.
 *
 * @since 0.15.0
 *
 * @param icon      - The trash icon the popover anchors to.
 * @param copy      - The translatable popover copy (mirrored from the wrapper).
 * @param onConfirm - Fired when the deliberate Delete button is pressed.
 * @return The open-popover handle.
 */
function openConfirm(
	icon: HTMLElement,
	copy: ConfirmCopy,
	onConfirm: () => void
): { popover: HTMLElement; icon: HTMLElement; dismiss: () => void } {
	const doc = icon.ownerDocument;

	// Build the popover: a permanence-stating message and the explicit Delete /
	// Cancel pair. The destructive button is marked so the stylesheet can colour it.
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
		POPOVER_CLASS + '__button ' + POPOVER_CLASS + '__button--delete';
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

	// Dismiss removes the popover, unarms the icon, and drops the Escape listener;
	// it is idempotent so the delegated wrapper handler and Cancel can both call it.
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

	// Wire the two deliberate choices and take over Escape; the confirm fires the
	// caller's delete, Cancel just dismisses. Focus the safe choice (Cancel) so a
	// stray Enter cancels and a keyboard user is not one keystroke from deletion.
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

	return { popover, icon, dismiss };
}

/**
 * Fires one confirmed delete and reflects its outcome live.
 *
 * Reads the icon's collection-relative path (set directly on the thumbnail icon,
 * or per slide by the lightbox controller), dims the icon while the delete is in
 * flight, then on success removes the image's tile from the DOM and tells the
 * lightbox to drop the image (advancing or closing the open viewer). On any
 * failure the icon silently returns to its plain state so the visitor can retry;
 * the popover has already closed. The tile is located by the path the server
 * mirrors onto each figure's anchor, so the same removal works whether the delete
 * was fired from a grid thumbnail or the open lightbox.
 *
 * @since 0.15.0
 *
 * @param wrapper     - The gallery wrapper the figures live in.
 * @param icon        - The trash icon that was confirmed.
 * @param deleteUrl   - The collection's images endpoint URL.
 * @param nonce       - The `wp_rest` nonce.
 * @param getLightbox - Returns the gallery's lightbox controller, or null when none is mounted.
 */
async function runDelete(
	wrapper: HTMLElement,
	icon: HTMLElement,
	deleteUrl: string,
	nonce: string,
	getLightbox: () => GalleryLightbox | null
): Promise< void > {
	// Resolve the target path off the icon; an icon with no path (a misconfigured
	// lightbox icon before its first slide) is left untouched.
	const path = icon.dataset.kntntPhotoDropPath;
	if ( ! path ) {
		return;
	}

	// Dim the icon while the delete runs, then reflect the outcome: on success remove
	// the tile live and advance/close the lightbox; on failure restore the icon so
	// the visitor can retry. The busy flag also blocks a second confirm mid-flight.
	icon.classList.add( BUSY_CLASS );
	const result = await deleteImage( deleteUrl, path, nonce );
	icon.classList.remove( BUSY_CLASS );
	if ( ! result.ok ) {
		return;
	}
	removeTile( wrapper, path, getLightbox() );
}

/**
 * Removes the deleted image's tile from the DOM and updates the lightbox.
 *
 * Finds the figure by the collection-relative path the server mirrors onto its
 * thumbnail anchor — the one identity shared by the grid tile and the lightbox
 * slide — so the removal is correct from either surface. The lightbox is told
 * first (it drops the image and advances or closes while the anchor still exists),
 * then the figure is removed from the grid. A path that matches no anchor (already
 * gone) is a no-op.
 *
 * @since 0.15.0
 *
 * @param wrapper  - The gallery wrapper the figures live in.
 * @param path     - The collection-relative path of the deleted image.
 * @param lightbox - The gallery's lightbox controller, or null when none is mounted.
 */
function removeTile(
	wrapper: HTMLElement,
	path: string,
	lightbox: GalleryLightbox | null
): void {
	// Locate the deleted image's thumbnail anchor by its mirrored path; it is the
	// identity both the grid tile and the lightbox slide are keyed on.
	const anchor = wrapper.querySelector< HTMLAnchorElement >(
		`${ SLIDE_LINK_SELECTOR }[data-kntnt-photo-drop-path="${ cssEscape(
			path
		) }"]`
	);
	if ( ! anchor ) {
		return;
	}

	// Tell the lightbox to drop the image first — it advances the open viewer or
	// closes when the set empties, and it needs the live anchor to find the slide —
	// then remove the figure from the grid.
	lightbox?.removeImage( anchor );
	anchor.closest( '.kntnt-photo-drop-gallery__item' )?.remove();
}

/**
 * Escapes a string for safe use inside a CSS attribute-selector value.
 *
 * Prefers the native `CSS.escape`, falling back to escaping the quote and
 * backslash characters that would otherwise break the `[attr="…"]` selector — a
 * collection-relative path can contain neither traversal nor schemes (the server
 * confines it), but it can contain spaces and other characters a selector needs
 * quoted, and this keeps the lookup robust without assuming the modern API.
 *
 * @since 0.15.0
 *
 * @param value - The attribute value to escape.
 * @return The escaped value, safe inside a double-quoted attribute selector.
 */
function cssEscape( value: string ): string {
	if ( typeof CSS !== 'undefined' && typeof CSS.escape === 'function' ) {
		return CSS.escape( value );
	}
	return value.replace( /["\\]/g, '\\$&' );
}
