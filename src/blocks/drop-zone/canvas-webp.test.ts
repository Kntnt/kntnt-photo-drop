/**
 * Jest tests for the Canvas → WebP encode wrapper.
 *
 * Exercises the promise wrapper around `canvas.toBlob` by mocking
 * `HTMLCanvasElement.prototype.toBlob` directly, so the test covers the wrapper's
 * own logic — the quality mapping, the resolve-on-blob path, and the
 * reject-on-null path — without depending on a real canvas encoder (jsdom does
 * not implement WebP encoding).
 *
 * @since 0.5.0
 */

import { encodeCanvasToWebp } from './canvas-webp';

/**
 * Installs a `toBlob` mock on the canvas prototype and returns the spy.
 *
 * The mock invokes the supplied callback with `blob`, capturing the `type` and
 * `quality` arguments the wrapper passed so the assertions can read them back.
 *
 * @param blob - The blob the mock hands the callback (null to exercise rejection).
 * @return The Jest mock standing in for `toBlob`.
 */
function mockToBlob( blob: Blob | null ): jest.Mock {
	// The wrapper passes (callback, type, quality); the mock only invokes the
	// callback, and Jest records the type and quality args regardless so the
	// assertions can read them back from `spy.mock.calls`.
	const spy = jest.fn( ( callback: ( result: Blob | null ) => void ) => {
		callback( blob );
	} );
	HTMLCanvasElement.prototype.toBlob =
		spy as unknown as HTMLCanvasElement[ 'toBlob' ];
	return spy;
}

describe( 'encodeCanvasToWebp', () => {
	it( 'resolves to the blob the browser hands back', async () => {
		const expected = new Blob( [ 'webp' ], { type: 'image/webp' } );
		mockToBlob( expected );

		const result = await encodeCanvasToWebp(
			document.createElement( 'canvas' ),
			80
		);

		expect( result ).toBe( expected );
	} );

	it( 'requests the image/webp MIME type', async () => {
		const spy = mockToBlob(
			new Blob( [ 'webp' ], { type: 'image/webp' } )
		);

		await encodeCanvasToWebp( document.createElement( 'canvas' ), 80 );

		expect( spy.mock.calls[ 0 ][ 1 ] ).toBe( 'image/webp' );
	} );

	it( 'maps a 0–100 quality onto the 0–1 toBlob argument', async () => {
		const spy = mockToBlob(
			new Blob( [ 'webp' ], { type: 'image/webp' } )
		);

		await encodeCanvasToWebp( document.createElement( 'canvas' ), 80 );

		expect( spy.mock.calls[ 0 ][ 2 ] ).toBeCloseTo( 0.8 );
	} );

	it( 'clamps an over-range quality to the 0–1 ceiling', async () => {
		const spy = mockToBlob(
			new Blob( [ 'webp' ], { type: 'image/webp' } )
		);

		await encodeCanvasToWebp( document.createElement( 'canvas' ), 150 );

		expect( spy.mock.calls[ 0 ][ 2 ] ).toBeCloseTo( 1 );
	} );

	it( 'clamps a negative quality to the 0–1 floor', async () => {
		const spy = mockToBlob(
			new Blob( [ 'webp' ], { type: 'image/webp' } )
		);

		await encodeCanvasToWebp( document.createElement( 'canvas' ), -10 );

		expect( spy.mock.calls[ 0 ][ 2 ] ).toBeCloseTo( 0 );
	} );

	it( 'rejects when the browser hands back a null blob', async () => {
		mockToBlob( null );

		await expect(
			encodeCanvasToWebp( document.createElement( 'canvas' ), 80 )
		).rejects.toThrow( 'Canvas could not be encoded to WebP.' );
	} );
} );
