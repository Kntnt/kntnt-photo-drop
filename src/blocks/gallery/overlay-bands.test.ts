/**
 * Jest tests for the editor's pure overlay band-exclusion logic.
 *
 * Breadcrumbs occupy a whole horizontal band (the position's vertical half —
 * top, middle, or bottom), so the editor mutually excludes that band from the
 * icon overlays and vice versa (ADR-0015). These pure helpers back that: `bandOf`
 * maps a nine-point position to its band, and `disabledPositions` turns a set of
 * occupied bands into the nine-point positions a picker must disable. The editor
 * shell wiring is exercised by e2e; this is the decision core (docs/testing.md,
 * "#47 Pure core + non-unit shell").
 *
 * @since 0.11.0
 */

import { bandOf, disabledPositions } from './overlay-bands';

describe( 'bandOf', () => {
	it( 'maps the top row positions to the top band', () => {
		expect( bandOf( 'top-left' ) ).toBe( 'top' );
		expect( bandOf( 'top-center' ) ).toBe( 'top' );
		expect( bandOf( 'top-right' ) ).toBe( 'top' );
	} );

	it( 'maps the middle row positions to the middle band', () => {
		expect( bandOf( 'middle-left' ) ).toBe( 'middle' );
		expect( bandOf( 'middle-center' ) ).toBe( 'middle' );
		expect( bandOf( 'middle-right' ) ).toBe( 'middle' );
	} );

	it( 'maps the bottom row positions to the bottom band', () => {
		expect( bandOf( 'bottom-left' ) ).toBe( 'bottom' );
		expect( bandOf( 'bottom-center' ) ).toBe( 'bottom' );
		expect( bandOf( 'bottom-right' ) ).toBe( 'bottom' );
	} );
} );

describe( 'disabledPositions', () => {
	it( 'disables nothing when no band is occupied', () => {
		expect( disabledPositions( [] ) ).toEqual( [] );
	} );

	it( 'disables exactly the three positions of one occupied band', () => {
		expect( disabledPositions( [ 'bottom' ] ) ).toEqual( [
			'bottom-left',
			'bottom-center',
			'bottom-right',
		] );
	} );

	it( 'disables every position across two occupied bands', () => {
		expect( disabledPositions( [ 'top', 'middle' ] ) ).toEqual( [
			'top-left',
			'top-center',
			'top-right',
			'middle-left',
			'middle-center',
			'middle-right',
		] );
	} );

	it( 'de-duplicates a band repeated in the occupied set', () => {
		// Two icons sharing the bottom band contribute it once, so the breadcrumb
		// picker disables only the three bottom positions, not six.
		expect( disabledPositions( [ 'bottom', 'bottom' ] ) ).toEqual( [
			'bottom-left',
			'bottom-center',
			'bottom-right',
		] );
	} );
} );
