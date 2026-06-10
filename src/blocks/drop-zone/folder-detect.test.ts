/**
 * Jest tests for the dragged-folder rules.
 *
 * Covers the synchronous snapshot (file-kind items only, unsupported browsers,
 * null entries), the directory detection the warning is keyed on, the batched
 * `readEntries` drain — including Chromium's 100-entries-per-call quirk — and
 * the flat top-level collection: loose files included, sub-directories never
 * recursed into, unreadable entries reported by name. All without a real drop
 * event, using mock entries and readers.
 *
 * @since 0.5.0
 */

import {
	collectTopLevelFiles,
	hasDirectoryEntry,
	readAllEntries,
	snapshotEntries,
	type DataTransferItemLike,
	type DirectoryReaderLike,
	type FileSystemEntryLike,
} from './folder-detect';

/**
 * Builds a file entry resolving to a real `File` of the given name.
 *
 * @param name - The file name the entry resolves to.
 * @return The synthetic file entry.
 */
function fileEntry( name: string ): FileSystemEntryLike {
	return {
		isDirectory: false,
		isFile: true,
		name,
		file: ( success ) => success( new File( [ 'x' ], name ) ),
	};
}

/**
 * Builds a file entry whose `file()` always fails.
 *
 * @param name - The file name reported as unreadable.
 * @return The synthetic broken file entry.
 */
function brokenFileEntry( name: string ): FileSystemEntryLike {
	return {
		isDirectory: false,
		isFile: true,
		name,
		file: ( _success, failure ) => failure( new Error( 'gone' ) ),
	};
}

/**
 * Builds a reader that hands out the given entries in fixed-size batches.
 *
 * Mimics Chromium's behaviour: at most `batchSize` entries per `readEntries`
 * call, then an empty batch to signal completion.
 *
 * @param entries   - The entries to hand out.
 * @param batchSize - The maximum batch size per call.
 * @return The synthetic directory reader.
 */
function batchedReader(
	entries: FileSystemEntryLike[],
	batchSize: number
): DirectoryReaderLike {
	let cursor = 0;
	return {
		readEntries: ( success ) => {
			const batch = entries.slice( cursor, cursor + batchSize );
			cursor += batch.length;
			success( batch );
		},
	};
}

/**
 * Builds a directory entry whose children come from a batched reader.
 *
 * @param name      - The directory name.
 * @param children  - The child entries the reader hands out.
 * @param batchSize - The maximum batch size per `readEntries` call.
 * @return The synthetic directory entry.
 */
function directoryEntry(
	name: string,
	children: FileSystemEntryLike[],
	batchSize = 100
): FileSystemEntryLike {
	return {
		isDirectory: true,
		isFile: false,
		name,
		createReader: () => batchedReader( children, batchSize ),
	};
}

describe( 'snapshotEntries', () => {
	it( 'captures the entries of file-kind items', () => {
		const entry = fileEntry( 'a.jpg' );
		const items: DataTransferItemLike[] = [
			{ kind: 'file', webkitGetAsEntry: () => entry },
		];

		expect( snapshotEntries( items ) ).toEqual( [ entry ] );
	} );

	it( 'skips non-file items such as dragged text', () => {
		const items: DataTransferItemLike[] = [
			{
				kind: 'string',
				webkitGetAsEntry: () => fileEntry( 'a.jpg' ),
			},
		];

		expect( snapshotEntries( items ) ).toEqual( [] );
	} );

	it( 'skips items without webkitGetAsEntry (unsupported browser)', () => {
		expect( snapshotEntries( [ { kind: 'file' } ] ) ).toEqual( [] );
	} );

	it( 'skips items whose entry resolves to null', () => {
		const items: DataTransferItemLike[] = [
			{ kind: 'file', webkitGetAsEntry: () => null },
		];

		expect( snapshotEntries( items ) ).toEqual( [] );
	} );
} );

describe( 'hasDirectoryEntry', () => {
	it( 'detects a directory among files', () => {
		expect(
			hasDirectoryEntry( [
				fileEntry( 'a.jpg' ),
				directoryEntry( '100CANON', [] ),
			] )
		).toBe( true );
	} );

	it( 'returns false for files only', () => {
		expect( hasDirectoryEntry( [ fileEntry( 'a.jpg' ) ] ) ).toBe( false );
	} );

	it( 'returns false for an empty snapshot', () => {
		expect( hasDirectoryEntry( [] ) ).toBe( false );
	} );
} );

describe( 'readAllEntries', () => {
	it( 'drains a reader that batches at 100 entries per call', async () => {
		// 250 entries forces three non-empty batches plus the empty terminator
		// — the Chromium quirk that silently truncates naive single reads.
		const children = Array.from( { length: 250 }, ( _unused, index ) =>
			fileEntry( `img-${ index }.jpg` )
		);

		const collected = await readAllEntries(
			batchedReader( children, 100 )
		);

		expect( collected ).toHaveLength( 250 );
		expect( collected[ 0 ]?.name ).toBe( 'img-0.jpg' );
		expect( collected[ 249 ]?.name ).toBe( 'img-249.jpg' );
	} );

	it( 'resolves to an empty list for an empty directory', async () => {
		await expect(
			readAllEntries( batchedReader( [], 100 ) )
		).resolves.toEqual( [] );
	} );

	it( 'rejects when a batch read fails', async () => {
		const reader: DirectoryReaderLike = {
			readEntries: ( _success, failure ) =>
				failure( new Error( 'denied' ) ),
		};

		await expect( readAllEntries( reader ) ).rejects.toThrow( 'denied' );
	} );
} );

describe( 'collectTopLevelFiles', () => {
	it( 'collects loose files and the top level of each directory, flat', async () => {
		const entries = [
			fileEntry( 'loose.jpg' ),
			directoryEntry( '100CANON', [
				fileEntry( 'IMG_0001.JPG' ),
				fileEntry( 'IMG_0002.JPG' ),
			] ),
		];

		const { files, unreadable } = await collectTopLevelFiles( entries );

		expect( files.map( ( file ) => file.name ) ).toEqual( [
			'loose.jpg',
			'IMG_0001.JPG',
			'IMG_0002.JPG',
		] );
		expect( unreadable ).toEqual( [] );
	} );

	it( 'never recurses into sub-directories', async () => {
		const entries = [
			directoryEntry( 'trip', [
				fileEntry( 'cover.jpg' ),
				directoryEntry( 'day1', [ fileEntry( 'hidden.jpg' ) ] ),
			] ),
		];

		const { files } = await collectTopLevelFiles( entries );

		expect( files.map( ( file ) => file.name ) ).toEqual( [ 'cover.jpg' ] );
	} );

	it( 'collects past the 100-entry batch boundary', async () => {
		const children = Array.from( { length: 150 }, ( _unused, index ) =>
			fileEntry( `img-${ index }.jpg` )
		);

		const { files } = await collectTopLevelFiles( [
			directoryEntry( 'big', children, 100 ),
		] );

		expect( files ).toHaveLength( 150 );
	} );

	it( 'reports an unreadable file entry by name instead of dropping it', async () => {
		const { files, unreadable } = await collectTopLevelFiles( [
			fileEntry( 'good.jpg' ),
			brokenFileEntry( 'bad.jpg' ),
		] );

		expect( files.map( ( file ) => file.name ) ).toEqual( [ 'good.jpg' ] );
		expect( unreadable ).toEqual( [ 'bad.jpg' ] );
	} );

	it( 'reports a directory whose listing fails', async () => {
		const failing: FileSystemEntryLike = {
			isDirectory: true,
			isFile: false,
			name: 'locked',
			createReader: () => ( {
				readEntries: ( _success, failure ) =>
					failure( new Error( 'denied' ) ),
			} ),
		};

		const { files, unreadable } = await collectTopLevelFiles( [ failing ] );

		expect( files ).toEqual( [] );
		expect( unreadable ).toEqual( [ 'locked' ] );
	} );

	it( 'reports a directory without createReader as unreadable', async () => {
		const bare: FileSystemEntryLike = {
			isDirectory: true,
			isFile: false,
			name: 'odd',
		};

		const { unreadable } = await collectTopLevelFiles( [ bare ] );

		expect( unreadable ).toEqual( [ 'odd' ] );
	} );
} );
