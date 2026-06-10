/**
 * Jest tests for the Gallery lightbox's keyboard-event → action mapper.
 *
 * The map is the single source of truth for which key does what inside the open
 * lightbox; these tests pin every binding, the no-op default so a stray key is
 * left to the browser (notably Tab, which the focus trap owns), and the
 * modifier guard so Alt/Cmd/Ctrl combinations — browser back among them — are
 * never hijacked.
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

	it( 'returns none for a bound key with Alt, Ctrl, or Meta held', () => {
		// Alt+ArrowLeft and Cmd+ArrowLeft are browser back; Ctrl combinations are
		// OS/browser shortcuts. None of them may page or close the lightbox.
		expect( actionForKey( 'ArrowLeft', { alt: true } ) ).toBe( 'none' );
		expect( actionForKey( 'ArrowLeft', { meta: true } ) ).toBe( 'none' );
		expect( actionForKey( 'ArrowRight', { ctrl: true } ) ).toBe( 'none' );
		expect( actionForKey( 'Escape', { meta: true } ) ).toBe( 'none' );
	} );

	it( 'keeps the binding when no modifier is held', () => {
		expect( actionForKey( 'ArrowLeft', {} ) ).toBe( 'prev' );
		expect(
			actionForKey( 'ArrowRight', {
				alt: false,
				ctrl: false,
				meta: false,
			} )
		).toBe( 'next' );
	} );
} );
