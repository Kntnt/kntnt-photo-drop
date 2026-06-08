<?php
/**
 * Pure URL arithmetic for a gallery image's main and thumbnail renditions.
 *
 * Images are served directly by URL — collections are public-by-path (ADR-0001),
 * so the gallery builds each `<img>` source from the uploads-root *URL* plus the
 * same relative path the filesystem uses, never through the Media Library. This
 * helper is the single place that arithmetic lives: given the collection's base
 * URL and an image's collection-root-relative path, it returns the main image
 * URL and, for any thumbnail width, the URL of that width's rendition inside the
 * hidden `.kntnt-thumbnails/<width>/` directory.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Storage\Index;

/**
 * Builds main and thumbnail URLs from a base URL and a relative image path.
 *
 * Every method is a pure, total function of its arguments — no filesystem
 * access, no WordPress calls — so the URL assembly is unit-testable in
 * isolation. The base URL is the collection root's URL (the uploads-root URL
 * plus the slug), passed without a trailing slash; each segment of the relative
 * path is URL-encoded so a filename with spaces or unicode yields a valid URL.
 *
 * @since 0.6.0
 */
final class Image_Url {

	/**
	 * Returns the main image URL for a collection-root-relative path.
	 *
	 * The main image lives at its relative path under the collection root, so the
	 * URL is the base URL with each path segment percent-encoded and re-joined.
	 *
	 * @since 0.6.0
	 *
	 * @param string $base_url      The collection root URL, without a trailing slash.
	 * @param string $relative_path The image path relative to the collection root.
	 * @return string The absolute URL of the main image.
	 */
	public static function main( string $base_url, string $relative_path ): string {
		return self::join( $base_url, $relative_path );
	}

	/**
	 * Returns the thumbnail URL for a relative path at a given width.
	 *
	 * A thumbnail sits beside its main image inside that folder's hidden
	 * `.kntnt-thumbnails/<width>/` directory under the same filename, so the URL
	 * is built from the image's directory, the hidden directory, the width, and
	 * the filename. A root-level image's thumbnails live directly under the
	 * collection root's hidden directory.
	 *
	 * @since 0.6.0
	 *
	 * @param string $base_url      The collection root URL, without a trailing slash.
	 * @param string $relative_path The main image path relative to the collection root.
	 * @param int    $width         The thumbnail width whose rendition is wanted.
	 * @return string The absolute URL of the thumbnail at the given width.
	 */
	public static function thumbnail( string $base_url, string $relative_path, int $width ): string {

		// Split the relative path into its directory and filename so the hidden
		// thumbnails directory can be spliced in between them.
		$slash     = strrpos( $relative_path, '/' );
		$directory = $slash === false ? '' : substr( $relative_path, 0, $slash );
		$file      = $slash === false ? $relative_path : substr( $relative_path, $slash + 1 );

		// Assemble the thumbnail's relative path: <dir>/.kntnt-thumbnails/<width>/<file>,
		// dropping the empty directory segment for a root-level image.
		$thumb_dir  = Index::THUMBNAILS_DIRNAME . '/' . $width;
		$thumb_path = $directory === '' ? $thumb_dir . '/' . $file : $directory . '/' . $thumb_dir . '/' . $file;

		return self::join( $base_url, $thumb_path );

	}

	/**
	 * Joins a base URL and a relative path, percent-encoding each path segment.
	 *
	 * Each segment is encoded with `rawurlencode()` so spaces, unicode, and other
	 * reserved characters in a filename produce a valid URL, while the slashes
	 * that separate segments are preserved. The base URL is taken verbatim (it is
	 * plugin-built, not user input).
	 *
	 * @since 0.6.0
	 *
	 * @param string $base_url      The base URL, without a trailing slash.
	 * @param string $relative_path The relative path to append.
	 * @return string The joined, segment-encoded URL.
	 */
	private static function join( string $base_url, string $relative_path ): string {

		// Encode each non-empty segment independently so the separating slashes
		// survive while the segment contents are made URL-safe.
		$encoded = array_map(
			static fn ( string $segment ): string => rawurlencode( $segment ),
			array_filter( explode( '/', $relative_path ), static fn ( string $segment ): bool => $segment !== '' ),
		);

		return rtrim( $base_url, '/' ) . '/' . implode( '/', $encoded );

	}

}
