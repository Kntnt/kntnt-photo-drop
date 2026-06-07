<?php
/**
 * A WebP codec test double that decodes and scales but always fails to encode.
 *
 * Wraps the real GD codec for every operation except `encode()`, which returns
 * `null`. It stands in for a host whose WebP encoder fails mid-operation, so a
 * test can prove the optimiser surfaces that failure as a rejected source rather
 * than writing a half-formed main. Kept out of the PSR-4 path and loaded via
 * tests/Pest.php, like the other test doubles.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Tests\Unit\Fixtures;

use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Webp_Codec;

/**
 * Delegates to GD for everything but `encode()`, which always returns null.
 *
 * @since 0.3.0
 */
final class Encode_Failing_Codec implements Webp_Codec {

	/**
	 * The real GD codec every non-encode call is delegated to.
	 *
	 * @since 0.3.0
	 * @var Gd_Webp_Codec
	 */
	private Gd_Webp_Codec $gd;

	/**
	 * Constructs the double with a real GD codec to delegate to.
	 *
	 * @since 0.3.0
	 */
	public function __construct() {
		$this->gd = new Gd_Webp_Codec();
	}

	/**
	 * Reports WebP support as available, so the optimiser proceeds to encode.
	 *
	 * @since 0.3.0
	 *
	 * @return bool Always true.
	 */
	public function is_supported(): bool {
		return true;
	}

	/**
	 * Delegates the format/width probe to the real GD codec.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source bytes.
	 * @return array{0:int,1:bool}|null The GD probe result.
	 */
	public function probe( string $bytes ): ?array {
		return $this->gd->probe( $bytes );
	}

	/**
	 * Delegates decoding to the real GD codec.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source bytes.
	 * @return object|null The decoded GD handle.
	 */
	public function decode( string $bytes ): ?object {
		return $this->gd->decode( $bytes );
	}

	/**
	 * Delegates the width query to the real GD codec.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image The GD handle.
	 * @return int The handle width.
	 */
	public function width( object $image ): int {
		return $this->gd->width( $image );
	}

	/**
	 * Delegates scaling to the real GD codec.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image        The GD handle.
	 * @param int    $target_width The target width.
	 * @return object|null The scaled GD handle.
	 */
	public function scale( object $image, int $target_width ): ?object {
		return $this->gd->scale( $image, $target_width );
	}

	/**
	 * Always fails to encode, standing in for a broken WebP encoder.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image   The GD handle (ignored).
	 * @param int    $quality The quality (ignored).
	 * @return string|null Always null.
	 */
	public function encode( object $image, int $quality ): ?string {
		return null;
	}

}
