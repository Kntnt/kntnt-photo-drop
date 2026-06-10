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
 * `Optimized_Image` (or `null` when the source is not a decodable image, is too
 * large to decode safely, or cannot be encoded). The codec is injected so
 * production binds GD while tests can drive the real GD codec or a stub; the
 * constructor selects the first supported codec and fails loudly if none on the
 * host can handle WebP, because a plugin that silently produces nothing would
 * break the contract invisibly. `is_available()` offers the same codec check
 * without the throw, for callers that only need a verdict (an admin notice).
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
		$candidate = self::select_codec();
		if ( $candidate !== null ) {
			$this->codec = $candidate;
			return;
		}

		// No codec on this host can encode WebP; fail loudly rather than rejecting
		// every file with no explanation.
		throw new \RuntimeException(
			'No image codec on this host can encode WebP (need GD or Imagick with WebP support).'
		);

	}

	/**
	 * Reports whether any codec on this host can encode WebP at all.
	 *
	 * The throw-free twin of the constructor's auto-selection, for callers that
	 * only need the verdict — an admin notice warning that uploads cannot work.
	 * Never throws: a codec whose support check itself blows up counts as
	 * unavailable.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when at least one codec reports WebP support.
	 */
	public static function is_available(): bool {

		// Any throw while probing support means that support is not usable.
		try {
			return self::select_codec() !== null;
		} catch ( \Throwable ) {
			return false;
		}

	}

	/**
	 * Produces the conforming WebP bytes for a source under a contract.
	 *
	 * The pipeline is: header-probe the bytes (reject unrecognisable sources);
	 * reject anything whose *declared* pixel area exceeds the megapixel input
	 * ceiling before a single pixel buffer is allocated, so a decompression
	 * bomb cannot OOM-kill the worker; raise the memory limit the way
	 * WordPress's own image editors do; then decode once — even an
	 * already-conforming WebP, as integrity validation, because the header
	 * probe cannot see a truncated body. The decode uprights EXIF-oriented
	 * sources, so the decoded handle's width (which may differ from the
	 * probe's) drives all width decisions. A WebP within the contract ceiling
	 * (or under a `null` ceiling, meaning no limit) is then passed through with
	 * its pixel data byte-identical — no second lossy pass — with EXIF/XMP
	 * container metadata stripped losslessly so a direct POST cannot publish
	 * GPS coordinates. Everything else is downscaled to the ceiling when
	 * exceeded (a `null` ceiling never scales, so nothing is ever upscaled) and
	 * re-encoded to WebP at the descriptor's quality — never a client-supplied
	 * one. Returns `null` when any step finds the source unusable, which the
	 * caller maps to a per-file rejection.
	 *
	 * @since 0.3.0
	 *
	 * @param string     $bytes      The raw source image bytes.
	 * @param Descriptor $descriptor The target collection's output contract.
	 * @return Optimized_Image|null The bytes to store, or null when the source is unusable.
	 */
	public function optimize( string $bytes, Descriptor $descriptor ): ?Optimized_Image {

		// Probe format and dimensions without decoding; an unrecognised source is
		// rejected here before any pixel buffer is allocated.
		$probe = $this->codec->probe( $bytes );
		if ( $probe === null ) {
			return null;
		}

		// Reject a declared pixel area over the input ceiling before any decode —
		// decoding it would OOM-kill the worker with an uncatchable fatal, taking
		// the whole batch (and the HTTP connection) down with it.
		if ( ! Input_Ceiling::allows( $probe['width'], $probe['height'] ) ) {
			Plugin::warning( 'Rejected a source image whose declared dimensions exceed the input megapixel ceiling.' );
			return null;
		}

		// Give the decode the same memory headroom WordPress grants its own image
		// editors. Guarded so the optimiser also runs in a plain-PHP test runtime.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		// Decode unconditionally — for an accept-as-is candidate this is the
		// integrity validation the header probe cannot provide (a WebP truncated
		// mid-body probes fine but must not be stored as a broken main). The codec
		// also uprights EXIF-oriented sources here.
		$image = $this->codec->decode( $bytes );
		if ( $image === null ) {
			return null;
		}

		// The decoded handle's width is authoritative: EXIF orientation may have
		// swapped the probe's width and height during decode, and the contract
		// must be enforced against the pixels that will actually be stored.
		$width = $this->codec->width( $image );
		if ( $width <= 0 ) {
			return null;
		}

		// Accept as-is when already WebP and within the ceiling (or no ceiling):
		// re-encoding a conforming source would only add a second lossy pass. The
		// stored pixel data is byte-identical to the source; only EXIF/XMP
		// container chunks are removed, losslessly, to match the privacy property
		// the re-encode path gets for free.
		if ( $probe['is_webp'] && $this->within_ceiling( $width, $descriptor->max_width ) ) {
			return new Optimized_Image( Webp_Metadata_Stripper::strip( $bytes ), $width, false );
		}

		// Downscale only when a finite ceiling is exceeded; a null ceiling and an
		// already-small image both skip scaling, so width is never increased.
		$target_width = $this->target_width( $width, $descriptor->max_width );
		if ( $target_width !== $width ) {
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
	 * Selects the first codec on this host with usable WebP support.
	 *
	 * GD first, since it is the tested production path, then Imagick for
	 * portability. Shared by the constructor (which throws on `null`) and
	 * `is_available()` (which maps `null` to `false`).
	 *
	 * @since 0.2.0
	 *
	 * @return Webp_Codec|null The first supported codec, or null when none is.
	 */
	private static function select_codec(): ?Webp_Codec {

		// Probe each candidate's support and return the first usable one.
		foreach ( [ new Gd_Webp_Codec(), new Imagick_Webp_Codec() ] as $candidate ) {
			if ( $candidate->is_supported() ) {
				return $candidate;
			}
		}

		return null;

	}

	/**
	 * Reports whether a width already satisfies the contract ceiling.
	 *
	 * A `null` ceiling means "no limit", so any width is within it; otherwise the
	 * width must not exceed the ceiling. This is the accept-as-is width test.
	 *
	 * @since 0.3.0
	 *
	 * @param int      $width     The decoded source width in pixels.
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
