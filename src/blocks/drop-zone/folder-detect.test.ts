/**
 * Jest tests for the dragged-folder rules.
 *
 * Covers the synchronous snapshot (file-kind items only, unsupported browsers,
 * null entries), the directory detection that splits the loose-file fast path
 * from the walk, the batched `readEntries` drain — including Chromium's
 * 100-entries-per-call quirk — and the recursive, hierarchy-preserving walk:
 * every file at every level included with its `fullPath`-derived relative path,
 * sub-directories recursed into, basename collisions across sub-folders kept
 * distinct, >100-entry directories drained, and unreadable files or subtrees
 * reported by path. All without a real drop event, using mock entries and
 * readers.
 *
 * @since 0.5.0
 */

import {
	hasDirectoryEntry,
	readAllEntries,
	snapshotEntries,
	walkDroppedEntries,
	type DataTransferItemLike,
	type DirectoryReaderLike,
	type FileSystemEntryLike,
} from './folder-detect';

/**
 * Builds a file entry resolving to a real `File` of the given base name.
 *
 * @param name     - The file base name the entry resolves to.
 * @param fullPath - The entry's path within the dropped tree; defaults to a
 *                 root-level `/<name>` so simple cases need not pass it.
 * @return The synthetic file entry.
 */
function fileEntry(
	name: string,
	fullPath = `/${ name }`
): FileSystemEntryLike {
	return {
		isDirectory: false,
		isFile: true,
		name,
		fullPath,
		file: ( success ) => success( new File( [ 'x' ], name ) ),
	};
}

/**
 * Builds a file entry whose `file()` always fails.
 *
 * @param name     - The file base name reported as unreadable.
 * @param fullPath - The entry's path within the dropped tree.
 * @return The synthetic broken file entry.
 */
function brokenFileEntry(
	name: string,
	fullPath = `/${ name }`
): FileSystemEntryLike {
	return {
		isDirectory: false,
		isFile: true,
		name,
		fullPath,
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
 * @param name      - The directory base name.
 * @param fullPath  - The directory's path within the dropped tree.
 * @param children  - The child entries the reader hands out.
 * @param batchSize - The maximum batch size per `readEntries` call.
 * @return The synthetic directory entry.
 */
function directoryEntry(
	name: string,
	fullPath: string,
	children: FileSystemEntryLike[],
	batchSize = 100
): FileSystemEntryLike {
	return {
		isDirectory: true,
		isFile: false,
		name,
		fullPath,
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
				directoryEntry( '100CANON', '/100CANON', [] ),
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

describe( 'walkDroppedEntries', () => {
	it( 'collects loose files and a directory top level with their paths', async () => {
		const entries = [
			fileEntry( 'loose.jpg' ),
			directoryEntry( '100CANON', '/100CANON', [
				fileEntry( 'IMG_0001.JPG', '/100CANON/IMG_0001.JPG' ),
				fileEntry( 'IMG_0002.JPG', '/100CANON/IMG_0002.JPG' ),
			] ),
		];

		const { files, unreadable } = await walkDroppedEntries( entries );

		// The leading slash of `fullPath` is stripped so the path matches the
		// shape `webkitRelativePath` carries for the picker.
		expect( files.map( ( walked ) => walked.relativePath ) ).toEqual( [
			'loose.jpg',
			'100CANON/IMG_0001.JPG',
			'100CANON/IMG_0002.JPG',
		] );
		expect( unreadable ).toEqual( [] );
	} );

	it( 'recurses into sub-directories, preserving the full hierarchy', async () => {
		const entries = [
			directoryEntry( 'trip', '/trip', [
				fileEntry( 'cover.jpg', '/trip/cover.jpg' ),
				directoryEntry( 'day1', '/trip/day1', [
					fileEntry( 'a.jpg', '/trip/day1/a.jpg' ),
					directoryEntry( 'morning', '/trip/day1/morning', [
						fileEntry( 'deep.jpg', '/trip/day1/morning/deep.jpg' ),
					] ),
				] ),
			] ),
		];

		const { files } = await walkDroppedEntries( entries );

		// Depth-first, in entry order, so a folder's images stay contiguous;
		// every level contributes, none is flattened away.
		expect( files.map( ( walked ) => walked.relativePath ) ).toEqual( [
			'trip/cover.jpg',
			'trip/day1/a.jpg',
			'trip/day1/morning/deep.jpg',
		] );
	} );

	it( 'keeps basename collisions in different sub-folders distinct', async () => {
		const entries = [
			directoryEntry( 'trip', '/trip', [
				directoryEntry( 'day1', '/trip/day1', [
					fileEntry( 'IMG_0001.JPG', '/trip/day1/IMG_0001.JPG' ),
				] ),
				directoryEntry( 'day2', '/trip/day2', [
					fileEntry( 'IMG_0001.JPG', '/trip/day2/IMG_0001.JPG' ),
				] ),
			] ),
		];

		const { files } = await walkDroppedEntries( entries );

		// Two files share a base name but ride distinct relative paths, so both
		// upload rather than one clobbering the other.
		expect( files.map( ( walked ) => walked.relativePath ) ).toEqual( [
			'trip/day1/IMG_0001.JPG',
			'trip/day2/IMG_0001.JPG',
		] );
		expect( files.map( ( walked ) => walked.file.name ) ).toEqual( [
			'IMG_0001.JPG',
			'IMG_0001.JPG',
		] );
	} );

	it( 'collects past the 100-entry batch boundary within one directory', async () => {
		const children = Array.from( { length: 150 }, ( _unused, index ) =>
			fileEntry( `img-${ index }.jpg`, `/big/img-${ index }.jpg` )
		);

		const { files } = await walkDroppedEntries( [
			directoryEntry( 'big', '/big', children, 100 ),
		] );

		expect( files ).toHaveLength( 150 );
		expect( files[ 0 ]?.relativePath ).toBe( 'big/img-0.jpg' );
		expect( files[ 149 ]?.relativePath ).toBe( 'big/img-149.jpg' );
	} );

	it( 'falls back to the base name when fullPath is empty', async () => {
		const { files } = await walkDroppedEntries( [
			fileEntry( 'loose.jpg', '' ),
		] );

		expect( files[ 0 ]?.relativePath ).toBe( 'loose.jpg' );
	} );

	it( 'reports an unreadable file by its path instead of dropping it', async () => {
		const { files, unreadable } = await walkDroppedEntries( [
			directoryEntry( 'trip', '/trip', [
				fileEntry( 'good.jpg', '/trip/good.jpg' ),
				brokenFileEntry( 'bad.jpg', '/trip/bad.jpg' ),
			] ),
		] );

		expect( files.map( ( walked ) => walked.relativePath ) ).toEqual( [
			'trip/good.jpg',
		] );
		expect( unreadable ).toEqual( [ 'trip/bad.jpg' ] );
	} );

	it( 'reports an unreadable subtree by the directory path', async () => {
		const failing: FileSystemEntryLike = {
			isDirectory: true,
			isFile: false,
			name: 'locked',
			fullPath: '/trip/locked',
			createReader: () => ( {
				readEntries: ( _success, failure ) =>
					failure( new Error( 'denied' ) ),
			} ),
		};
		const entries = [
			directoryEntry( 'trip', '/trip', [
				fileEntry( 'cover.jpg', '/trip/cover.jpg' ),
				failing,
			] ),
		];

		const { files, unreadable } = await walkDroppedEntries( entries );

		// The readable sibling still lands; only the failed subtree is surfaced,
		// by the directory's own path.
		expect( files.map( ( walked ) => walked.relativePath ) ).toEqual( [
			'trip/cover.jpg',
		] );
		expect( unreadable ).toEqual( [ 'trip/locked' ] );
	} );

	it( 'reports a directory without createReader as unreadable', async () => {
		const bare: FileSystemEntryLike = {
			isDirectory: true,
			isFile: false,
			name: 'odd',
			fullPath: '/odd',
		};

		const { unreadable } = await walkDroppedEntries( [ bare ] );

		expect( unreadable ).toEqual( [ 'odd' ] );
	} );
} );
