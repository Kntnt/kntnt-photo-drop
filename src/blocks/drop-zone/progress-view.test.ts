/**
 * Jest tests for the Drop Zone progress/summary DOM view.
 *
 * The view projects a `ProgressSnapshot` from the bucket-accounting model
 * onto the three regions `Render_Drop_Zone` emits (issue #44): the `__progress`
 * aggregate bar with the summary-and-Cancel row beneath it, the `__status`
 * three-bucket result list, and the `__summary` live region. These tests pin,
 * in jsdom, the load-bearing DOM contract — the `role="progressbar"` plus
 * `aria-valuenow`/`aria-valuemax`, the "N of T processed" summary row that sits
 * directly below the bar with Cancel on the same row (issue #68; the earlier
 * "N / total" lower-corner counters are gone), the live-region announcement,
 * the Cancel wiring, and the three-bucket finalisation (uploaded as a count,
 * skipped and failed by filename, a Retry-failed button that fires exactly the
 * failed set, and no Retry button when nothing failed) — and that every
 * filename is written as inert text.
 *
 * @since 0.11.0
 */

import { createProgressModel } from './progress-model';
import {
	createProgressView,
	type ProgressCallbacks,
	type ProgressElements,
	type ProgressStrings,
} from './progress-view';
import type { FailedFile } from './progress-model';

/**
 * The pre-translated string fixtures mirroring the server-translated strings.
 */
const STRINGS: ProgressStrings = {
	cancel: 'Cancel',
	retryFailed: 'Retry failed',
	summaryTemplate: '{processed} of {total} processed',
	bucketUploaded: '{uploaded} uploaded',
	bucketSkipped: 'Skipped',
	bucketFailed: 'Failed',
};

/**
 * Builds the three regions plus the view over them, capturing the callbacks.
 *
 * @return The elements, the callback spies, and the view under test.
 */
function freshView(): {
	elements: ProgressElements;
	cancelled: number;
	retried: FailedFile[][];
	view: ReturnType< typeof createProgressView >;
} {
	const elements: ProgressElements = {
		progress: document.createElement( 'div' ),
		status: document.createElement( 'div' ),
		summary: document.createElement( 'p' ),
	};
	const record = { cancelled: 0, retried: [] as FailedFile[][] };
	const callbacks: ProgressCallbacks = {
		onCancel: () => {
			record.cancelled += 1;
		},
		onRetry: ( failed ) => {
			record.retried.push( [ ...failed ] );
		},
	};

	return {
		elements,
		get cancelled() {
			return record.cancelled;
		},
		retried: record.retried,
		view: createProgressView( elements, STRINGS, callbacks ),
	};
}

describe( 'createProgressView', () => {
	describe( 'render (live)', () => {
		it( 'marks the bar as a progressbar with aria-valuenow/valuemax', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'uploaded' );
			model.record( 'b', 'b', 'pending' );
			model.record( 'c', 'c', 'pending' );

			view.render( model.snapshot() );

			const bar = elements.progress.querySelector(
				'[role="progressbar"]'
			);
			expect( bar ).not.toBeNull();
			expect( bar?.getAttribute( 'aria-valuenow' ) ).toBe( '1' );
			expect( bar?.getAttribute( 'aria-valuemax' ) ).toBe( '3' );
		} );

		it( 'shows the "N of T processed" summary directly below the bar', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'uploaded' );
			model.record( 'b', 'b', 'uploaded' );
			model.record( 'c', 'c', 'pending' );
			model.record( 'd', 'd', 'pending' );

			view.render( model.snapshot() );

			// The summary row carries "2 of 4 processed" and sits immediately
			// after the bar in document order, so it renders below it.
			const summary = elements.progress.querySelector(
				'.kntnt-photo-drop-drop-zone__progress-summary'
			);
			expect( summary?.textContent ).toContain( '2 of 4 processed' );
			const children = Array.from( elements.progress.children );
			const barIndex = children.findIndex( ( child ) =>
				child.matches( '[role="progressbar"]' )
			);
			const summaryIndex = children.indexOf( summary as Element );
			expect( barIndex ).toBeGreaterThanOrEqual( 0 );
			expect( summaryIndex ).toBe( barIndex + 1 );
		} );

		it( 'no longer renders the lower "N /" and "T" corner counters', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'uploaded' );
			model.record( 'b', 'b', 'uploaded' );
			model.record( 'c', 'c', 'pending' );
			model.record( 'd', 'd', 'pending' );

			view.render( model.snapshot() );

			expect(
				elements.progress.querySelector(
					'.kntnt-photo-drop-drop-zone__progress-count'
				)
			).toBeNull();
			expect(
				elements.progress.querySelector(
					'.kntnt-photo-drop-drop-zone__progress-total'
				)
			).toBeNull();
			expect( elements.progress.textContent ).not.toContain( '2 / 4' );
		} );

		it( 'places Cancel on the same row as the summary', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'uploaded' );
			model.record( 'b', 'b', 'pending' );

			view.render( model.snapshot() );

			// Cancel is a child of the summary row, not a loose sibling of the
			// bar, so the "N of T processed" text and Cancel share one row.
			const summaryRow = elements.progress.querySelector(
				'.kntnt-photo-drop-drop-zone__progress-summary'
			);
			const cancel = elements.progress.querySelector(
				'.kntnt-photo-drop-drop-zone__cancel'
			);
			expect( cancel ).not.toBeNull();
			expect( summaryRow?.contains( cancel ) ).toBe( true );
		} );

		it( 'announces progress in the live summary region', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'uploaded' );
			model.record( 'b', 'b', 'pending' );

			view.render( model.snapshot() );

			expect( elements.summary.textContent ).toBe( '1 of 2 processed' );
		} );

		it( 'renders a Cancel button that fires onCancel', () => {
			const view = freshView();
			const model = createProgressModel();
			model.record( 'a', 'a', 'pending' );

			view.view.render( model.snapshot() );

			const cancel = view.elements.progress.querySelector( 'button' );
			expect( cancel?.textContent ).toBe( 'Cancel' );
			cancel?.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
			expect( view.cancelled ).toBe( 1 );
		} );
	} );

	describe( 'finalise (summary)', () => {
		it( 'reports the uploaded count and lists skipped and failed by name', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'trip/a.jpg', 'a.jpg', 'uploaded' );
			model.record( 'trip/b.jpg', 'b.jpg', 'uploaded' );
			model.record( 'trip/c.jpg', 'c.jpg', 'skipped' );
			model.record( 'trip/d.jpg', 'd.jpg', 'failed' );

			view.finalise( model.snapshot() );

			const text = elements.status.textContent ?? '';
			expect( text ).toContain( '2 uploaded' );
			expect( text ).toContain( 'c.jpg' );
			expect( text ).toContain( 'd.jpg' );
		} );

		it( 'renders a Retry-failed button that fires the failed set', () => {
			const view = freshView();
			const model = createProgressModel();
			model.record( 'trip/d.jpg', 'd.jpg', 'failed' );
			model.record( 'trip/e.jpg', 'e.jpg', 'failed' );

			view.view.finalise( model.snapshot() );

			const retry = Array.from(
				view.elements.status.querySelectorAll( 'button' )
			).find( ( b ) => b.textContent === 'Retry failed' );
			expect( retry ).toBeDefined();
			retry?.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
			expect( view.retried ).toEqual( [
				[
					{ key: 'trip/d.jpg', fileName: 'd.jpg' },
					{ key: 'trip/e.jpg', fileName: 'e.jpg' },
				],
			] );
		} );

		it( 'omits the Retry button when nothing failed', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a.jpg', 'a.jpg', 'uploaded' );
			model.record( 'b.jpg', 'b.jpg', 'skipped' );

			view.finalise( model.snapshot() );

			const retry = Array.from(
				elements.status.querySelectorAll( 'button' )
			).find( ( b ) => b.textContent === 'Retry failed' );
			expect( retry ).toBeUndefined();
		} );

		it( 'clears the live progress bar once finalised', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record( 'a.jpg', 'a.jpg', 'uploaded' );

			view.render( model.snapshot() );
			expect(
				elements.progress.querySelector( '[role="progressbar"]' )
			).not.toBeNull();

			view.finalise( model.snapshot() );
			expect(
				elements.progress.querySelector( '[role="progressbar"]' )
			).toBeNull();
		} );

		it( 'writes a hostile failed filename as inert text', () => {
			const { elements, view } = freshView();
			const model = createProgressModel();
			model.record(
				'evil',
				'<img src=x onerror=alert(1)>.jpg',
				'failed'
			);

			view.finalise( model.snapshot() );

			expect( elements.status.querySelector( 'img' ) ).toBeNull();
			expect( elements.status.textContent ).toContain( '<img' );
		} );
	} );
} );
