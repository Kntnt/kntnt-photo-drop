/**
 * The pure regenerate batch-loop driver — sequencing for regenerate-then-flip.
 *
 * The admin Edit page re-derives a collection's full and thumbnail renditions at
 * new widths by driving the manage-gated REST endpoint one main per request, then
 * finalising the flip (ADR-0013). This module is the pure sequencing core of that
 * flow: given an injected dispatcher (the thing that actually POSTs a request and
 * resolves its decoded reply) and a progress sink, it walks the batch cursor until
 * the server reports the collection is done, then dispatches the finalise. It owns
 * no DOM and no `fetch`, so the DOM wiring and the real network in `regenerate.ts`
 * stay a thin shell over this Jest-tested driver. The safety rule lives here too:
 * a transport failure stops the loop *before* the finalise, so a partial run never
 * flips the descriptor.
 *
 * @since 0.15.0
 */

/**
 * The decoded shape of one regenerate-endpoint reply the driver reads.
 *
 * `ok` reflects the transport plus HTTP status (a non-2xx is `ok: false`). A batch
 * reply carries `total` (the collection's main count) and `done` (whether the
 * cursor reached the last main); a finalise reply carries `finalized`. The driver
 * reads only these fields, so the dispatcher may include others freely.
 *
 * @since 0.15.0
 */
export interface RegenerateResponse {
	readonly ok: boolean;
	readonly total?: number;
	readonly done?: boolean;
	readonly finalized?: boolean;
}

/**
 * The four target rendition values a regenerate run aims at.
 *
 * The three re-derivable fields are `number | null`: `null` is the collapse-to-parent
 * **unset** state (an emptied Edit-page field), sent to the server as JSON null so the
 * flip persists "unset" distinct from a concrete width/quality (ADR-0013/#71). The
 * thumbnail quality is always concrete. The dispatcher serialises these verbatim, so a
 * `null` reaches the REST endpoint as JSON null, which it reads as unset.
 *
 * @since 0.15.0
 */
export interface RegenerateTarget {
	readonly fullWidth: number | null;
	readonly fullQuality: number | null;
	readonly thumbnailWidth: number | null;
	readonly thumbnailQuality: number;
}

/**
 * The function that dispatches one regenerate request and resolves its reply.
 *
 * Injected so the driver is pure: `regenerate.ts` binds it to a real `fetch`
 * against the collection's REST route with the nonce, while a test binds a fake
 * that records calls and returns canned replies.
 *
 * @since 0.15.0
 */
export type RegenerateDispatcher = (
	body: Record< string, unknown >
) => Promise< RegenerateResponse >;

/**
 * The sink the driver reports cursor progress to.
 *
 * Called once per settled batch with the number of mains processed so far and the
 * collection's total, so the caller can drive the shared "N / total" progress bar.
 *
 * @since 0.15.0
 */
export type RegenerateProgress = ( processed: number, total: number ) => void;

/**
 * The outcome of a whole regenerate run.
 *
 * @since 0.15.0
 */
export interface RegenerateOutcome {
	readonly ok: boolean;
}

/**
 * The upper bound on batch iterations, a guard against a server that never
 * reports `done` (a bug or a hostile reply) spinning the loop forever.
 *
 * @since 0.15.0
 */
const MAX_BATCHES = 100_000;

/**
 * Drives the batched re-derive to completion, then finalises the flip.
 *
 * Dispatches a batch per cursor index — each carrying the target widths — until the
 * server reports `done`, recording one processed unit per settled batch (capped at
 * the reported total so an empty collection records nothing past zero), then
 * dispatches the finalise that flips the descriptor and prunes the old buckets. A
 * batch whose reply is not `ok` (transport or HTTP failure) aborts the run
 * immediately and the finalise is never dispatched, so a partial run leaves the old
 * descriptor active and is safely re-runnable. An empty collection (`total` 0)
 * still finalises, since a no-image collection is a valid flip target.
 *
 * @since 0.15.0
 *
 * @param dispatch   - The request dispatcher.
 * @param target     - The four target rendition values.
 * @param onProgress - The cursor-progress sink.
 * @return The run outcome (`ok` false when any batch or the finalise failed).
 */
export async function runRegeneration(
	dispatch: RegenerateDispatcher,
	target: RegenerateTarget,
	onProgress: RegenerateProgress
): Promise< RegenerateOutcome > {
	// Walk the batch cursor: dispatch one main per index, reporting progress, until
	// the server says the collection is done or a batch fails.
	let index = 0;
	for ( ; index < MAX_BATCHES; index += 1 ) {
		const batch = await dispatch( { ...target, index } );
		if ( ! batch.ok ) {
			return { ok: false };
		}
		const total = batch.total ?? 0;
		onProgress( Math.min( index + 1, total ), total );
		if ( batch.done ) {
			break;
		}
	}

	// Every main is re-derived: finalise the flip and prune. Only a successful
	// finalise reports the run as ok, since that is the moment the gallery switches.
	const final = await dispatch( { ...target, finalize: true } );
	return { ok: final.ok };
}
