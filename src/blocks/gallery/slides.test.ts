/**
 * Jest tests for the anchor data contract read by the lightbox and slideshow.
 *
 * The load-bearing assertion is that the slide's *download* target — `main` —
 * is read from its own `data-kntnt-photo-drop-main` attribute and stays
 * independent of the *display* target `url` (the full rendition the lightbox
 * shows). The download is always the main image (ADR-0013), so the two must not
 * be conflated: a future full-rendition split that points `url` at a bounded
 * full image must not drag the download target off the main. Each field also
 * degrades on its own when the attribute is absent.
 *
 * @since 0.11.0
 */

import { readSlides } from './slides';

/**
 * Builds a thumbnail anchor with the given data attributes for `readSlides`.
 *
 * @param attrs - The data attributes (and href) to set on the anchor.
 * @return The constructed anchor element.
 */
function anchor( attrs: Record< string, string > ): HTMLAnchorElement {
	const a = document.createElement( 'a' );
	a.className = 'kntnt-photo-drop-gallery__link';
	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( key === 'href' ) {
			a.href = value;
		} else {
			a.setAttribute( key, value );
		}
	}
	return a;
}

describe( 'readSlides', () => {
	it( 'reads the download target from the main data attribute, not the full one', () => {
		// The display (`full`) and download (`main`) renditions are different URLs;
		// the slide must carry the main as its download target and the full as its
		// display URL, never collapsing one onto the other.
		const slides = readSlides( [
			anchor( {
				href: 'https://example.test/photos/a.jpg.webp',
				'data-kntnt-photo-drop-main':
					'https://example.test/photos/a.jpg.webp',
				'data-kntnt-photo-drop-full':
					'https://example.test/photos/.kntnt-thumbnails/1600/a.jpg.webp',
			} ),
		] );

		expect( slides[ 0 ]?.main ).toBe(
			'https://example.test/photos/a.jpg.webp'
		);
		expect( slides[ 0 ]?.url ).toBe(
			'https://example.test/photos/.kntnt-thumbnails/1600/a.jpg.webp'
		);
	} );

	it( 'falls back to the anchor href for the main target when the attribute is absent', () => {
		// With no explicit main attribute the anchor href is the no-JS download
		// fallback, so it is the natural main-target fallback too.
		const slides = readSlides( [
			anchor( { href: 'https://example.test/photos/b.jpg.webp' } ),
		] );

		expect( slides[ 0 ]?.main ).toBe(
			'https://example.test/photos/b.jpg.webp'
		);
	} );

	it( 'reads the display url and srcset from their own attributes', () => {
		// The display URL comes from the full attribute and the responsive srcset
		// from its own; both degrade independently of the main target.
		const slides = readSlides( [
			anchor( {
				href: 'https://example.test/photos/c.jpg.webp',
				'data-kntnt-photo-drop-full':
					'https://example.test/photos/full/c.jpg.webp',
				'data-kntnt-photo-drop-srcset':
					'https://example.test/photos/full/c.jpg.webp 1600w',
			} ),
		] );

		expect( slides[ 0 ]?.url ).toBe(
			'https://example.test/photos/full/c.jpg.webp'
		);
		expect( slides[ 0 ]?.srcset ).toBe(
			'https://example.test/photos/full/c.jpg.webp 1600w'
		);
	} );
} );
