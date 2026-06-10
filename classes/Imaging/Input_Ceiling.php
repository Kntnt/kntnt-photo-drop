<?php
/**
 * The megapixel input ceiling every decode path must respect.
 *
 * Decoding allocates roughly four bytes per pixel, so a 100-megapixel source —
 * or a small PNG whose header *declares* such dimensions, the classic
 * decompression bomb — OOM-kills the PHP worker with an uncatchable fatal: the
 * uploader sees a dead connection and a CLI batch dies mid-run. The ceiling is
 * checked against the header probe's declared dimensions *before* any pixel
 * buffer is allocated, in every place the plugin decodes (the optimiser's
 * paths and the thumbnailer's main-image decode), so the knowledge lives in
 * exactly one class.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * Decides whether declared image dimensions are safe to decode.
 *
 * The ceiling defaults to 50 megapixels — comfortably above any current phone
 * sensor while far below the area that exhausts a typical PHP memory limit —
 * and is tunable via the `kntnt_photo_drop_max_input_megapixels` filter. A
 * filter returning anything but a positive number is a misuse and falls back
 * to the default rather than silently disabling the guard. Stateless; both
 * members are static.
 *
 * @since 0.2.0
 */
final class Input_Ceiling {

	/**
	 * The default ceiling in megapixels when the filter leaves it untouched.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private const DEFAULT_MAX_MEGAPIXELS = 50;

	/**
	 * Reports whether a declared pixel area is within the input ceiling.
	 *
	 * The multiplication is done in float so a hostile header declaring
	 * near-`PHP_INT_MAX` dimensions cannot overflow the check itself.
	 *
	 * @since 0.2.0
	 *
	 * @param int $width  The probed (declared) width in pixels.
	 * @param int $height The probed (declared) height in pixels.
	 * @return bool True when decoding this area is allowed.
	 */
	public static function allows( int $width, int $height ): bool {
		return (float) $width * (float) $height <= self::max_megapixels() * 1_000_000;
	}

	/**
	 * Resolves the ceiling in megapixels through the filter, hardened.
	 *
	 * @since 0.2.0
	 *
	 * @return float The ceiling in megapixels, always positive.
	 */
	private static function max_megapixels(): float {

		// Apply the filter and validate its return: only a positive int or float
		// is accepted, so a buggy filter can never disable the OOM guard.
		$filtered = apply_filters( 'kntnt_photo_drop_max_input_megapixels', self::DEFAULT_MAX_MEGAPIXELS );
		if ( ( is_int( $filtered ) || is_float( $filtered ) ) && $filtered > 0 ) {
			return (float) $filtered;
		}

		return (float) self::DEFAULT_MAX_MEGAPIXELS;

	}

}
