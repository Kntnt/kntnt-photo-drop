/**
 * The Canvas → WebP encode wrapper (the Cimo technique).
 *
 * The Drop Zone optimises each image in the browser before upload: the view
 * module downscales the decoded bitmap to the collection's max width onto a
 * canvas, then this wrapper re-encodes those pixels to WebP at the collection's
 * quality by calling `canvas.toBlob(…, 'image/webp', quality)`. No WebAssembly and
 * no extra binary assets are involved — the browser's own canvas encoder does the
 * work (ADR: client-side optimisation via Canvas, not jSquash).
 *
 * The function is a thin, pure-ish promise wrapper around the inherently
 * callback-shaped `toBlob`, kept separate from any DOM wiring so Jest can cover it
 * by mocking `toBlob` alone. The client optimisation is a bandwidth
 * optimisation only; the server re-enforces the contract on every file
 * (ADR-0006), so a browser that cannot produce WebP simply uploads the canvas's
 * fallback bytes and the server converts them.
 *
 * @since 0.5.0
 */

/**
 * The WebP MIME type `canvas.toBlob` is asked to produce.
 *
 * @since 0.5.0
 */
const WEBP_MIME = 'image/webp';

/**
 * The fraction `quality` (0–100) is mapped onto for `toBlob`'s 0–1 argument.
 *
 * @since 0.5.0
 */
const QUALITY_DIVISOR = 100;

/**
 * Encodes a canvas's current pixels to a WebP blob at the given quality.
 *
 * Wraps the callback-shaped `canvas.toBlob` in a promise. The `quality` is the
 * collection's contract value on the 0–100 scale; it is clamped into range and
 * divided to the 0–1 fraction `toBlob` expects. The promise rejects only when the
 * browser hands back a `null` blob — the documented signal that the canvas could
 * not encode to the requested type — so the caller can fall back to the original
 * bytes and let the server convert them.
 *
 * @since 0.5.0
 *
 * @param canvas  - The canvas holding the already-downscaled pixels to encode.
 * @param quality - The collection's WebP quality on the 0–100 scale.
 * @return A promise resolving to the encoded WebP blob.
 */
export function encodeCanvasToWebp(
	canvas: HTMLCanvasElement,
	quality: number
): Promise< Blob > {
	// Clamp the 0–100 contract value into range and map it onto toBlob's 0–1
	// quality argument, so a descriptor value outside the band cannot produce a
	// nonsensical encoder argument.
	const clamped = Math.min( QUALITY_DIVISOR, Math.max( 0, quality ) );
	const fraction = clamped / QUALITY_DIVISOR;

	return new Promise< Blob >( ( resolve, reject ) => {
		canvas.toBlob(
			( blob ) => {
				// A null blob is the browser's signal that it could not encode to
				// WebP; reject so the caller can upload the original and let the
				// server re-encode.
				if ( ! blob ) {
					reject(
						new Error( 'Canvas could not be encoded to WebP.' )
					);
					return;
				}
				resolve( blob );
			},
			WEBP_MIME,
			fraction
		);
	} );
}
