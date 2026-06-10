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

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Optimizer;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Tests\Unit\Fixtures\Encode_Failing_Codec;

// The optimiser raises the memory limit before its first decode; wire the
// WordPress helper to a recording stub so every test tolerates the call and
// one test can assert it happened with the right context.
beforeEach( function (): void {
	$GLOBALS['kntnt_optimizer_memory_limit_calls'] = [];
	Functions\when( 'wp_raise_memory_limit' )->alias(
		static function ( string $context ): bool {
			$GLOBALS['kntnt_optimizer_memory_limit_calls'][] = $context;
			return true;
		}
	);
} );

/**
 * Runs a callback with PHP warnings and notices swallowed locally.
 *
 * PHPUnit's error handler reports even `@`-suppressed engine warnings as test
 * warnings, and hostile-input tests (zero-byte, truncated bytes) deliberately
 * provoke such warnings inside GD; a one-off swallowing handler keeps those
 * expected diagnostics out of the test report.
 *
 * @param callable $callback The code to run quietly.
 * @return mixed The callback's return value.
 */
function optimizer_quietly( callable $callback ): mixed {
	set_error_handler( static fn (): bool => true, E_WARNING | E_NOTICE );
	try {
		return $callback();
	} finally {
		restore_error_handler();
	}
}

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
 * Encodes a palette (non-truecolor) image to PNG bytes at a given size.
 *
 * `imagecreate()` allocates a palette handle, which `imagepng()` keeps as an
 * indexed PNG; decoding it yields a palette GD handle, exactly the input that
 * `imagewebp()` cannot encode without truecolor promotion. The left half is
 * painted with a transparent palette entry when requested, so transparency
 * survival can be asserted after conversion.
 *
 * @param bool $with_transparency Whether to make the left half transparent.
 * @return string The indexed PNG bytes (60×40).
 */
function palette_png_bytes( bool $with_transparency = false ): string {

	// Allocate a palette handle with an opaque background and, optionally, a
	// transparent entry covering the left half.
	$image = imagecreate( 60, 40 );
	imagecolorallocate( $image, 10, 20, 30 );
	if ( $with_transparency ) {
		$transparent = imagecolorallocate( $image, 250, 0, 0 );
		imagecolortransparent( $image, $transparent );
		imagefilledrectangle( $image, 0, 0, 29, 39, $transparent );
	}

	ob_start();
	imagepng( $image );
	return (string) ob_get_clean();

}

/**
 * Splices a minimal EXIF APP1 segment carrying only Orientation into a JPEG.
 *
 * Builds a little-endian TIFF block with a single IFD0 entry (tag 0x0112,
 * SHORT) and inserts it as an APP1 segment directly after the SOI marker —
 * the shape `exif_read_data()` reads an Orientation tag from.
 *
 * @param int $width       The stored (pre-rotation) image width in pixels.
 * @param int $height      The stored (pre-rotation) image height in pixels.
 * @param int $orientation The EXIF Orientation tag value (1–8).
 * @return string The JPEG bytes carrying the orientation tag.
 */
function exif_jpeg_bytes( int $width, int $height, int $orientation ): string {

	// A baseline JPEG to splice the EXIF segment into.
	$jpeg = jpeg_bytes( $width, $height );

	// The TIFF block: II header, one IFD0 entry holding the orientation SHORT.
	$tiff = 'II' . pack( 'v', 42 ) . pack( 'V', 8 )
		. pack( 'v', 1 )
		. pack( 'v', 0x0112 ) . pack( 'v', 3 ) . pack( 'V', 1 )
		. pack( 'v', $orientation ) . pack( 'v', 0 )
		. pack( 'V', 0 );

	// Wrap as an APP1 segment and insert it directly after the SOI marker.
	$payload = "Exif\x00\x00" . $tiff;
	$app1    = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	return substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 );

}

/**
 * Builds a PNG header *declaring* 100000×100000 pixels with no body at all.
 *
 * A stand-in for a decompression bomb: `getimagesizefromstring()` happily
 * reports the declared ten-gigapixel area from the IHDR alone, so the probe
 * succeeds while an actual decode would exhaust the worker's memory. The
 * optimiser must reject it from the header, before any decode.
 *
 * @return string The hostile PNG header bytes.
 */
function bomb_png_bytes(): string {
	$ihdr_data = pack( 'N', 100000 ) . pack( 'N', 100000 ) . "\x08\x06\x00\x00\x00";
	$ihdr      = pack( 'N', 13 ) . 'IHDR' . $ihdr_data . pack( 'N', crc32( 'IHDR' . $ihdr_data ) );
	return "\x89PNG\r\n\x1a\n" . $ihdr;
}

/**
 * Appends an EXIF chunk to a WebP container, fixing up the RIFF size.
 *
 * Models a camera/editor WebP that carries GPS EXIF: the chunk is appended
 * after the image chunks and the RIFF size is recomputed, so the result still
 * probes and decodes as a valid WebP.
 *
 * @param string $webp         The clean WebP bytes to taint.
 * @param string $exif_payload The EXIF chunk payload.
 * @return string The WebP bytes carrying the EXIF chunk.
 */
function webp_with_exif_chunk( string $webp, string $exif_payload ): string {
	$pad   = strlen( $exif_payload ) % 2 === 1 ? "\x00" : '';
	$chunk = 'EXIF' . pack( 'V', strlen( $exif_payload ) ) . $exif_payload . $pad;
	$body  = substr( $webp, 12 ) . $chunk;
	return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
}

/**
 * Stubs the megapixel-ceiling filter to return a given value.
 *
 * Every other filter passes its value through unchanged, mirroring Brain
 * Monkey's default pass-through behaviour.
 *
 * @param mixed $megapixels The value the ceiling filter should return.
 */
function stub_megapixel_ceiling( mixed $megapixels ): void {
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $megapixels ): mixed {
			return $hook === 'kntnt_photo_drop_max_input_megapixels' ? $megapixels : $value;
		}
	);
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

test( 'is_available reports true on a GD-with-WebP host without throwing', function (): void {
	// The throw-free twin of the constructor: on this host GD can encode WebP,
	// so the availability verdict an admin notice asks for must be true.
	expect( Optimizer::is_available() )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Decode safety: extreme aspect ratios, the megapixel ceiling, memory headroom
// ---------------------------------------------------------------------------

test( 'an extreme-aspect panorama is downscaled without a GD throw', function (): void {
	// A 4000×2 source scaled to a 320 ceiling derives a sub-pixel height; the
	// single-argument imagescale() would throw a ValueError here. The optimiser
	// must instead clamp the height to one pixel and produce a real WebP.
	$result = gd_optimizer()->optimize( jpeg_bytes( 4000, 2 ), new Descriptor( 'X', 320, 80, [] ) );

	expect( $result )->not->toBeNull();
	expect( $result->width )->toBe( 320 );
	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
} );

test( 'a source declaring a huge pixel area is rejected from the header alone', function (): void {
	// The bomb declares 100000×100000 in its IHDR with no body: the probe sees
	// ten gigapixels, far over the 50-megapixel default, and the optimiser must
	// reject before any decode could OOM the worker. The memory raise sits after
	// the ceiling, so it must not have fired either — proof the rejection
	// happened before the decode stage.
	$result = gd_optimizer()->optimize( bomb_png_bytes(), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->toBeNull();
	expect( $GLOBALS['kntnt_optimizer_memory_limit_calls'] )->toBe( [] );
} );

test( 'the megapixel ceiling honours its filter', function (): void {
	// With the ceiling filtered down to 0.01 MP (10 000 px), a perfectly normal
	// 200×200 (40 000 px) source must be rejected — proving the filter, not the
	// default, drives the ceiling.
	stub_megapixel_ceiling( 0.01 );

	$result = gd_optimizer()->optimize( jpeg_bytes( 200, 200 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->toBeNull();
} );

test( 'an invalid megapixel filter return falls back to the default ceiling', function ( mixed $bogus ): void {
	// A filter returning a non-number or a non-positive number is a misuse and
	// must fall back to the 50 MP default rather than disabling ingestion (or
	// the guard): the 200×200 source sails through.
	stub_megapixel_ceiling( $bogus );

	$result = gd_optimizer()->optimize( jpeg_bytes( 200, 200 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->not->toBeNull();
} )->with( [
	'a string'   => [ 'lots' ],
	'a negative' => [ -5 ],
	'zero'       => [ 0 ],
	'null'       => [ null ],
	'a boolean'  => [ true ],
] );

test( 'the megapixel ceiling also guards the accept-as-is path', function (): void {
	// An already-conforming WebP is still decoded (validation), so the ceiling
	// must reject it too before that decode.
	stub_megapixel_ceiling( 0.01 );

	$result = gd_optimizer()->optimize( webp_bytes( 200, 200 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->toBeNull();
} );

test( 'the memory limit is raised for images exactly once per optimisation', function (): void {
	// The optimiser must ask WordPress for the image-editing memory headroom
	// exactly the way core's own editors do, before the decode.
	gd_optimizer()->optimize( jpeg_bytes( 100, 100 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $GLOBALS['kntnt_optimizer_memory_limit_calls'] )->toBe( [ 'image' ] );
} );

test( 'a zero-byte source yields null', function (): void {
	$result = optimizer_quietly(
		static fn (): ?object => gd_optimizer()->optimize( '', new Descriptor( 'X', 1920, 80, [] ) )
	);

	expect( $result )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Integrity validation: the header probe alone is not enough
// ---------------------------------------------------------------------------

test( 'a truncated WebP that probes fine is rejected by the validation decode', function (): void {
	// Cutting a WebP mid-body leaves the header intact, so the probe reports
	// sane dimensions and WebP-ness — but storing the bytes would publish a
	// broken main. The full validation decode must catch it as a rejection.
	$truncated = substr( webp_bytes( 500, 400 ), 0, 300 );

	expect( ( new Gd_Webp_Codec() )->probe( $truncated ) )->not->toBeNull();
	$result = optimizer_quietly(
		static fn (): ?object => gd_optimizer()->optimize( $truncated, new Descriptor( 'X', 1920, 80, [] ) )
	);
	expect( $result )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Privacy: EXIF/XMP is stripped on the accept-as-is path too
// ---------------------------------------------------------------------------

test( 'an accept-as-is WebP has its EXIF chunk stripped without a re-encode', function (): void {
	// A conforming WebP POSTed with a GPS EXIF chunk must not be published with
	// the coordinates. Stripping the appended chunk restores the original clean
	// container byte-for-byte, proving the pixel data saw no second lossy pass.
	$clean   = webp_bytes( 300, 200 );
	$tainted = webp_with_exif_chunk( $clean, 'GPS-COORDS-SECRET' );

	$result = gd_optimizer()->optimize( $tainted, new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result->reencoded )->toBeFalse();
	expect( $result->bytes )->toBe( $clean );
} );

// ---------------------------------------------------------------------------
// EXIF orientation: the server path matches the browser's baked-in rotation
// ---------------------------------------------------------------------------

test( 'an EXIF-oriented portrait JPEG is stored upright', function (): void {
	// Orientation 6 stores landscape pixels that must be rotated 90° clockwise:
	// a 30×20 stored frame is a 20×30 upright photo, and the stored main must
	// carry the upright dimensions.
	$result = gd_optimizer()->optimize( exif_jpeg_bytes( 30, 20, 6 ), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result->width )->toBe( 20 );
	$decoded = imagecreatefromstring( $result->bytes );
	expect( imagesx( $decoded ) )->toBe( 20 );
	expect( imagesy( $decoded ) )->toBe( 30 );
} );

test( 'a source whose rotation pushes it over the ceiling is still scaled to the contract', function (): void {
	// The stored frame is 100×300 — within a 150 ceiling by probe width — but
	// orientation 6 swaps it to 300×100 upright, which exceeds the ceiling. The
	// target width must be derived from the decoded (rotated) handle, so the
	// stored main lands at exactly 150.
	$result = gd_optimizer()->optimize( exif_jpeg_bytes( 100, 300, 6 ), new Descriptor( 'X', 150, 80, [] ) );

	expect( $result->width )->toBe( 150 );
	expect( $result->reencoded )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Palette inputs are valid sources
// ---------------------------------------------------------------------------

test( 'a palette PNG is converted to WebP rather than rejected', function (): void {
	// imagewebp() cannot encode palette handles; without truecolor promotion in
	// the decode this perfectly valid source would be rejected with a warning.
	$result = gd_optimizer()->optimize( palette_png_bytes(), new Descriptor( 'X', 1920, 80, [] ) );

	expect( $result )->not->toBeNull();
	expect( is_webp_magic( $result->bytes ) )->toBeTrue();
	expect( $result->reencoded )->toBeTrue();
} );

test( 'palette transparency survives the truecolor promotion and encode', function (): void {
	// The transparent palette entry covering the left half must arrive in the
	// stored WebP as fully transparent alpha while the right half stays opaque.
	$result = gd_optimizer()->optimize( palette_png_bytes( true ), new Descriptor( 'X', 1920, 80, [] ) );

	$decoded = imagecreatefromstring( $result->bytes );
	expect( ( imagecolorat( $decoded, 5, 5 ) >> 24 ) & 0x7F )->toBe( 127 );
	expect( ( imagecolorat( $decoded, 50, 5 ) >> 24 ) & 0x7F )->toBe( 0 );
} );
