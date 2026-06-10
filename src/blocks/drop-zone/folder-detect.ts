/**
 * The dragged-folder rules: detection and flat top-level collection.
 *
 * A flat drag-and-drop of loose files is the Drop Zone's fast path, but a
 * visitor can also drag a whole *folder* onto the zone. The design contract
 * (design.md, ADR-0006 surroundings) is explicit: folder structure is preserved
 * only via the "Select folder" picker; a dropped folder is detected, warned
 * about, and — on consent — contributes only its top-level files, flat, with no
 * recursion into sub-directories. A plain `dataTransfer.files` read cannot reach
 * a folder's contents at all, so the view module reads the drop's
 * `webkitGetAsEntry()` entries and runs these rules to collect the top-level
 * files itself.
 *
 * Detection must be synchronous: `webkitGetAsEntry()` only works while the
 * `drop` event is being dispatched, so the entries are snapshotted first and
 * everything asynchronous works off that snapshot. Reading a directory uses
 * `createReader()` with repeated `readEntries()` calls, because Chromium
 * returns at most 100 entries per call and signals completion with an empty
 * batch. All rules are pure over structural shapes so Jest covers them with
 * mock entries and readers — including the >100-entry batching — without a
 * real drop event.
 *
 * @since 0.5.0
 */

/**
 * The minimal directory-reader shape the collection rules read.
 *
 * Mirrors `FileSystemDirectoryReader`: each `readEntries` call hands the next
 * batch to `success` and an empty batch signals completion. A real reader from
 * `createReader()` satisfies this structurally.
 *
 * @since 0.2.0
 */
export interface DirectoryReaderLike {
	/** Reads the next batch of entries; an empty batch means done. */
	readonly readEntries: (
		success: ( entries: FileSystemEntryLike[] ) => void,
		failure: ( error: unknown ) => void
	) => void;
}

/**
 * The minimal filesystem-entry shape the rules read.
 *
 * Mirrors the parts of `FileSystemEntry` (and its file/directory subtypes)
 * the rules need. `file` and `createReader` are optional in the type because
 * only the matching subtype carries each; a real entry from
 * `webkitGetAsEntry()` satisfies this structurally.
 *
 * @since 0.5.0
 */
export interface FileSystemEntryLike {
	/** True when the entry is a directory. */
	readonly isDirectory: boolean;
	/** True when the entry is a plain file. */
	readonly isFile: boolean;
	/** The entry's base name, used to report unreadable entries. */
	readonly name: string;
	/** Resolves the entry to its `File`; present on file entries. */
	readonly file?: (
		success: ( file: File ) => void,
		failure: ( error: unknown ) => void
	) => void;
	/** Creates a reader over the directory's children; present on directories. */
	readonly createReader?: () => DirectoryReaderLike;
}

/**
 * The minimal `DataTransferItem` shape the snapshot reads.
 *
 * Only `kind` and `webkitGetAsEntry` are needed; a real `DataTransferItem`
 * satisfies this structurally. `webkitGetAsEntry` is optional in the type
 * because older or non-Chromium browsers may not expose it — such items
 * simply yield no entry and fall through to the plain-file intake.
 *
 * @since 0.5.0
 */
export interface DataTransferItemLike {
	/** The item kind — `'file'` for a dragged file or folder. */
	readonly kind: string;
	/** Returns the filesystem entry for a `'file'` item, or null. */
	readonly webkitGetAsEntry?: () => FileSystemEntryLike | null;
}

/**
 * The result of collecting a dropped batch: the readable files plus the names
 * of entries that could not be read.
 *
 * The unreadable names exist so the view can render an honest failed status
 * row instead of silently dropping an entry — silent loss is exactly what the
 * folder-drop handling guards against.
 *
 * @since 0.2.0
 */
export interface DroppedFileCollection {
	/** The files resolved from the drop, in traversal order. */
	readonly files: File[];
	/** The names of entries (files or directories) that failed to read. */
	readonly unreadable: string[];
}

/**
 * Synchronously snapshots the filesystem entries of a drop.
 *
 * Must run while the `drop` event is being dispatched — afterwards the items
 * are neutered and `webkitGetAsEntry()` returns null. Non-file items (dragged
 * text or URLs), items without `webkitGetAsEntry`, and null entries are
 * dropped from the snapshot.
 *
 * @since 0.2.0
 *
 * @param items - The dropped items from the drop event's `dataTransfer.items`.
 * @return The non-null entries, in item order.
 */
export function snapshotEntries(
	items: Iterable< DataTransferItemLike >
): FileSystemEntryLike[] {
	const entries: FileSystemEntryLike[] = [];

	// Only file-kind items can carry an entry; everything else is dragged
	// text or an unsupported browser, both excluded from the snapshot.
	for ( const item of items ) {
		if ( item.kind !== 'file' || ! item.webkitGetAsEntry ) {
			continue;
		}
		const entry = item.webkitGetAsEntry();
		if ( entry !== null ) {
			entries.push( entry );
		}
	}

	return entries;
}

/**
 * Reports whether a snapshot contains at least one directory.
 *
 * This is the trigger for the folder warning: a drop with no directory entry
 * is the loose-file fast path and uploads proceed untouched.
 *
 * @since 0.2.0
 *
 * @param entries - The snapshotted entries from `snapshotEntries`.
 * @return True when at least one entry is a directory.
 */
export function hasDirectoryEntry(
	entries: readonly FileSystemEntryLike[]
): boolean {
	return entries.some( ( entry ) => entry.isDirectory );
}

/**
 * Reads every child entry of a directory reader, batch by batch.
 *
 * Chromium caps `readEntries` at 100 entries per call, so the reader is
 * drained with repeated calls until it hands back an empty batch — a camera
 * folder with hundreds of images would otherwise silently lose everything
 * after the first batch. Rejects when any batch read fails.
 *
 * @since 0.2.0
 *
 * @param reader - The directory reader to drain.
 * @return All child entries, in read order.
 */
export function readAllEntries(
	reader: DirectoryReaderLike
): Promise< FileSystemEntryLike[] > {
	return new Promise( ( resolve, reject ) => {
		const collected: FileSystemEntryLike[] = [];

		// Drain the reader until the empty batch signals completion; each call
		// returns at most 100 entries on Chromium.
		const readBatch = (): void => {
			reader.readEntries( ( batch ) => {
				if ( batch.length === 0 ) {
					resolve( collected );
					return;
				}
				collected.push( ...batch );
				readBatch();
			}, reject );
		};
		readBatch();
	} );
}

/**
 * Resolves a file entry to its `File`.
 *
 * Wraps the callback-shaped `FileSystemFileEntry.file()` in a promise;
 * rejects when the entry exposes no `file` method or the read fails.
 *
 * @since 0.2.0
 *
 * @param entry - The file entry to resolve.
 * @return The entry's file.
 */
function entryFile( entry: FileSystemEntryLike ): Promise< File > {
	return new Promise( ( resolve, reject ) => {
		if ( ! entry.file ) {
			reject( new Error( 'Entry exposes no file() method.' ) );
			return;
		}
		entry.file( resolve, reject );
	} );
}

/**
 * Collects the uploadable files of a consented folder drop, flat.
 *
 * Implements the design contract for a dropped folder: every loose file entry
 * is included, and each directory contributes only its *top-level* file
 * entries — sub-directories are skipped entirely, never recursed into (folder
 * structure is the "Select folder" picker's job). Entries that fail to read,
 * and directories whose listing fails, are reported by name in `unreadable`
 * rather than silently dropped.
 *
 * @since 0.2.0
 *
 * @param entries - The snapshotted entries from `snapshotEntries`.
 * @return The readable files plus the names of unreadable entries.
 */
export async function collectTopLevelFiles(
	entries: readonly FileSystemEntryLike[]
): Promise< DroppedFileCollection > {
	const files: File[] = [];
	const unreadable: string[] = [];

	// Resolve one file entry into the result bins; an unreadable entry is
	// recorded by name so the caller can surface it.
	const readInto = async ( entry: FileSystemEntryLike ): Promise< void > => {
		try {
			files.push( await entryFile( entry ) );
		} catch {
			unreadable.push( entry.name );
		}
	};

	for ( const entry of entries ) {
		// A loose file dropped alongside the folder is part of the batch.
		if ( entry.isFile ) {
			await readInto( entry );
			continue;
		}
		if ( ! entry.isDirectory ) {
			continue;
		}

		// Take only the directory's top-level file entries — no recursion;
		// a failed listing marks the whole directory unreadable.
		try {
			if ( ! entry.createReader ) {
				throw new Error( 'Directory exposes no createReader().' );
			}
			const children = await readAllEntries( entry.createReader() );
			for ( const child of children ) {
				if ( child.isFile ) {
					await readInto( child );
				}
			}
		} catch {
			unreadable.push( entry.name );
		}
	}

	return { files, unreadable };
}
