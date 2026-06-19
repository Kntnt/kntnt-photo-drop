/**
 * Jest tests for the pure Drop Zone progress/bucket-accounting model.
 *
 * The model is the load-bearing pure core behind the aggregate progress bar,
 * the three-bucket summary, the Cancel finalisation, and the Retry-failed set
 * (issue #44). It owns no DOM and no upload state, so jsdom is not even needed:
 * these tests pin the accounting — processed/total, the uploaded count, the
 * skipped and failed filename lists, the retry move-between-buckets rule, and
 * the cancel-finalises-pending rule — over plain values.
 *
 * @since 0.15.0
 */

import { createProgressModel } from './progress-model';

describe( 'createProgressModel', () => {
	it( 'counts each distinct key once toward the total', () => {
		const model = createProgressModel();

		model.record( 'a.jpg', 'a.jpg', 'pending' );
		model.record( 'b.jpg', 'b.jpg', 'pending' );
		// Re-recording the same key is a transition, not a new file.
		model.record( 'a.jpg', 'a.jpg', 'uploaded' );

		expect( model.snapshot().total ).toBe( 2 );
	} );

	it( 'treats only terminal states as processed', () => {
		const model = createProgressModel();

		model.record( 'a.jpg', 'a.jpg', 'pending' );
		model.record( 'b.jpg', 'b.jpg', 'uploaded' );
		model.record( 'c.jpg', 'c.jpg', 'skipped' );
		model.record( 'd.jpg', 'd.jpg', 'failed' );

		const snapshot = model.snapshot();
		expect( snapshot.processed ).toBe( 3 );
		expect( snapshot.total ).toBe( 4 );
	} );

	it( 'reports the uploaded count and the skipped/failed filename lists', () => {
		const model = createProgressModel();

		model.record( 'trip/a.jpg', 'a.jpg', 'uploaded' );
		model.record( 'trip/b.jpg', 'b.jpg', 'uploaded' );
		model.record( 'trip/c.jpg', 'c.jpg', 'skipped' );
		model.record( 'trip/d.jpg', 'd.jpg', 'failed' );

		const snapshot = model.snapshot();
		expect( snapshot.uploaded ).toBe( 2 );
		expect( snapshot.skipped ).toEqual( [ 'c.jpg' ] );
		expect( snapshot.failed ).toEqual( [
			{ key: 'trip/d.jpg', fileName: 'd.jpg' },
		] );
	} );

	it( 'moves a retried file from failed to uploaded without double-counting', () => {
		const model = createProgressModel();

		model.record( 'a.jpg', 'a.jpg', 'failed' );
		expect( model.snapshot().failed ).toEqual( [
			{ key: 'a.jpg', fileName: 'a.jpg' },
		] );
		expect( model.snapshot().processed ).toBe( 1 );

		// The retry path re-runs the file: pending again, then uploaded.
		model.record( 'a.jpg', 'a.jpg', 'pending' );
		model.record( 'a.jpg', 'a.jpg', 'uploaded' );

		const snapshot = model.snapshot();
		expect( snapshot.failed ).toEqual( [] );
		expect( snapshot.uploaded ).toBe( 1 );
		expect( snapshot.processed ).toBe( 1 );
		expect( snapshot.total ).toBe( 1 );
	} );

	it( 'keeps the latest display name for a key (the server name on success)', () => {
		const model = createProgressModel();

		// The client keys by relative path but the server may answer with a
		// canonical display name; the later record wins.
		model.record( 'trip/raw', 'raw', 'pending' );
		model.record( 'trip/raw', 'raw.jpg.webp', 'failed' );

		expect( model.snapshot().failed ).toEqual( [
			{ key: 'trip/raw', fileName: 'raw.jpg.webp' },
		] );
	} );

	it( 'is complete only once every file has reached a terminal state', () => {
		const model = createProgressModel();

		expect( model.snapshot().complete ).toBe( false );

		model.record( 'a.jpg', 'a.jpg', 'pending' );
		model.record( 'b.jpg', 'b.jpg', 'uploaded' );
		expect( model.snapshot().complete ).toBe( false );

		model.record( 'a.jpg', 'a.jpg', 'uploaded' );
		expect( model.snapshot().complete ).toBe( true );
	} );

	it( 'reports an empty model as not complete', () => {
		const model = createProgressModel();

		const snapshot = model.snapshot();
		expect( snapshot.total ).toBe( 0 );
		expect( snapshot.processed ).toBe( 0 );
		expect( snapshot.complete ).toBe( false );
	} );

	describe( 'finalise (Cancel)', () => {
		it( 'drops still-pending files so the bar reads complete at cancel', () => {
			const model = createProgressModel();

			model.record( 'a.jpg', 'a.jpg', 'uploaded' );
			model.record( 'b.jpg', 'b.jpg', 'uploaded' );
			model.record( 'c.jpg', 'c.jpg', 'pending' );
			model.record( 'd.jpg', 'd.jpg', 'pending' );

			model.finalise();

			const snapshot = model.snapshot();
			expect( snapshot.total ).toBe( 2 );
			expect( snapshot.processed ).toBe( 2 );
			expect( snapshot.uploaded ).toBe( 2 );
			expect( snapshot.complete ).toBe( true );
		} );

		it( 'keeps already-settled buckets when finalising', () => {
			const model = createProgressModel();

			model.record( 'a.jpg', 'a.jpg', 'uploaded' );
			model.record( 'b.jpg', 'b.jpg', 'skipped' );
			model.record( 'c.jpg', 'c.jpg', 'failed' );
			model.record( 'd.jpg', 'd.jpg', 'pending' );

			model.finalise();

			const snapshot = model.snapshot();
			expect( snapshot.uploaded ).toBe( 1 );
			expect( snapshot.skipped ).toEqual( [ 'b.jpg' ] );
			expect( snapshot.failed ).toEqual( [
				{ key: 'c.jpg', fileName: 'c.jpg' },
			] );
			expect( snapshot.total ).toBe( 3 );
			expect( snapshot.complete ).toBe( true );
		} );

		it( 'lets a fresh file re-grow the total after a cancel', () => {
			const model = createProgressModel();

			model.record( 'a.jpg', 'a.jpg', 'pending' );
			model.finalise();
			expect( model.snapshot().total ).toBe( 0 );

			model.record( 'b.jpg', 'b.jpg', 'pending' );
			const snapshot = model.snapshot();
			expect( snapshot.total ).toBe( 1 );
			expect( snapshot.complete ).toBe( false );
		} );
	} );
} );
