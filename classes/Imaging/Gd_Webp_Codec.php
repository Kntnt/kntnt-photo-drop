<?php
/**
 * The GD-backed WebP codec — the tested production path.
 *
 * Implements the codec seam with PHP's GD extension: `getimagesizefromstring()`
 * to probe, `imagecreatefromstring()` to decode any format GD recognises,
 * `imagescale()` to downscale, and `imagewebp()` (captured through an output
 * buffer) to encode. Alpha is preserved across decode, scale, and encode so a
 * transparent PNG becomes a transparent WebP. The host runs GD with WebP
 * support and no Imagick, so this is the codec the plugin actually exercises;
 * the Imagick codec exists only for portability.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * A `Webp_Codec` built on the GD extension.
 *
 * Every method degrades to `null` rather than throwing when GD cannot perform an
 * operation, so a single undecodable source becomes a rejected file instead of a
 * fatal error in the middle of a batch. Holds no state; one instance serves any
 * number of optimisations.
 *
 * @since 0.3.0
 */
final class Gd_Webp_Codec implements Webp_Codec {

	/**
	 * Reports whether GD is loaded with WebP encode support.
	 *
	 * Requires both the extension and `imagewebp()`; a GD built without WebP
	 * lacks the function, in which case this codec cannot be the working one.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True when GD can encode WebP.
	 */
	public function is_supported(): bool {
		return extension_loaded( 'gd' ) && function_exists( 'imagewebp' );
	}

	/**
	 * Probes raw bytes for width and WebP-ness via `getimagesizefromstring()`.
	 *
	 * Reads only the header, so the accept-as-is decision costs no full decode.
	 * Returns `null` for anything GD does not recognise as an image, which the
	 * optimiser maps to a rejected source.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return array{0:int,1:bool}|null `[ $width, $is_webp ]`, or null when undecodable.
	 */
	public function probe( string $bytes ): ?array {

		// A header probe yields the dimensions and type without allocating a pixel
		// buffer; a false result means the bytes are not a recognised image.
		$info = getimagesizefromstring( $bytes );
		if ( ! is_array( $info ) ) {
			return null;
		}

		// A non-positive width is not a usable image; treat it as undecodable.
		$width = $info[0];
		if ( $width <= 0 ) {
			return null;
		}

		return [ $width, $info[2] === IMAGETYPE_WEBP ];

	}

	/**
	 * Decodes raw bytes into a GD image handle with alpha preserved.
	 *
	 * `imagecreatefromstring()` auto-detects the format. Alpha blending is turned
	 * off and alpha saving on so a transparent source survives a later encode;
	 * GD otherwise flattens transparency on output.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return object|null The GD handle, or null when the bytes are undecodable.
	 */
	public function decode( string $bytes ): ?object {

		// Decode any GD-supported format; a false return is an undecodable source.
		// The silence is deliberate: GD emits a warning for malformed bytes, which
		// is exactly the hostile-input case the false return already handles.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed source bytes are an expected, handled case (false return), not a bug to surface.
		$image = @imagecreatefromstring( $bytes );
		if ( $image === false ) {
			return null;
		}

		// Preserve transparency through subsequent scale and encode steps.
		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		return $image;

	}

	/**
	 * Returns the pixel width of a GD handle.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image The GD image handle.
	 * @return int The width in pixels.
	 */
	public function width( object $image ): int {
		return $image instanceof \GdImage ? imagesx( $image ) : 0;
	}

	/**
	 * Downscales a GD handle to an exact width, keeping aspect and alpha.
	 *
	 * Uses the single-argument form of `imagescale()`, which derives a
	 * proportional height itself (clamping an extreme aspect ratio to at least one
	 * pixel) under GD's default bilinear-fixed resampling. The explicit-height
	 * form is deliberately avoided: combined with an interpolation filter it fails
	 * outright on some GD builds, whereas the width-only form is reliable
	 * everywhere. Alpha is re-armed on the fresh handle so transparency survives
	 * the encode step.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image        The GD source handle.
	 * @param int    $target_width The exact width the result must have.
	 * @return object|null The scaled GD handle, or null when scaling failed.
	 */
	public function scale( object $image, int $target_width ): ?object {

		// Only a real GD handle can be scaled; anything else is a programming error
		// upstream, surfaced here as a failed scale rather than a fatal type error.
		if ( ! $image instanceof \GdImage ) {
			return null;
		}

		// Scale to the exact width and let GD derive a proportional height; a false
		// return is a failure the optimiser reports as a rejected source.
		$scaled = imagescale( $image, $target_width );
		if ( $scaled === false ) {
			return null;
		}

		// Re-arm alpha preservation on the fresh handle so transparency survives
		// the encode step.
		imagealphablending( $scaled, false );
		imagesavealpha( $scaled, true );

		return $scaled;

	}

	/**
	 * Encodes a GD handle to WebP bytes at the descriptor's quality.
	 *
	 * `imagewebp()` writes to a stream, so the output is captured through an
	 * output buffer and returned as a byte string. An empty capture means the
	 * encode failed and is reported as `null`.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image   The GD handle to encode.
	 * @param int    $quality The WebP quality (0–100) from the descriptor.
	 * @return string|null The encoded WebP bytes, or null on failure.
	 */
	public function encode( object $image, int $quality ): ?string {

		// Only a real GD handle can be encoded.
		if ( ! $image instanceof \GdImage ) {
			return null;
		}

		// Capture imagewebp()'s stream output as a string; a failed encode or an
		// empty buffer yields null so the caller never writes a half-formed main.
		ob_start();
		$ok    = imagewebp( $image, null, $quality );
		$bytes = ob_get_clean();
		if ( ! $ok || ! is_string( $bytes ) || $bytes === '' ) {
			return null;
		}

		return $bytes;

	}

}
