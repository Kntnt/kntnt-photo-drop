<?php
/**
 * A WebP codec test double that delegates to GD while counting its decodes.
 *
 * Wraps the real GD codec for every operation and tallies each `decode()` call,
 * so a test can prove that a code path which only needs a main's *width* reads it
 * from the index rather than allocating a full pixel buffer per main. It stands in
 * exactly where the production GD codec would, so the behaviour under test is
 * unchanged — only the decode count is observable. Kept out of the PSR-4 path and
 * loaded via tests/Pest.php, like the other test doubles.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

namespace Tests\Unit\Fixtures;

use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Webp_Codec;

/**
 * Delegates to GD for everything and counts how many times `decode()` is called.
 *
 * @since 0.15.0
 */
final class Counting_Codec implements Webp_Codec {

	/**
	 * The number of times `decode()` has been called on this instance.
	 *
	 * @since 0.15.0
	 * @var int
	 */
	public int $decodes = 0;

	/**
	 * The real GD codec every call is delegated to.
	 *
	 * @since 0.15.0
	 * @var Gd_Webp_Codec
	 */
	private Gd_Webp_Codec $gd;

	/**
	 * Constructs the double with a real GD codec to delegate to.
	 *
	 * @since 0.15.0
	 */
	public function __construct() {
		$this->gd = new Gd_Webp_Codec();
	}

	/**
	 * Reports WebP support as available, delegating to the real GD codec.
	 *
	 * @since 0.15.0
	 *
	 * @return bool Whether GD can handle WebP on this host.
	 */
	public function is_supported(): bool {
		return $this->gd->is_supported();
	}

	/**
	 * Delegates the format/width probe to the real GD codec.
	 *
	 * @since 0.15.0
	 *
	 * @param string $bytes The raw source bytes.
	 * @return array{width:int,height:int,is_webp:bool}|null The GD probe result.
	 */
	public function probe( string $bytes ): ?array {
		return $this->gd->probe( $bytes );
	}

	/**
	 * Counts the decode, then delegates to the real GD codec.
	 *
	 * The single instrumented method: each call increments the tally so a test can
	 * assert a width-only code path performed no full decode.
	 *
	 * @since 0.15.0
	 *
	 * @param string $bytes The raw source bytes.
	 * @return object|null The decoded GD handle.
	 */
	public function decode( string $bytes ): ?object {
		++$this->decodes;
		return $this->gd->decode( $bytes );
	}

	/**
	 * Delegates the width query to the real GD codec.
	 *
	 * @since 0.15.0
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
	 * @since 0.15.0
	 *
	 * @param object $image        The GD handle.
	 * @param int    $target_width The target width.
	 * @return object|null The scaled GD handle.
	 */
	public function scale( object $image, int $target_width ): ?object {
		return $this->gd->scale( $image, $target_width );
	}

	/**
	 * Delegates encoding to the real GD codec.
	 *
	 * @since 0.15.0
	 *
	 * @param object $image   The GD handle.
	 * @param int    $quality The WebP quality.
	 * @return string|null The encoded WebP bytes, or null on failure.
	 */
	public function encode( object $image, int $quality ): ?string {
		return $this->gd->encode( $image, $quality );
	}

}
