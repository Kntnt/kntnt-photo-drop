/**
 * Jest tests for the per-mount retained-files registry.
 *
 * Retry-failed must re-upload the *real* bytes of each failed image, not a
 * fabricated empty file (issue #44). The bucket-accounting model only carries a
 * failed file's `{ key, fileName }` — enough to label the summary row, but not
 * the source bytes — so the live view wiring keeps the original
 * {@link QueuedFile} here, keyed by the same relative path the model keys by, and
 * resolves the failed set back to those queued files on Retry.
 *
 * These tests pin the load-bearing contract that broke before: a resolved file
 * carries its original byte length (a zero-byte file would be silently uploaded
 * as a corrupt artifact), only the failed keys are resolved, and a key with no
 * retained file is dropped rather than fabricated.
 *
 * @since 0.11.0
 */

import { createRetainedFiles } from './retained-files';
import type { QueuedFile } from './upload-queue';
import type { FailedFile } from './progress-model';

/**
 * Builds a queued file whose File carries a fixed number of real bytes.
 *
 * @param relativePath - The source-relative path that keys the file.
 * @param byteLength    - How many bytes the backing File should hold.
 * @return The queued file with a non-empty File.
 */
function queuedWithBytes(
	relativePath: string,
	byteLength: number
): QueuedFile {
	return {
		file: new File( [ new Uint8Array( byteLength ) ], relativePath ),
		relativePath,
	};
}

describe( 'createRetainedFiles', () => {
	it( 'resolves a failed file back to its original bytes, not an empty file', () => {
		const retained = createRetainedFiles();
		const original = queuedWithBytes( 'trip/sunset.jpg', 2048 );
		retained.retain( original );

		// The model only knows the key and the display name; Retry must still
		// re-send the real 2048 bytes, not a fabricated zero-byte file.
		const failed: FailedFile[] = [
			{ key: 'trip/sunset.jpg', fileName: 'sunset.jpg' },
		];
		const resolved = retained.resolve( failed );

		expect( resolved ).toHaveLength( 1 );
		expect( resolved[ 0 ]?.relativePath ).toBe( 'trip/sunset.jpg' );
		expect( resolved[ 0 ]?.file.size ).toBe( 2048 );
	} );

	it( 'resolves exactly the failed keys, leaving the rest retained', () => {
		const retained = createRetainedFiles();
		retained.retain( queuedWithBytes( 'a.jpg', 10 ) );
		retained.retain( queuedWithBytes( 'b.jpg', 20 ) );
		retained.retain( queuedWithBytes( 'c.jpg', 30 ) );

		// Only b.jpg failed; resolve must return b.jpg alone.
		const resolved = retained.resolve( [
			{ key: 'b.jpg', fileName: 'b.jpg' },
		] );

		expect( resolved.map( ( q ) => q.relativePath ) ).toEqual( [ 'b.jpg' ] );
		expect( resolved[ 0 ]?.file.size ).toBe( 20 );
	} );

	it( 'drops a failed key with no retained file instead of fabricating one', () => {
		const retained = createRetainedFiles();
		retained.retain( queuedWithBytes( 'a.jpg', 10 ) );

		// A walk-failure entry (recorded failed by path with no QueuedFile ever
		// retained) must not become a zero-byte upload; it is simply not resolved.
		const resolved = retained.resolve( [
			{ key: 'a.jpg', fileName: 'a.jpg' },
			{ key: 'ghost/unreadable', fileName: 'ghost/unreadable' },
		] );

		expect( resolved.map( ( q ) => q.relativePath ) ).toEqual( [ 'a.jpg' ] );
	} );
} );
