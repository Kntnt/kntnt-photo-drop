<?php
/**
 * The `<original>.webp` main-image naming rule.
 *
 * A main image is stored under its original filename with `.webp` appended,
 * except an input that is already WebP (case-insensitive) is not doubled. The
 * rule is reversible: the stored name maps back to the original for display.
 * On the case-sensitive Linux server the mapping is collision-free, because
 * two distinct originals can only collide if they differ solely in case, which
 * the case-sensitive filesystem already keeps apart.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

/**
 * Converts between an original filename and its stored `<original>.webp` form.
 *
 * Both directions are pure, total functions of the filename alone; they touch
 * no filesystem and hold no state, so every method is static. The boundary is
 * deliberately narrow — two methods, to-stored and to-original — because every
 * ingestion path (the Drop Zone endpoint and `image import`) must name files
 * identically, and the gallery must reverse that naming for display.
 *
 * @since 0.1.0
 */
final class Image_Name {

	/**
	 * The extension every stored main image carries, including the leading dot.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const WEBP_EXTENSION = '.webp';

	/**
	 * Returns the stored filename for an original image filename.
	 *
	 * Appends `.webp` to the original name, except when the name already ends
	 * in `.webp` (compared case-insensitively), in which case it is returned
	 * unchanged so the extension is never doubled. The original may carry any
	 * number of other dots (`a.b.c.jpg`) and any Unicode characters; only the
	 * trailing `.webp` is special.
	 *
	 * @since 0.1.0
	 *
	 * @param string $original_name The original filename, without any path.
	 * @return string The stored filename ending in a single `.webp`.
	 */
	public static function to_stored( string $original_name ): string {

		// An already-WebP input keeps its name; anything else gains the suffix.
		if ( self::is_webp( $original_name ) ) {
			return $original_name;
		}

		return $original_name . self::WEBP_EXTENSION;

	}

	/**
	 * Returns the original filename for a stored filename.
	 *
	 * `to_stored()` preserves the original name and appends `.webp`, so a name
	 * the plugin produced is the original *with its own extension intact*
	 * followed by `.webp` (`IMG_2024.jpg.webp`). The reverse therefore strips a
	 * trailing `.webp` only when the remainder still carries an extension —
	 * i.e. contains a dot. A single-extension `.webp` (`sunset.webp`) was an
	 * already-WebP original that `to_stored()` left alone, so it maps back to
	 * itself; stripping it would invent an extensionless name that never
	 * existed.
	 *
	 * This makes the pair reversible for every realistic input: an original
	 * carrying any extension (the universal case for a photo file) round-trips
	 * exactly. The one input that cannot round-trip — an extensionless original
	 * like `sunset` — collides on disk with the already-WebP `sunset.webp` and
	 * is resolved here in favour of the latter, which is the spec's choice.
	 *
	 * @since 0.1.0
	 *
	 * @param string $stored_name The stored filename, without any path.
	 * @return string The original filename for display.
	 */
	public static function to_original( string $stored_name ): string {

		// A name that is not WebP cannot be one we produced; return it as-is.
		if ( ! self::is_webp( $stored_name ) ) {
			return $stored_name;
		}

		// Strip the trailing `.webp` only when the remainder still has an
		// extension (a dot). A remainder with no dot was an already-WebP
		// original stored verbatim, so the stored name *is* the original.
		$without_suffix = substr( $stored_name, 0, -strlen( self::WEBP_EXTENSION ) );
		if ( ! str_contains( $without_suffix, '.' ) ) {
			return $stored_name;
		}

		return $without_suffix;

	}

	/**
	 * Reports whether a basename is a stored main image's filename.
	 *
	 * The naming side of "is this a main image": a stored main is a filename
	 * already in its `to_stored()` form (so it is idempotent under the naming
	 * rule — `IMG.jpg.webp`, not the un-stored `IMG.jpg`) that ends in `.webp`
	 * (case-insensitive). It is a pure check of the name alone — it neither
	 * touches the filesystem nor knows about a file's *location*, so a caller
	 * that must also exclude a thumbnail (a derived `<name>.webp` living under
	 * the hidden artifacts directory) adds that path-based check on top. This is
	 * the single authoritative source the doctor's classification and the
	 * gallery's add-to-media gate both read, so the two never drift (ADR-0013).
	 *
	 * @since 0.11.0
	 *
	 * @param string $name The basename to inspect, without any path.
	 * @return bool True when the basename is a stored main image's filename.
	 */
	public static function is_stored_main( string $name ): bool {
		return self::to_stored( $name ) === $name && self::is_webp( $name );
	}

	/**
	 * Reports whether a filename ends in `.webp`, compared case-insensitively.
	 *
	 * Uses a case-insensitive suffix test so `Photo.WEBP`, `image.WebP`, and
	 * `clip.webp` are all recognised as WebP and never doubled.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The filename to inspect.
	 * @return bool True when the name ends in `.webp` in any letter case.
	 */
	private static function is_webp( string $name ): bool {
		return str_ends_with( strtolower( $name ), self::WEBP_EXTENSION );
	}

}
