<?php
/**
 * The result of running one source image through the optimiser.
 *
 * Carries the WebP bytes the caller should write as the main image, the pixel
 * width those bytes have, and whether the optimiser produced them by re-encoding
 * (decode → downscale → re-encode) or simply passed the already-conforming
 * source through untouched. The "accepted as-is" flag is what lets the Ingestor
 * map an unchanged WebP to a `stored` outcome and a transformed one to
 * `reencoded`, and it is the proof a conforming input was never put through a
 * second lossy pass.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * An immutable `{ bytes, width, reencoded }` view of an optimiser result.
 *
 * Produced only by `Optimizer::optimize()`; a value object with no behaviour
 * beyond holding the three facts a caller needs to persist the main and report
 * the outcome. `bytes` is the exact byte string to write — for an accepted-as-is
 * input it is identical to the source, so a hash comparison proves no re-encode.
 *
 * @since 0.3.0
 */
final readonly class Optimized_Image {

	/**
	 * Constructs an optimiser result from its three settled parts.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes     The WebP bytes to write as the main image.
	 * @param int    $width     The pixel width of those bytes.
	 * @param bool   $reencoded True when the optimiser transformed the source; false when it passed through.
	 */
	public function __construct(
		public string $bytes,
		public int $width,
		public bool $reencoded,
	) {}

}
