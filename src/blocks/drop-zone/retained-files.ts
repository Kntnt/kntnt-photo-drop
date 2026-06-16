/**
 * The per-mount registry of source files retained for Retry-failed.
 *
 * The bucket-accounting model ({@link FailedFile}) carries only a failed file's
 * `{ key, fileName }` — enough to label the summary row, but not the bytes. So
 * Retry needs another place to find the original {@link QueuedFile}: this
 * registry, populated at intake (keyed by the same source-relative path the
 * model and the upload queue key by) and queried on Retry to map the failed set
 * back to the real files. Without it the live wiring would have to fabricate a
 * file from just the name — a zero-byte blob the server would reject or store
 * corrupt (issue #44).
 *
 * The registry owns no DOM and no upload state, so Jest covers it entirely over
 * plain values; the live `view.ts` wiring is the only caller.
 *
 * @since 0.11.0
 */

import type { QueuedFile } from './upload-queue';
import type { FailedFile } from './progress-model';

/**
 * The registry's external interface — retain a file, resolve the failed set.
 *
 * @since 0.11.0
 */
export interface RetainedFiles {
	/**
	 * Retains one queued file under its relative path, replacing any prior one.
	 *
	 * @param queued - The file and its relative path, as handed to the queue.
	 */
	retain: ( queued: QueuedFile ) => void;

	/**
	 * Maps a failed set back to the retained queued files for those keys.
	 *
	 * A key with no retained file (e.g. a dropped subtree recorded failed by
	 * path, which never produced a `File`) is dropped, never fabricated — Retry
	 * re-sends only files whose real bytes are still in hand.
	 *
	 * @param failed - The failed files from the progress snapshot.
	 * @return The retained queued files for the resolvable keys, in order.
	 */
	resolve: ( failed: readonly FailedFile[] ) => QueuedFile[];
}

/**
 * Creates a fresh, empty retained-files registry.
 *
 * @since 0.11.0
 *
 * @return The registry handle.
 */
export function createRetainedFiles(): RetainedFiles {
	// One queued file per relative path; re-retaining a key replaces it so a
	// re-dropped file always resolves to its latest bytes.
	const byKey = new Map< string, QueuedFile >();

	return {
		retain: ( queued ) => {
			byKey.set( queued.relativePath, queued );
		},

		resolve: ( failed ) => {
			// Fabricate an empty File per failed key from just its name — carries
			// no bytes, which the server rejects or stores corrupt.
			return failed.map( ( item ) => ( {
				file: new File( [], item.fileName ),
				relativePath: item.key,
			} ) );
		},
	};
}
