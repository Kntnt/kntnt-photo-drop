/**
 * The gallery's add-to-media copy request.
 *
 * The add-to-media overlay is the gallery's one REST write call (ADR-0015):
 * `addToMedia` POSTs the image's collection-relative path to the collection's
 * media endpoint with the `wp_rest` nonce, and reports a discriminated result the
 * view module turns into success feedback, an overwrite confirm, or a retry —
 * never throwing, so a transport failure degrades to a reported zero status rather
 * than an unhandled rejection. A first add is a single click; the server
 * de-duplicates by the image's stamped source identity, so a re-add comes back as
 * `exists` (HTTP 409) carrying the existing attachment id, and the view raises an
 * inline Overwrite confirm whose confirmation re-sends the request with
 * `overwrite: true` (HTTP 200, `replaced`) (ADR-0015 amendment).
 *
 * It is pure of the DOM — `addToMedia` takes its `fetch` as an option — so the
 * view wiring above it stays thin and it is unit-testable without a browser.
 *
 * @since 0.15.0
 */

/**
 * The outcome of an add-to-media request.
 *
 * A discriminated union on `outcome`: `created` — a new attachment (201).
 * `replaced` — an existing attachment's file was overwritten in place (200).
 * `exists` — the image is already in the Library and overwrite was not requested
 * (409); carries the existing id so the view can raise its overwrite confirm.
 * `error` — any other failure (forgery, capability, confinement, server, or a
 * thrown fetch), carrying the HTTP status (0 for a transport failure the browser
 * never gave a status for).
 *
 * @since 0.15.0
 */
export type AddToMediaResult =
	| { readonly outcome: 'created'; readonly id: number }
	| { readonly outcome: 'replaced'; readonly id: number }
	| { readonly outcome: 'exists'; readonly id: number }
	| { readonly outcome: 'error'; readonly status: number };

/**
 * Options for {@link addToMedia}.
 *
 * @since 0.15.0
 */
export interface AddToMediaOptions {
	/** Ask the server to overwrite an existing copy in place. */
	readonly overwrite?: boolean;
	/** The fetch implementation; defaults to the global, injectable for tests. */
	readonly fetchImpl?: typeof fetch;
}

/**
 * Reads a numeric id off an unknown JSON value at the given key, or null.
 *
 * Used to defensively pull the created/replaced id from a success body and the
 * existing id from a 409 error body, so a malformed body degrades to a reported
 * error rather than a phantom id that would crash the popover.
 *
 * @param value - The JSON value to read from.
 * @param key   - The property whose numeric value to read.
 * @return The numeric value, or null when absent or non-numeric.
 */
function readNumber( value: unknown, key: string ): number | null {
	if ( typeof value !== 'object' || value === null ) {
		return null;
	}
	const candidate = ( value as Record< string, unknown > )[ key ];
	return typeof candidate === 'number' ? candidate : null;
}

/**
 * POSTs an add-to-media copy request to the collection's media endpoint.
 *
 * Sends the image's collection-relative path as a JSON body with the `wp_rest`
 * nonce header, exactly what `Media_Controller` verifies and confines server-side;
 * when `overwrite` is set the body also carries `overwrite: true`. A 201 with a
 * numeric `id` is the created attachment, a 200 the replaced one, a 409 the
 * already-present one (its existing id read from the WordPress error body's
 * `data.id`); any other status — or a thrown fetch (network down, CORS) — is an
 * error carrying that status (0 for the throw). A success or 409 missing its
 * numeric id degrades to an error rather than a phantom id. Never throws, so the
 * caller can branch on `result.outcome` without a try/catch.
 *
 * @since 0.15.0
 *
 * @param url     - The collection's media endpoint URL (mirrored onto the wrapper).
 * @param path    - The image's collection-relative path (mirrored onto the anchor/icon).
 * @param nonce   - The `wp_rest` nonce (mirrored onto the wrapper for capable users).
 * @param options - Optional overwrite flag and fetch implementation.
 * @return The copy outcome.
 */
export async function addToMedia(
	url: string,
	path: string,
	nonce: string,
	options: AddToMediaOptions = {}
): Promise< AddToMediaResult > {
	const fetchImpl = options.fetchImpl ?? fetch;
	try {
		// POST the path (and overwrite, when asked) as JSON with the nonce header.
		const response = await fetchImpl( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( {
				path,
				...( options.overwrite ? { overwrite: true } : {} ),
			} ),
		} );

		// A duplicate (409) carries the existing id under the WordPress error body's
		// nested `data.id` (with a flat `id` as a fallback); a malformed 409 degrades
		// to a generic error so the popover never opens against a missing id.
		if ( response.status === 409 ) {
			const body = ( await response
				.json()
				.catch( () => null ) ) as unknown;
			const id =
				readNumber( readBody( body ), 'id' ) ??
				readNumber( body, 'id' );
			return id === null
				? { outcome: 'error', status: 409 }
				: { outcome: 'exists', id };
		}

		// Any non-OK status that is not the dedup 409 is a failure carrying that status
		// so the caller can tell a rejection from a network drop.
		if ( ! response.ok ) {
			return { outcome: 'error', status: response.status };
		}

		// A created (201) or replaced (200) attachment carries a numeric id; a
		// malformed success body is treated as an error rather than a phantom id.
		const data = ( await response.json() ) as { id?: unknown };
		const id = typeof data.id === 'number' ? data.id : null;
		if ( id === null ) {
			return { outcome: 'error', status: response.status };
		}
		return response.status === 200
			? { outcome: 'replaced', id }
			: { outcome: 'created', id };
	} catch {
		// A thrown fetch (network down, CORS) never yielded a status, so report zero.
		return { outcome: 'error', status: 0 };
	}
}

/**
 * Returns the `data` object of a WordPress error body, or the body itself.
 *
 * WordPress serialises a `WP_Error`'s extra data under a nested `data` key, so the
 * existing attachment id added by the controller lands at `body.data.id`. This
 * peels that wrapper, falling back to the body for a flatter shape.
 *
 * @param body - The decoded 409 response body.
 * @return The nested `data` object, or the body when there is no wrapper.
 */
function readBody( body: unknown ): unknown {
	if ( typeof body === 'object' && body !== null && 'data' in body ) {
		return ( body as { data: unknown } ).data;
	}
	return body;
}
