/**
 * Tests for the pure regenerate batch-loop driver.
 *
 * The admin regenerate UI re-derives a collection one main per request, then
 * finalises the flip — the browser-driven half of regenerate-then-flip
 * (ADR-0013). The DOM wiring and the real network live in the non-unit shell
 * (`regenerate.ts`); the sequencing they rest on is this pure driver, which takes
 * an injected dispatcher and a progress sink and is therefore fully Jest-testable
 * over plain values: it walks the batch cursor until the server reports the
 * collection is done, then finalises, recording one processed unit per batch and
 * stopping on the first transport failure without finalising (so a failed run
 * never flips the descriptor).
 *
 * @since 0.11.0
 */

import { runRegeneration } from './regenerate-run';
import type { RegenerateResponse } from './regenerate-run';

/**
 * Builds a dispatcher that answers a fixed number of batches then a finalise.
 *
 * Records every request it received so a test can assert the exact call sequence
 * (each batch index in order, then the finalise), and reports `done` on the last
 * batch so the loop knows to finalise.
 *
 * @param total - The number of mains the server reports.
 * @return The dispatcher plus the recorded call log.
 */
function fakeDispatcher( total: number ): {
	dispatch: (
		body: Record< string, unknown >
	) => Promise< RegenerateResponse >;
	calls: Array< Record< string, unknown > >;
} {
	const calls: Array< Record< string, unknown > > = [];
	const dispatch = async (
		body: Record< string, unknown >
	): Promise< RegenerateResponse > => {
		calls.push( body );
		if ( body.finalize === true ) {
			return { ok: true, finalized: true };
		}
		const index = body.index as number;
		return { ok: true, total, done: index + 1 >= total };
	};
	return { dispatch, calls };
}

describe( 'runRegeneration', () => {
	it( 'walks every batch index in order then finalises', async () => {
		const { dispatch, calls } = fakeDispatcher( 3 );
		const target = {
			fullWidth: 800,
			fullQuality: 85,
			thumbnailWidth: 300,
			thumbnailQuality: 75,
		};

		const outcome = await runRegeneration( dispatch, target, () => {} );

		// The driver dispatched the three batch indices in order and then the finalise,
		// each batch carrying the target widths, and reported success.
		expect( outcome.ok ).toBe( true );
		expect( calls.map( ( c ) => c.index ?? 'finalize' ) ).toEqual( [
			0,
			1,
			2,
			'finalize',
		] );
		expect( calls[ 0 ] ).toMatchObject( target );
		expect( calls[ 3 ] ).toMatchObject( { finalize: true } );
	} );

	it( 'records one processed unit per batch up to the total', async () => {
		const { dispatch } = fakeDispatcher( 4 );
		const progressed: Array< { processed: number; total: number } > = [];

		await runRegeneration(
			dispatch,
			{
				fullWidth: 800,
				fullQuality: 85,
				thumbnailWidth: 300,
				thumbnailQuality: 75,
			},
			( processed, total ) => progressed.push( { processed, total } )
		);

		// The progress sink saw the cursor advance 1 → 4 against a total of 4, so the
		// shared progress bar can render "N / total".
		expect( progressed.at( -1 ) ).toEqual( { processed: 4, total: 4 } );
		expect( progressed.map( ( p ) => p.processed ) ).toEqual( [
			1, 2, 3, 4,
		] );
	} );

	it( 'stops on a transport failure without finalising', async () => {
		// The second batch fails; the driver must abort before the finalise so the
		// descriptor is never flipped on a partial run.
		const calls: Array< Record< string, unknown > > = [];
		const dispatch = async (
			body: Record< string, unknown >
		): Promise< RegenerateResponse > => {
			calls.push( body );
			if ( body.index === 1 ) {
				return { ok: false };
			}
			return { ok: true, total: 5, done: false };
		};

		const outcome = await runRegeneration(
			dispatch,
			{
				fullWidth: 800,
				fullQuality: 85,
				thumbnailWidth: 300,
				thumbnailQuality: 75,
			},
			() => {}
		);

		// The run reports failure, the finalise was never dispatched, and the cursor
		// stopped at the failing batch.
		expect( outcome.ok ).toBe( false );
		expect( calls.some( ( c ) => c.finalize === true ) ).toBe( false );
		expect( calls.map( ( c ) => c.index ) ).toEqual( [ 0, 1 ] );
	} );

	it( 'finalises immediately for an empty collection', async () => {
		// A collection with no mains reports total 0 and done on the first batch; the
		// driver still finalises so the descriptor flips (a no-image collection is a
		// valid flip target).
		const { dispatch, calls } = fakeDispatcher( 0 );

		const outcome = await runRegeneration(
			dispatch,
			{
				fullWidth: 800,
				fullQuality: 85,
				thumbnailWidth: 300,
				thumbnailQuality: 75,
			},
			() => {}
		);

		expect( outcome.ok ).toBe( true );
		expect( calls.some( ( c ) => c.finalize === true ) ).toBe( true );
	} );
} );
