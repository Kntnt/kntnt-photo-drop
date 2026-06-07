<?php
/**
 * Tests for the optimisation boundary — `docs/testing.md`
 * § *Server-side contract re-enforcement*.
 *
 * Every test generates a real image via GD and drives the real GD codec, so the
 * contract re-enforcement is exercised against true encoding rather than a mock:
 * an over-ceiling source is downscaled to exactly the ceiling and stored WebP, a
 * non-WebP source is converted (asserted via RIFF/WEBP magic bytes), an
 * already-conforming WebP is passed through byte-identical (no second lossy
 * pass), a null ceiling never upscales, and quality comes only from the
 * descriptor.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Optimizer;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Tests\Unit\Fixtures\Encode_Failing_Codec;

/**
 * Encodes a solid-colour true-colour image to JPEG bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The JPEG bytes.
 */
function jpeg_bytes( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 30, 120, 200 ) );
	ob_start();
	imagejpeg( $image, null, 90 );
	return (string) ob_get_clean();
}

/**
 * Encodes a solid-colour true-colour image to PNG bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The PNG bytes.
 */
function png_bytes( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 200, 60, 60 ) );
	ob_start();
	imagepng( $image );
	return (string) ob_get_clean();
}

/**
 * Encodes a true-colour image to WebP bytes at a given size and quality.
 *
 * @param int $width   The image width in pixels.
 * @param int $height  The image height in pixels.
 * @param int $quality The WebP quality.
 * @return string The WebP bytes.
 */
function webp_bytes( int $width, int $height, int $quality = 80 ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 60, 200, 90 ) );
	ob_start();
	imagewebp( $image, null, $quality );
	return (string) ob_get_clean();
}

/**
 * Encodes a high-frequency random-noise image to JPEG bytes.
 *
 * A flat colour compresses to almost nothing regardless of quality, so quality
 * effects are invisible; per-pixel random noise gives the encoder real detail to
 * spend bits on, making a lower quality measurably smaller than a higher one.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The JPEG bytes.
 */
function noisy_jpeg_bytes( int $width, int $height ): string {

	// Paint each pixel a pseudo-random colour so the image carries high-frequency
	// detail the encoder must compress, making quality differences observable.
	$image = imagecreatetruecolor( $width, $height );
	for ( $y = 0; $y < $height; $y++ ) {
		for ( $x = 0; $x < $width; $x++ ) {
			$colour = imagecolorallocate( $image, ( $x * 7 + $y * 13 ) % 256, ( $x * 17 ) % 256, ( $y * 23 ) % 256 );
			imagesetpixel( $image, $x, $y, $colour );
		}
	}
	ob_start();
	imagejpeg( $image, null, 100 );
	return (string) ob_get_clean();
}

/**
 * Reports whether a byte string carries the RIFF/WEBP container magic.
 *
 * A WebP file begins with the ASCII `RIFF` chunk id and carries `WEBP` at byte
 * offset 8, so checking both proves the optimiser produced a real WebP rather
 * than passing some other format through.
 *
 * @param string $bytes The bytes to inspect.
 * @return bool True when the bytes are a WebP container.
 */
function is_webp_magic( string $bytes ): bool {
	return str_starts_with( $bytes, 'RIFF' ) && substr( $bytes, 8, 4 ) === 'WEBP';
}

/**
 * Builds an optimiser bound to the real GD codec.
 *
 * @return Optimizer The optimiser under test.
 */
function gd_optimizer(): Optimizer {
	return new Optimizer( new Gd_Webp_Codec() );
}

// ---------------------------------------------------------------------------
// Downscaling and format conversion
// ---------------------------------------------------------------------------

test( 'an over-ceiling image is downscaled to exactly the ceiling and stored WebP', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_optimizer()->optimize( jpeg_bytes( 4000, 2000 ), $descriptor );

	// The stored main is exactly the ceiling wide, is real WebP, and is flagged as
	// re-encoded since the source had to be transformed.
	expect( $result )->not->toBeNull();
	expect( $result->width )->toBe( 1920 );
	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
	expect( $result->reencoded )->toBeTrue();
} );

test( 'a JPEG within the ceiling is converted to WebP without downscaling', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_optimizer()->optimize( jpeg_bytes( 1200, 800 ), $descriptor );

	// A non-WebP source is always re-encoded (format conversion), but its width is
	// left alone because it is already within the ceiling.
	expect( $result->width )->toBe( 1200 );
	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
	expect( $result->reencoded )->toBeTrue();
} );

test( 'a PNG is converted to WebP', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_optimizer()->optimize( png_bytes( 900, 600 ), $descriptor );

	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
	expect( $result->reencoded )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Accept-as-is (no second lossy pass)
// ---------------------------------------------------------------------------

test( 'an already-conforming WebP at or under the ceiling is stored byte-identical', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );
	$source     = webp_bytes( 800, 600 );

	$result = gd_optimizer()->optimize( $source, $descriptor );

	// The bytes are returned untouched (hash-equal) and the result is not flagged
	// re-encoded, proving no second lossy pass was applied.
	expect( $result->bytes )->toBe( $source );
	expect( $result->reencoded )->toBeFalse();
	expect( $result->width )->toBe( 800 );
} );

test( 'a WebP exactly at the ceiling is accepted as-is', function (): void {
	$descriptor = new Descriptor( 'X', 1000, 80, [] );
	$source     = webp_bytes( 1000, 700 );

	$result = gd_optimizer()->optimize( $source, $descriptor );

	expect( $result->bytes )->toBe( $source );
	expect( $result->reencoded )->toBeFalse();
} );

test( 'an over-ceiling WebP is re-encoded and downscaled to the ceiling', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_optimizer()->optimize( webp_bytes( 3000, 1500 ), $descriptor );

	// Already WebP but over the ceiling: it must be downscaled, so it is a real
	// re-encode, not a pass-through.
	expect( $result->width )->toBe( 1920 );
	expect( $result->reencoded )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Null ceiling never upscales
// ---------------------------------------------------------------------------

test( 'a null ceiling stores a small WebP at its own width without re-encoding', function (): void {
	$descriptor = new Descriptor( 'X', null, 80, [] );
	$source     = webp_bytes( 64, 48 );

	$result = gd_optimizer()->optimize( $source, $descriptor );

	// No ceiling and already WebP means accept-as-is at the source's own width;
	// the width is never enlarged.
	expect( $result->bytes )->toBe( $source );
	expect( $result->width )->toBe( 64 );
	expect( $result->reencoded )->toBeFalse();
} );

test( 'a null ceiling converts a small JPEG to WebP at its own width, never upscaling', function (): void {
	$descriptor = new Descriptor( 'X', null, 80, [] );

	$result = gd_optimizer()->optimize( jpeg_bytes( 50, 40 ), $descriptor );

	// Format conversion still happens, but the width is the source's own — a null
	// ceiling never upscales a small image.
	expect( $result->width )->toBe( 50 );
	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Quality comes from the descriptor only
// ---------------------------------------------------------------------------

test( 'quality is taken from the descriptor, so a lower quality yields smaller bytes', function (): void {
	$source = noisy_jpeg_bytes( 400, 400 );

	// The same high-frequency source re-encoded at two descriptor qualities must
	// differ in size — lower quality is smaller — proving the descriptor's
	// quality, not any fixed default, drives the WebP encode.
	$low  = gd_optimizer()->optimize( $source, new Descriptor( 'X', 1920, 10, [] ) );
	$high = gd_optimizer()->optimize( $source, new Descriptor( 'X', 1920, 95, [] ) );

	expect( strlen( $low->bytes ) )->toBeLessThan( strlen( $high->bytes ) );
} );

// ---------------------------------------------------------------------------
// Undecodable sources are rejected
// ---------------------------------------------------------------------------

test( 'an undecodable source yields null', function (): void {
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_optimizer()->optimize( 'this is not an image', $descriptor );

	expect( $result )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Codec selection
// ---------------------------------------------------------------------------

test( 'auto-selection picks a working codec on a GD-equipped host', function (): void {
	// With no injection the optimiser auto-selects GD; on this host that must
	// succeed and optimise a real image, proving a working codec was chosen and
	// the loud-failure branch was not taken.
	$result = ( new Optimizer() )->optimize( jpeg_bytes( 100, 100 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
} );

test( 'an encode failure from the codec is surfaced as a rejected source', function (): void {
	// A codec that decodes but cannot encode (a WebP-less build mid-operation)
	// must yield null rather than a half-formed main; the optimiser surfaces the
	// failure to the caller as a rejection.
	$optimizer = new Optimizer( new Encode_Failing_Codec() );

	$result = $optimizer->optimize( jpeg_bytes( 100, 100 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->toBeNull();
} );
