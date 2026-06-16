/**
 * RED skeleton — the gallery add-to-media copy request and its confirm gate.
 *
 * Intentionally incomplete so the co-located test's assertions fail for real
 * before the implementation lands. Replaced wholesale in the GREEN step.
 *
 * @since 0.12.0
 */

/**
 * The outcome of an add-to-media copy request.
 *
 * @since 0.12.0
 */
export type AddToMediaResult =
	| { readonly ok: true; readonly id: number }
	| { readonly ok: false; readonly status: number };

/**
 * RED skeleton: always reports a transport failure.
 *
 * @since 0.12.0
 *
 * @param _url   - The collection's media endpoint URL.
 * @param _path  - The image's collection-relative path.
 * @param _nonce - The `wp_rest` nonce.
 * @param _fetchImpl - The fetch implementation (injected for tests).
 * @return The copy outcome.
 */
export async function addToMedia(
	_url: string,
	_path: string,
	_nonce: string,
	_fetchImpl: typeof fetch = fetch
): Promise< AddToMediaResult > {
	return { ok: false, status: 0 };
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
	activate(): boolean;
	/** Disarms the gate. */
	cancel(): void;
}

/**
 * RED skeleton: a gate that never arms.
 *
 * @since 0.12.0
 *
 * @return The confirm gate.
 */
export function confirmGate(): ConfirmGate {
	return {
		armed: false,
		activate: () => false,
		cancel: () => undefined,
	};
}
