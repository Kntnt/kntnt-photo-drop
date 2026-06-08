<?php
/**
 * Pure assembly of a gallery image's caption string from its path.
 *
 * Canvas re-encoding strips all EXIF/IPTC at ingestion, so there is no embedded
 * caption or capture date to draw on — a caption is derived from the filename or
 * the folder path alone (docs/blocks.md). This helper is that derivation: given
 * an image's collection-root-relative path, the original filename, and the
 * caption attributes, it returns the caption text. Three contents are supported
 * — none, filename, and a path breadcrumb — each with an optional humanise pass
 * (strip the stored `.webp`, drop the extension, turn separators into spaces),
 * and the breadcrumb optionally prefixed with the collection's display name and
 * joined by a configurable separator. The result is plain text; escaping and
 * placement are the renderer's job.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Collection\Image_Name;

/**
 * Derives the caption text for one image from its path and the caption settings.
 *
 * A pure, total function of its inputs — no I/O — so every content/humanise/
 * separator combination is unit-testable. The single entry point `build()`
 * returns the empty string for the `none` content (the renderer then emits no
 * caption element at all) and the assembled text otherwise. Humanisation maps
 * the stored `<original>.webp` name back to the original first (so the appended
 * `.webp` never leaks into a caption), then strips the remaining extension and
 * normalises separators to spaces.
 *
 * @since 0.6.0
 */
final class Caption_Builder {

	/**
	 * The `captionContent` value that suppresses the caption entirely.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const CONTENT_NONE = 'none';

	/**
	 * The `captionContent` value that captions with the filename only.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const CONTENT_FILENAME = 'filename';

	/**
	 * The `captionContent` value that captions with the folder-path breadcrumb.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	public const CONTENT_PATH = 'path';

	/**
	 * Builds the caption text for one image, or `''` when none is wanted.
	 *
	 * Dispatches on the caption content: `none` yields the empty string;
	 * `filename` yields the image's own name (humanised when asked); `path`
	 * yields a breadcrumb of the folder segments and the filename, joined by the
	 * separator, optionally prefixed with the collection's display name. Every
	 * segment is humanised together when humanisation is on, so a breadcrumb reads
	 * as words rather than raw directory names.
	 *
	 * @since 0.6.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $content         One of CONTENT_NONE, CONTENT_FILENAME, CONTENT_PATH.
	 * @param bool   $humanize        Whether to strip extensions and normalise separators to spaces.
	 * @param bool   $include_name    Whether a path breadcrumb is prefixed with the collection name.
	 * @param string $separator       The breadcrumb separator between segments.
	 * @param string $collection_name The collection's display name, used only when prefixing.
	 * @return string The caption text, or `''` when the content is `none`.
	 */
	public static function build(
		string $relative_path,
		string $content,
		bool $humanize,
		bool $include_name,
		string $separator,
		string $collection_name,
	): string {

		// Dispatch on the requested content; an unrecognised content is treated as
		// "none" so a stray attribute value can never emit a malformed caption.
		return match ( $content ) {
			self::CONTENT_FILENAME => self::filename_caption( $relative_path, $humanize ),
			self::CONTENT_PATH     => self::path_caption(
				$relative_path,
				$humanize,
				$include_name,
				$separator,
				$collection_name,
			),
			default => '',
		};

	}

	/**
	 * Builds a filename-only caption for one image.
	 *
	 * Takes the last path segment (the filename), maps it back from its stored
	 * `<original>.webp` form to the original name, and humanises it when asked.
	 *
	 * @since 0.6.0
	 *
	 * @param string $relative_path The image path relative to the collection root.
	 * @param bool   $humanize      Whether to humanise the filename.
	 * @return string The filename caption.
	 */
	private static function filename_caption( string $relative_path, bool $humanize ): string {

		// The filename is the final path segment; everything before it is the
		// folder context a filename caption deliberately omits.
		$slash    = strrpos( $relative_path, '/' );
		$filename = $slash === false ? $relative_path : substr( $relative_path, $slash + 1 );

		return self::humanize_segment( $filename, $humanize );

	}

	/**
	 * Builds a path-breadcrumb caption for one image.
	 *
	 * Splits the relative path into folder segments and the filename, humanises
	 * each segment when asked, optionally prepends the collection display name,
	 * and joins everything with the separator padded by single spaces. A
	 * root-level image yields just its filename (plus the optional collection
	 * prefix), since there are no folder segments to show.
	 *
	 * @since 0.6.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param bool   $humanize        Whether to humanise each segment.
	 * @param bool   $include_name    Whether to prefix the breadcrumb with the collection name.
	 * @param string $separator       The breadcrumb separator.
	 * @param string $collection_name The collection's display name.
	 * @return string The breadcrumb caption.
	 */
	private static function path_caption(
		string $relative_path,
		bool $humanize,
		bool $include_name,
		string $separator,
		string $collection_name,
	): string {

		// Split into segments, humanising the filename (the last segment) via the
		// stored-name mapping and each folder segment as a plain directory name.
		$segments = explode( '/', $relative_path );
		$last      = count( $segments ) - 1;
		$crumbs    = [];
		foreach ( $segments as $position => $segment ) {
			$is_filename = $position === $last;
			$crumbs[]    = $is_filename
				? self::humanize_segment( $segment, $humanize )
				: self::humanize_directory( $segment, $humanize );
		}

		// Prefix the collection's display name when asked, so the breadcrumb reads
		// from the collection down to the image.
		if ( $include_name && $collection_name !== '' ) {
			array_unshift( $crumbs, $collection_name );
		}

		// Join with the separator padded by spaces; an empty separator collapses to
		// a single space so segments never run together.
		$glue = $separator === '' ? ' ' : ' ' . $separator . ' ';

		return implode( $glue, $crumbs );

	}

	/**
	 * Humanises a filename segment, mapping it back from its stored form first.
	 *
	 * The stored name is `<original>.webp`, so the original is recovered before
	 * any humanisation, ensuring the appended `.webp` never appears in a caption.
	 * When humanisation is on, the remaining extension is dropped and separators
	 * (`_`, `-`, `.`) become spaces; when off, the recovered original name is
	 * returned verbatim.
	 *
	 * @since 0.6.0
	 *
	 * @param string $filename The stored filename segment.
	 * @param bool   $humanize Whether to humanise.
	 * @return string The display text for the filename.
	 */
	private static function humanize_segment( string $filename, bool $humanize ): string {

		// Recover the original name from the stored `<original>.webp` form so the
		// appended extension never leaks, whether or not we humanise further.
		$original = Image_Name::to_original( $filename );
		if ( ! $humanize ) {
			return $original;
		}

		// Drop the remaining extension (the original's own, e.g. `.jpg`) and turn
		// the common filename separators into spaces, then collapse runs of space.
		$dot       = strrpos( $original, '.' );
		$base      = $dot === false || $dot === 0 ? $original : substr( $original, 0, $dot );
		$spaced    = str_replace( [ '_', '-', '.' ], ' ', $base );
		$collapsed = preg_replace( '/\s+/', ' ', $spaced );

		return trim( $collapsed ?? $spaced );

	}

	/**
	 * Humanises a directory segment of a breadcrumb.
	 *
	 * A directory has no stored-name suffix to strip, so humanisation only turns
	 * the filename separators into spaces and collapses the result; with
	 * humanisation off the raw directory name is returned.
	 *
	 * @since 0.6.0
	 *
	 * @param string $directory The directory segment.
	 * @param bool   $humanize  Whether to humanise.
	 * @return string The display text for the directory.
	 */
	private static function humanize_directory( string $directory, bool $humanize ): string {

		// Without humanisation the raw directory name stands; otherwise normalise
		// its separators to spaces and collapse runs of whitespace.
		if ( ! $humanize ) {
			return $directory;
		}
		$spaced    = str_replace( [ '_', '-', '.' ], ' ', $directory );
		$collapsed = preg_replace( '/\s+/', ' ', $spaced );

		return trim( $collapsed ?? $spaced );

	}

}
