/**
 * Jest tests for the Gallery lightbox's keyboard-event → action mapper.
 *
 * The map is the single source of truth for which key does what inside the open
 * lightbox; these tests pin every binding and the no-op default so a stray key
 * is left to the browser (notably Tab, which the focus trap owns).
 *
 * @since 0.7.0
 */

import { actionForKey } from './lightbox-keys';

describe( 'actionForKey', () => {
	it( 'maps the arrows to prev and next', () => {
		expect( actionForKey( 'ArrowLeft' ) ).toBe( 'prev' );
		expect( actionForKey( 'ArrowRight' ) ).toBe( 'next' );
	} );

	it( 'maps Escape to close', () => {
		expect( actionForKey( 'Escape' ) ).toBe( 'close' );
	} );

	it( 'maps Home and End to first and last', () => {
		expect( actionForKey( 'Home' ) ).toBe( 'first' );
		expect( actionForKey( 'End' ) ).toBe( 'last' );
	} );

	it( 'returns none for an unbound key so the browser keeps it', () => {
		expect( actionForKey( 'Tab' ) ).toBe( 'none' );
		expect( actionForKey( 'a' ) ).toBe( 'none' );
		expect( actionForKey( 'Enter' ) ).toBe( 'none' );
	} );
} );
