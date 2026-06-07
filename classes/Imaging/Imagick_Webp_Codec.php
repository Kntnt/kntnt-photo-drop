<?php
/**
 * The Imagick-backed WebP codec — a portability fallback, not the tested path.
 *
 * Implements the codec seam with the Imagick extension for hosts that ship
 * Imagick instead of (or alongside) a WebP-capable GD. The plugin's own host
 * runs GD with WebP and no Imagick, so this codec is never exercised there; it
 * exists so the same optimiser works unchanged on a different stack. Its WebP
 * support is gated behind `is_supported()`, which checks both the extension and
 * that the build actually lists WebP among its formats.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * A `Webp_Codec` built on the Imagick extension.
 *
 * Mirrors the GD codec's degrade-to-`null` contract: any Imagick exception is
 * caught and turned into a `null` so one bad source never aborts a batch. Holds
 * no state; one instance serves any number of optimisations.
 *
 * @since 0.3.0
 */
final class Imagick_Webp_Codec implements Webp_Codec {

	/**
	 * Reports whether Imagick is loaded and its build supports WebP.
	 *
	 * Checks the extension first, then queries the build's format list, because
	 * an Imagick compiled without the WebP delegate cannot encode WebP even though
	 * the class exists.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True when Imagick can encode WebP.
	 */
	public function is_supported(): bool {

		// The class must exist before its formats can be queried.
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		// Query the build's supported formats; WEBP must be among them. Any failure
		// querying is treated as "no WebP support".
		try {
			return \Imagick::queryFormats( 'WEBP' ) !== [];
		} catch ( \Throwable ) {
			return false;
		}

	}

	/**
	 * Probes raw bytes for width and WebP-ness by reading the image header.
	 *
	 * Imagick has no header-only probe, so it reads the blob and inspects the
	 * decoded format and geometry, then discards the handle. Returns `null` for
	 * anything Imagick cannot read.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return array{0:int,1:bool}|null `[ $width, $is_webp ]`, or null when undecodable.
	 */
	public function probe( string $bytes ): ?array {

		// Read the blob to learn its format and width; a read failure means the
		// bytes are not a decodable image.
		try {
			$image = new \Imagick();
			$image->readImageBlob( $bytes );
			$width   = $image->getImageWidth();
			$is_webp = strtoupper( $image->getImageFormat() ) === 'WEBP';
			$image->clear();
		} catch ( \Throwable ) {
			return null;
		}

		// A non-positive width is not a usable image.
		if ( $width <= 0 ) {
			return null;
		}

		return [ $width, $is_webp ];

	}

	/**
	 * Decodes raw bytes into an Imagick handle.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return object|null The Imagick handle, or null when the bytes are undecodable.
	 */
	public function decode( string $bytes ): ?object {

		// Read the blob into a fresh handle; any failure is an undecodable source.
		try {
			$image = new \Imagick();
			$image->readImageBlob( $bytes );
			return $image;
		} catch ( \Throwable ) {
			return null;
		}

	}

	/**
	 * Returns the pixel width of an Imagick handle.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image The Imagick handle.
	 * @return int The width in pixels.
	 */
	public function width( object $image ): int {

		// Only a real Imagick handle has a width to report.
		if ( ! $image instanceof \Imagick ) {
			return 0;
		}

		try {
			return $image->getImageWidth();
		} catch ( \Throwable ) {
			return 0;
		}

	}

	/**
	 * Downscales an Imagick handle to an exact width, keeping the aspect ratio.
	 *
	 * Passing height `0` to `scaleImage()` lets Imagick derive the height from the
	 * aspect ratio. The source handle is mutated in place and returned, matching
	 * the seam's "the caller may discard the source" contract.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image        The Imagick source handle.
	 * @param int    $target_width The exact width the result must have.
	 * @return object|null The scaled handle, or null when scaling failed.
	 */
	public function scale( object $image, int $target_width ): ?object {

		// Only a real Imagick handle can be scaled.
		if ( ! $image instanceof \Imagick ) {
			return null;
		}

		// Scale to the target width, letting Imagick derive a proportional height
		// from the zero placeholder; an exception is reported as a failed scale.
		try {
			$image->scaleImage( $target_width, 0 );
			return $image;
		} catch ( \Throwable ) {
			return null;
		}

	}

	/**
	 * Encodes an Imagick handle to WebP bytes at the descriptor's quality.
	 *
	 * Sets the output format and compression quality, then serialises the blob.
	 * Re-encoding strips embedded metadata, the intended privacy property.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image   The Imagick handle to encode.
	 * @param int    $quality The WebP quality (0–100) from the descriptor.
	 * @return string|null The encoded WebP bytes, or null on failure.
	 */
	public function encode( object $image, int $quality ): ?string {

		// Only a real Imagick handle can be encoded.
		if ( ! $image instanceof \Imagick ) {
			return null;
		}

		// Force WebP at the descriptor's quality and serialise; an empty or failed
		// blob is reported as null so no half-formed main is written.
		try {
			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $quality );
			$bytes = $image->getImageBlob();
			return $bytes === '' ? null : $bytes;
		} catch ( \Throwable ) {
			return null;
		}

	}

}
