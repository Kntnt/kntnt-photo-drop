/**
 * Jest tests for the Gallery lightbox's pure index reducer.
 *
 * These pin the navigation contract the DOM wiring depends on: open clamps into
 * range, next/prev wrap at the edges, first/last clamp to the ends, neighbours
 * report the wrapped adjacents, and an empty gallery is inert. No DOM is
 * involved — the reducer is a pure state machine, so the bounds and wrap cases
 * are exercised directly.
 *
 * @since 0.7.0
 */

import {
	close,
	createLightboxState,
	first,
	last,
	neighbours,
	next,
	open,
	prev,
} from './lightbox-index';

describe( 'createLightboxState', () => {
	it( 'starts closed at the first image', () => {
		const state = createLightboxState( 5 );
		expect( state.count ).toBe( 5 );
		expect( state.index ).toBe( 0 );
		expect( state.open ).toBe( false );
	} );

	it( 'clamps a negative count to zero', () => {
		expect( createLightboxState( -3 ).count ).toBe( 0 );
	} );
} );

describe( 'open', () => {
	it( 'opens at the requested index', () => {
		const state = open( createLightboxState( 5 ), 2 );
		expect( state.open ).toBe( true );
		expect( state.index ).toBe( 2 );
	} );

	it( 'clamps an out-of-range index into bounds', () => {
		expect( open( createLightboxState( 5 ), 99 ).index ).toBe( 4 );
		expect( open( createLightboxState( 5 ), -1 ).index ).toBe( 0 );
	} );

	it( 'cannot open an empty gallery', () => {
		const state = open( createLightboxState( 0 ), 0 );
		expect( state.open ).toBe( false );
	} );
} );

describe( 'close', () => {
	it( 'closes while preserving the index', () => {
		const opened = open( createLightboxState( 5 ), 3 );
		const closed = close( opened );
		expect( closed.open ).toBe( false );
		expect( closed.index ).toBe( 3 );
	} );
} );

describe( 'next / prev wrap at the edges', () => {
	it( 'advances one image', () => {
		expect( next( open( createLightboxState( 5 ), 1 ) ).index ).toBe( 2 );
	} );

	it( 'wraps from the last image to the first', () => {
		expect( next( open( createLightboxState( 5 ), 4 ) ).index ).toBe( 0 );
	} );

	it( 'steps back one image', () => {
		expect( prev( open( createLightboxState( 5 ), 3 ) ).index ).toBe( 2 );
	} );

	it( 'wraps from the first image to the last', () => {
		expect( prev( open( createLightboxState( 5 ), 0 ) ).index ).toBe( 4 );
	} );

	it( 'is a no-op on an empty gallery', () => {
		const empty = createLightboxState( 0 );
		expect( next( empty ).index ).toBe( 0 );
		expect( prev( empty ).index ).toBe( 0 );
	} );
} );

describe( 'first / last clamp to the ends', () => {
	it( 'jumps to the first image', () => {
		expect( first( open( createLightboxState( 5 ), 3 ) ).index ).toBe( 0 );
	} );

	it( 'jumps to the last image', () => {
		expect( last( open( createLightboxState( 5 ), 1 ) ).index ).toBe( 4 );
	} );
} );

describe( 'neighbours', () => {
	it( 'reports the wrapped adjacent indices', () => {
		const middle = neighbours( open( createLightboxState( 5 ), 2 ) );
		expect( middle.prev ).toBe( 1 );
		expect( middle.next ).toBe( 3 );

		const edge = neighbours( open( createLightboxState( 5 ), 0 ) );
		expect( edge.prev ).toBe( 4 );
		expect( edge.next ).toBe( 1 );
	} );

	it( 'collapses both neighbours onto the only image of a single-image gallery', () => {
		const single = neighbours( open( createLightboxState( 1 ), 0 ) );
		expect( single.prev ).toBe( 0 );
		expect( single.next ).toBe( 0 );
	} );

	it( 'reports null neighbours for an empty gallery', () => {
		const empty = neighbours( createLightboxState( 0 ) );
		expect( empty.prev ).toBeNull();
		expect( empty.next ).toBeNull();
	} );
} );
