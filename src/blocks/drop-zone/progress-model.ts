/**
 * The pure Drop Zone progress/bucket-accounting model — STUB (RED).
 *
 * Deliberately inert so the failing tests fail on real assertions, not an
 * import error. Replaced by the real accounting in the GREEN step.
 *
 * @since 0.11.0
 */

/**
 * The state a tracked file can be in.
 *
 * @since 0.11.0
 */
export type FileState = 'pending' | 'uploaded' | 'skipped' | 'failed';

/**
 * A failed file, carrying enough to re-queue it on Retry.
 *
 * @since 0.11.0
 */
export interface FailedFile {
	readonly key: string;
	readonly fileName: string;
}

/**
 * An immutable read of the accounting at one moment.
 *
 * @since 0.11.0
 */
export interface ProgressSnapshot {
	readonly total: number;
	readonly processed: number;
	readonly uploaded: number;
	readonly skipped: readonly string[];
	readonly failed: readonly FailedFile[];
	readonly complete: boolean;
}

/**
 * The model's external interface.
 *
 * @since 0.11.0
 */
export interface ProgressModel {
	record( key: string, fileName: string, state: FileState ): void;
	finalise(): void;
	snapshot(): ProgressSnapshot;
}

/**
 * Creates the progress model — STUB (RED).
 *
 * @since 0.11.0
 *
 * @return An inert model whose snapshot is always empty.
 */
export function createProgressModel(): ProgressModel {
	return {
		record: () => undefined,
		finalise: () => undefined,
		snapshot: () => ( {
			total: 0,
			processed: 0,
			uploaded: 0,
			skipped: [],
			failed: [],
			complete: false,
		} ),
	};
}
