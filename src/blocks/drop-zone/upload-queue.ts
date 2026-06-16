/**
 * The bounded, cancellable Drop Zone upload queue — STUB (RED).
 *
 * Inert so the failing tests fail on real assertions, not an import error.
 * Replaced by the real queue in the GREEN step.
 *
 * @since 0.11.0
 */

/**
 * One file queued for upload, paired with the relative path to send for it.
 *
 * @since 0.11.0
 */
export interface QueuedFile {
	readonly file: File;
	readonly relativePath: string;
}

/**
 * A handle on one in-flight upload — the queue holds it to abort on Cancel.
 *
 * @since 0.11.0
 */
export interface UploadHandle {
	/** Aborts the in-flight request; the settle promise still resolves. */
	readonly abort: () => void;
	/** Resolves when the file has settled (uploaded, skipped, or failed). */
	readonly settled: Promise< void >;
}

/**
 * The queue's external interface — enqueue, retry a failed set, cancel.
 *
 * @since 0.11.0
 */
export interface UploadQueue {
	enqueue( files: readonly QueuedFile[] ): void;
	retry( files: readonly QueuedFile[] ): void;
	cancel(): void;
}

/**
 * Creates the upload queue — STUB (RED).
 *
 * @since 0.11.0
 *
 * @return An inert queue that uploads nothing.
 */
export function createUploadQueue(
	_process: ( queued: QueuedFile ) => UploadHandle,
	_onBusy: ( busy: boolean ) => void
): UploadQueue {
	return {
		enqueue: () => undefined,
		retry: () => undefined,
		cancel: () => undefined,
	};
}
