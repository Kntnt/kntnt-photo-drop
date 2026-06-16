<?php
/**
 * Pure assembly of a gallery image's breadcrumb overlay from its path.
 *
 * Breadcrumbs replace the former caption (ADR-0015): the image's humanised
 * folder path, with the **collection's display name as the first crumb**, shown
 * on its own line over the image. Canvas re-encoding strips all EXIF/IPTC at
 * ingestion, so there is no embedded caption or capture date — the crumbs are
 * derived from the path alone. Unlike the old caption there is no humanise
 * toggle and no filename-vs-path choice: segments are *always* humanised (the
 * stored `<original>.webp` name maps back to its original first, then the
 * extension is dropped and separators become spaces), and the breadcrumb is
 * always the full path. A hide-count drops that many leading crumbs (the
 * collection name first), never dropping the final filename crumb, so the
 * overlay always names the image it sits on. The result is plain text; escaping,
 * the leading-ellipsis overflow, and placement are the renderer's job.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Collection\Image_Name;

/**
 * Derives the breadcrumb crumbs and text for one gallery image.
 *
 * A pure, total function of its inputs — no I/O — so every hide-count and path
 * shape is unit-testable. `crumbs()` is the canonical derivation (the ordered
 * list of display segments), `text()` is the separator-joined string the gallery
 * mirrors onto the anchor, and `humanise_filename()` is the alt-text helper (the
 * filename segment alone, humanised). All humanisation routes through the same
 * private `humanise()`, so a folder segment and a filename segment normalise
 * identically — the only difference is that a filename is first mapped back from
 * its stored `<original>.webp` form.
 *
 * @since 0.11.0
 */
final class Breadcrumb_Builder {

	/**
	 * Returns the ordered breadcrumb crumbs for one image.
	 *
	 * Builds the full list — the collection display name, then each humanised
	 * folder segment, then the humanised filename — and drops the leading
	 * `$hide_count` crumbs. The final crumb (the image's own name) is always
	 * kept, so a hide-count at or beyond the crumb count still names the image
	 * rather than yielding a blank breadcrumb; a negative hide-count is treated
	 * as zero. An empty collection name contributes no leading crumb, so the
	 * path simply starts at its first real segment.
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $collection_name The collection's display name (the first crumb).
	 * @param int    $hide_count      How many leading crumbs to drop (clamped to ≥ 0).
	 * @return array<int,string> The crumbs to show, in order.
	 */
	public static function crumbs( string $relative_path, string $collection_name, int $hide_count ): array {

		// Split the path into folder segments and the filename, humanising each
		// segment; the filename (last segment) is first mapped back from its stored
		// `<original>.webp` form so the appended extension never leaks.
		$segments = explode( '/', $relative_path );
		$last     = count( $segments ) - 1;
		$crumbs   = [];
		foreach ( $segments as $position => $segment ) {
			$source   = $position === $last ? Image_Name::to_original( $segment ) : $segment;
			$crumbs[] = self::humanise( $source );
		}

		// Prefix the collection display name as the first crumb, unless it is empty
		// (no display name → no leading crumb, the path starts at its first segment).
		if ( $collection_name !== '' ) {
			array_unshift( $crumbs, $collection_name );
		}

		// Drop the leading crumbs the hide-count asks for, but never the final
		// filename crumb: the breadcrumb must always name the image it sits on, so
		// the count is clamped to ≥ 0 and capped one below the crumb total.
		$drop = max( 0, min( $hide_count, count( $crumbs ) - 1 ) );

		return array_slice( $crumbs, $drop );

	}

	/**
	 * Returns the breadcrumb text — the crumbs joined by the separator.
	 *
	 * Joins the {@see crumbs()} with the separator padded by single spaces; an
	 * empty separator collapses to a single space so crumbs never run together.
	 * This is the string the gallery mirrors onto the anchor's
	 * `data-kntnt-photo-drop-breadcrumbs` so the lightbox shows the same overlay.
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $collection_name The collection's display name (the first crumb).
	 * @param int    $hide_count      How many leading crumbs to drop.
	 * @param string $separator       The crumb separator (free text).
	 * @return string The joined breadcrumb text.
	 */
	public static function text(
		string $relative_path,
		string $collection_name,
		int $hide_count,
		string $separator,
	): string {

		// Join the crumbs with the separator padded by spaces; an empty separator
		// collapses to a single space so adjacent crumbs never abut.
		$crumbs = self::crumbs( $relative_path, $collection_name, $hide_count );
		$glue   = $separator === '' ? ' ' : ' ' . $separator . ' ';

		return implode( $glue, $crumbs );

	}

	/**
	 * Returns the humanised filename of an image, for its `alt` text.
	 *
	 * The image's `alt` is its own name made readable: the final path segment,
	 * mapped back from its stored `<original>.webp` form and humanised the same
	 * way a breadcrumb crumb is. Kept beside the breadcrumb assembly so the
	 * filename humanisation has one source.
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path The image path relative to the collection root.
	 * @return string The humanised filename.
	 */
	public static function humanise_filename( string $relative_path ): string {

		// Take the final path segment (the filename) and humanise it via the
		// stored-name mapping; the folder context is deliberately omitted from alt.
		$slash    = strrpos( $relative_path, '/' );
		$filename = $slash === false ? $relative_path : substr( $relative_path, $slash + 1 );

		return self::humanise( Image_Name::to_original( $filename ) );

	}

	/**
	 * Humanises one already-original segment into readable display text.
	 *
	 * Drops a trailing extension (the original's own, e.g. `.jpg`, or a folder
	 * that happens to contain a dot is left alone since a leading-dot or
	 * extensionless name has nothing to strip), turns the common filename
	 * separators (`_`, `-`, `.`) into spaces, and collapses runs of whitespace.
	 * The caller has already mapped a stored `<original>.webp` filename back to
	 * its original, so this never sees the appended `.webp`.
	 *
	 * @since 0.11.0
	 *
	 * @param string $segment The path segment (filename already de-stored).
	 * @return string The humanised display text.
	 */
	private static function humanise( string $segment ): string {

		// Drop a trailing extension when there is one (not a leading dot), then
		// normalise the filename separators to spaces and collapse runs of space.
		$dot       = strrpos( $segment, '.' );
		$base      = $dot === false || $dot === 0 ? $segment : substr( $segment, 0, $dot );
		$spaced    = str_replace( [ '_', '-', '.' ], ' ', $base );
		$collapsed = preg_replace( '/\s+/', ' ', $spaced );

		return trim( $collapsed ?? $spaced );

	}

}
