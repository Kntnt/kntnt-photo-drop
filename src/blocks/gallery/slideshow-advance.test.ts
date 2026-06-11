/**
 * Jest tests for the slideshow's pure advance gate and index wrap.
 *
 * These pin the slideshow's central timing invariant (ADR-0009): a slide
 * advances only when *both* the visible-time timer has fired *and* the next
 * image has finished loading — a slow image extends the current slide rather
 * than dissolving to a blank, and a fast image waits politely for the timer.
 * The index wrap pins the endless loop: the slide after the last is the first.
 *
 * @since 0.7.0
 */

import {
	createAdvanceGate,
	imageLoaded,
	nextIndex,
	shouldAdvance,
	timerFired,
} from './slideshow-advance';

describe( 'the advance gate', () => {
	it( 'does not advance before anything has happened', () => {
		expect( shouldAdvance( createAdvanceGate() ) ).toBe( false );
	} );

	it( 'does not advance on the timer alone — the next image may still be loading', () => {
		expect( shouldAdvance( timerFired( createAdvanceGate() ) ) ).toBe(
			false
		);
	} );

	it( 'does not advance on the image alone — the slide has not had its time', () => {
		expect( shouldAdvance( imageLoaded( createAdvanceGate() ) ) ).toBe(
			false
		);
	} );

	it( 'advances when the image was ready before the timer fired', () => {
		expect(
			shouldAdvance( timerFired( imageLoaded( createAdvanceGate() ) ) )
		).toBe( true );
	} );

	it( 'advances when a late image arrives after the timer fired', () => {
		expect(
			shouldAdvance( imageLoaded( timerFired( createAdvanceGate() ) ) )
		).toBe( true );
	} );
} );

describe( 'nextIndex', () => {
	it( 'steps forward inside the set', () => {
		expect( nextIndex( 0, 3 ) ).toBe( 1 );
		expect( nextIndex( 1, 3 ) ).toBe( 2 );
	} );

	it( 'wraps from the last slide back to the first — the endless loop', () => {
		expect( nextIndex( 2, 3 ) ).toBe( 0 );
	} );

	it( 'stays at zero for a degenerate set', () => {
		expect( nextIndex( 0, 0 ) ).toBe( 0 );
		expect( nextIndex( 5, 0 ) ).toBe( 0 );
		expect( nextIndex( 0, 1 ) ).toBe( 0 );
	} );
} );
