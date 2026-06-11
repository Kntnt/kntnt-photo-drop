/**
 * The dragged-folder rules: detection and recursive, hierarchy-preserving walk.
 *
 * A flat drag-and-drop of loose files is the Drop Zone's fast path, but a
 * visitor can also drag a whole *folder* onto the zone. The design contract
 * (design.md, ADR-0008) is explicit: a drop and the "Select folder" picker have
 * one semantics — a dropped folder is traversed recursively and every file at
 * every level is recreated under the collection with its source-relative path
 * intact, byte-for-byte where the same folder picked via `webkitdirectory`
 * would land. Each file's path is derived from the entry's `fullPath` (leading
 * slash stripped) so it has the same shape `webkitRelativePath` carries for the
 * picker. A plain `dataTransfer.files` read cannot reach a folder's contents at
 * all, so the view module reads the drop's `webkitGetAsEntry()` entries and
 * runs these rules to walk the tree itself.
 *
 * Detection must be synchronous: `webkitGetAsEntry()` only works while the
 * `drop` event is being dispatched, so the entries are snapshotted first and
 * everything asynchronous works off that snapshot. Reading a directory uses
 * `createReader()` with repeated `readEntries()` calls, because Chromium
 * returns at most 100 entries per call and signals completion with an empty
 * batch. All rules are pure over structural shapes so Jest covers them with
 * mock entries and readers — including the >100-entry batching and unreadable
 * subtrees — without a real drop event.
 *
 * @since 0.5.0
 */

/**
 * The minimal directory-reader shape the walk reads.
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
 * only the matching subtype carries each; `fullPath` is the entry's absolute
 * path within the dropped tree, from which the source-relative path is derived.
 * A real entry from `webkitGetAsEntry()` satisfies this structurally.
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
	/** The entry's path within the dropped tree, e.g. `/trip/day1/IMG_2024.jpg`. */
	readonly fullPath: string;
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
 * One file walked out of a drop, paired with its source-relative path.
 *
 * The path mirrors `webkitRelativePath`'s shape — the entry's `fullPath` with
 * the leading slash stripped — so a drop and the picker recreate the same
 * sub-directories under the collection (ADR-0008). It travels explicitly with
 * the file because a `File` resolved from `entry.file()` carries an empty
 * `webkitRelativePath`, so the path could not otherwise be recovered from the
 * file alone.
 *
 * @since 0.5.0
 */
export interface WalkedFile {
	/** The file resolved from a file entry. */
	readonly file: File;
	/** The source-relative path, e.g. `trip/day1/IMG_2024.jpg`. */
	readonly relativePath: string;
}

/**
 * The result of walking a dropped batch: the readable files paired with their
 * relative paths, plus the paths of entries that could not be read.
 *
 * The unreadable paths exist so the view can render an honest failed status
 * row instead of silently dropping an entry — silent loss is exactly what the
 * folder-drop handling guards against. A failed directory listing surfaces the
 * whole subtree under that directory's path.
 *
 * @since 0.2.0
 */
export interface DroppedFileCollection {
	/** The files resolved from the drop, each with its relative path, in walk order. */
	readonly files: WalkedFile[];
	/** The relative paths of entries (files or directories) that failed to read. */
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
 * This is the trigger for the recursive walk: a drop with no directory entry
 * is the loose-file fast path and uploads proceed straight off the plain
 * `files` list, skipping the async traversal entirely.
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
 * Derives an entry's source-relative path from its `fullPath`.
 *
 * `fullPath` is rooted at the dropped tree with a leading slash
 * (`/trip/day1/IMG_2024.jpg`); stripping that slash yields the shape
 * `webkitRelativePath` carries for the same file picked via `webkitdirectory`,
 * so a drop and the picker recreate identical sub-directories server-side
 * (ADR-0008). When `fullPath` is unexpectedly empty (a browser that does not
 * populate it), the entry's base name is the lossless fallback.
 *
 * @since 0.5.0
 *
 * @param entry - The entry whose relative path is wanted.
 * @return The source-relative path with no leading slash.
 */
function relativePathOf( entry: FileSystemEntryLike ): string {
	const fullPath = entry.fullPath;
	if ( fullPath === '' ) {
		return entry.name;
	}
	return fullPath.startsWith( '/' ) ? fullPath.slice( 1 ) : fullPath;
}

/**
 * Walks a dropped batch recursively, preserving each file's hierarchy.
 *
 * Implements the ADR-0008 contract: every loose file entry is included, and
 * each directory is descended into at every level — every file at every depth
 * contributes, each carrying the relative path derived from its `fullPath`, so
 * the on-disk placement matches the "Select folder" picker exactly. The walk
 * is depth-first in entry order, so a folder's files stay contiguous. Entries
 * that fail to read, and directories whose listing fails, are reported by their
 * relative path in `unreadable` (a failed listing surfaces the whole subtree
 * under that directory) rather than silently dropped.
 *
 * @since 0.5.0
 *
 * @param entries - The snapshotted entries from `snapshotEntries`.
 * @return The readable files with their paths plus the paths of unreadable entries.
 */
export async function walkDroppedEntries(
	entries: readonly FileSystemEntryLike[]
): Promise< DroppedFileCollection > {
	const files: WalkedFile[] = [];
	const unreadable: string[] = [];

	// Resolve one file entry into the result bins, keyed by its source-relative
	// path; an unreadable entry is recorded by that path so the caller can
	// surface it as a failed row.
	const readInto = async ( entry: FileSystemEntryLike ): Promise< void > => {
		const relativePath = relativePathOf( entry );
		try {
			files.push( { file: await entryFile( entry ), relativePath } );
		} catch {
			unreadable.push( relativePath );
		}
	};

	// Descend one directory: drain its children, recurse into sub-directories,
	// and read its files; a failed listing marks the whole subtree unreadable
	// by the directory's own path.
	const descend = async ( entry: FileSystemEntryLike ): Promise< void > => {
		try {
			if ( ! entry.createReader ) {
				throw new Error( 'Directory exposes no createReader().' );
			}
			const children = await readAllEntries( entry.createReader() );
			await walkInto( children );
		} catch {
			unreadable.push( relativePathOf( entry ) );
		}
	};

	// Walk a level in entry order: each file is read, each directory recursed
	// into, anything else (a symlink-like oddity) ignored.
	const walkInto = async (
		level: readonly FileSystemEntryLike[]
	): Promise< void > => {
		for ( const entry of level ) {
			if ( entry.isFile ) {
				await readInto( entry );
			} else if ( entry.isDirectory ) {
				await descend( entry );
			}
		}
	};

	await walkInto( entries );

	return { files, unreadable };
}
