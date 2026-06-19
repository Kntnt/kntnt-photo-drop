/**
 * Tests for the tier-width live clamp DOM shell.
 *
 * The Create/Edit forms wire three number inputs (upload_width, full_width,
 * thumbnail_width) so the tier ladder stays self-consistent as the builder
 * commits a value. The critical constraint is that the clamp runs only when a
 * field is committed (on `change`), not on every keystroke (on `input`). A
 * value typed digit-by-digit — e.g. `3`, `38`, `384`, `3840` — must not strand
 * the lower tiers at the value that passed through during an intermediate
 * keystroke; only the final committed value should trigger the clamp.
 *
 * @since 0.11.0
 */

import { init } from './width-clamp-dom';

/**
 * Creates a number input with the given `name` and optional starting value,
 * appends it to `document.body`, and returns it.
 *
 * @param name  - The input's `name` attribute.
 * @param value - The input's initial value (defaults to an empty string).
 * @return The created input element.
 */
function makeInput( name: string, value = '' ): HTMLInputElement {
	const input = document.createElement( 'input' );
	input.type = 'number';
	input.name = name;
	input.value = value;
	document.body.appendChild( input );
	return input;
}

/**
 * Dispatches a synthetic event of the given type on the target element.
 *
 * @param target - The element to dispatch the event on.
 * @param type   - The event type to dispatch (`input` or `change`).
 */
function fire( target: HTMLInputElement, type: 'input' | 'change' ): void {
	target.dispatchEvent( new Event( type, { bubbles: true } ) );
}

describe( 'init — tier-width live clamp DOM wiring', () => {
	beforeEach( () => {
		// Reset the DOM between tests so each test starts with a clean body.
		document.body.innerHTML = '';
	} );

	it( 'does not strand lower tiers at a mid-typed intermediate value', () => {
		// Arrange: Full=1920, Thumbnail=640; the upload field starts empty.
		const upload = makeInput( 'upload_width', '' );
		const full = makeInput( 'full_width', '1920' );
		const thumbnail = makeInput( 'thumbnail_width', '640' );
		init();

		// Act: simulate typing "3840" digit by digit — each keystroke fires `input`
		// but only the final committed value fires `change`.
		for ( const partial of [ '3', '38', '384', '3840' ] ) {
			upload.value = partial;
			fire( upload, 'input' );
		}
		// Commit the final value.
		fire( upload, 'change' );

		// Assert: the lower tiers must NOT be stranded at the intermediate value
		// that passed through while the user was still typing.
		expect( full.value ).not.toBe( '3' );
		expect( thumbnail.value ).not.toBe( '3' );

		// More precisely: 1920 ≤ 3840 and 640 ≤ 1920, so both are within bounds
		// and must remain exactly as typed.
		expect( full.value ).toBe( '1920' );
		expect( thumbnail.value ).toBe( '640' );
	} );

	it( 'clamps lower tiers to the committed upload width', () => {
		// Arrange: Full=1920, Thumbnail=640; upload is committed to 1600.
		const upload = makeInput( 'upload_width', '' );
		const full = makeInput( 'full_width', '1920' );
		const thumbnail = makeInput( 'thumbnail_width', '640' );
		init();

		// Act: commit a value that is below Full but above Thumbnail.
		upload.value = '1600';
		fire( upload, 'change' );

		// Assert: Full is clamped to 1600; Thumbnail stays within the new Full.
		expect( full.value ).toBe( '1600' );
		expect( thumbnail.value ).toBe( '640' );
	} );
} );
