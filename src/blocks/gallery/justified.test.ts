/**
 * Jest tests for the Gallery editor's pure justified-layout math.
 *
 * The editor preview computes per-image flex values the same way the PHP
 * `Justified_Layout` computes them server-side, so these tests pin the lockstep
 * rule: each image's basis is its natural width at the target height, its grow is
 * its aspect ratio, and the images in the final (incomplete) row are flagged so
 * the caller can left-align rather than stretch them.
 *
 * @since 0.6.0
 */

import { computeJustifiedLayout, type ImageDimensions } from './justified';

describe( 'computeJustifiedLayout', () => {
	it( 'sets the basis to the natural width at the target height', () => {
		// A 3:2 image at a 200px target height is 300px wide naturally.
		const images: ImageDimensions[] = [ { width: 300, height: 200 } ];
		const flex = computeJustifiedLayout( images, 200, 10 );
		expect( flex[ 0 ]?.basis ).toBeCloseTo( 300 );
		expect( flex[ 0 ]?.grow ).toBeCloseTo( 1.5 );
	} );

	it( 'returns one descriptor per image in input order', () => {
		const images: ImageDimensions[] = [
			{ width: 200, height: 200 },
			{ width: 400, height: 200 },
		];
		const flex = computeJustifiedLayout( images, 200, 10 );
		expect( flex ).toHaveLength( 2 );
		expect( flex[ 0 ]?.grow ).toBeCloseTo( 1 );
		expect( flex[ 1 ]?.grow ).toBeCloseTo( 2 );
	} );

	it( 'flags every image of a single-row gallery as the last row', () => {
		// Two narrow images at a small height fit in one row of a wide container,
		// so both are in the final row and would be left-aligned.
		const images: ImageDimensions[] = [
			{ width: 100, height: 100 },
			{ width: 100, height: 100 },
		];
		const flex = computeJustifiedLayout( images, 100, 10, 960 );
		expect( flex.every( ( entry ) => entry.lastRow ) ).toBe( true );
	} );

	it( 'marks only the final row when images overflow into multiple rows', () => {
		// Six square images 240px wide at a 240px height need ~250px each; a 600px
		// container holds two per row, so the first four are not the last row and
		// the final two are.
		const images: ImageDimensions[] = Array.from( { length: 6 }, () => ( {
			width: 240,
			height: 240,
		} ) );
		const flex = computeJustifiedLayout( images, 240, 10, 600 );
		const lastRowFlags = flex.map( ( entry ) => entry.lastRow );
		expect( lastRowFlags.slice( 0, 4 ) ).toEqual( [
			false,
			false,
			false,
			false,
		] );
		expect( lastRowFlags.slice( 4 ) ).toEqual( [ true, true ] );
	} );

	it( 'falls back to a square ratio for a corrupt zero dimension', () => {
		const images: ImageDimensions[] = [ { width: 0, height: 0 } ];
		const flex = computeJustifiedLayout( images, 200, 10 );
		expect( flex[ 0 ]?.grow ).toBeCloseTo( 1 );
	} );
} );
