<?php
/**
 * Tests for the GD codec's hardened mechanics — the probe shape, the
 * degenerate-geometry scale, the silenced encode, and the EXIF upright
 * transform.
 *
 * Every test drives the real GD extension. The orientation suite constructs a
 * small asymmetric image with a marked corner pixel and asserts the pixel's
 * position after each of the eight EXIF orientations, which pins the rotation
 * directions empirically (GD's `imagerotate()` treats positive angles as
 * counter-clockwise) rather than trusting the documentation.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;

/**
 * Builds a 3×2 black image with a red marker pixel at the top-left corner.
 *
 * The asymmetric size plus the corner marker make every one of the eight
 * orientation transforms produce a distinct, predictable result.
 *
 * @return \GdImage The marked test image.
 */
function codec_marked_image(): \GdImage {
	$image = imagecreatetruecolor( 3, 2 );
	imagefilledrectangle( $image, 0, 0, 2, 1, imagecolorallocate( $image, 0, 0, 0 ) );
	imagesetpixel( $image, 0, 0, imagecolorallocate( $image, 255, 0, 0 ) );
	return $image;
}

/**
 * Locates the red marker pixel and reports the image geometry around it.
 *
 * @param \GdImage $image The image to scan.
 * @return array{0:int,1:int,2:int,3:int} `[ width, height, marker_x, marker_y ]`.
 */
function codec_find_marker( \GdImage $image ): array {

	// Scan every pixel for the saturated-red marker; the images are tiny.
	$width  = imagesx( $image );
	$height = imagesy( $image );
	for ( $y = 0; $y < $height; $y++ ) {
		for ( $x = 0; $x < $width; $x++ ) {
			$colour = imagecolorat( $image, $x, $y );
			if ( ( ( $colour >> 16 ) & 0xFF ) > 200 && ( ( $colour >> 8 ) & 0xFF ) < 50 ) {
				return [ $width, $height, $x, $y ];
			}
		}
	}

	return [ $width, $height, -1, -1 ];

}

/**
 * Encodes a true-colour image to WebP bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The WebP bytes.
 */
function codec_webp_bytes( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 60, 200, 90 ) );
	ob_start();
	imagewebp( $image, null, 80 );
	return (string) ob_get_clean();
}

/**
 * Encodes a true-colour image to JPEG bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The JPEG bytes.
 */
function codec_jpeg_bytes( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 30, 120, 200 ) );
	ob_start();
	imagejpeg( $image, null, 90 );
	return (string) ob_get_clean();
}

/**
 * Splices a minimal EXIF APP1 segment carrying only Orientation into a JPEG.
 *
 * @param int $width       The stored image width in pixels.
 * @param int $height      The stored image height in pixels.
 * @param int $orientation The EXIF Orientation tag value (1–8).
 * @return string The JPEG bytes carrying the orientation tag.
 */
function codec_exif_jpeg_bytes( int $width, int $height, int $orientation ): string {

	// A baseline JPEG plus a little-endian TIFF block holding one IFD0 entry.
	$jpeg = codec_jpeg_bytes( $width, $height );
	$tiff = 'II' . pack( 'v', 42 ) . pack( 'V', 8 )
		. pack( 'v', 1 )
		. pack( 'v', 0x0112 ) . pack( 'v', 3 ) . pack( 'V', 1 )
		. pack( 'v', $orientation ) . pack( 'v', 0 )
		. pack( 'V', 0 );

	// Wrap as APP1 and insert directly after the SOI marker.
	$payload = "Exif\x00\x00" . $tiff;
	$app1    = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	return substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 );

}

/**
 * Runs a callback with PHP warnings and notices swallowed locally.
 *
 * PHPUnit's error handler reports even `@`-suppressed engine warnings as test
 * warnings; hostile-input tests deliberately provoke them inside GD.
 *
 * @param callable $callback The code to run quietly.
 * @return mixed The callback's return value.
 */
function codec_quietly( callable $callback ): mixed {
	set_error_handler( static fn (): bool => true, E_WARNING | E_NOTICE );
	try {
		return $callback();
	} finally {
		restore_error_handler();
	}
}

// ---------------------------------------------------------------------------
// The probe contract: named keys, height included, hostile bytes rejected
// ---------------------------------------------------------------------------

test( 'probe reports width, height, and webp-ness under named keys', function (): void {
	$codec = new Gd_Webp_Codec();

	// A JPEG and a WebP both probe to the full associative shape the megapixel
	// ceiling and the doctor consume; only the WebP is flagged as such.
	expect( $codec->probe( codec_jpeg_bytes( 120, 80 ) ) )->toBe(
		[
			'width'   => 120,
			'height'  => 80,
			'is_webp' => false,
		]
	);
	expect( $codec->probe( codec_webp_bytes( 64, 48 ) ) )->toBe(
		[
			'width'   => 64,
			'height'  => 48,
			'is_webp' => true,
		]
	);
} );

test( 'probe rejects garbage and zero-byte input as null', function (): void {
	$codec = new Gd_Webp_Codec();

	expect( $codec->probe( 'not an image at all' ) )->toBeNull();
	expect( codec_quietly( static fn (): ?array => $codec->probe( '' ) ) )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Scaling: degenerate geometry must degrade, never throw
// ---------------------------------------------------------------------------

test( 'scaling an extreme panorama clamps the derived height to one pixel', function (): void {
	// 10000×2 scaled to 320 derives a 0.064-pixel height; the single-argument
	// imagescale() throws a ValueError on the truncated zero. The codec must
	// clamp to one pixel and return a usable handle instead.
	$codec = new Gd_Webp_Codec();
	$image = imagecreatetruecolor( 10000, 2 );

	$scaled = $codec->scale( $image, 320 );

	expect( $scaled )->toBeInstanceOf( \GdImage::class );
	expect( imagesx( $scaled ) )->toBe( 320 );
	expect( imagesy( $scaled ) )->toBe( 1 );
} );

test( 'scaling a non-GD handle degrades to null', function (): void {
	expect( ( new Gd_Webp_Codec() )->scale( new \stdClass(), 320 ) )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Encoding: a palette handle is a clean failure, not warning garbage
// ---------------------------------------------------------------------------

test( 'encoding a palette handle yields null rather than warning text', function (): void {
	// imagewebp() *warns* on palette handles while returning true, and the
	// warning text would otherwise be captured as the "encoded bytes". The
	// codec must silence the warning and report the empty capture as null.
	$palette = imagecreate( 50, 40 );
	imagecolorallocate( $palette, 200, 50, 50 );

	$encoded = codec_quietly( static fn (): ?string => ( new Gd_Webp_Codec() )->encode( $palette, 80 ) );

	expect( $encoded )->toBeNull();
} );

test( 'encoding a non-GD handle degrades to null', function (): void {
	expect( ( new Gd_Webp_Codec() )->encode( new \stdClass(), 80 ) )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Decoding: truecolor promotion, truncated bodies, EXIF uprighting
// ---------------------------------------------------------------------------

test( 'decode promotes a palette source to a truecolor handle', function (): void {
	// An indexed PNG decodes to a palette handle, which imagewebp() cannot
	// encode; the codec must hand back truecolor so the encode step always works.
	$palette = imagecreate( 60, 40 );
	imagecolorallocate( $palette, 10, 20, 30 );
	ob_start();
	imagepng( $palette );
	$png = (string) ob_get_clean();

	$decoded = ( new Gd_Webp_Codec() )->decode( $png );

	expect( $decoded )->toBeInstanceOf( \GdImage::class );
	expect( imageistruecolor( $decoded ) )->toBeTrue();
} );

test( 'decode rejects a truncated WebP body as null', function (): void {
	$truncated = substr( codec_webp_bytes( 500, 400 ), 0, 300 );

	$decoded = codec_quietly( static fn (): ?object => ( new Gd_Webp_Codec() )->decode( $truncated ) );

	expect( $decoded )->toBeNull();
} );

test( 'decode uprights an EXIF-oriented JPEG, swapping its dimensions', function (): void {
	// Orientation 6 stores landscape pixels of a portrait photo; the decoded
	// handle must already be rotated upright, so 30×20 stored becomes 20×30.
	$decoded = ( new Gd_Webp_Codec() )->decode( codec_exif_jpeg_bytes( 30, 20, 6 ) );

	expect( imagesx( $decoded ) )->toBe( 20 );
	expect( imagesy( $decoded ) )->toBe( 30 );
} );

// ---------------------------------------------------------------------------
// The EXIF upright transform, pinned per orientation by a marked corner pixel
// ---------------------------------------------------------------------------

test( 'apply_orientation moves a marked corner pixel to its upright position', function (
	int $orientation,
	int $width,
	int $height,
	int $x,
	int $y,
): void {
	// Start from a 3×2 image with the marker at (0,0) and assert exactly where
	// the standard EXIF mapping must land it. Orientations 5 (transpose) and 7
	// (transverse) are the composites whose directions are easiest to get
	// backwards; the expected coordinates below were derived from the EXIF
	// row/column definitions and verified against GD empirically.
	$upright = ( new Gd_Webp_Codec() )->apply_orientation( codec_marked_image(), $orientation );

	expect( codec_find_marker( $upright ) )->toBe( [ $width, $height, $x, $y ] );
} )->with( [
	'1 upright stays put'             => [ 1, 3, 2, 0, 0 ],
	'2 flip-H mirrors to top-right'   => [ 2, 3, 2, 2, 0 ],
	'3 rotate-180 to bottom-right'    => [ 3, 3, 2, 2, 1 ],
	'4 flip-V mirrors to bottom-left' => [ 4, 3, 2, 0, 1 ],
	'5 transpose keeps top-left'      => [ 5, 2, 3, 0, 0 ],
	'6 rotate-CW-90 to top-right'     => [ 6, 2, 3, 1, 0 ],
	'7 transverse to bottom-right'    => [ 7, 2, 3, 1, 2 ],
	'8 rotate-CCW-90 to bottom-left'  => [ 8, 2, 3, 0, 2 ],
] );

test( 'apply_orientation leaves out-of-range values untouched', function (): void {
	$image = codec_marked_image();

	$result = ( new Gd_Webp_Codec() )->apply_orientation( $image, 9 );

	expect( $result )->toBe( $image );
	expect( codec_find_marker( $result ) )->toBe( [ 3, 2, 0, 0 ] );
} );
