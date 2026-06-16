/**
 * The Drop Zone progress/summary DOM view — STUB (RED).
 *
 * Inert so the failing tests fail on real assertions, not an import error.
 * Replaced by the real renderer in the GREEN step.
 *
 * @since 0.11.0
 */

import type { FailedFile, ProgressSnapshot } from './progress-model';

/**
 * The pre-translated strings the progress view renders.
 *
 * @since 0.11.0
 */
export interface ProgressStrings {
	readonly countTemplate: string;
	readonly cancel: string;
	readonly retryFailed: string;
	readonly summaryTemplate: string;
	readonly bucketUploaded: string;
	readonly bucketSkipped: string;
	readonly bucketFailed: string;
}

/**
 * The elements the view writes into.
 *
 * @since 0.11.0
 */
export interface ProgressElements {
	readonly progress: HTMLElement;
	readonly status: HTMLElement;
	readonly summary: HTMLElement;
}

/**
 * The callbacks the view fires from its Cancel and Retry controls.
 *
 * @since 0.11.0
 */
export interface ProgressCallbacks {
	readonly onCancel: () => void;
	readonly onRetry: ( failed: readonly FailedFile[] ) => void;
}

/**
 * The view's external interface — render the live bar, finalise the summary.
 *
 * @since 0.11.0
 */
export interface ProgressView {
	render( snapshot: ProgressSnapshot ): void;
	finalise( snapshot: ProgressSnapshot ): void;
}

/**
 * Creates the progress view — STUB (RED).
 *
 * @since 0.11.0
 *
 * @return An inert view that writes nothing.
 */
export function createProgressView(
	_elements: ProgressElements,
	_strings: ProgressStrings,
	_callbacks: ProgressCallbacks
): ProgressView {
	return {
		render: () => undefined,
		finalise: () => undefined,
	};
}
