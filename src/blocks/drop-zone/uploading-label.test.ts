/**
 * Jest tests for the live upload-progress label formatter.
 *
 * Verifies the rounding and `%d` substitution of `formatUploadingLabel`, the pure
 * helper the per-file `onprogress` handler uses to show a real percentage while a
 * large file uploads (issue #31). Pure and DOM-free, so it runs in jsdom alone.
 *
 * @since 0.4.0
 */

import { formatUploadingLabel } from './uploading-label';

/**
 * The percent template fixture mirroring the server-translated string.
 */
const TEMPLATE = 'Uploading… %d%%';

describe( 'formatUploadingLabel', () => {
	it( 'substitutes the rounded percentage into the template', () => {
		expect( formatUploadingLabel( TEMPLATE, 50, 100 ) ).toBe(
			'Uploading… 50%'
		);
	} );

	it( 'rounds to the nearest whole percent', () => {
		// 1/3 of the bytes rounds to 33%.
		expect( formatUploadingLabel( TEMPLATE, 1, 3 ) ).toBe(
			'Uploading… 33%'
		);
	} );

	it( 'reports 0% at the start of an upload', () => {
		expect( formatUploadingLabel( TEMPLATE, 0, 1000 ) ).toBe(
			'Uploading… 0%'
		);
	} );

	it( 'reports 100% on completion', () => {
		expect( formatUploadingLabel( TEMPLATE, 1000, 1000 ) ).toBe(
			'Uploading… 100%'
		);
	} );

	it( 'treats a non-positive total as 0% rather than dividing by zero', () => {
		expect( formatUploadingLabel( TEMPLATE, 10, 0 ) ).toBe(
			'Uploading… 0%'
		);
	} );
} );
