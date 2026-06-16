<?php
/**
 * The six per-field rendition defaults that pre-fill a create form.
 *
 * Establishing a collection needs a default for each of the six rendition knobs
 * when a flag or form field is left blank (ADR-0013). Each default reads one
 * `kntnt_photo_drop_default_{upload,full,thumbnail}_{width,quality}` filter so a
 * site can change the starting point without touching code, and hardens the
 * filter's return so a buggy filter can never seed a degenerate collection.
 * Both the admin Create form (pre-fill) and the CLI `collection create` (flag
 * default) read these, keeping the two lifecycle surfaces in step.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * Resolves the filter-driven defaults for the six rendition settings.
 *
 * Every method is a thin, hardened read of one filter: a positive-int width, a
 * 0–100 quality, and the nullable upload width whose "no value" means the
 * source's own dimensions. The hardening lives in two private helpers shared by
 * the five concrete fields, so a malformed filter return falls back to the
 * documented default rather than seeding a degenerate collection.
 *
 * @since 0.7.0
 */
final class Rendition_Defaults {

	/**
	 * Returns the default upload-width ceiling, or null for the source dimensions.
	 *
	 * The upload width is the only nullable rendition field: its documented default
	 * is "the source's own dimensions" (`null`), and a filter return that is null,
	 * non-integer, or non-positive collapses to that same null — there is no
	 * fallback ceiling to invent.
	 *
	 * @since 0.7.0
	 *
	 * @return int|null The default upload-width ceiling, or null for the source dimensions.
	 */
	public static function upload_width(): ?int {

		// Only a strictly-positive integer is a real ceiling; everything else
		// (null, 0, negative, non-integer) means the source's own dimensions.
		$filtered = apply_filters( 'kntnt_photo_drop_default_upload_width', null );
		return is_int( $filtered ) && $filtered > 0 ? $filtered : null;

	}

	/**
	 * Returns the default upload (main) quality, hardened to 0–100.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default upload quality.
	 */
	public static function upload_quality(): int {
		return self::quality( 'kntnt_photo_drop_default_upload_quality', 95 );
	}

	/**
	 * Returns the default full-image width, hardened to a positive integer.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default full-image width.
	 */
	public static function full_width(): int {
		return self::width( 'kntnt_photo_drop_default_full_width', 1920 );
	}

	/**
	 * Returns the default full-image quality, hardened to 0–100.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default full-image quality.
	 */
	public static function full_quality(): int {
		return self::quality( 'kntnt_photo_drop_default_full_quality', 85 );
	}

	/**
	 * Returns the default thumbnail width, hardened to a positive integer.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default thumbnail width.
	 */
	public static function thumbnail_width(): int {
		return self::width( 'kntnt_photo_drop_default_thumbnail_width', 640 );
	}

	/**
	 * Returns the default thumbnail quality, hardened to 0–100.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default thumbnail quality.
	 */
	public static function thumbnail_quality(): int {
		return self::quality( 'kntnt_photo_drop_default_thumbnail_quality', 75 );
	}

	/**
	 * Reads a positive-integer width filter, falling back to a default.
	 *
	 * A width must be a strictly-positive integer; a non-positive or non-integer
	 * filter return is unusable and falls back to the documented default.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook    The width filter name.
	 * @param int    $default The documented default width.
	 * @return int The resolved width.
	 */
	private static function width( string $hook, int $default ): int {
		$filtered = apply_filters( $hook, $default );
		return is_int( $filtered ) && $filtered > 0 ? $filtered : $default;
	}

	/**
	 * Reads a 0–100 quality filter, falling back to a default.
	 *
	 * A quality must be an integer in 0–100; anything outside that range (or
	 * non-integer) falls back to the documented default.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook    The quality filter name.
	 * @param int    $default The documented default quality.
	 * @return int The resolved quality.
	 */
	private static function quality( string $hook, int $default ): int {
		$filtered = apply_filters( $hook, $default );
		return is_int( $filtered ) && $filtered >= 0 && $filtered <= 100 ? $filtered : $default;
	}

}
