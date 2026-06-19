/**
 * The Drop Zone progress/summary DOM view.
 *
 * A several-hundred-file batch needs an aggregate read, not a per-file line
 * list: this view projects a {@link ProgressSnapshot} from the bucket-accounting
 * model onto the three regions `Render_Drop_Zone` emits (issue #44). While the
 * batch runs, `render` draws the `__progress` aggregate bar — a
 * `role="progressbar"` element carrying `aria-valuenow`/`aria-valuemax` and a
 * "processed / total" read-out (the total sits at the far right) — with a Cancel
 * button beside it, and writes a terse progress line into the `__summary` live
 * region so assistive tech is told without the chatter a per-file list would
 * cause. When the batch settles (or is cancelled), `finalise` swaps the live bar
 * for the three-bucket summary in `__status`: the uploaded count as a number,
 * the skipped and failed files listed by name, and — only when something failed
 * — a Retry-failed button that re-queues exactly the failures.
 *
 * Every filename is written through `textContent`, never `innerHTML`, so a
 * hostile filename cannot inject markup. The view owns no upload state and takes
 * plain elements and callbacks, so Jest covers it in jsdom alone.
 *
 * @since 0.15.0
 */

import type { FailedFile, ProgressSnapshot } from './progress-model';

/**
 * The pre-translated strings the progress view renders.
 *
 * View-script modules cannot import `@wordpress/i18n`, so every runtime string
 * is translated server-side and handed across the Interactivity context. The
 * `summaryTemplate` carries `{processed}`/`{total}` tokens; `bucketUploaded`
 * carries an `{uploaded}` token.
 *
 * @since 0.15.0
 */
export interface ProgressStrings {
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
 * The `progress` region holds the live bar and Cancel; the `status` region
 * holds the final three-bucket summary and Retry; the `summary` region is the
 * single `aria-live` announcer.
 *
 * @since 0.15.0
 */
export interface ProgressElements {
	readonly progress: HTMLElement;
	readonly status: HTMLElement;
	readonly summary: HTMLElement;
}

/**
 * The callbacks the view fires from its Cancel and Retry controls.
 *
 * @since 0.15.0
 */
export interface ProgressCallbacks {
	readonly onCancel: () => void;
	readonly onRetry: ( failed: readonly FailedFile[] ) => void;
}

/**
 * The view's external interface — render the live bar, finalise the summary.
 *
 * @since 0.15.0
 */
export interface ProgressView {
	/**
	 * Draws the live aggregate bar and Cancel control and announces progress.
	 *
	 * @param snapshot - The current accounting from the progress model.
	 */
	render: ( snapshot: ProgressSnapshot ) => void;

	/**
	 * Swaps the live bar for the final three-bucket summary and Retry control.
	 *
	 * @param snapshot - The settled (or cancelled) accounting.
	 */
	finalise: ( snapshot: ProgressSnapshot ) => void;
}

/**
 * The default block-element class that roots the stylesheet targets.
 *
 * The Drop Zone is the original consumer, so its block-element class is the
 * default; the admin regenerate UI passes its own class
 * (`kntnt-photo-drop-regenerate`) so the same view drives a second surface without
 * the logic being duplicated (ADR-0013).
 *
 * @since 0.15.0
 */
const DEFAULT_BLOCK = 'kntnt-photo-drop-drop-zone';

/**
 * Fills a `{processed}`/`{total}` template with the snapshot's counts.
 *
 * @param template - The pre-translated template carrying the two tokens.
 * @param snapshot - The accounting to read the counts from.
 * @return The filled string.
 */
function fillCounts( template: string, snapshot: ProgressSnapshot ): string {
	return template
		.replace( '{processed}', String( snapshot.processed ) )
		.replace( '{total}', String( snapshot.total ) );
}

/**
 * Builds a labelled list block for one summary bucket.
 *
 * Returns a section carrying a heading and one `<li>` per filename, each written
 * as inert text. An empty bucket yields no element so the summary shows only the
 * buckets that have members.
 *
 * @param block    - The block-element class prefix the section's classes are rooted on.
 * @param modifier - The bucket name, used as the element-class modifier.
 * @param heading  - The pre-translated bucket heading.
 * @param names    - The filenames in the bucket, in queue order.
 * @return The section element, or null when the bucket is empty.
 */
function bucketSection(
	block: string,
	modifier: string,
	heading: string,
	names: readonly string[]
): HTMLElement | null {
	if ( names.length === 0 ) {
		return null;
	}

	// Heading plus a plain list; every name is set via textContent so a hostile
	// filename lands as inert text, never markup.
	const section = document.createElement( 'div' );
	section.className = `${ block }__bucket ${ block }__bucket--${ modifier }`;
	const title = document.createElement( 'p' );
	title.className = `${ block }__bucket-heading`;
	title.textContent = heading;
	const list = document.createElement( 'ul' );
	for ( const name of names ) {
		const item = document.createElement( 'li' );
		item.textContent = name;
		list.appendChild( item );
	}
	section.append( title, list );

	return section;
}

/**
 * Creates the progress view over the given regions, strings, and callbacks.
 *
 * The `blockClass` parameter lets a second surface reuse this view without
 * duplicating its DOM logic: the Drop Zone keeps the default block-element class,
 * while the admin regenerate UI passes `kntnt-photo-drop-regenerate` so the bar,
 * the read-out, the buckets, and the buttons carry that surface's classes instead
 * (ADR-0013).
 *
 * @since 0.15.0
 *
 * @param elements   - The three regions the view writes into.
 * @param strings    - The pre-translated strings.
 * @param callbacks  - The Cancel and Retry handlers.
 * @param blockClass - The block-element class the rendered classes are rooted on.
 * @return The progress view handle.
 */
export function createProgressView(
	elements: ProgressElements,
	strings: ProgressStrings,
	callbacks: ProgressCallbacks,
	blockClass: string = DEFAULT_BLOCK
): ProgressView {
	// Root every rendered class on the caller's block-element prefix so one view
	// serves the Drop Zone and the admin regenerate surface alike.
	const BLOCK = blockClass;

	return {
		render: ( snapshot ) => {
			// Redraw the bar from scratch each tick: a progressbar element
			// carrying the ARIA value attributes and a fill whose width tracks
			// the ratio.
			elements.progress.replaceChildren();
			const bar = document.createElement( 'div' );
			bar.className = `${ BLOCK }__progress-bar`;
			bar.setAttribute( 'role', 'progressbar' );
			bar.setAttribute( 'aria-valuemin', '0' );
			bar.setAttribute( 'aria-valuemax', String( snapshot.total ) );
			bar.setAttribute( 'aria-valuenow', String( snapshot.processed ) );
			const fill = document.createElement( 'span' );
			fill.className = `${ BLOCK }__progress-fill`;
			const ratio =
				snapshot.total > 0 ? snapshot.processed / snapshot.total : 0;
			fill.style.width = `${ Math.round( ratio * 100 ) }%`;
			bar.appendChild( fill );

			// The summary row sits directly below the bar (issue #68): the
			// "N of T processed" read-out on the left and Cancel pushed to the
			// far right of the same row, so the batch size and the abort control
			// share one line and the earlier lower-corner counters are gone.
			const row = document.createElement( 'div' );
			row.className = `${ BLOCK }__progress-summary`;
			const text = document.createElement( 'p' );
			text.className = `${ BLOCK }__progress-text`;
			text.textContent = fillCounts( strings.summaryTemplate, snapshot );
			const cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = `${ BLOCK }__cancel`;
			cancel.textContent = strings.cancel;
			cancel.addEventListener( 'click', () => callbacks.onCancel() );
			row.append( text, cancel );

			// Mount the bar and its summary row, and announce the same progress
			// line through the dedicated aria-live region so assistive tech is
			// told without the chatter a per-file list would cause.
			elements.progress.append( bar, row );
			elements.summary.textContent = fillCounts(
				strings.summaryTemplate,
				snapshot
			);
		},

		finalise: ( snapshot ) => {
			// The live phase is over: tear down the bar and Cancel, and clear
			// the announcer so it does not repeat a stale progress line.
			elements.progress.replaceChildren();
			elements.summary.textContent = '';

			// The three-bucket summary: the uploaded count as a number, then the
			// skipped and failed files listed by name (empty buckets are
			// omitted), all written as inert text.
			elements.status.replaceChildren();
			const uploaded = document.createElement( 'p' );
			uploaded.className = `${ BLOCK }__bucket ${ BLOCK }__bucket--uploaded`;
			uploaded.textContent = strings.bucketUploaded.replace(
				'{uploaded}',
				String( snapshot.uploaded )
			);
			elements.status.appendChild( uploaded );
			const skipped = bucketSection(
				BLOCK,
				'skipped',
				strings.bucketSkipped,
				snapshot.skipped
			);
			if ( skipped ) {
				elements.status.appendChild( skipped );
			}
			const failedNames = snapshot.failed.map(
				( file ) => file.fileName
			);
			const failed = bucketSection(
				BLOCK,
				'failed',
				strings.bucketFailed,
				failedNames
			);
			if ( failed ) {
				elements.status.appendChild( failed );
			}

			// Retry-failed re-queues exactly the failures; offer it only when
			// there is something to retry.
			if ( snapshot.failed.length > 0 ) {
				const retry = document.createElement( 'button' );
				retry.type = 'button';
				retry.className = `${ BLOCK }__retry`;
				retry.textContent = strings.retryFailed;
				retry.addEventListener( 'click', () =>
					callbacks.onRetry( snapshot.failed )
				);
				elements.status.appendChild( retry );
			}
		},
	};
}
