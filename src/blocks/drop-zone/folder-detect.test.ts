/**
 * Jest tests for the dragged-folder detector.
 *
 * The detector answers "did the visitor drop at least one folder?" over a
 * DataTransfer-like list, using each item's `webkitGetAsEntry().isDirectory`. The
 * tests cover a dropped folder, dropped files, a mix, the unsupported-browser case
 * (no `webkitGetAsEntry`), and non-file items — without a real drop event.
 *
 * @since 0.5.0
 */

import { hasDroppedFolder, type DataTransferItemLike } from './folder-detect';

/**
 * Builds a file-kind item whose entry reports the given directory flag.
 *
 * @param isDirectory - Whether the item's entry is a directory.
 * @return The synthetic DataTransferItem-like.
 */
function fileItem( isDirectory: boolean ): DataTransferItemLike {
	return {
		kind: 'file',
		webkitGetAsEntry: () => ( { isDirectory } ),
	};
}

describe( 'hasDroppedFolder', () => {
	it( 'returns true when a single dropped item is a directory', () => {
		expect( hasDroppedFolder( [ fileItem( true ) ] ) ).toBe( true );
	} );

	it( 'returns false when every dropped item is a file', () => {
		expect(
			hasDroppedFolder( [ fileItem( false ), fileItem( false ) ] )
		).toBe( false );
	} );

	it( 'returns true when at least one item among files is a directory', () => {
		expect(
			hasDroppedFolder( [
				fileItem( false ),
				fileItem( true ),
				fileItem( false ),
			] )
		).toBe( true );
	} );

	it( 'returns false when an item lacks webkitGetAsEntry (unsupported browser)', () => {
		expect( hasDroppedFolder( [ { kind: 'file' } ] ) ).toBe( false );
	} );

	it( 'ignores non-file items such as dragged text', () => {
		const textItem: DataTransferItemLike = {
			kind: 'string',
			webkitGetAsEntry: () => ( { isDirectory: true } ),
		};
		expect( hasDroppedFolder( [ textItem ] ) ).toBe( false );
	} );

	it( 'returns false when an entry resolves to null', () => {
		const nullEntryItem: DataTransferItemLike = {
			kind: 'file',
			webkitGetAsEntry: () => null,
		};
		expect( hasDroppedFolder( [ nullEntryItem ] ) ).toBe( false );
	} );

	it( 'returns false for an empty list', () => {
		expect( hasDroppedFolder( [] ) ).toBe( false );
	} );
} );
