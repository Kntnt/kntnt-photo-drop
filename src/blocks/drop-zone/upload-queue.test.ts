/**
 * Jest tests for the bounded, cancellable Drop Zone upload queue.
 *
 * The queue is the load-bearing wiring behind Cancel and Retry-failed
 * (issue #44): it bounds how many uploads overlap, dedupes a file by its
 * relative path so a duplicate never double-uploads, aborts every in-flight
 * upload and clears the pending backlog on Cancel (so the `beforeunload` guard
 * stands down and already-uploaded files persist), and re-queues exactly the
 * failed set on Retry even though those keys were seen before.
 *
 * The per-file processor is injected, so these tests drive the queue with a
 * controllable fake — no XHR, no Interactivity runtime, no DOM — and pin the
 * coordination the live `view.ts` wiring depends on.
 *
 * @since 0.11.0
 */

import {
	createUploadQueue,
	type QueuedFile,
	type UploadHandle,
} from './upload-queue';

/**
 * A controllable fake upload: records its abort and exposes a manual resolver.
 */
interface FakeUpload {
	readonly queued: QueuedFile;
	readonly handle: UploadHandle;
	aborted: boolean;
	settle: () => void;
}

/**
 * A processor double that hands back manually-settled uploads.
 *
 * Each `process` call records the queued file and returns a handle whose
 * `settled` promise resolves only when the test calls `settle()` — so the test
 * controls exactly how many uploads are in flight at any moment.
 */
function fakeProcessor(): {
	process: ( item: QueuedFile ) => UploadHandle;
	uploads: FakeUpload[];
} {
	const uploads: FakeUpload[] = [];

	const process = ( item: QueuedFile ): UploadHandle => {
		let resolve!: () => void;
		const settled = new Promise< void >( ( r ) => {
			resolve = r;
		} );
		const upload: FakeUpload = {
			queued: item,
			aborted: false,
			settle: resolve,
			handle: {
				abort: () => {
					upload.aborted = true;
				},
				settled,
			},
		};
		uploads.push( upload );
		return upload.handle;
	};

	return { process, uploads };
}

/**
 * Builds a queued file from a relative path (its own name as the file name).
 *
 * @param relativePath - The source-relative path that keys the file.
 * @return The queued file.
 */
function queued( relativePath: string ): QueuedFile {
	return {
		file: new File( [ 'x' ], relativePath ),
		relativePath,
	};
}

/**
 * Lets the microtask queue drain so `settled.finally(pump)` runs.
 */
async function flush(): Promise< void > {
	await Promise.resolve();
	await Promise.resolve();
}

describe( 'createUploadQueue', () => {
	it( 'starts at most the concurrency limit and pulls the next as slots free', async () => {
		const { process, uploads } = fakeProcessor();
		const queue = createUploadQueue( process, () => undefined );

		queue.enqueue( [
			queued( 'a' ),
			queued( 'b' ),
			queued( 'c' ),
			queued( 'd' ),
			queued( 'e' ),
		] );

		// Four slots, so the fifth waits until one of the first four settles.
		expect( uploads ).toHaveLength( 4 );

		uploads[ 0 ]?.settle();
		await flush();
		expect( uploads ).toHaveLength( 5 );
	} );

	it( 'dedupes a file by relative path, never uploading it twice', () => {
		const { process, uploads } = fakeProcessor();
		const queue = createUploadQueue( process, () => undefined );

		queue.enqueue( [ queued( 'trip/a.jpg' ) ] );
		queue.enqueue( [ queued( 'trip/a.jpg' ) ] );

		expect( uploads ).toHaveLength( 1 );
	} );

	it( 'reports busy as the batch starts and idle once it drains', async () => {
		const { process, uploads } = fakeProcessor();
		const states: boolean[] = [];
		const queue = createUploadQueue( process, ( busy ) =>
			states.push( busy )
		);

		queue.enqueue( [ queued( 'a' ), queued( 'b' ) ] );
		expect( states ).toEqual( [ true ] );

		uploads[ 0 ]?.settle();
		uploads[ 1 ]?.settle();
		await flush();
		expect( states ).toEqual( [ true, false ] );
	} );

	describe( 'cancel', () => {
		it( 'aborts every in-flight upload', () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			queue.enqueue( [ queued( 'a' ), queued( 'b' ) ] );
			queue.cancel();

			expect( uploads[ 0 ]?.aborted ).toBe( true );
			expect( uploads[ 1 ]?.aborted ).toBe( true );
		} );

		it( 'stops the queue so pending files never start', async () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			// Six files, four in flight, two pending behind them.
			queue.enqueue( [
				queued( 'a' ),
				queued( 'b' ),
				queued( 'c' ),
				queued( 'd' ),
				queued( 'e' ),
				queued( 'f' ),
			] );
			expect( uploads ).toHaveLength( 4 );

			queue.cancel();

			// Even as the aborted uploads settle, the two pending files must
			// never be handed to the processor.
			uploads.forEach( ( upload ) => upload.settle() );
			await flush();
			expect( uploads ).toHaveLength( 4 );
		} );

		it( 'clears the busy guard once the cancelled batch settles', async () => {
			const { process, uploads } = fakeProcessor();
			const states: boolean[] = [];
			const queue = createUploadQueue( process, ( busy ) =>
				states.push( busy )
			);

			queue.enqueue( [ queued( 'a' ), queued( 'b' ) ] );
			queue.cancel();
			uploads.forEach( ( upload ) => upload.settle() );
			await flush();

			expect( states[ states.length - 1 ] ).toBe( false );
		} );

		it( 're-arms the queue so a fresh batch after a cancel uploads', async () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			// Cancel the first batch: its in-flight upload aborts and settles, so
			// the queue drains to idle exactly as it would for a real Cancel.
			queue.enqueue( [ queued( 'a.jpg' ) ] );
			expect( uploads ).toHaveLength( 1 );
			queue.cancel();
			uploads.forEach( ( upload ) => upload.settle() );
			await flush();

			// A brand-new drag-drop / folder-pick after the cancel must upload, not
			// freeze at 0%: enqueue (not just retry) has to lift the cancel stop.
			queue.enqueue( [ queued( 'b.jpg' ) ] );
			expect( uploads ).toHaveLength( 2 );
			expect( uploads[ 1 ]?.queued.relativePath ).toBe( 'b.jpg' );
		} );
	} );

	describe( 'retry', () => {
		it( 're-queues a previously-failed file even though its key was seen', async () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			// First pass: the file is processed once and settles (as a failure,
			// from the queue's point of view it just settled).
			queue.enqueue( [ queued( 'a.jpg' ) ] );
			expect( uploads ).toHaveLength( 1 );
			uploads[ 0 ]?.settle();
			await flush();

			// Retry must run it again — the dedup set must not swallow it.
			queue.retry( [ queued( 'a.jpg' ) ] );
			expect( uploads ).toHaveLength( 2 );
		} );

		it( 'needs retry, not enqueue, to re-run a settled key — enqueue is swallowed', async () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			// First pass: the file uploads once and settles (a failure).
			queue.enqueue( [ queued( 'a.jpg' ) ] );
			expect( uploads ).toHaveLength( 1 );
			uploads[ 0 ]?.settle();
			await flush();

			// A plain enqueue of the same key is swallowed by the dedup set — this
			// is exactly why the Drop Zone's Retry must route through retry: an
			// enqueue-based retry would silently upload nothing.
			queue.enqueue( [ queued( 'a.jpg' ) ] );
			await flush();
			expect( uploads ).toHaveLength( 1 );

			// retry re-admits the already-seen key, so the failure actually re-runs.
			queue.retry( [ queued( 'a.jpg' ) ] );
			await flush();
			expect( uploads ).toHaveLength( 2 );
		} );

		it( 're-queues only the files handed to it, not the whole batch', async () => {
			const { process, uploads } = fakeProcessor();
			const queue = createUploadQueue( process, () => undefined );

			queue.enqueue( [ queued( 'a.jpg' ), queued( 'b.jpg' ) ] );
			uploads.forEach( ( upload ) => upload.settle() );
			await flush();

			// Only 'a.jpg' failed; retry it alone.
			queue.retry( [ queued( 'a.jpg' ) ] );
			await flush();

			const retried = uploads
				.slice( 2 )
				.map( ( u ) => u.queued.relativePath );
			expect( retried ).toEqual( [ 'a.jpg' ] );
		} );
	} );
} );
