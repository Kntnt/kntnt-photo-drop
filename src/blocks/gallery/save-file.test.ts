/**
 * Jest tests for the programmatic image download.
 *
 * `filenameFromUrl` is pinned across the URL shapes a collection produces —
 * nested paths, percent-encoded names, query strings — plus the degenerate
 * inputs that must fall back to a neutral name instead of throwing
 * mid-download. `saveFile` is pinned on its load-bearing promise: a same-origin
 * image (the default) saves through a direct same-document `<a download>` with
 * no fetch and no blob — the path that avoids Firefox's new-tab behaviour; a
 * cross-origin image takes the blob path, where the saved click goes to a
 * same-document object-URL anchor (never the remote URL, which an environment
 * could turn into navigation) and carries the derived filename. When the
 * cross-origin fetch cannot run (no CORS, an offline network, a non-OK response)
 * the save must still never navigate the current tab nor open a new one: it falls
 * back to clicking a same-document, same-tab `<a download>` at the remote URL —
 * the documented no-JS fallback — rather than the old current-tab navigation.
 * `shouldInterceptClick` is pinned on the modified-click contract every download
 * trigger shares: a plain primary click is intercepted (and saved
 * programmatically), every modified or non-primary click passes through to the
 * browser so the `<a download>` semantics stay the no-JS / save-as fallback.
 *
 * @since 0.5.0
 */

import { filenameFromUrl, saveFile, shouldInterceptClick } from './save-file';

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
	} );

	it( 'downloads a same-origin image with a direct <a download>, no fetch or blob', async () => {
		// Same-origin is the common case (renditions served from the site's own
		// uploads dir). A direct same-document <a download> downloads in every
		// browser without the new tab Firefox opens for a blob: URL it renders
		// inline — so the save never fetches and never builds a blob here.
		global.fetch = jest.fn();

		let clickedHref = '';
		let clickedDownload = '';
		let clickedTarget = '';
		clickSpy.mockImplementation( function ( this: HTMLAnchorElement ) {
			clickedHref = this.href;
			clickedDownload = this.download;
			clickedTarget = this.target;
		} );

		const url = `${ window.location.origin }/uploads/kntnt-photo-drop/photos/sunrise.jpg.webp`;
		await saveFile( url );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( URL.createObjectURL ).not.toHaveBeenCalled();
		expect( clickedHref ).toBe( url );
		expect( clickedDownload ).toBe( 'sunrise.jpg.webp' );
		expect( clickedTarget ).not.toBe( '_blank' );
	} );

	it( 'clicks a same-document object-URL anchor named after the image', async () => {
		// A successful cross-origin fetch must hand the blob to the download
		// machinery via a temporary anchor pointing at the object URL — never at
		// the remote URL.
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

	it( 'hands the cross-origin download machinery a non-renderable blob', async () => {
		// On the cross-origin blob path the bytes are best-effort re-typed to
		// application/octet-stream rather than handed over as the sniffable
		// image/webp the response carried.
		const blob = new Blob( [ 'webp-bytes' ], { type: 'image/webp' } );
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			blob: () => Promise.resolve( blob ),
		} as unknown as Response );

		let createdType = '';
		( URL.createObjectURL as jest.Mock ).mockImplementation(
			( b: Blob ) => {
				createdType = b.type;
				return objectUrl;
			}
		);

		await saveFile( 'https://example.test/photos/sunrise.jpg.webp' );

		expect( createdType ).toBe( 'application/octet-stream' );
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

	it( 'falls back to a same-tab <a download> at the remote URL when the response is not OK', async () => {
		// A non-OK response cannot yield a blob, but the save must still neither
		// navigate the current tab nor open a new one: it clicks a same-document
		// <a download> at the remote URL — the no-JS fallback, in the same tab.
		global.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 404,
		} as unknown as Response );

		let clickedHref = '';
		let clickedDownload = '';
		let clickedTarget = '';
		clickSpy.mockImplementation( function ( this: HTMLAnchorElement ) {
			clickedHref = this.href;
			clickedDownload = this.download;
			clickedTarget = this.target;
		} );

		await saveFile( 'https://cdn.example.test/photos/sunrise.jpg.webp' );

		expect( clickedHref ).toBe(
			'https://cdn.example.test/photos/sunrise.jpg.webp'
		);
		expect( clickedDownload ).toBe( 'sunrise.jpg.webp' );
		expect( clickedTarget ).not.toBe( '_blank' );
	} );

	it( 'falls back to a same-tab <a download> when the fetch itself rejects (cross-origin without CORS)', async () => {
		// A cross-origin host without CORS makes fetch reject; the save must fall
		// back to the same-tab <a download> at the remote URL, so the gallery tab
		// is never navigated away and no new tab opens.
		global.fetch = jest
			.fn()
			.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		let clickedHref = '';
		let clickedDownload = '';
		let clickedTarget = '';
		clickSpy.mockImplementation( function ( this: HTMLAnchorElement ) {
			clickedHref = this.href;
			clickedDownload = this.download;
			clickedTarget = this.target;
		} );

		await saveFile( 'https://cdn.example.test/a.webp' );

		expect( clickedHref ).toBe( 'https://cdn.example.test/a.webp' );
		expect( clickedDownload ).toBe( 'a.webp' );
		expect( clickedTarget ).not.toBe( '_blank' );
	} );

	it( 'leaves no temporary anchor behind after the fallback', async () => {
		// The fallback anchor is appended to drive the click; it must be removed
		// again so the document is left exactly as it was found.
		global.fetch = jest
			.fn()
			.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		await saveFile( 'https://cdn.example.test/a.webp' );

		expect( document.querySelectorAll( 'a' ) ).toHaveLength( 0 );
	} );
} );

describe( 'shouldInterceptClick', () => {
	// A minimal mouse-event shape covering the five fields the predicate reads;
	// each test overrides only what it asserts on, the rest staying the plain
	// primary-click defaults.
	const plainPrimary = {
		button: 0,
		metaKey: false,
		ctrlKey: false,
		shiftKey: false,
		altKey: false,
	};

	it( 'intercepts a plain primary click', () => {
		// The only click the download trigger handles itself — saved
		// programmatically so no environment can turn it into navigation.
		expect( shouldInterceptClick( plainPrimary ) ).toBe( true );
	} );

	it.each( [
		[ 'meta', { metaKey: true } ],
		[ 'ctrl', { ctrlKey: true } ],
		[ 'shift', { shiftKey: true } ],
		[ 'alt', { altKey: true } ],
	] )(
		'passes a %s-modified click through to the browser',
		( _label, mods ) => {
			// A modifier means the visitor asked the browser for its own behaviour
			// (save-as, new tab/window), so the handler must not intercept.
			expect( shouldInterceptClick( { ...plainPrimary, ...mods } ) ).toBe(
				false
			);
		}
	);

	it( 'passes a non-primary (middle/right) click through to the browser', () => {
		// Only the primary button downloads; a middle/right click is the
		// browser's to handle (open in new tab, context menu).
		expect( shouldInterceptClick( { ...plainPrimary, button: 1 } ) ).toBe(
			false
		);
	} );
} );
