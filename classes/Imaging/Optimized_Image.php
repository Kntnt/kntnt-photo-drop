<?php
/**
 * The result of running one source image through the optimiser.
 *
 * Carries the WebP bytes the caller should write as the main image, the pixel
 * width those bytes have, whether the optimiser produced them by re-encoding
 * (decode → downscale → re-encode) or simply passed the already-conforming
 * source through untouched, and the decoded, upright, contract-width image handle
 * those bytes were produced from. The "accepted as-is" flag is what lets the
 * Ingestor map an unchanged WebP to a `stored` outcome and a transformed one to
 * `reencoded`, and it is the proof a conforming input was never put through a
 * second lossy pass. The decoded handle is the optimiser's own — the same pixels
 * it just wrote as the main — so the deriver can scale its renditions from it
 * without re-reading and re-decoding the stored main, the redundant
 * full-resolution decode the two-tier baseline never paid (issue #57).
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
 * beyond holding the facts a caller needs to persist the main and report the
 * outcome. `bytes` is the exact byte string to write — for an accepted-as-is
 * input it is identical to the source (bar losslessly stripped metadata), so a
 * hash comparison proves no re-encode. `image` is the decoded handle those bytes
 * came from: byte-identical pixels to the stored main, handed forward so the
 * deriver scales the renditions from it instead of decoding the main a second
 * time. The handle is owned by the codec that produced it; a caller that finishes
 * with it does not free it (codec handle lifetime, per `Webp_Codec`).
 *
 * @since 0.3.0
 */
final readonly class Optimized_Image {

	/**
	 * Constructs an optimiser result from its settled parts.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes     The WebP bytes to write as the main image.
	 * @param int    $width     The pixel width of those bytes.
	 * @param bool   $reencoded True when the optimiser transformed the source; false when it passed through.
	 * @param object $image     The decoded, upright, contract-width handle the bytes were produced from.
	 */
	public function __construct(
		public string $bytes,
		public int $width,
		public bool $reencoded,
		public object $image,
	) {}

}
