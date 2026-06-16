/**
 * The gallery's trash delete request — the permanent removal of a collection
 * image (ADR-0015).
 *
 * The trash overlay is the gallery's one destructive REST call: `deleteImage`
 * sends a `DELETE` carrying the image's collection-relative path to the
 * collection's images endpoint with the `wp_rest` nonce, and reports a
 * discriminated result the view module turns into live tile removal or a silent
 * retry — never throwing, so a transport failure degrades to a reported zero
 * status rather than an unhandled rejection. It is the destructive twin of the
 * add-to-media copy request: same path mirror, same nonce, same JSON-body shape —
 * only the HTTP method and the server effect differ.
 *
 * Pure of the DOM — `deleteImage` takes its `fetch` as an argument — so the view
 * wiring above it stays thin and it is unit-testable without a browser. The
 * deliberate, destructive confirm is the view module's inline popover (an
 * explicit Delete/Cancel, never a single click), kept in the DOM layer because it
 * is all element creation and focus handling.
 *
 * @since 0.13.0
 */

/**
 * The outcome of a trash delete request.
 *
 * A discriminated union: a success carries nothing beyond the flag (the server
 * removed the image and its derived artifacts), a failure carries the HTTP status
 * (or `0` for a transport failure the browser never gave a status for), so the
 * caller can distinguish a forbidden/rejected delete from a network drop.
 *
 * @since 0.13.0
 */
export type DeleteImageResult =
	| { readonly ok: true }
	| { readonly ok: false; readonly status: number };

/**
 * Sends a trash delete request to the collection's images endpoint.
 *
 * Sends the image's collection-relative path as a JSON body with the `wp_rest`
 * nonce header on a `DELETE`, exactly what `Images_Controller` verifies and
 * confines server-side. A 2xx is the permanent removal of the main image and its
 * derived artifacts; any other status is a failure carrying that status (forgery,
 * capability, confinement, or a server error); a thrown fetch (network down, CORS)
 * is a failure with status `0`. Never throws, so the caller can branch on
 * `result.ok` without a try/catch.
 *
 * @since 0.13.0
 *
 * @param url       - The collection's images endpoint URL (mirrored onto the wrapper).
 * @param path      - The image's collection-relative path (mirrored onto the anchor/icon).
 * @param nonce     - The `wp_rest` nonce (mirrored onto the wrapper for capable users).
 * @param fetchImpl - The fetch implementation; defaults to the global, injectable for tests.
 * @return The delete outcome.
 */
export async function deleteImage(
	url: string,
	path: string,
	nonce: string,
	fetchImpl: typeof fetch = fetch
): Promise< DeleteImageResult > {
	try {
		// DELETE the path as JSON with the nonce header; a non-OK status is a failure
		// carrying that status so the caller can tell a rejection from a network drop.
		const response = await fetchImpl( url, {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( { path } ),
		} );
		return response.ok
			? { ok: true }
			: { ok: false, status: response.status };
	} catch {
		// A thrown fetch (network down, CORS) never yielded a status, so report zero.
		return { ok: false, status: 0 };
	}
}
