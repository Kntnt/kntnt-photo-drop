/**
 * Jest tests for the gallery trash overlay's DOM wiring — the confirm flow.
 *
 * The trash action is permanent and has no recycle bin (ADR-0015), so the load-
 * bearing behaviour of this module is its safety: a single click on the trash icon
 * must never delete — it must open an inline popover whose explicit Delete button
 * is the only thing that fires the request, and Cancel must dismiss with nothing
 * removed. These tests drive `wireTrash` over a real (jsdom) gallery wrapper with a
 * stubbed global `fetch`, asserting: a first click sends no request but opens the
 * Delete/Cancel popover; the deliberate Delete button sends exactly one DELETE and
 * removes the image's tile from the DOM; Cancel sends nothing and removes nothing;
 * and an un-capable wrapper (no nonce/URL) leaves the icon completely inert. The
 * pure request shape is pinned separately in `delete-image.test.ts`.
 *
 * @since 0.15.0
 */

import { wireTrash } from './trash-view';
import type { GalleryLightbox } from './lightbox';

/**
 * The fetch double's recorded calls, reset before each test.
 *
 * @since 0.15.0
 */
let fetchCalls: { url: string; init?: RequestInit }[] = [];

/**
 * Installs a global `fetch` stub returning a canned status, recording each call.
 *
 * @param status - The HTTP status the response reports.
 */
function stubFetch( status: number ): void {
	fetchCalls = [];
	( globalThis as { fetch: typeof fetch } ).fetch = ( (
		url: string,
		init?: RequestInit
	): Promise< Response > => {
		fetchCalls.push( { url, init } );
		return Promise.resolve( {
			ok: status >= 200 && status < 300,
			status,
		} as Response );
	} ) as typeof fetch;
}

/**
 * Builds a gallery wrapper holding one figure with a trash icon, attached to the body.
 *
 * Mirrors the server markup the view module enhances: the wrapper carries the
 * shared `wp_rest` nonce and the trash delete-URL (the capable-user credential),
 * and the figure's thumbnail anchor and trash icon both carry the image's
 * collection-relative path. When `capable` is false the credential is omitted, so
 * the icon must stay inert.
 *
 * @param capable - Whether to emit the nonce + delete-URL (a capable user).
 * @return The wrapper, the trash icon, and the figure.
 */
function buildWrapper( capable: boolean ): {
	wrapper: HTMLElement;
	icon: HTMLButtonElement;
	figure: HTMLElement;
} {
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'kntnt-photo-drop-gallery';
	if ( capable ) {
		wrapper.dataset.kntntPhotoDropNonce = 'nonce-123';
		wrapper.dataset.kntntPhotoDropDeleteUrl =
			'https://example.test/wp-json/kntnt-photo-drop/v1/collections/photos/images';
	}

	const figure = document.createElement( 'figure' );
	figure.className = 'kntnt-photo-drop-gallery__item';
	const anchor = document.createElement( 'a' );
	anchor.className = 'kntnt-photo-drop-gallery__link';
	anchor.href = 'https://example.test/photo.jpg.webp';
	anchor.dataset.kntntPhotoDropPath = 'photo.jpg.webp';
	const icon = document.createElement( 'button' );
	icon.type = 'button';
	icon.className =
		'kntnt-photo-drop-gallery__icon kntnt-photo-drop-gallery__icon--trash';
	icon.dataset.kntntPhotoDropPath = 'photo.jpg.webp';
	figure.append( anchor, icon );
	wrapper.append( figure );
	document.body.append( wrapper );

	return { wrapper, icon, figure };
}

/**
 * Resolves microtasks so an awaited fetch in the click handler settles.
 *
 * @return A promise that resolves after the pending microtask queue drains.
 */
function flush(): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'wireTrash', () => {
	it( 'opens a Delete/Cancel popover on the first click and sends no request', () => {
		stubFetch( 200 );
		const { wrapper, icon } = buildWrapper( true );
		wireTrash( wrapper, () => null );

		icon.click();

		// A single click is not a delete: no request has been sent, and the confirm
		// popover with both an explicit Delete and Cancel is now open.
		expect( fetchCalls ).toHaveLength( 0 );
		const popover = wrapper.querySelector(
			'.kntnt-photo-drop-gallery__confirm'
		);
		expect( popover ).not.toBeNull();
		expect(
			popover?.querySelector(
				'.kntnt-photo-drop-gallery__confirm__button--delete'
			)
		).not.toBeNull();
		expect(
			popover?.querySelector(
				'.kntnt-photo-drop-gallery__confirm__button--cancel'
			)
		).not.toBeNull();
	} );

	it( 'sends exactly one DELETE and removes the tile when Delete is confirmed', async () => {
		stubFetch( 200 );
		const { wrapper, icon, figure } = buildWrapper( true );
		wireTrash( wrapper, () => null );

		// Open the popover, then press the deliberate Delete button.
		icon.click();
		const confirm = wrapper.querySelector< HTMLButtonElement >(
			'.kntnt-photo-drop-gallery__confirm__button--delete'
		);
		confirm!.click();
		await flush();

		// Exactly one DELETE went to the images endpoint carrying the image's path, and
		// the image's tile is gone from the DOM (the index self-heals on the next render).
		expect( fetchCalls ).toHaveLength( 1 );
		expect( fetchCalls[ 0 ]!.init?.method ).toBe( 'DELETE' );
		expect( JSON.parse( String( fetchCalls[ 0 ]!.init?.body ) ) ).toEqual( {
			path: 'photo.jpg.webp',
		} );
		expect( figure.isConnected ).toBe( false );
	} );

	it( 'sends nothing and removes nothing when Cancel is pressed', () => {
		stubFetch( 200 );
		const { wrapper, icon, figure } = buildWrapper( true );
		wireTrash( wrapper, () => null );

		// Open the popover, then dismiss it via Cancel.
		icon.click();
		const cancel = wrapper.querySelector< HTMLButtonElement >(
			'.kntnt-photo-drop-gallery__confirm__button--cancel'
		);
		cancel!.click();

		// No request was sent, the popover is gone, and the tile remains.
		expect( fetchCalls ).toHaveLength( 0 );
		expect(
			wrapper.querySelector( '.kntnt-photo-drop-gallery__confirm' )
		).toBeNull();
		expect( figure.isConnected ).toBe( true );
	} );

	it( 'leaves the tile in place when the delete request fails', async () => {
		stubFetch( 403 );
		const { wrapper, icon, figure } = buildWrapper( true );
		wireTrash( wrapper, () => null );

		icon.click();
		wrapper
			.querySelector< HTMLButtonElement >(
				'.kntnt-photo-drop-gallery__confirm__button--delete'
			)!
			.click();
		await flush();

		// A rejected delete sent its request but removed nothing — the visitor can retry.
		expect( fetchCalls ).toHaveLength( 1 );
		expect( figure.isConnected ).toBe( true );
	} );

	it( 'notifies the lightbox to drop the image on a confirmed delete', async () => {
		stubFetch( 200 );
		const { wrapper, icon } = buildWrapper( true );
		const removed: HTMLAnchorElement[] = [];
		const lightbox = {
			removeImage: ( link: HTMLAnchorElement ) => removed.push( link ),
		} as unknown as GalleryLightbox;
		wireTrash( wrapper, () => lightbox );

		icon.click();
		wrapper
			.querySelector< HTMLButtonElement >(
				'.kntnt-photo-drop-gallery__confirm__button--delete'
			)!
			.click();
		await flush();

		// The lightbox was handed the deleted image's anchor so it can advance or close
		// the open viewer in lock-step with the tile removal (ADR-0015).
		expect( removed ).toHaveLength( 1 );
		expect( removed[ 0 ]!.dataset.kntntPhotoDropPath ).toBe(
			'photo.jpg.webp'
		);
	} );

	it( 'leaves the icon inert when the wrapper carries no credential', () => {
		stubFetch( 200 );
		const { wrapper, icon } = buildWrapper( false );
		wireTrash( wrapper, () => null );

		icon.click();

		// An un-capable user's wrapper has no nonce/URL, so no listener is installed:
		// the click opens no popover and sends no request.
		expect(
			wrapper.querySelector( '.kntnt-photo-drop-gallery__confirm' )
		).toBeNull();
		expect( fetchCalls ).toHaveLength( 0 );
	} );
} );
