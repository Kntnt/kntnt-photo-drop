/**
 * The gallery's add-to-media copy request and its two-click confirm gate.
 *
 * The add-to-media overlay is the gallery's one REST write call (ADR-0015):
 * `addToMedia` POSTs the image's collection-relative path to the collection's
 * media endpoint with the `wp_rest` nonce, and reports a discriminated result the
 * view module turns into success feedback or a retry — never throwing, so a
 * transport failure degrades to a reported zero status rather than an unhandled
 * rejection. `confirmGate` is the pure state behind the confirm callout: a copy
 * into the Media Library is a deliberate act, so a first activation only *arms*
 * the control and a second within the armed window fires it, mirroring the
 * two-click safety the destructive trash action uses.
 *
 * Both are pure of the DOM — `addToMedia` takes its `fetch` as an argument and
 * `confirmGate` holds only a boolean — so the view wiring above them stays thin
 * and both are unit-testable without a browser.
 *
 * @since 0.12.0
 */

/**
 * The outcome of an add-to-media copy request.
 *
 * A discriminated union: a success carries the created attachment id, a failure
 * carries the HTTP status (or `0` for a transport failure the browser never gave
 * a status for), so the caller can distinguish a forbidden/rejected copy from a
 * network drop.
 *
 * @since 0.12.0
 */
export type AddToMediaResult =
	| { readonly ok: true; readonly id: number }
	| { readonly ok: false; readonly status: number };

/**
 * POSTs an add-to-media copy request to the collection's media endpoint.
 *
 * Sends the image's collection-relative path as a JSON body with the `wp_rest`
 * nonce header, exactly what `Media_Controller` verifies and confines server-side.
 * A 2xx with a numeric `id` is the created attachment; any other status is a
 * failure carrying that status (forgery, capability, confinement, or a server
 * error); a thrown fetch (network down, CORS) is a failure with status `0`. Never
 * throws, so the caller can branch on `result.ok` without a try/catch.
 *
 * @since 0.12.0
 *
 * @param url       - The collection's media endpoint URL (mirrored onto the wrapper).
 * @param path      - The image's collection-relative path (mirrored onto the anchor/icon).
 * @param nonce     - The `wp_rest` nonce (mirrored onto the wrapper for capable users).
 * @param fetchImpl - The fetch implementation; defaults to the global, injectable for tests.
 * @return The copy outcome.
 */
export async function addToMedia(
	url: string,
	path: string,
	nonce: string,
	fetchImpl: typeof fetch = fetch
): Promise< AddToMediaResult > {
	try {
		// POST the path as JSON with the nonce header; a non-OK status is a failure
		// carrying that status so the caller can tell a rejection from a network drop.
		const response = await fetchImpl( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( { path } ),
		} );
		if ( ! response.ok ) {
			return { ok: false, status: response.status };
		}

		// A created attachment carries a numeric id; a malformed success body is
		// treated as a failure rather than a phantom id.
		const data = ( await response.json() ) as { id?: unknown };
		return typeof data.id === 'number'
			? { ok: true, id: data.id }
			: { ok: false, status: response.status };
	} catch {
		// A thrown fetch (network down, CORS) never yielded a status, so report zero.
		return { ok: false, status: 0 };
	}
}

/**
 * A two-click confirm gate.
 *
 * @since 0.12.0
 */
export interface ConfirmGate {
	/** Whether the gate is currently armed (awaiting the confirming second click). */
	readonly armed: boolean;
	/** Activates the gate; returns true when this activation fires the action. */
	readonly activate: () => boolean;
	/** Disarms the gate. */
	readonly cancel: () => void;
}

/**
 * Creates a two-click confirm gate.
 *
 * The first `activate()` arms the gate and returns `false` (the view shows the
 * confirm affordance); a second `activate()` while armed returns `true` (the view
 * fires the copy) and disarms. `cancel()` disarms without firing, so a dismissed
 * callout leaves the next activation only re-arming — a copy never happens on a
 * single stray click.
 *
 * @since 0.12.0
 *
 * @return The confirm gate.
 */
export function confirmGate(): ConfirmGate {
	let armed = false;
	return {
		get armed(): boolean {
			return armed;
		},
		activate(): boolean {
			// A second activation while armed fires and disarms; the first only arms.
			if ( armed ) {
				armed = false;
				return true;
			}
			armed = true;
			return false;
		},
		cancel(): void {
			armed = false;
		},
	};
}
