/**
 * The `webkitRelativePath` → upload-metadata mapping for picker-sourced files.
 *
 * The Drop Zone preserves folder structure through the "Select folder" control:
 * when a directory is picked, each `File` carries a `webkitRelativePath` such as
 * `trip/day1/IMG_2024.jpg`. The server recreates those sub-directories under the
 * collection root (the path is hard-sanitised and `realpath`-confined server-side,
 * ADR-0006), so the client just needs to forward the right relative path per file.
 *
 * This module is the pure rule for deriving that path from the `File` alone: prefer
 * the browser-supplied `webkitRelativePath` when present (a folder selection), and
 * fall back to the plain file name for a loose file (the loose-file picker or a
 * flat drag-and-drop). It serves the two flows whose path lives *on* the file — a
 * dropped folder instead carries each file's path explicitly alongside it, because
 * a `File` resolved from `entry.file()` has an empty `webkitRelativePath`, so the
 * walk in `folder-detect.ts` derives that path from the entry's `fullPath` and this
 * mapping is not consulted for it. It holds no DOM state so Jest can cover it in
 * isolation; the view module calls it once per picker/loose file when building
 * each upload's metadata.
 *
 * @since 0.5.0
 */

/**
 * The minimal `File` shape this mapping reads.
 *
 * Only the two fields the rule needs are required, so a plain test object stands
 * in for a real `File` without constructing one. A real `File` satisfies this
 * shape structurally.
 *
 * @since 0.5.0
 */
export interface RelativePathFile {
	/** The file's base name, e.g. `IMG_2024.jpg`. */
	readonly name: string;
	/**
	 * The browser-supplied path within a selected directory, e.g.
	 * `trip/day1/IMG_2024.jpg`. Empty for a loose file from a flat drag-drop.
	 */
	readonly webkitRelativePath?: string;
}

/**
 * Derives the relative path to send for one file.
 *
 * Returns the `webkitRelativePath` verbatim when the browser supplied a non-empty
 * one (a folder selection, so the nested structure is preserved), otherwise the
 * plain file name (a loose file, which lands at the collection root). The value is
 * forwarded as the `relativePath` upload field; no sanitisation happens here
 * because the server is the trust boundary and must see the raw bytes — including
 * any traversal attempt — to reject them (ADR-0006).
 *
 * @since 0.5.0
 *
 * @param file - The file whose relative path is wanted.
 * @return The relative path to send as the `relativePath` field.
 */
export function relativePathForFile( file: RelativePathFile ): string {
	const webkitPath = file.webkitRelativePath ?? '';
	return webkitPath !== '' ? webkitPath : file.name;
}
