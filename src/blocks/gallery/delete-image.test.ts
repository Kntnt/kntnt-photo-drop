/**
 * Jest tests for the gallery's trash delete request.
 *
 * `deleteImage` is the gallery's one destructive REST call (ADR-0015): it sends a
 * `DELETE` carrying the image's collection-relative path to the images endpoint
 * with the `wp_rest` nonce, and reports a discriminated result the view module
 * turns into live tile removal or a silent retry. These tests pin the request
 * shape (URL, HTTP method, JSON body, nonce header) and the three outcomes — a
 * successful delete, a non-OK status (forgery/capability/confinement rejection),
 * and a transport failure — so the view wiring above it can stay thin.
 *
 * @since 0.15.0
 */

import { deleteImage } from './delete-image';

/**
 * Builds a `fetch`-shaped stub returning one canned response.
 *
 * Records the URL and init it was called with so a test can assert the request
 * shape, and resolves to a `Response`-like object carrying the given status.
 *
 * @param status - The HTTP status the response reports.
 * @return A fetch double plus the recorded call.
 */
function fakeFetch( status: number ): {
	fetch: ( url: string, init?: RequestInit ) => Promise< Response >;
	calls: { url: string; init?: RequestInit }[];
} {
	const calls: { url: string; init?: RequestInit }[] = [];
	const fetch = ( url: string, init?: RequestInit ): Promise< Response > => {
		calls.push( { url, init } );
		return Promise.resolve( {
			ok: status >= 200 && status < 300,
			status,
		} as Response );
	};
	return { fetch, calls };
}

describe( 'deleteImage', () => {
	it( 'sends a DELETE with the path as JSON and the nonce header to the images endpoint', async () => {
		const { fetch, calls } = fakeFetch( 200 );

		await deleteImage(
			'https://example.test/wp-json/kntnt-photo-drop/v1/collections/photos/images',
			'2026/06/15/jane/IMG.jpg.webp',
			'abc123',
			fetch
		);

		expect( calls ).toHaveLength( 1 );
		const call = calls[ 0 ]!;
		expect( call.url ).toBe(
			'https://example.test/wp-json/kntnt-photo-drop/v1/collections/photos/images'
		);
		expect( call.init?.method ).toBe( 'DELETE' );
		const headers = call.init?.headers as Record< string, string >;
		expect( headers[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
		expect( headers[ 'Content-Type' ] ).toBe( 'application/json' );
		expect( JSON.parse( String( call.init?.body ) ) ).toEqual( {
			path: '2026/06/15/jane/IMG.jpg.webp',
		} );
	} );

	it( 'reports success on a 200', async () => {
		const { fetch } = fakeFetch( 200 );

		const result = await deleteImage( '/images', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( true );
	} );

	it( 'reports a non-OK status as a failure carrying that status', async () => {
		const { fetch } = fakeFetch( 403 );

		const result = await deleteImage( '/images', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( false );
		if ( ! result.ok ) {
			expect( result.status ).toBe( 403 );
		}
	} );

	it( 'reports a transport failure as a zero status, never throwing', async () => {
		const fetch = (): Promise< Response > =>
			Promise.reject( new Error( 'network down' ) );

		const result = await deleteImage( '/images', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( false );
		if ( ! result.ok ) {
			expect( result.status ).toBe( 0 );
		}
	} );
} );
