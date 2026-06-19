/**
 * The bounded, cancellable Drop Zone upload queue.
 *
 * A several-hundred-file batch must keep the link busy without opening a socket
 * per file, must let the photographer abandon it mid-flight without losing what
 * already uploaded, and must let them re-run only the files that failed. This
 * queue owns that coordination (issue #44): it holds at most
 * {@link MAX_CONCURRENT_UPLOADS} uploads in flight, pulls the next the moment a
 * slot frees, and dedupes each file by its source-relative path — the key the
 * server recreates sub-directories from — so a duplicate drop never
 * double-uploads. `cancel()` aborts every in-flight upload and clears the
 * pending backlog so the queue drains to idle (the `beforeunload` guard stands
 * down) while the already-uploaded files stay on disk; `retry()` re-queues
 * exactly the files handed to it, re-admitting keys the dedup set had already
 * seen.
 *
 * The per-file processor is injected as an {@link UploadHandle}-returning
 * function, so the queue owns no XHR, no DOM, and no Interactivity coupling —
 * a deep, narrow seam Jest covers with a controllable fake (the live wiring in
 * `view.ts` supplies the real `XMLHttpRequest`-based processor).
 *
 * @since 0.15.0
 */

/**
 * One file queued for upload, paired with the relative path to send for it.
 *
 * The relative path is the file's dedup key and the field the server recreates
 * sub-directories from; a loose file's path is its plain name.
 *
 * @since 0.15.0
 */
export interface QueuedFile {
	readonly file: File;
	readonly relativePath: string;
}

/**
 * A handle on one in-flight upload — the queue holds it to abort on Cancel.
 *
 * `abort()` cancels the underlying request; `settled` resolves once the file
 * has reached a terminal state (uploaded, skipped, or failed) — and, crucially,
 * resolves on an aborted upload too, so a cancelled batch still drains the
 * in-flight count to zero and the queue can report idle.
 *
 * @since 0.15.0
 */
export interface UploadHandle {
	readonly abort: () => void;
	readonly settled: Promise< void >;
}

/**
 * The queue's external interface — enqueue a batch, retry a failed set, cancel.
 *
 * @since 0.15.0
 */
export interface UploadQueue {
	/**
	 * Adds a batch, dropping any file whose relative path was already seen.
	 *
	 * @param files - The files to upload, each paired with its relative path.
	 */
	enqueue: ( files: readonly QueuedFile[] ) => void;

	/**
	 * Re-queues exactly the given files, re-admitting their already-seen keys.
	 *
	 * The Retry-failed path: a previously-settled file is run again, so the
	 * dedup set must not swallow it. A cancelled queue is reactivated.
	 *
	 * @param files - The failed files to run again.
	 */
	retry: ( files: readonly QueuedFile[] ) => void;

	/**
	 * Aborts every in-flight upload and clears the pending backlog.
	 *
	 * Already-uploaded files stay on disk; the queue drains to idle as the
	 * aborted uploads settle, so the caller's `beforeunload` guard stands down.
	 */
	cancel: () => void;
}

/**
 * The number of uploads allowed in flight at once.
 *
 * Each file is still one request; this only bounds how many of those requests
 * overlap. Four keeps a fast link busy through the per-file convert→encode
 * latency without opening a socket per file in a several-hundred-file batch.
 *
 * @since 0.15.0
 */
const MAX_CONCURRENT_UPLOADS = 4;

/**
 * Creates the bounded, cancellable upload queue over an injected processor.
 *
 * The `process` function is called once per file and returns the handle the
 * queue holds to abort the upload on Cancel; its `settled` promise drives the
 * concurrency accounting. The `onBusy` callback flips true as the first file of
 * a batch starts and false once the queue empties (including after a cancel),
 * so the caller can arm and disarm the `beforeunload` guard.
 *
 * @since 0.15.0
 *
 * @param process - Starts one upload and returns its abortable handle.
 * @param onBusy  - Called with the busy state as the queue starts and empties.
 * @return The queue handle.
 */
export function createUploadQueue(
	process: ( queued: QueuedFile ) => UploadHandle,
	onBusy: ( busy: boolean ) => void
): UploadQueue {
	// The pending backlog, the keys already admitted (for dedup), the handles
	// of the uploads currently in flight (for Cancel), and the two flags that
	// track whether a batch is draining and whether Cancel has stopped it.
	const pending: QueuedFile[] = [];
	const seen = new Set< string >();
	const inFlight = new Set< UploadHandle >();
	let draining = false;
	let cancelled = false;

	// Pull files into free slots until the limit is reached or the backlog is
	// empty; when both the backlog and the in-flight set are empty the batch is
	// done and the guard can stand down. A cancelled queue starts nothing.
	const pump = (): void => {
		while (
			! cancelled &&
			inFlight.size < MAX_CONCURRENT_UPLOADS &&
			pending.length > 0
		) {
			const next = pending.shift();
			if ( ! next ) {
				break;
			}
			const handle = process( next );
			inFlight.add( handle );
			void handle.settled.finally( () => {
				inFlight.delete( handle );
				if ( pending.length === 0 && inFlight.size === 0 ) {
					draining = false;
					onBusy( false );
				} else {
					pump();
				}
			} );
		}
	};

	// Admit a batch: drop any key already seen this mount (a duplicate would
	// double-upload the same bytes), then push the survivors and start draining
	// if the queue was idle. Used by both enqueue and retry, which differ only
	// in whether they pre-clear the dedup set.
	const admit = ( files: readonly QueuedFile[] ): void => {
		const fresh = files.filter( ( queued ) => {
			if ( seen.has( queued.relativePath ) ) {
				return false;
			}
			seen.add( queued.relativePath );
			return true;
		} );
		if ( fresh.length === 0 ) {
			return;
		}

		// Lift any prior Cancel stop before pumping: a new batch with real work —
		// whether a fresh drag-drop via enqueue or a Retry — re-arms the queue,
		// the mirror of the cancel that drained it to idle. Without this, pump()
		// stays gated off after a Cancel and the fresh batch freezes at 0% until a
		// reload. An all-duplicate enqueue returns above, so it never re-arms.
		cancelled = false;

		pending.push( ...fresh );
		if ( ! draining ) {
			draining = true;
			onBusy( true );
		}
		pump();
	};

	return {
		enqueue: ( files ) => admit( files ),

		retry: ( files ) => {
			// Re-admit the failed keys so the dedup set does not reject these
			// already-seen files — exactly what Retry must override; admit() then
			// lifts the cancel stop and pumps.
			for ( const queued of files ) {
				seen.delete( queued.relativePath );
			}
			admit( files );
		},

		cancel: () => {
			// Stop the pump, forget the backlog, and abort every in-flight
			// upload; the aborted uploads still resolve their settled promise,
			// so the finally-handler drains the in-flight count to zero and
			// fires onBusy(false). Already-uploaded files are untouched on disk.
			cancelled = true;
			pending.length = 0;
			for ( const handle of inFlight ) {
				handle.abort();
			}
		},
	};
}
