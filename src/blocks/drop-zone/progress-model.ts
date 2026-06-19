/**
 * The pure Drop Zone progress/bucket-accounting model.
 *
 * A several-hundred-file batch needs an aggregate read, not an append-only
 * log: this model tracks one entry per file — keyed by the file's relative
 * path — and reduces them to the numbers the UI projects (issue #44). The
 * progress bar reads `processed`/`total`; the three-bucket summary reads the
 * `uploaded` count and the `skipped`/`failed` filename lists; the Retry-failed
 * button re-queues exactly the `failed` keys; and `complete` gates the switch
 * from the live bar to the final summary.
 *
 * Each `record` is a *transition*, not an append: re-recording a key replaces
 * its state and display name, so a retried file moves from `failed` into
 * `uploaded` without being counted twice, and the server's canonical name
 * overwrites the client's relative-path-derived one. `finalise` is the Cancel
 * primitive — it drops every still-pending entry so the bar reads complete at
 * the cancel point and the summary shows exactly the files that settled, while
 * the already-terminal buckets (uploaded / skipped / failed) are untouched.
 *
 * The model owns no DOM and no upload state and takes no collaborators, so it
 * is a deep, narrow seam that Jest covers entirely over plain values.
 *
 * @since 0.15.0
 */

/**
 * The state a tracked file can be in.
 *
 * `pending` covers every transient phase (queued, converting, uploading) and
 * is excluded from `processed`; the three terminal states are counted.
 * `skipped` covers both server-side duplicates and the client type pre-filter.
 *
 * @since 0.15.0
 */
export type FileState = 'pending' | 'uploaded' | 'skipped' | 'failed';

/**
 * A failed file, carrying enough to re-queue it on Retry.
 *
 * The `key` is the file's relative path (what the upload queue dedupes and
 * keys by); the `fileName` is the latest display name for the row.
 *
 * @since 0.15.0
 */
export interface FailedFile {
	readonly key: string;
	readonly fileName: string;
}

/**
 * An immutable read of the accounting at one moment.
 *
 * @since 0.15.0
 */
export interface ProgressSnapshot {
	/** Distinct files currently tracked. */
	readonly total: number;
	/** Files in a terminal state (uploaded + skipped + failed). */
	readonly processed: number;
	/** Count of uploaded files (the summary shows a number, not names). */
	readonly uploaded: number;
	/** Skipped filenames, in first-seen order. */
	readonly skipped: readonly string[];
	/** Failed files (key + latest name), in first-seen order, for Retry. */
	readonly failed: readonly FailedFile[];
	/** True once every tracked file has settled and at least one exists. */
	readonly complete: boolean;
}

/**
 * The model's external interface — record transitions, finalise, read.
 *
 * @since 0.15.0
 */
export interface ProgressModel {
	/**
	 * Records a file's current state, creating or transitioning its entry.
	 *
	 * @param key      - The stable per-file key (the relative path).
	 * @param fileName - The display name; the latest record for a key wins.
	 * @param state    - The file's current state.
	 */
	record: ( key: string, fileName: string, state: FileState ) => void;

	/**
	 * Drops every still-pending file so the accounting reflects a cancel.
	 *
	 * Terminal entries (uploaded / skipped / failed) are kept; only entries
	 * still `pending` — queued or in-flight when Cancel was pressed — are
	 * removed, so the bar reads complete and the summary shows what settled.
	 */
	finalise: () => void;

	/**
	 * Returns an immutable read of the current accounting.
	 *
	 * @return The snapshot.
	 */
	snapshot: () => ProgressSnapshot;
}

/**
 * One tracked file's mutable entry.
 *
 * @since 0.15.0
 */
interface Entry {
	fileName: string;
	state: FileState;
}

/**
 * Creates a fresh progress model.
 *
 * The entry map preserves first-seen insertion order, which the skipped and
 * failed lists inherit, so the summary reads in the order files were queued.
 *
 * @since 0.15.0
 *
 * @return The progress model handle.
 */
export function createProgressModel(): ProgressModel {
	// One entry per key; Map iteration is insertion-ordered, which the
	// skipped/failed lists rely on for a stable, queue-order summary.
	const entries = new Map< string, Entry >();

	return {
		record: ( key, fileName, state ) => {
			const existing = entries.get( key );
			if ( existing ) {
				existing.fileName = fileName;
				existing.state = state;
				return;
			}
			entries.set( key, { fileName, state } );
		},

		finalise: () => {
			// Cancel keeps what settled and forgets what was still in flight,
			// so processed === total and no aborted file is mislabelled failed.
			for ( const [ key, entry ] of entries ) {
				if ( entry.state === 'pending' ) {
					entries.delete( key );
				}
			}
		},

		snapshot: () => {
			// Fold the entries into the projected numbers in one pass, keeping
			// the skipped/failed lists in the map's insertion order.
			let uploaded = 0;
			const skipped: string[] = [];
			const failed: FailedFile[] = [];
			for ( const [ key, entry ] of entries ) {
				if ( entry.state === 'uploaded' ) {
					uploaded += 1;
				} else if ( entry.state === 'skipped' ) {
					skipped.push( entry.fileName );
				} else if ( entry.state === 'failed' ) {
					failed.push( { key, fileName: entry.fileName } );
				}
			}

			const total = entries.size;
			const processed = uploaded + skipped.length + failed.length;

			return {
				total,
				processed,
				uploaded,
				skipped,
				failed,
				complete: total > 0 && processed === total,
			};
		},
	};
}
