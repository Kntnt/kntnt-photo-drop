<?php
/**
 * The production dimension reader, backed by WordPress / GD image inspection.
 *
 * Resolves a main image's width and height with `wp_getimagesize()` when
 * WordPress is loaded (the same call core uses, which falls through to GD), and
 * with the plain `getimagesize()` otherwise so the reader still works in a unit
 * runtime without a full WordPress install. It reads each file exactly once and
 * holds no state, so the index can call it per main image during a rebuild and
 * never on a cache hit.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * Reads image dimensions via `wp_getimagesize()` / `getimagesize()`.
 *
 * The default `Dimension_Reader` bound in production. It treats an unreadable
 * or undecodable file as "no dimensions" (`null`) rather than throwing, so one
 * bad file during a rebuild degrades to a skipped entry instead of aborting the
 * whole index.
 *
 * @since 0.1.0
 */
final class Wp_Dimension_Reader implements Dimension_Reader {

	/**
	 * Returns the pixel dimensions of an image file, or null when unreadable.
	 *
	 * Prefers `wp_getimagesize()` (WordPress's hardened wrapper) and falls back
	 * to `getimagesize()` outside a WordPress runtime. Both return a `[ width,
	 * height, … ]` array on success and `false` on failure; a `false`, or a
	 * non-positive dimension, yields `null` so the index records nothing for a
	 * file it could not measure.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @return array{0:int,1:int}|null `[ $width, $height ]`, or null when unreadable.
	 */
	public function dimensions( string $file_path ): ?array {

		// Inspect the file once with WordPress's wrapper when available, plain
		// getimagesize() otherwise; both yield the dimensions or false.
		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file_path ) : getimagesize( $file_path );
		if ( ! is_array( $size ) ) {
			return null;
		}

		// A valid image reports two positive integer leading dimensions; reject
		// anything else so a zero-sized or malformed result never enters the index.
		$width  = $size[0] ?? 0;
		$height = $size[1] ?? 0;
		if ( ! is_int( $width ) || ! is_int( $height ) || $width <= 0 || $height <= 0 ) {
			return null;
		}

		return [ $width, $height ];

	}

}
