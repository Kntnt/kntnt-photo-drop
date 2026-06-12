/**
 * The hidden filesystem-noise pre-filter.
 *
 * A dropped or picked folder carries more than the photographer's images: the
 * operating system seeds it with hidden bookkeeping files the photographer never
 * sees in Finder/Explorer and never meant to publish. macOS is the worst
 * offender — beside every `DSCF0012.JPG` it writes an AppleDouble sidecar
 * `._DSCF0012.JPG` (the resource fork and extended attributes of a file copied
 * across a filesystem that cannot store them inline), plus per-folder
 * `.DS_Store` view-state files. Because a sidecar inherits the photo's `.JPG`
 * name, the upload type pre-filter (`file-filter.ts`, a RAW/video deny-list)
 * waves it through, and it surfaces in the Drop Zone as a "ghost file" the
 * photographer cannot account for.
 *
 * The shared trait of all this noise is a Unix-hidden basename: a leading dot.
 * No legitimate publishable photo is named that way, so a basename beginning
 * with `.` is treated as filesystem noise and silently dropped at intake —
 * never uploaded, and not even given a status row, since surfacing a file the
 * photographer cannot see would be as confusing as uploading it.
 *
 * The rule is pure over the basename so Jest covers it without File objects; the
 * view module applies it on every intake path before the type pre-filter.
 *
 * @since 0.10.1
 */

/**
 * Reports whether a file is hidden OS bookkeeping rather than a real photo.
 *
 * The test is a leading-dot basename: it matches AppleDouble sidecars
 * (`._DSCF0012.JPG`), `.DS_Store`, and every other dotfile a folder pick or drop
 * sweeps up. The caller passes the file's own basename (`File.name`), never a
 * full relative path, so a legitimate photo inside an ordinarily-named folder is
 * unaffected.
 *
 * @since 0.10.1
 *
 * @param name - The file's basename, e.g. `._DSCF0012.JPG` or `DSCF0012.JPG`.
 * @return True when the file is hidden filesystem noise to be silently ignored.
 */
export function isHiddenFile( name: string ): boolean {
	return name.startsWith( '.' );
}
