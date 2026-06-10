/**
 * Jest tests for the Gallery lightbox's swipe-decision function.
 *
 * These pin the three guards a naive sign check misses: a horizontal swipe must
 * clear the distance threshold and dominate the vertical travel before it pages
 * the lightbox, and a gesture that ever went multi-touch — a pinch — is
 * discarded outright; otherwise a tap, a jitter, or a vertical scroll is left
 * alone. The direction mapping (left → next, right → prev) is pinned too.
 *
 * @since 0.7.0
 */

import { actionForSwipe, DEFAULT_SWIPE_THRESHOLD } from './lightbox-swipe';

describe( 'actionForSwipe', () => {
	it( 'pages to the next image on a leftward swipe', () => {
		expect( actionForSwipe( -80, 5 ) ).toBe( 'next' );
	} );

	it( 'pages to the previous image on a rightward swipe', () => {
		expect( actionForSwipe( 80, -5 ) ).toBe( 'prev' );
	} );

	it( 'ignores a swipe shorter than the threshold', () => {
		expect( actionForSwipe( DEFAULT_SWIPE_THRESHOLD - 1, 0 ) ).toBe(
			'none'
		);
	} );

	it( 'ignores a mostly-vertical gesture even when it clears the threshold', () => {
		// 50px across but 120px down — a vertical scroll, not a horizontal page.
		expect( actionForSwipe( 50, 120 ) ).toBe( 'none' );
	} );

	it( 'ignores a perfectly diagonal gesture (horizontal does not dominate)', () => {
		expect( actionForSwipe( 60, 60 ) ).toBe( 'none' );
	} );

	it( 'honours a custom threshold', () => {
		// Below the custom 100px threshold a rightward swipe is ignored; above it,
		// the same direction pages to the previous image.
		expect( actionForSwipe( 40, 0, { threshold: 100 } ) ).toBe( 'none' );
		expect( actionForSwipe( 120, 0, { threshold: 100 } ) ).toBe( 'prev' );
	} );

	it( 'discards a gesture that ever went multi-touch, however clean its delta', () => {
		// A pinch records garbage deltas; even one that happens to look like a
		// perfect horizontal swipe must not page the lightbox.
		expect( actionForSwipe( -200, 0, { multiTouch: true } ) ).toBe(
			'none'
		);
		expect( actionForSwipe( 200, 0, { multiTouch: true } ) ).toBe( 'none' );
	} );

	it( 'keeps paging when the gesture is explicitly single-touch', () => {
		expect( actionForSwipe( -80, 5, { multiTouch: false } ) ).toBe(
			'next'
		);
	} );
} );
