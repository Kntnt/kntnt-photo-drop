/**
 * The upload-response interpretation rules.
 *
 * The REST endpoint answers in two shapes: a per-file outcome object
 * (`Upload_Controller::respond()` — `outcome`, `name`, …; 200 for
 * written/skipped, 422 for rejected) and a `WP_Error` envelope (`code`,
 * `message`, `data`) for request-level failures such as an expired nonce.
 * These rules turn a raw HTTP status plus a parsed (or unparseable) body into
 * the three decisions the uploader needs: which outcome a success carries,
 * whether a failure is a nonce rejection worth one automatic retry after a
 * nonce refresh, and which human-readable label to surface — always preferring
 * the server's actionable `message` over the generic "Upload failed".
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
 * The pre-translated labels the interpretation rules can emit.
 *
 * A subset of the full string map `Render_Drop_Zone::translations()` passes
 * through the Interactivity context; declared here so the rules and their
 * tests need only the keys they read.
 *
 * @since 0.2.0
 */
export interface OutcomeStrings {
	readonly outcomeStored: string;
	readonly outcomeReencoded: string;
	readonly outcomeSkipped: string;
	readonly outcomeRejected: string;
	readonly uploadFailed: string;
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
 * success may never be reported without a parsed outcome.
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
 * Reads the server's human-readable `message` from an error body.
 *
 * A `WP_Error` REST envelope carries an actionable, translated `message`
 * (e.g. "Your session could not be verified. Please reload and try again.");
 * returns it when present and non-empty, null otherwise.
 *
 * @since 0.2.0
 *
 * @param payload - The parsed JSON body, or null when parsing failed.
 * @return The server's message, or null when the body carries none.
 */
export function readErrorMessage( payload: unknown ): string | null {
	if ( payload === null || typeof payload !== 'object' ) {
		return null;
	}
	const message = ( payload as { message?: unknown } ).message;

	return typeof message === 'string' && message !== '' ? message : null;
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

/**
 * Maps a per-file outcome to its pre-translated status label.
 *
 * @since 0.2.0
 *
 * @param outcome - The backed outcome from the REST response.
 * @param strings - The pre-translated label map.
 * @return The label to show for that outcome.
 */
export function labelForOutcome(
	outcome: UploadOutcome[ 'outcome' ],
	strings: OutcomeStrings
): string {
	switch ( outcome ) {
		case 'stored':
			return strings.outcomeStored;
		case 'reencoded':
			return strings.outcomeReencoded;
		case 'skipped':
			return strings.outcomeSkipped;
		case 'rejected':
			return strings.outcomeRejected;
		default:
			return strings.uploadFailed;
	}
}

/**
 * Chooses the most informative label for a failed response.
 *
 * Preference order: the server's actionable `message` (a `WP_Error` envelope),
 * then the outcome label (a 422 rejection carries `outcome: 'rejected'`), and
 * only as a last resort the generic "Upload failed" — so a photographer whose
 * session expired reads the server's "reload and try again", not a shrug.
 *
 * @since 0.2.0
 *
 * @param payload - The parsed JSON body, or null when parsing failed.
 * @param strings - The pre-translated label map.
 * @return The label to surface for the failed file.
 */
export function errorLabelFor(
	payload: unknown,
	strings: OutcomeStrings
): string {
	// The server's own message is the most actionable text available.
	const message = readErrorMessage( payload );
	if ( message !== null ) {
		return message;
	}

	// A per-file outcome (e.g. a 422 rejection) still labels the failure more
	// precisely than the generic fallback.
	const outcome = readOutcome( payload );
	if ( outcome !== null ) {
		return labelForOutcome( outcome.outcome, strings );
	}

	return strings.uploadFailed;
}
