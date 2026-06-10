<?php
/**
 * The GD-backed WebP codec — the tested production path.
 *
 * Implements the codec seam with PHP's GD extension: `getimagesizefromstring()`
 * to probe, `imagecreatefromstring()` to decode any format GD recognises,
 * `imagescale()` to downscale, and `imagewebp()` (captured through an output
 * buffer) to encode. Decoding promotes palette handles to truecolor (GD cannot
 * encode a palette handle to WebP) and physically uprights EXIF-oriented
 * sources; alpha is preserved across decode, scale, and encode so a transparent
 * PNG becomes a transparent WebP. The host runs GD with WebP support and no
 * Imagick, so this is the codec the plugin actually exercises; the Imagick
 * codec exists only for portability.
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
 * operation — including GD's own throws (`imagescale()` raises a `ValueError`
 * for degenerate geometry), which are caught and mapped to `null` — so a single
 * undecodable source becomes a rejected file instead of a fatal error in the
 * middle of a batch. Holds no state; one instance serves any number of
 * optimisations.
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
	 * Probes raw bytes for dimensions and WebP-ness via `getimagesizefromstring()`.
	 *
	 * Reads only the header, so callers can apply the megapixel input ceiling
	 * before any pixel buffer is allocated. Returns `null` for anything GD does
	 * not recognise as an image, which the optimiser maps to a rejected source.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return array{width: int, height: int, is_webp: bool}|null The probed facts, or null when unrecognisable.
	 */
	public function probe( string $bytes ): ?array {

		// A header probe yields the dimensions and type without allocating a pixel
		// buffer; a false result means the bytes are not a recognised image. The
		// silence is deliberate: hostile bytes (zero-length, garbage) make the
		// probe warn, which is exactly the case the false return already handles.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unrecognisable source bytes are an expected, handled case (false return), not a bug to surface.
		$info = @getimagesizefromstring( $bytes );
		if ( ! is_array( $info ) ) {
			return null;
		}

		// Non-positive dimensions are not a usable image; treat as unrecognisable.
		$width  = $info[0];
		$height = $info[1];
		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return [
			'width'   => $width,
			'height'  => $height,
			'is_webp' => $info[2] === IMAGETYPE_WEBP,
		];

	}

	/**
	 * Decodes raw bytes into an upright, truecolor GD handle with alpha preserved.
	 *
	 * `imagecreatefromstring()` auto-detects the format. A palette handle (a
	 * palette PNG or GIF) is promoted to truecolor because `imagewebp()` cannot
	 * encode palette images; the promotion carries palette transparency over as
	 * alpha. Alpha blending is turned off and alpha saving on so a transparent
	 * source survives a later encode. Finally, when the source carries an EXIF
	 * Orientation tag, the pixels are physically rotated/flipped to upright —
	 * the encode strips the tag, so the pixels themselves must be correct.
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

		// Promote a palette handle to truecolor — imagewebp() cannot encode
		// palette images — keeping any palette transparency as alpha pixels.
		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}

		// Upright an EXIF-oriented source before anything else sees the handle,
		// so every later width read, scale, and encode operates on correct pixels.
		$orientation = $this->read_orientation( $bytes );
		if ( $orientation !== 1 ) {
			$image = $this->apply_orientation( $image, $orientation );
		}

		// Preserve transparency through subsequent scale and encode steps; armed
		// last because rotation can hand back a fresh handle.
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
	 * The proportional height is computed explicitly and clamped to one pixel:
	 * the single-argument form of `imagescale()` truncates the derived height and
	 * throws a `ValueError` when an extreme aspect ratio (a panorama scaled to a
	 * thumbnail width) truncates it to zero. Any throw from GD is caught and
	 * reported as a failed scale, so one degenerate source never aborts a batch.
	 * Alpha is re-armed on the fresh handle so transparency survives the encode
	 * step.
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

		// Scale to the exact width with an explicitly derived, one-pixel-clamped
		// height under GD's default resampling; any GD throw or false return is a
		// failure the optimiser reports as a rejected source.
		try {
			$width         = imagesx( $image );
			$height        = imagesy( $image );
			$target_height = max( 1, (int) round( $height * $target_width / $width ) );
			$scaled        = imagescale( $image, $target_width, $target_height );
			if ( $scaled === false ) {
				return null;
			}
		} catch ( \Throwable ) {
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
	 * output buffer and returned as a byte string. The call is silenced because
	 * `imagewebp()` *warns* on handles it cannot encode (palette images) while
	 * still returning true — the warning text must never pollute the captured
	 * buffer — and any GD throw is caught. An empty capture means the encode
	 * failed and is reported as `null`.
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

		// Capture imagewebp()'s stream output as a string, unwinding the buffer on
		// any GD throw; a failed encode or an empty buffer yields null so the
		// caller never writes a half-formed main.
		$buffer_level = ob_get_level();
		try {
			ob_start();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imagewebp() warns (e.g. on palette handles) while returning true; the warning must not leak into the captured byte buffer, and the empty-buffer check already handles the failure.
			$ok    = @imagewebp( $image, null, $quality );
			$bytes = ob_get_clean();
		} catch ( \Throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			return null;
		}
		if ( ! $ok || ! is_string( $bytes ) || $bytes === '' ) {
			return null;
		}

		return $bytes;

	}

	/**
	 * Physically transforms a GD handle to upright for an EXIF orientation.
	 *
	 * Implements the standard EXIF mapping. `imagerotate()` treats positive
	 * angles as **counter-clockwise** (verified empirically), so clockwise 90° is
	 * `-90`. Orientations 5 and 7 are the diagonal flips: 5 (transpose) is
	 * flip-horizontal followed by a 90° counter-clockwise rotation, and 7
	 * (transverse) is flip-horizontal followed by a 90° clockwise rotation —
	 * verified by tracking a marked corner pixel through each composite.
	 * Orientation 1 and any out-of-range value return the handle unchanged.
	 *
	 * @since 0.2.0
	 *
	 * @param \GdImage $image       The decoded GD handle to upright.
	 * @param int      $orientation The EXIF Orientation tag value (1–8).
	 * @return \GdImage The upright handle (possibly the same instance).
	 */
	public function apply_orientation( \GdImage $image, int $orientation ): \GdImage {
		return match ( $orientation ) {
			2 => $this->flip( $image, IMG_FLIP_HORIZONTAL ),
			3 => $this->rotate( $image, 180 ),
			4 => $this->flip( $image, IMG_FLIP_VERTICAL ),
			5 => $this->rotate( $this->flip( $image, IMG_FLIP_HORIZONTAL ), 90 ),
			6 => $this->rotate( $image, -90 ),
			7 => $this->rotate( $this->flip( $image, IMG_FLIP_HORIZONTAL ), -90 ),
			8 => $this->rotate( $image, 90 ),
			default => $image,
		};
	}

	/**
	 * Rotates a handle by an angle, falling back to the original on failure.
	 *
	 * A failed `imagerotate()` (effectively impossible on a valid handle) keeps
	 * the un-rotated image rather than failing the whole decode: a sideways
	 * photo is a degraded result, an aborted batch is a broken one.
	 *
	 * @since 0.2.0
	 *
	 * @param \GdImage $image The handle to rotate.
	 * @param int      $angle The rotation angle in degrees, counter-clockwise positive.
	 * @return \GdImage The rotated handle, or the original when rotation failed.
	 */
	private function rotate( \GdImage $image, int $angle ): \GdImage {
		$rotated = imagerotate( $image, $angle, 0 );
		return $rotated === false ? $image : $rotated;
	}

	/**
	 * Flips a handle in place and returns it, enabling composition in `match`.
	 *
	 * @since 0.2.0
	 *
	 * @param \GdImage $image The handle to flip.
	 * @param int      $mode  One of the `IMG_FLIP_*` constants.
	 * @return \GdImage The same handle, flipped.
	 */
	private function flip( \GdImage $image, int $mode ): \GdImage {
		imageflip( $image, $mode );
		return $image;
	}

	/**
	 * Reads the EXIF Orientation tag from JPEG or TIFF source bytes.
	 *
	 * Returns `1` (upright) whenever no trustworthy tag exists: non-JPEG/TIFF
	 * formats (the only formats EXIF lives in and `exif_read_data()` supports),
	 * a PHP without the exif extension, malformed EXIF data, or an out-of-range
	 * value. The bytes are fed through a `php://temp` stream so they are not
	 * duplicated through a base64 data URI.
	 *
	 * @since 0.2.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return int The orientation value 1–8, with 1 for "none/unknown".
	 */
	private function read_orientation( string $bytes ): int {

		// Only JPEG and TIFF carry EXIF, and exif_read_data() supports only those;
		// gating on the magic bytes avoids needless warnings for other formats.
		$is_jpeg = str_starts_with( $bytes, "\xFF\xD8\xFF" );
		$is_tiff = str_starts_with( $bytes, "II*\x00" ) || str_starts_with( $bytes, "MM\x00*" );
		if ( ( ! $is_jpeg && ! $is_tiff ) || ! function_exists( 'exif_read_data' ) ) {
			return 1;
		}

		// Stream the bytes to the EXIF reader through php://temp; any failure —
		// stream trouble, malformed EXIF (which only warns), a reader throw —
		// resolves to "no orientation" rather than a rejected source.
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a file; WP_Filesystem has no equivalent.
			$stream = fopen( 'php://temp', 'r+b' );
			if ( $stream === false ) {
				return 1;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the in-memory php://temp stream, not a file.
			fwrite( $stream, $bytes );
			rewind( $stream );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- exif_read_data() warns on malformed EXIF segments, an expected hostile-input case already handled by the false return.
			$exif = @exif_read_data( $stream );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the in-memory php://temp stream.
			fclose( $stream );
		} catch ( \Throwable ) {
			return 1;
		}

		// Accept only a well-formed in-range tag; everything else means upright.
		$orientation = is_array( $exif ) ? ( $exif['Orientation'] ?? 1 ) : 1;
		return is_int( $orientation ) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;

	}

}
