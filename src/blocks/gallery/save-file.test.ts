/**
 * Jest tests for the programmatic image download.
 *
 * `filenameFromUrl` is pinned across the URL shapes a collection produces —
 * nested paths, percent-encoded names, query strings — plus the degenerate
 * inputs that must fall back to a neutral name instead of throwing
 * mid-download. `saveFile` is pinned on its load-bearing promise: the saved
 * click goes to a same-document object-URL anchor (never the remote URL, which
 * an environment could turn into navigation) and carries the derived filename.
 *
 * @since 0.5.0
 */

import { filenameFromUrl, saveFile } from './save-file';

describe( 'filenameFromUrl', () => {
	it( 'returns the last path segment of an absolute URL', () => {
		expect(
			filenameFromUrl(
				'https://example.test/uploads/kntnt-photo-drop/photos/sunrise.jpg.webp'
			)
		).toBe( 'sunrise.jpg.webp' );
	} );

	it( 'resolves a relative URL against the given base', () => {
		expect(
			filenameFromUrl(
				'photos/dune.webp',
				'https://example.test/uploads/'
			)
		).toBe( 'dune.webp' );
	} );

	it( 'strips the query string and fragment from the name', () => {
		expect(
			filenameFromUrl( 'https://example.test/a/b.webp?v=2#top' )
		).toBe( 'b.webp' );
	} );

	it( 'decodes a percent-encoded segment', () => {
		expect(
			filenameFromUrl( 'https://example.test/sol%20uppg%C3%A5ng.webp' )
		).toBe( 'sol uppgång.webp' );
	} );

	it( 'falls back to a neutral name when the path has no segment', () => {
		expect( filenameFromUrl( 'https://example.test/' ) ).toBe( 'image' );
	} );

	it( 'falls back to a neutral name on a malformed percent encoding', () => {
		// decodeURIComponent throws on a truncated escape; the fallback must
		// absorb that rather than crash the download.
		expect( filenameFromUrl( 'https://example.test/bad%E0%A4%A' ) ).toBe(
			'image'
		);
	} );
} );

describe( 'saveFile', () => {
	// The browser pieces jsdom does not implement — fetch, the object-URL
	// factory — are stubbed per test; the click is observed on the anchor
	// prototype so the temporary anchor needs no special construction.
	const objectUrl = 'blob:https://example.test/fake-object-url';
	let clickSpy: jest.SpyInstance;

	beforeEach( () => {
		jest.useFakeTimers();
		clickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => undefined );
		URL.createObjectURL = jest.fn().mockReturnValue( objectUrl );
		URL.revokeObjectURL = jest.fn();
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
		clickSpy.mockRestore();
		window.location.hash = '';
	} );

	it( 'clicks a same-document object-URL anchor named after the image', async () => {
		// A successful fetch must hand the blob to the download machinery via a
		// temporary anchor pointing at the object URL — never at the remote URL.
		const blob = new Blob( [ 'webp-bytes' ] );
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			blob: () => Promise.resolve( blob ),
		} as unknown as Response );

		let clickedHref = '';
		let clickedDownload = '';
		clickSpy.mockImplementation( function ( this: HTMLAnchorElement ) {
			clickedHref = this.href;
			clickedDownload = this.download;
		} );

		await saveFile( 'https://example.test/photos/sunrise.jpg.webp' );

		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/photos/sunrise.jpg.webp'
		);
		expect( clickedHref ).toBe( objectUrl );
		expect( clickedDownload ).toBe( 'sunrise.jpg.webp' );
	} );

	it( 'revokes the object URL after the hand-off delay', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			blob: () => Promise.resolve( new Blob( [ 'x' ] ) ),
		} as unknown as Response );

		await saveFile( 'https://example.test/a.webp' );

		// The revocation is deliberately deferred so the browser's download
		// machinery has taken over before the URL dies.
		expect( URL.revokeObjectURL ).not.toHaveBeenCalled();
		jest.runAllTimers();
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( objectUrl );
	} );

	it( 'leaves no temporary anchor behind in the document', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			blob: () => Promise.resolve( new Blob( [ 'x' ] ) ),
		} as unknown as Response );

		await saveFile( 'https://example.test/a.webp' );

		expect( document.querySelectorAll( 'a' ) ).toHaveLength( 0 );
	} );

	it( 'falls back to plain navigation, with no download click, when the response is not OK', async () => {
		// A hash URL keeps the fallback observable: jsdom implements only hash
		// navigation, so `location.assign` of anything else would be swallowed
		// as "not implemented" instead of landing in `location.hash`.
		global.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 404,
		} as unknown as Response );

		await saveFile( '#missing' );

		expect( clickSpy ).not.toHaveBeenCalled();
		expect( window.location.hash ).toBe( '#missing' );
	} );

	it( 'falls back to plain navigation when the fetch itself rejects', async () => {
		global.fetch = jest.fn().mockRejectedValue( new Error( 'offline' ) );

		await saveFile( '#offline' );

		expect( clickSpy ).not.toHaveBeenCalled();
		expect( window.location.hash ).toBe( '#offline' );
	} );
} );
