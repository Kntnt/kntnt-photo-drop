/**
 * Jest tests for the add-to-media copy request.
 *
 * `addToMedia` is the gallery's one REST write call (ADR-0015): it POSTs the
 * image's collection-relative path to the media endpoint with the `wp_rest`
 * nonce, and reports a discriminated result the view module turns into success
 * feedback, an overwrite confirm, or a retry. These tests pin the request shape
 * (URL, JSON body, nonce header, the optional `overwrite` field) and the four
 * outcomes — a created attachment (201), a replaced one (200), an already-present
 * one (409, carrying the existing id), and an error (any other status, or a
 * transport failure) — plus the defensive branch where a success body lacks a
 * numeric id, so the view wiring above it can stay thin.
 *
 * @since 0.15.0
 */

import { addToMedia } from './add-to-media';

/**
 * Builds a `fetch`-shaped stub returning one canned response.
 *
 * Records the URL and init it was called with so a test can assert the request
 * shape, and resolves to a `Response`-like object carrying the given status and
 * JSON body.
 *
 * @param status - The HTTP status the response reports.
 * @param body   - The JSON body the response resolves to.
 * @return A fetch double plus the recorded call.
 */
function fakeFetch(
	status: number,
	body: unknown
): {
	fetch: ( url: string, init?: RequestInit ) => Promise< Response >;
	calls: { url: string; init?: RequestInit }[];
} {
	const calls: { url: string; init?: RequestInit }[] = [];
	const fetch = ( url: string, init?: RequestInit ): Promise< Response > => {
		calls.push( { url, init } );
		return Promise.resolve( {
			ok: status >= 200 && status < 300,
			status,
			json: () => Promise.resolve( body ),
		} as Response );
	};
	return { fetch, calls };
}

describe( 'addToMedia', () => {
	it( 'POSTs the path as JSON with the nonce header to the media endpoint', async () => {
		const { fetch, calls } = fakeFetch( 201, { id: 7 } );

		await addToMedia(
			'https://example.test/wp-json/kntnt-photo-drop/v1/collections/photos/media',
			'2026/06/15/jane/IMG.jpg.webp',
			'abc123',
			{ fetchImpl: fetch }
		);

		expect( calls ).toHaveLength( 1 );
		const call = calls[ 0 ]!;
		expect( call.url ).toBe(
			'https://example.test/wp-json/kntnt-photo-drop/v1/collections/photos/media'
		);
		expect( call.init?.method ).toBe( 'POST' );
		const headers = call.init?.headers as Record< string, string >;
		expect( headers[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
		expect( headers[ 'Content-Type' ] ).toBe( 'application/json' );
		expect( JSON.parse( String( call.init?.body ) ) ).toEqual( {
			path: '2026/06/15/jane/IMG.jpg.webp',
		} );
	} );

	it( 'adds the overwrite flag to the body when asked to overwrite', async () => {
		const { fetch, calls } = fakeFetch( 200, { id: 7 } );

		await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
			overwrite: true,
		} );

		expect( JSON.parse( String( calls[ 0 ]!.init?.body ) ) ).toEqual( {
			path: 'a.webp',
			overwrite: true,
		} );
	} );

	it( 'reports a 201 as a created attachment', async () => {
		const { fetch } = fakeFetch( 201, { id: 42 } );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'created', id: 42 } );
	} );

	it( 'reports a 200 as a replaced attachment', async () => {
		const { fetch } = fakeFetch( 200, { id: 42 } );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'replaced', id: 42 } );
	} );

	it( 'reports a 409 as an existing attachment, reading the id from the error data', async () => {
		const { fetch } = fakeFetch( 409, {
			code: 'kntnt_photo_drop_already_in_media',
			data: { status: 409, id: 99 },
		} );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'exists', id: 99 } );
	} );

	it( 'reports a malformed 409 (no numeric id) as a generic error', async () => {
		const { fetch } = fakeFetch( 409, { code: 'something', data: {} } );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'error', status: 409 } );
	} );

	it( 'reports a non-OK status as an error carrying that status', async () => {
		const { fetch } = fakeFetch( 403, { code: 'forbidden' } );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'error', status: 403 } );
	} );

	it( 'reports a transport failure as a zero status, never throwing', async () => {
		const fetch = (): Promise< Response > =>
			Promise.reject( new Error( 'network down' ) );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'error', status: 0 } );
	} );

	it( 'reports a malformed success body (no numeric id) as an error carrying the status', async () => {
		const { fetch } = fakeFetch( 201, { ok: true } );

		const result = await addToMedia( '/media', 'a.webp', 'n', {
			fetchImpl: fetch,
		} );

		expect( result ).toEqual( { outcome: 'error', status: 201 } );
	} );
} );
