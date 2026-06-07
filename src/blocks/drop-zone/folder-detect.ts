/**
 * The dragged-folder detector.
 *
 * A flat drag-and-drop of loose files is the Drop Zone's fast path, but a visitor
 * can also drag a whole *folder* onto the zone. The browser does not expand that
 * folder into its files for a plain drop, and recursive directory traversal is out
 * of scope (ADR: folder structure via `webkitdirectory` only), so the Drop Zone
 * detects a dropped folder and warns — offering to continue with just the
 * top-level images. This module is that detection rule.
 *
 * Detection uses `DataTransferItem.webkitGetAsEntry()`: a dropped directory yields
 * an entry whose `isDirectory` is true. The rule is pure over a DataTransfer-like
 * shape so Jest can cover it without a real drop event; the view module calls it
 * from the drop handler and surfaces the warning when it returns true.
 *
 * @since 0.5.0
 */

/**
 * The minimal filesystem-entry shape the detector reads.
 *
 * Mirrors the part of `FileSystemEntry` the rule needs — only the `isDirectory`
 * flag. A real entry from `webkitGetAsEntry()` satisfies this structurally.
 *
 * @since 0.5.0
 */
export interface FolderEntryLike {
	/** True when the entry is a directory rather than a file. */
	readonly isDirectory: boolean;
}

/**
 * The minimal `DataTransferItem` shape the detector reads.
 *
 * Only `kind` and `webkitGetAsEntry` are needed; a real `DataTransferItem`
 * satisfies this structurally. `webkitGetAsEntry` is optional in the type because
 * older or non-Chromium browsers may not expose it — the detector treats its
 * absence as "not a folder".
 *
 * @since 0.5.0
 */
export interface DataTransferItemLike {
	/** The item kind — `'file'` for a dragged file or folder. */
	readonly kind: string;
	/** Returns the filesystem entry for a `'file'` item, or null. */
	readonly webkitGetAsEntry?: () => FolderEntryLike | null;
}

/**
 * Reports whether any of the dropped items is a directory.
 *
 * Examines each `DataTransferItem`: a `'file'`-kind item whose
 * `webkitGetAsEntry()` returns a directory entry is a dropped folder. Returns true
 * on the first such item. Items without `webkitGetAsEntry` (unsupported browsers)
 * or whose entry is a file are not folders. No recursion happens here — the rule
 * only answers "did the visitor drop at least one folder?", which is what the
 * warning is keyed on; the actual upload still flattens to the top-level files the
 * browser surfaces.
 *
 * @since 0.5.0
 *
 * @param items - The dropped items from a drop event's `dataTransfer.items`.
 * @return True when at least one dropped item is a directory.
 */
export function hasDroppedFolder(
	items: Iterable< DataTransferItemLike >
): boolean {
	for ( const item of items ) {
		// Only file-kind items can be a folder; a string item (dragged text/URL)
		// never is, and an item without webkitGetAsEntry cannot be inspected.
		if ( item.kind !== 'file' || ! item.webkitGetAsEntry ) {
			continue;
		}

		const entry = item.webkitGetAsEntry();
		if ( entry?.isDirectory ) {
			return true;
		}
	}

	return false;
}
