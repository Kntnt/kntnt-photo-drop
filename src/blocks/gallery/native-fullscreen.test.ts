/**
 * Jest tests for the slideshow's broken-fullscreen user-agent guard.
 *
 * These pin the one supported-but-broken environment the slideshow must route
 * around (ADR-0009): Firefox for iOS/iPadOS (`FxiOS`), which exposes
 * `Element.requestFullscreen` but black-screens the fullscreened overlay. Every
 * agent where native fullscreen works — Safari and Chrome on iOS, and all
 * desktop browsers — must keep it.
 *
 * @since 0.13.3
 */

import { hasBrokenElementFullscreen } from './native-fullscreen';

describe( 'hasBrokenElementFullscreen', () => {
	it( 'skips fullscreen for Firefox on iPadOS', () => {
		const ua =
			'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/119.0 Mobile/15E148 Safari/605.1.15';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( true );
	} );

	it( 'skips fullscreen for Firefox on iOS (iPhone) too', () => {
		const ua =
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/119.0 Mobile/15E148 Safari/605.1.15';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( true );
	} );

	it( 'keeps fullscreen for Safari on iPad', () => {
		const ua =
			'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( false );
	} );

	it( 'keeps fullscreen for Chrome on iOS (CriOS)', () => {
		const ua =
			'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.0.0 Mobile/15E148 Safari/604.1';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( false );
	} );

	it( 'keeps fullscreen for desktop Firefox', () => {
		const ua =
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:120.0) Gecko/20100101 Firefox/120.0';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( false );
	} );

	it( 'keeps fullscreen for desktop Chrome', () => {
		const ua =
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		expect( hasBrokenElementFullscreen( ua ) ).toBe( false );
	} );

	it( 'keeps fullscreen when the user agent is unavailable', () => {
		expect( hasBrokenElementFullscreen( '' ) ).toBe( false );
	} );
} );
