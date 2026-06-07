<?php
/**
 * The optimisation boundary — the single place a source is made conforming.
 *
 * Given source image bytes and a collection's `Descriptor` (the target output
 * contract), the optimiser returns the WebP bytes to store as the main image
 * together with their width and whether a re-encode was needed. It is the one
 * code path behind both `image import` and the REST upload endpoint, so
 * "conforming by construction" holds no matter how a file arrives. The contract
 * rules live here; the pixel mechanics live behind the injected `Webp_Codec`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Applies a collection's output contract to one source image.
 *
 * The external interface is a single deep method, `optimize()`, that hides the
 * whole accept-as-is-or-re-encode decision behind one call returning an
 * `Optimized_Image` (or `null` when the source is not a decodable image or
 * cannot be encoded). The codec is injected so production binds GD while tests
 * can drive the real GD codec or a stub; the constructor selects the first
 * supported codec and fails loudly if none on the host can handle WebP, because
 * a plugin that silently produces nothing would break the contract invisibly.
 *
 * @since 0.3.0
 */
final class Optimizer {

	/**
	 * The codec that performs the actual decode, scale, and WebP encode.
	 *
	 * @since 0.3.0
	 * @var Webp_Codec
	 */
	private readonly Webp_Codec $codec;

	/**
	 * Constructs the optimiser, choosing a WebP-capable codec.
	 *
	 * When a codec is injected it is used as-is (tests, or a host that has
	 * already chosen one). Otherwise the first supported codec is selected —
	 * GD first, since it is the tested production path, then Imagick for
	 * portability. If neither can encode WebP the constructor throws, surfacing a
	 * misconfigured host immediately rather than letting every ingestion silently
	 * reject.
	 *
	 * @since 0.3.0
	 *
	 * @param Webp_Codec|null $codec An explicit codec, or null to auto-select.
	 * @throws \RuntimeException When no available codec can encode WebP.
	 */
	public function __construct( ?Webp_Codec $codec = null ) {

		// An injected codec wins outright; this is the test and pre-chosen-host path.
		if ( $codec !== null ) {
			$this->codec = $codec;
			return;
		}

		// Auto-select the first codec whose WebP support is actually present,
		// preferring the tested GD path over the Imagick portability fallback.
		foreach ( [ new Gd_Webp_Codec(), new Imagick_Webp_Codec() ] as $candidate ) {
			if ( $candidate->is_supported() ) {
				$this->codec = $candidate;
				return;
			}
		}

		// No codec on this host can encode WebP; fail loudly rather than rejecting
		// every file with no explanation.
		throw new \RuntimeException(
			'No image codec on this host can encode WebP (need GD or Imagick with WebP support).'
		);

	}

	/**
	 * Produces the conforming WebP bytes for a source under a contract.
	 *
	 * The accept-as-is fast path returns the source untouched — same bytes, so a
	 * hash comparison proves no second lossy pass — when it is already WebP *and*
	 * its width is within the ceiling (or the ceiling is `null`, meaning no
	 * limit). Otherwise the source is decoded; if a finite ceiling is set and the
	 * width exceeds it the image is downscaled to exactly the ceiling (a `null`
	 * ceiling never scales, so a small image keeps its own width and is never
	 * upscaled); then it is re-encoded to WebP at the descriptor's quality —
	 * never a client-supplied one. Returns `null` when the source is not a
	 * decodable image or the encode fails, which the caller maps to a rejection.
	 *
	 * @since 0.3.0
	 *
	 * @param string     $bytes      The raw source image bytes.
	 * @param Descriptor $descriptor The target collection's output contract.
	 * @return Optimized_Image|null The bytes to store, or null when the source is unusable.
	 */
	public function optimize( string $bytes, Descriptor $descriptor ): ?Optimized_Image {

		// Probe format and width without decoding; an unrecognised source is
		// rejected here before any pixel buffer is allocated.
		$probe = $this->codec->probe( $bytes );
		if ( $probe === null ) {
			return null;
		}
		[ $source_width, $is_webp ] = $probe;

		// Accept as-is when already WebP and within the ceiling (or no ceiling):
		// re-encoding a conforming source would only add a second lossy pass.
		if ( $is_webp && $this->within_ceiling( $source_width, $descriptor->max_width ) ) {
			return new Optimized_Image( $bytes, $source_width, false );
		}

		// Otherwise the source must be transformed: decode it into a pixel buffer.
		$image = $this->codec->decode( $bytes );
		if ( $image === null ) {
			return null;
		}

		// Downscale only when a finite ceiling is exceeded; a null ceiling and an
		// already-small image both skip scaling, so width is never increased.
		$target_width = $this->target_width( $source_width, $descriptor->max_width );
		if ( $target_width !== $source_width ) {
			$scaled = $this->codec->scale( $image, $target_width );
			if ( $scaled === null ) {
				Plugin::warning( 'Failed to downscale a source image during optimisation.' );
				return null;
			}
			$image = $scaled;
		}

		// Re-encode to WebP at the descriptor's quality; the width is whatever the
		// (possibly scaled) handle now carries.
		$encoded = $this->codec->encode( $image, $descriptor->quality );
		if ( $encoded === null ) {
			Plugin::warning( 'Failed to encode a source image to WebP during optimisation.' );
			return null;
		}

		return new Optimized_Image( $encoded, $this->codec->width( $image ), true );

	}

	/**
	 * Reports whether a width already satisfies the contract ceiling.
	 *
	 * A `null` ceiling means "no limit", so any width is within it; otherwise the
	 * width must not exceed the ceiling. This is the accept-as-is width test.
	 *
	 * @since 0.3.0
	 *
	 * @param int      $width     The source width in pixels.
	 * @param int|null $max_width The contract ceiling, or null for no limit.
	 * @return bool True when the width is within the ceiling.
	 */
	private function within_ceiling( int $width, ?int $max_width ): bool {
		return $max_width === null || $width <= $max_width;
	}

	/**
	 * Computes the width the stored main should have under the contract.
	 *
	 * Returns the ceiling when a finite ceiling is exceeded (downscale to exactly
	 * the ceiling), and the source width otherwise — so a `null` ceiling or an
	 * already-conforming width is left unchanged and never upscaled.
	 *
	 * @since 0.3.0
	 *
	 * @param int      $source_width The decoded source width in pixels.
	 * @param int|null $max_width    The contract ceiling, or null for no limit.
	 * @return int The target width for the stored main.
	 */
	private function target_width( int $source_width, ?int $max_width ): int {

		// A finite ceiling that the source exceeds is the only case that shrinks
		// the image; everything else keeps the source width.
		if ( $max_width !== null && $source_width > $max_width ) {
			return $max_width;
		}

		return $source_width;

	}

}
