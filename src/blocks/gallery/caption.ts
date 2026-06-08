/**
 * Pure caption-string assembly for the Gallery editor preview.
 *
 * The frontend captions are assembled server-side by the PHP `Caption_Builder`;
 * the editor preview needs the same derivation in the browser so its sample
 * tiles read exactly as the published page will. This module is that derivation
 * as a pure function — no DOM, no React — so it is unit-testable in isolation and
 * kept in lockstep with the PHP rule: map the stored `<original>.webp` name back
 * to the original, optionally humanise (drop the extension, separators to
 * spaces), and for a path breadcrumb join the folder segments and the filename
 * with the separator, optionally prefixed by the collection name.
 *
 * @since 0.6.0
 */

import type { CaptionContent } from './attributes';

/**
 * The settings that drive caption assembly, mirroring the block's attributes.
 *
 * @since 0.6.0
 */
export interface CaptionSettings {
	/** The caption content mode. */
	readonly content: CaptionContent;
	/** Whether to humanise filenames and path segments. */
	readonly humanize: boolean;
	/** Whether a breadcrumb is prefixed with the collection name. */
	readonly includeName: boolean;
	/** The breadcrumb separator. */
	readonly separator: string;
	/** The collection display name, used only when prefixing. */
	readonly collectionName: string;
}

/**
 * Recovers an original filename from its stored `<original>.webp` form.
 *
 * Mirrors PHP `Image_Name::to_original`: a stored name is the original with
 * `.webp` appended unless the original was already WebP. The suffix is stripped
 * only when the remainder still carries an extension (a dot); a single-extension
 * `.webp` was an already-WebP original and maps back to itself.
 *
 * @since 0.6.0
 *
 * @param storedName - The stored filename.
 * @return The original filename for display.
 */
export function toOriginalName( storedName: string ): string {
	if ( ! storedName.toLowerCase().endsWith( '.webp' ) ) {
		return storedName;
	}
	const withoutSuffix = storedName.slice( 0, -'.webp'.length );
	return withoutSuffix.includes( '.' ) ? withoutSuffix : storedName;
}

/**
 * Builds the caption text for one image path, or `''` when none is wanted.
 *
 * Dispatches on the content mode: `none` yields `''`; `filename` yields the
 * image's own name; `path` yields the folder breadcrumb plus the filename. An
 * unrecognised mode is treated as `none`, mirroring the PHP fallback.
 *
 * @since 0.6.0
 *
 * @param relativePath - The image path relative to the collection root.
 * @param settings     - The caption settings.
 * @return The caption text, or `''` for the `none` content.
 */
export function buildCaption(
	relativePath: string,
	settings: CaptionSettings
): string {
	switch ( settings.content ) {
		case 'filename':
			return filenameCaption( relativePath, settings.humanize );
		case 'path':
			return pathCaption( relativePath, settings );
		default:
			return '';
	}
}

/**
 * Builds a filename-only caption from the last path segment.
 *
 * @since 0.6.0
 *
 * @param relativePath - The image path relative to the collection root.
 * @param humanize     - Whether to humanise the filename.
 * @return The filename caption.
 */
function filenameCaption( relativePath: string, humanize: boolean ): string {
	const segments = relativePath.split( '/' );
	const filename = segments[ segments.length - 1 ] ?? relativePath;
	return humanizeFilename( filename, humanize );
}

/**
 * Builds a path-breadcrumb caption from the folder segments and the filename.
 *
 * @since 0.6.0
 *
 * @param relativePath - The image path relative to the collection root.
 * @param settings     - The caption settings.
 * @return The breadcrumb caption.
 */
function pathCaption(
	relativePath: string,
	settings: CaptionSettings
): string {
	const segments = relativePath.split( '/' );
	const lastIndex = segments.length - 1;
	const crumbs = segments.map( ( segment, index ) =>
		index === lastIndex
			? humanizeFilename( segment, settings.humanize )
			: humanizeDirectory( segment, settings.humanize )
	);
	if ( settings.includeName && settings.collectionName !== '' ) {
		crumbs.unshift( settings.collectionName );
	}
	const glue = settings.separator === '' ? ' ' : ` ${ settings.separator } `;
	return crumbs.join( glue );
}

/**
 * Humanises a filename segment, mapping it back from its stored form first.
 *
 * @since 0.6.0
 *
 * @param filename - The stored filename segment.
 * @param humanize - Whether to humanise.
 * @return The display text for the filename.
 */
function humanizeFilename( filename: string, humanize: boolean ): string {
	const original = toOriginalName( filename );
	if ( ! humanize ) {
		return original;
	}
	const dot = original.lastIndexOf( '.' );
	const base = dot <= 0 ? original : original.slice( 0, dot );
	return normalizeSeparators( base );
}

/**
 * Humanises a directory segment of a breadcrumb.
 *
 * @since 0.6.0
 *
 * @param directory - The directory segment.
 * @param humanize  - Whether to humanise.
 * @return The display text for the directory.
 */
function humanizeDirectory( directory: string, humanize: boolean ): string {
	return humanize ? normalizeSeparators( directory ) : directory;
}

/**
 * Turns filename separators into spaces and collapses runs of whitespace.
 *
 * @since 0.6.0
 *
 * @param value - The value to normalise.
 * @return The normalised, trimmed text.
 */
function normalizeSeparators( value: string ): string {
	return value
		.replace( /[_\-.]/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
}
