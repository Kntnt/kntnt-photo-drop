/**
 * Tests for the pure regenerate target reader.
 *
 * The admin Edit page's regenerate fields are read into a `RegenerateTarget` the
 * batch driver sends to the REST endpoint. The three re-derivable fields (full
 * width, full quality, thumbnail width) may be left **empty** to leave them unset —
 * the collapse-to-parent state (ADR-0013/#71) — which must read as `null` (sent over
 * the wire as JSON null), not as a concrete `0`. A present field must be a valid
 * integer; a non-integer makes the whole target malformed. These pin that the reader
 * distinguishes empty (unset) from present-but-garbage (malformed) from concrete, in
 * jsdom over a real fields fragment.
 *
 * @since 0.11.0
 */

import { readTarget } from './regenerate-target';

/**
 * Builds a regenerate fields fragment with the four inputs set to given values.
 *
 * An empty string stands for a cleared field; the markup mirrors what the admin page
 * renders (number inputs keyed by `name`).
 *
 * @param values                   - The raw string value for each of the four fields.
 * @param values.full_width        - The Full width input's raw value.
 * @param values.full_quality      - The Full quality input's raw value.
 * @param values.thumbnail_width   - The Thumbnail width input's raw value.
 * @param values.thumbnail_quality - The Thumbnail quality input's raw value.
 * @return The host element holding the four number inputs.
 */
function fieldsFragment( values: {
	full_width: string;
	full_quality: string;
	thumbnail_width: string;
	thumbnail_quality: string;
} ): HTMLElement {
	const root = document.createElement( 'div' );
	root.innerHTML = `
		<input name="full_width" type="number" value="${ values.full_width }" />
		<input name="full_quality" type="number" value="${ values.full_quality }" />
		<input name="thumbnail_width" type="number" value="${ values.thumbnail_width }" />
		<input name="thumbnail_quality" type="number" value="${ values.thumbnail_quality }" />
	`;
	return root;
}

describe( 'readTarget', () => {
	it( 'reads four present integers into a concrete target', () => {
		const root = fieldsFragment( {
			full_width: '1280',
			full_quality: '80',
			thumbnail_width: '320',
			thumbnail_quality: '60',
		} );

		expect( readTarget( root ) ).toEqual( {
			fullWidth: 1280,
			fullQuality: 80,
			thumbnailWidth: 320,
			thumbnailQuality: 60,
		} );
	} );

	it( 'reads an emptied full width as null (unset), not 0', () => {
		const root = fieldsFragment( {
			full_width: '',
			full_quality: '80',
			thumbnail_width: '320',
			thumbnail_quality: '60',
		} );

		const target = readTarget( root );
		expect( target ).not.toBeNull();
		expect( target?.fullWidth ).toBeNull();
		expect( target?.fullQuality ).toBe( 80 );
		expect( target?.thumbnailWidth ).toBe( 320 );
	} );

	it( 'reads an emptied full quality as null (follow upload quality), not 0', () => {
		const root = fieldsFragment( {
			full_width: '1280',
			full_quality: '',
			thumbnail_width: '320',
			thumbnail_quality: '60',
		} );

		const target = readTarget( root );
		expect( target ).not.toBeNull();
		expect( target?.fullQuality ).toBeNull();
		expect( target?.fullWidth ).toBe( 1280 );
	} );

	it( 'reads an emptied thumbnail width as null (follow full width), not 0', () => {
		const root = fieldsFragment( {
			full_width: '1280',
			full_quality: '80',
			thumbnail_width: '',
			thumbnail_quality: '60',
		} );

		const target = readTarget( root );
		expect( target ).not.toBeNull();
		expect( target?.thumbnailWidth ).toBeNull();
	} );

	it( 'reads all three re-derivable fields empty together as unset', () => {
		const root = fieldsFragment( {
			full_width: '',
			full_quality: '',
			thumbnail_width: '',
			thumbnail_quality: '70',
		} );

		expect( readTarget( root ) ).toEqual( {
			fullWidth: null,
			fullQuality: null,
			thumbnailWidth: null,
			thumbnailQuality: 70,
		} );
	} );

	it( 'rejects a present-but-non-integer width as a malformed target', () => {
		const root = fieldsFragment( {
			full_width: '1.5',
			full_quality: '80',
			thumbnail_width: '320',
			thumbnail_quality: '60',
		} );

		expect( readTarget( root ) ).toBeNull();
	} );

	it( 'rejects an empty thumbnail quality — it is always concrete, never unset', () => {
		const root = fieldsFragment( {
			full_width: '1280',
			full_quality: '80',
			thumbnail_width: '320',
			thumbnail_quality: '',
		} );

		expect( readTarget( root ) ).toBeNull();
	} );

	it( 'rejects an out-of-range quality as a malformed target', () => {
		const root = fieldsFragment( {
			full_width: '1280',
			full_quality: '101',
			thumbnail_width: '320',
			thumbnail_quality: '60',
		} );

		expect( readTarget( root ) ).toBeNull();
	} );
} );
