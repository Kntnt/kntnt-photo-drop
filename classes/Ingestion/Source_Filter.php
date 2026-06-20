<?php
/**
 * The import-source type pre-filter — the server-side mirror of the Drop Zone's.
 *
 * A camera folder rarely holds only photos: RAW siblings (`.cr2`, `.nef`, …) and
 * video clips (`.mov`, `.mp4`, …) sit next to every shoot. When the CLI walks a
 * directory it should skip them the same way the Drop Zone does in the browser,
 * so an operator who imports a whole folder gets a clean report instead of one
 * `rejected` row per RAW/video sibling the optimiser would have refused anyway.
 *
 * This is a deliberate duplication: the rule already lives in
 * `src/blocks/drop-zone/file-filter.ts` for the browser, and TypeScript cannot be
 * shared into PHP. The extension list below must be kept in sync with that file.
 * Unlike the browser, the CLI has no MIME type to inspect (it walks a
 * filesystem), so only the extension branch of the rule is portable here; the
 * common video containers are on the list, and the optimiser remains the
 * backstop for anything that slips through.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Ingestion;

/**
 * Decides whether a filename is worth importing at all.
 *
 * Pure over a filename, so it is unit-tested without touching the filesystem.
 * The rule is a deny-list, not an allow-list: RAW and video extensions are
 * refused, and everything else — including unusual or extension-less names — is
 * allowed through to the optimiser, which re-enforces the real contract. A false
 * negative here costs one rejected import; a false positive would silently drop
 * a real photo, so the list stays narrow.
 *
 * @since 0.13.0
 */
final class Source_Filter {

	/**
	 * Extensions denied before import, all lower-case and dot-less.
	 *
	 * RAW formats (Canon, Nikon, Sony, Adobe, Fujifilm, Olympus, Panasonic,
	 * Samsung, Pentax) plus the common camera and phone video containers — each a
	 * format the WebP pipeline can never accept. Mirror of `DENIED_EXTENSIONS` in
	 * `src/blocks/drop-zone/file-filter.ts`; keep the two lists in sync.
	 *
	 * @since 0.13.0
	 * @var array<int,string>
	 */
	private const DENIED_EXTENSIONS = [
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
	];

	/**
	 * Reports whether a file is worth importing, by name alone.
	 *
	 * Denies a name whose extension is on the RAW/video deny-list; allows
	 * everything else, including a name with no extension. The extension match is
	 * case-insensitive and reads the last dot segment, so `IMG_0001.CR2` and
	 * `clip.final.MOV` are both denied.
	 *
	 * @since 0.13.0
	 *
	 * @param string $name The file's basename, e.g. `IMG_0001.CR2`.
	 * @return bool True when the file should be imported.
	 */
	public function should_import( string $name ): bool {

		// A name without a usable extension (no dot, or a trailing dot) has nothing
		// to match against the deny-list, so it passes through to the optimiser.
		$dot = strrpos( $name, '.' );
		if ( $dot === false || $dot === strlen( $name ) - 1 ) {
			return true;
		}

		// Deny the RAW and video containers the optimiser can never accept.
		$extension = strtolower( substr( $name, $dot + 1 ) );
		return ! in_array( $extension, self::DENIED_EXTENSIONS, true );

	}

}
