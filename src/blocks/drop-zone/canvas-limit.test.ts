/**
 * Jest tests for the safe-canvas-area cap.
 *
 * The cap is the decision that keeps iOS Safari from drawing onto a canvas it
 * silently leaves blank: anything above 16,777,216 pixels must fall back to a
 * raw upload, anything at or below it may be drawn.
 *
 * @since 0.2.0
 */

import { exceedsSafeCanvasArea, MAX_SAFE_CANVAS_AREA } from './canvas-limit';

describe( 'exceedsSafeCanvasArea', () => {
	it( 'allows a typical downscaled photo', () => {
		expect( exceedsSafeCanvasArea( 1920, 1080 ) ).toBe( false );
	} );

	it( 'allows exactly the 4096×4096 safe ceiling', () => {
		expect( exceedsSafeCanvasArea( 4096, 4096 ) ).toBe( false );
	} );

	it( 'rejects one pixel above the ceiling', () => {
		expect( exceedsSafeCanvasArea( 4097, 4096 ) ).toBe( true );
	} );

	it( 'rejects a full-resolution medium-format image', () => {
		expect( exceedsSafeCanvasArea( 11648, 8736 ) ).toBe( true );
	} );

	it( 'rejects an extreme panorama whose area exceeds the cap', () => {
		expect( exceedsSafeCanvasArea( 65_536, 512 ) ).toBe( true );
	} );

	it( 'exports the iOS Safari area constant', () => {
		expect( MAX_SAFE_CANVAS_AREA ).toBe( 4096 * 4096 );
	} );
} );
