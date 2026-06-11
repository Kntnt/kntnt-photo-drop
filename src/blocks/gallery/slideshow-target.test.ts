/**
 * Jest tests for the slideshow's pure trigger-target resolution.
 *
 * These pin the documented custom-trigger contract (ADR-0009): a
 * `data-kntnt-photo-drop-slideshow` attribute whose value names a gallery's
 * HTML anchor targets that gallery; a valueless attribute forgivingly targets
 * the page's first slideshow-enabled gallery; and a value that matches no
 * anchor targets nothing — it must never fall back to a random gallery. A
 * pasted `#anchor` (the href habit) is accepted as the bare anchor.
 *
 * @since 0.7.0
 */

import { resolveSlideshowTarget } from './slideshow-target';

describe( 'resolveSlideshowTarget', () => {
	it( 'targets the gallery whose anchor matches the value', () => {
		expect(
			resolveSlideshowTarget( 'second', [ 'first', 'second' ] )
		).toBe( 1 );
	} );

	it( 'targets the first enabled gallery when the attribute is valueless', () => {
		expect( resolveSlideshowTarget( '', [ 'a', 'b' ] ) ).toBe( 0 );
		expect( resolveSlideshowTarget( null, [ 'a', 'b' ] ) ).toBe( 0 );
	} );

	it( 'accepts a pasted #anchor as the bare anchor', () => {
		expect(
			resolveSlideshowTarget( '#second', [ 'first', 'second' ] )
		).toBe( 1 );
	} );

	it( 'trims surrounding whitespace from the value', () => {
		expect( resolveSlideshowTarget( '  first ', [ 'first' ] ) ).toBe( 0 );
	} );

	it( 'targets nothing when the value matches no gallery', () => {
		expect( resolveSlideshowTarget( 'missing', [ 'first' ] ) ).toBe( -1 );
	} );

	it( 'never matches an anchorless gallery by an empty-looking value', () => {
		// A gallery without an HTML anchor registers as ''; a trigger whose value
		// reduces to '' must hit the forgiving first-match rule, never a string
		// comparison against those empty ids.
		expect( resolveSlideshowTarget( '#', [ '', 'real' ] ) ).toBe( 0 );
	} );

	it( 'targets nothing on a page with no enabled gallery', () => {
		expect( resolveSlideshowTarget( '', [] ) ).toBe( -1 );
		expect( resolveSlideshowTarget( 'any', [] ) ).toBe( -1 );
	} );
} );
