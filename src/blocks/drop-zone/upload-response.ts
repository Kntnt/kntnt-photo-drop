/**
 * The upload-response interpretation rules.
 *
 * The REST endpoint answers in two shapes: a per-file outcome object
 * (`Upload_Controller::respond()` — `outcome`, `name`, …; 200 for
 * written/skipped, 422 for rejected) and a `WP_Error` envelope (`code`,
 * `message`, `data`) for request-level failures such as an expired nonce.
 * These rules turn a raw HTTP status plus a parsed (or unparseable) body into
 * the two decisions the uploader needs: which outcome a success carries (so the
 * file can be recorded `uploaded` or `skipped`, under the server's canonical
 * name), and whether a failure is a nonce rejection worth one automatic retry
 * after a nonce refresh. Every other failure is recorded `failed` without
 * inspecting the body — the aggregate summary lists failures by name, not by
 * reason (issue #44).
 *
 * The rules are pure over plain values so Jest covers every response shape
 * without a network; the view module's XHR handler is the only caller.
 *
 * @since 0.2.0
 */

/**
 * The per-file outcome shape the REST endpoint returns on 200/422.
 *
 * Mirrors `Upload_Controller::respond()`: the backed `outcome` plus the
 * display `name`. Only these two fields are read at runtime.
 *
 * @since 0.2.0
 */
export interface UploadOutcome {
	readonly outcome: 'stored' | 'skipped' | 'reencoded' | 'rejected';
	readonly name: string | null;
}

/**
 * The error codes that signal an expired or invalid `wp_rest` nonce.
 *
 * `kntnt_photo_drop_invalid_nonce` is the plugin's own verdict
 * (`Upload_Controller`); `rest_cookie_invalid_nonce` is core's verdict when
 * cookie authentication itself rejects the header before the route runs.
 * Either one means a fresh nonce may rescue the upload.
 *
 * @since 0.2.0
 */
const NONCE_ERROR_CODES: ReadonlySet< string > = new Set( [
	'kntnt_photo_drop_invalid_nonce',
	'rest_cookie_invalid_nonce',
] );

/**
 * The outcome values the endpoint can return, used to validate a payload.
 *
 * @since 0.2.0
 */
const KNOWN_OUTCOMES: ReadonlySet< string > = new Set( [
	'stored',
	'skipped',
	'reencoded',
	'rejected',
] );

/**
 * Extracts a per-file outcome from a parsed response body.
 *
 * Returns the typed outcome when the payload is an object carrying one of the
 * four backed outcome values, null for anything else — a `WP_Error` envelope,
 * an unparseable body, or a shape from some interfering proxy. A null return
 * means the upload must be treated as failed even on a 2xx status, because a
 * success may never be recorded without a parsed outcome.
 *
 * @since 0.2.0
 *
 * @param payload - The parsed JSON body, or null when parsing failed.
 * @return The validated outcome, or null when the body carries none.
 */
export function readOutcome( payload: unknown ): UploadOutcome | null {
	// Reject everything that is not an object with a known outcome value; the
	// display name is optional and falls back to null.
	if ( payload === null || typeof payload !== 'object' ) {
		return null;
	}
	const record = payload as { outcome?: unknown; name?: unknown };
	if (
		typeof record.outcome !== 'string' ||
		! KNOWN_OUTCOMES.has( record.outcome )
	) {
		return null;
	}

	return {
		outcome: record.outcome as UploadOutcome[ 'outcome' ],
		name: typeof record.name === 'string' ? record.name : null,
	};
}

/**
 * Decides whether a failed response is a nonce rejection worth a retry.
 *
 * True only for a 401/403 whose body carries one of the known nonce error
 * codes — the plugin's own or core's cookie-auth code. Other 401/403s (e.g.
 * a user without the upload capability) are not retried, because a fresh
 * nonce cannot change an authorisation verdict.
 *
 * @since 0.2.0
 *
 * @param httpStatus - The HTTP status of the failed response.
 * @param payload    - The parsed JSON body, or null when parsing failed.
 * @return True when refreshing the nonce and retrying once makes sense.
 */
export function isNonceRejection(
	httpStatus: number,
	payload: unknown
): boolean {
	// Only an authentication-shaped status can be a nonce problem.
	if ( httpStatus !== 401 && httpStatus !== 403 ) {
		return false;
	}

	// The body must name a nonce error code; anything else is a different
	// rejection a retry cannot fix.
	if ( payload === null || typeof payload !== 'object' ) {
		return false;
	}
	const code = ( payload as { code?: unknown } ).code;

	return typeof code === 'string' && NONCE_ERROR_CODES.has( code );
}
