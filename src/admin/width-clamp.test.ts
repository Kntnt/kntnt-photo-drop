/**
 * Tests for the pure tier-width clamp.
 *
 * The Create/Edit forms keep the rendition widths in a descending tier ladder live:
 * Full may not exceed the upload limit (when one is set), and Thumbnail may not
 * exceed Full (ADR-0013 — each tier is skipped when the source is no wider). The DOM
 * wiring lives in the non-unit shell (`width-clamp-dom.ts`); the rule it enforces
 * rests on this pure function, which takes the three raw width strings plus the
 * upload-width mode and returns the Full and Thumbnail values clamped down to their
 * ceiling. The server re-validates at submit, so this is a self-consistency aid — but
 * it must clamp exactly to the tier above, cascade a lowered upload limit through
 * both lower tiers, leave a within-bounds value alone, and never coerce a blank or
 * malformed field (#71).
 *
 * @since 0.13.0
 */

import { clampWidths } from './width-clamp';

describe( 'clampWidths', () => {
	it( 'clamps Full down to the upload width when an upload limit is set', () => {
		// Full exceeds the pixel ceiling, so it is lowered to it; Thumbnail is within
		// the new Full and is left as typed.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '1600',
				full: '1920',
				thumbnail: '640',
			} )
		).toEqual( { full: '1600', thumbnail: '640' } );
	} );

	it( 'leaves Full unconstrained from above when the upload is "Original dimensions"', () => {
		// Under "no limit" there is no upper bound on Full, so a large Full stands;
		// the Thumbnail-to-Full rule still applies independently.
		expect(
			clampWidths( {
				uploadMode: 'none',
				upload: '',
				full: '5000',
				thumbnail: '640',
			} )
		).toEqual( { full: '5000', thumbnail: '640' } );
	} );

	it( 'clamps Thumbnail down to the Full width', () => {
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '1920',
				full: '1280',
				thumbnail: '1600',
			} )
		).toEqual( { full: '1280', thumbnail: '1280' } );
	} );

	it( 'cascades a lowered upload limit through Full into Thumbnail in one call', () => {
		// The upload limit drops below both lower tiers: Full clamps to the upload
		// width, and Thumbnail clamps to the *already-clamped* Full, not its old value.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '500',
				full: '1920',
				thumbnail: '640',
			} )
		).toEqual( { full: '500', thumbnail: '500' } );
	} );

	it( 'leaves a within-bounds ladder untouched', () => {
		// Thumbnail ≤ Full ≤ upload already holds, so nothing is clamped.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '1920',
				full: '1280',
				thumbnail: '640',
			} )
		).toEqual( { full: '1280', thumbnail: '640' } );
	} );

	it( 'leaves a blank Full or Thumbnail field untouched', () => {
		// An empty field is valid "use the default" input and must never be coerced to
		// a number, even when the tier above is a concrete ceiling (#71).
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '800',
				full: '',
				thumbnail: '',
			} )
		).toEqual( { full: '', thumbnail: '' } );
	} );

	it( 'does not clamp against a blank upload limit', () => {
		// A blank upload field under the "limit" mode is no usable ceiling, so Full is
		// left as typed rather than clamped against nothing.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '',
				full: '1920',
				thumbnail: '640',
			} )
		).toEqual( { full: '1920', thumbnail: '640' } );
	} );

	it( 'passes a malformed value through rather than coercing it', () => {
		// A non-integer or non-positive value is left for the server to reject; clamping
		// it would only hide the error the builder needs to see.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '1920',
				full: 'abc',
				thumbnail: '-5',
			} )
		).toEqual( { full: 'abc', thumbnail: '-5' } );
	} );

	it( 'leaves equal widths untouched (a tier may collapse, not error)', () => {
		// Equal widths are a valid degenerate ladder (the tier simply collapses to one
		// file), so a value equal to its ceiling is within bounds and not clamped.
		expect(
			clampWidths( {
				uploadMode: 'limit',
				upload: '1280',
				full: '1280',
				thumbnail: '1280',
			} )
		).toEqual( { full: '1280', thumbnail: '1280' } );
	} );
} );
