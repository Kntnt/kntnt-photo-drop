/**
 * Jest tests for the justified layout's pure last-row detection.
 *
 * The view module reads each figure's rendered `offsetTop` and asks
 * `lastRowFlags` which figures form the actual last row, overriding the
 * server's assumed-width flags. These tests pin the whole contract: the
 * max-offset row and only that row is flagged, a single row flags everything,
 * and the degenerate empty gallery yields an empty result.
 *
 * @since 0.2.0
 */

import { lastRowFlags } from './justified-rows';

describe( 'lastRowFlags', () => {
	it( 'returns an empty list for an empty gallery', () => {
		expect( lastRowFlags( [] ) ).toEqual( [] );
	} );

	it( 'flags every figure when the gallery is a single row', () => {
		expect( lastRowFlags( [ 0, 0, 0 ] ) ).toEqual( [ true, true, true ] );
	} );

	it( 'flags only the figures sharing the maximum offset', () => {
		// Two rows of three and a last row of two: only the 520-offset figures
		// are the last row, whatever the rows above look like.
		expect( lastRowFlags( [ 0, 0, 0, 260, 260, 260, 520, 520 ] ) ).toEqual(
			[ false, false, false, false, false, false, true, true ]
		);
	} );

	it( 'flags a lone figure on its own final row', () => {
		expect( lastRowFlags( [ 0, 0, 300 ] ) ).toEqual( [
			false,
			false,
			true,
		] );
	} );
} );
