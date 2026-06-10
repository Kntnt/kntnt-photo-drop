/**
 * The upload type pre-filter.
 *
 * A camera folder rarely holds only JPEGs: RAW siblings (`.cr2`, `.nef`, …) and
 * video clips (`.mov`, `.mp4`, …) sit next to every shoot. The server rejects
 * them, but only after the browser has uploaded their full multi-hundred-MB
 * originals — so the Drop Zone filters them out before any bytes move. The rule
 * is a deny-list, not an allow-list: HEIC and other unknown-but-plausible image
 * types still get their chance, because the server re-enforces the contract on
 * every file anyway (ADR-0006) and a false negative there costs one rejected
 * upload, while a false positive here would silently exclude a real photo.
 *
 * The rule is pure over a name and a MIME type so Jest covers it without File
 * objects; the view module applies it on every intake path — the click and
 * folder pickers, the loose-file drop, and the consented folder drop — before any
 * file is queued for upload.
 *
 * @since 0.2.0
 */

/**
 * File extensions denied before upload, all lower-case and dot-less.
 *
 * RAW formats (Canon, Nikon, Sony, Adobe, Fujifilm, Olympus, Panasonic, Samsung,
 * Pentax) plus the common camera and phone video containers. Each entry is a
 * format the server's WebP pipeline can never accept, so uploading it only burns
 * the photographer's bandwidth.
 *
 * @since 0.2.0
 */
const DENIED_EXTENSIONS: ReadonlySet< string > = new Set( [
	'cr2',
	'cr3',
	'nef',
	'arw',
	'dng',
	'raf',
	'orf',
	'rw2',
	'srw',
	'pef',
	'mov',
	'mp4',
	'm4v',
	'avi',
	'mts',
	'm2ts',
	'mkv',
	'webm',
	'3gp',
] );

/**
 * Decides whether a file is worth uploading at all.
 *
 * Denies a file whose MIME type is any `video/*` or whose extension is on the
 * RAW/video deny-list; allows everything else, including files with no or
 * unknown MIME types, so unusual image formats still reach the server-side
 * contract enforcement. The extension match is case-insensitive and reads the
 * last dot segment, so `IMG_0001.CR2` and `clip.final.MOV` are both denied.
 *
 * @since 0.2.0
 *
 * @param name - The file name, e.g. `IMG_0001.CR2`.
 * @param type - The file's MIME type, possibly empty.
 * @return True when the file should be uploaded.
 */
export function shouldUploadFile( name: string, type: string ): boolean {
	// Any video MIME type is denied outright — the server can never store it,
	// and clips are the largest files a camera folder holds.
	if ( type.toLowerCase().startsWith( 'video/' ) ) {
		return false;
	}

	// Deny by extension for the RAW and video formats browsers often report
	// with an empty or generic MIME type; a name without a dot has no
	// extension and passes.
	const dot = name.lastIndexOf( '.' );
	if ( dot === -1 || dot === name.length - 1 ) {
		return true;
	}
	const extension = name.slice( dot + 1 ).toLowerCase();

	return ! DENIED_EXTENSIONS.has( extension );
}
