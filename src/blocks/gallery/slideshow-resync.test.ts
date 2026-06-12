/**
 * Jest tests for the slideshow's cycle-boundary resync (ADR-0011).
 *
 * These pin the resync's three decisions: how a fetched page resolves to the
 * gallery's fresh slide list (anchor id first, document-order index as the
 * fallback, `null` when the wrapper cannot be found), how a settled fetch maps
 * onto the playback (a failure keeps the stale list, an emptied view ends the
 * playback, a fresh view replaces the list wholesale), and how the provider
 * guards the network (`no-store`, bounded by an abort timeout, every failure
 * collapsing to `null`).
 *
 * @since 0.9.0
 */

import { createResync, freshSlides, resolveResync } from './slideshow-resync';
import type { GallerySlide } from './slides';

/**
 * Builds one thumbnail anchor carrying the full anchor data contract.
 *
 * @param name - A short image name woven into every attribute.
 * @return The anchor markup.
 */
function link( name: string ): string {
	return (
		`<a class="kntnt-photo-drop-gallery__link" href="https://site.test/${ name }.webp"` +
		` data-kntnt-photo-drop-full="https://site.test/${ name }.webp"` +
		` data-kntnt-photo-drop-srcset="https://site.test/${ name }-320.webp 320w"` +
		` data-kntnt-photo-drop-caption="${ name } caption">` +
		`<img src="https://site.test/${ name }-320.webp" alt="${ name }"></a>`
	);
}

/**
 * Builds one gallery block wrapper holding the given anchors.
 *
 * @param links - The anchor markup, in gallery order.
 * @param id    - The wrapper's HTML anchor id, or `''` for none.
 * @return The wrapper markup.
 */
function gallery( links: readonly string[], id = '' ): string {
	const idAttribute = id === '' ? '' : ` id="${ id }"`;
	return (
		`<div${ idAttribute } class="wp-block-kntnt-photo-drop-gallery kntnt-photo-drop-gallery">` +
		`${ links.join( '' ) }</div>`
	);
}

/**
 * Builds a whole fetched-page document around the given galleries.
 *
 * @param galleries - The gallery wrapper markup, in document order.
 * @return The page HTML.
 */
function pageHtml( ...galleries: readonly string[] ): string {
	return `<!doctype html><html><body><main>${ galleries.join(
		''
	) }</main></body></html>`;
}

/**
 * Shorthand for the slide URLs a parsed list resolved to.
 *
 * @param slides - The parsed slides.
 * @return The slide URLs in order.
 */
function urls( slides: readonly GallerySlide[] | null ): string[] {
	return ( slides ?? [] ).map( ( slide ) => slide.url );
}

describe( 'freshSlides', () => {
	it( 'resolves the gallery by its anchor id regardless of position', () => {
		const html = pageHtml(
			gallery( [ link( 'alpha' ) ] ),
			gallery( [ link( 'beta' ) ], 'target' )
		);
		expect( urls( freshSlides( html, 'target', 0 ) ) ).toEqual( [
			'https://site.test/beta.webp',
		] );
	} );

	it( 'falls back to the document-order index when no id is set', () => {
		const html = pageHtml(
			gallery( [ link( 'alpha' ) ] ),
			gallery( [ link( 'beta' ) ] )
		);
		expect( urls( freshSlides( html, '', 1 ) ) ).toEqual( [
			'https://site.test/beta.webp',
		] );
	} );

	it( 'returns null when the id is set but missing from the page', () => {
		const html = pageHtml( gallery( [ link( 'alpha' ) ] ) );
		expect( freshSlides( html, 'gone', 0 ) ).toBeNull();
	} );

	it( 'returns null when the index falls outside the page galleries', () => {
		const html = pageHtml( gallery( [ link( 'alpha' ) ] ) );
		expect( freshSlides( html, '', 5 ) ).toBeNull();
	} );

	it( 'returns an empty list when the gallery has no images left', () => {
		const html = pageHtml( gallery( [] ) );
		expect( freshSlides( html, '', 0 ) ).toEqual( [] );
	} );

	it( 'reads the full anchor data contract off each fresh anchor', () => {
		const html = pageHtml( gallery( [ link( 'alpha' ) ] ) );
		expect( freshSlides( html, '', 0 ) ).toEqual( [
			{
				url: 'https://site.test/alpha.webp',
				srcset: 'https://site.test/alpha-320.webp 320w',
				label: 'alpha',
				caption: 'alpha caption',
			},
		] );
	} );
} );

describe( 'resolveResync', () => {
	const current: readonly GallerySlide[] = [
		{ url: 'a.webp', srcset: '', label: '', caption: '' },
	];

	it( 'keeps the stale list when the fetch failed', () => {
		expect( resolveResync( null, current ) ).toEqual( {
			slides: current,
			end: false,
		} );
	} );

	it( 'ends the playback on an emptied view — takedowns propagate', () => {
		expect( resolveResync( [], current ).end ).toBe( true );
	} );

	it( 'replaces the list wholesale on a fresh view', () => {
		const fresh: readonly GallerySlide[] = [
			{ url: 'b.webp', srcset: '', label: '', caption: '' },
			{ url: 'c.webp', srcset: '', label: '', caption: '' },
		];
		expect( resolveResync( fresh, current ) ).toEqual( {
			slides: fresh,
			end: false,
		} );
	} );
} );

describe( 'createResync', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		delete ( window as { fetch?: unknown } ).fetch;
	} );

	/**
	 * Mounts a live gallery wrapper and returns it.
	 *
	 * @param id - The wrapper's HTML anchor id, or `''` for none.
	 * @return The live wrapper element.
	 */
	function mountWrapper( id = '' ): HTMLElement {
		document.body.innerHTML = gallery( [ link( 'alpha' ) ], id );
		const wrapper = document.querySelector< HTMLElement >(
			'.kntnt-photo-drop-gallery'
		);
		if ( ! wrapper ) {
			throw new Error( 'fixture wrapper missing' );
		}
		return wrapper;
	}

	it( 'returns the fresh slides of the refetched page, fetched uncached', async () => {
		const wrapper = mountWrapper();
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () =>
				pageHtml( gallery( [ link( 'alpha' ), link( 'gamma' ) ] ) ),
		} );
		window.fetch = fetchMock as unknown as typeof fetch;

		const fresh = await createResync( wrapper )();

		expect( urls( fresh ) ).toEqual( [
			'https://site.test/alpha.webp',
			'https://site.test/gamma.webp',
		] );
		expect( fetchMock ).toHaveBeenCalledWith(
			window.location.href,
			expect.objectContaining( { cache: 'no-store' } )
		);
	} );

	it( 'matches its own gallery by id in the refetched page', async () => {
		const wrapper = mountWrapper( 'show' );
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () =>
				pageHtml(
					gallery( [ link( 'other' ) ] ),
					gallery( [ link( 'beta' ) ], 'show' )
				),
		} ) as unknown as typeof fetch;

		expect( urls( await createResync( wrapper )() ) ).toEqual( [
			'https://site.test/beta.webp',
		] );
	} );

	it( 'treats a gallery missing from a fetched page as the empty view', async () => {
		// The server renders an emptied or deleted collection as no wrapper
		// at all, so "gone" must read as "empty" (end), never as a failure
		// (keep stale) — the takedown signal of ADR-0011.
		const wrapper = mountWrapper();
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () => '<!doctype html><html><body></body></html>',
		} ) as unknown as typeof fetch;

		expect( await createResync( wrapper )() ).toEqual( [] );
	} );

	it( 'returns null on an HTTP error status', async () => {
		const wrapper = mountWrapper();
		window.fetch = jest
			.fn()
			.mockResolvedValue( { ok: false } ) as unknown as typeof fetch;

		expect( await createResync( wrapper )() ).toBeNull();
	} );

	it( 'returns null when the network fails', async () => {
		const wrapper = mountWrapper();
		window.fetch = jest
			.fn()
			.mockRejectedValue(
				new Error( 'offline' )
			) as unknown as typeof fetch;

		expect( await createResync( wrapper )() ).toBeNull();
	} );

	it( 'aborts a hung fetch after the timeout and returns null', async () => {
		const wrapper = mountWrapper();
		window.fetch = ( (
			_url: string,
			init: { signal: AbortSignal }
		): Promise< never > =>
			new Promise( ( _resolve, reject ) => {
				init.signal.addEventListener( 'abort', () =>
					reject( new Error( 'aborted' ) )
				);
			} ) ) as unknown as typeof fetch;

		expect( await createResync( wrapper, 20 )() ).toBeNull();
	} );
} );
