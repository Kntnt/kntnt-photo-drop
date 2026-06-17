/**
 * Jest tests for the add-to-media copy request and its confirm gate.
 *
 * `addToMedia` is the gallery's one REST write call (ADR-0015): it POSTs the
 * image's collection-relative path to the media endpoint with the `wp_rest`
 * nonce, and reports a discriminated result the view module turns into success
 * feedback or a retry. These tests pin the request shape (URL, JSON body, nonce
 * header) and the three outcomes — a created attachment, a non-OK status
 * (forgery/capability/confinement rejection), and a transport failure — so the
 * view wiring above it can stay thin. `confirmGate` is pinned on the two-click
 * contract the confirm callout shares with the destructive trash action: a first
 * activation arms the action and a second within the armed window fires it, so a
 * copy never happens on a single stray click.
 *
 * @since 0.12.0
 */

import { addToMedia, confirmGate } from './add-to-media';

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
			fetch
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

	it( 'reports the created attachment id on a 201', async () => {
		const { fetch } = fakeFetch( 201, { id: 42 } );

		const result = await addToMedia( '/media', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( true );
		if ( result.ok ) {
			expect( result.id ).toBe( 42 );
		}
	} );

	it( 'reports a non-OK status as a failure carrying that status', async () => {
		const { fetch } = fakeFetch( 403, { code: 'forbidden' } );

		const result = await addToMedia( '/media', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( false );
		if ( ! result.ok ) {
			expect( result.status ).toBe( 403 );
		}
	} );

	it( 'reports a transport failure as a zero status, never throwing', async () => {
		const fetch = (): Promise< Response > =>
			Promise.reject( new Error( 'network down' ) );

		const result = await addToMedia( '/media', 'a.webp', 'n', fetch );

		expect( result.ok ).toBe( false );
		if ( ! result.ok ) {
			expect( result.status ).toBe( 0 );
		}
	} );
} );

describe( 'confirmGate', () => {
	it( 'arms on the first activation and does not fire', () => {
		const gate = confirmGate();
		expect( gate.activate() ).toBe( false );
		expect( gate.armed ).toBe( true );
	} );

	it( 'fires on a second activation while armed', () => {
		const gate = confirmGate();
		gate.activate();
		expect( gate.activate() ).toBe( true );
	} );

	it( 'disarms on cancel, so the next activation only re-arms', () => {
		const gate = confirmGate();
		gate.activate();
		gate.cancel();
		expect( gate.armed ).toBe( false );
		expect( gate.activate() ).toBe( false );
	} );
} );
