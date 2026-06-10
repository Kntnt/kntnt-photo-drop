<?php
/**
 * Tests for the lossless WebP metadata stripper — the privacy half of the
 * accept-as-is path.
 *
 * The suite builds synthetic RIFF containers chunk by chunk, so every parse
 * branch is pinned: EXIF and XMP chunks dropped with the RIFF size recomputed,
 * VP8X feature flags cleared while the other bits survive, odd-sized payloads
 * walked across their pad byte, and — the load-bearing safety property — any
 * byte string that is not a cleanly parsable WebP container passed through
 * untouched. A final test taints a real GD WebP and proves the strip restores
 * the clean original byte-for-byte and that GD still decodes the result.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Imaging\Webp_Metadata_Stripper;

/**
 * Builds one RIFF chunk: fourcc + uint32-LE size + payload + odd-size pad.
 *
 * @param string $fourcc  The four-character chunk id.
 * @param string $payload The chunk payload.
 * @return string The serialised chunk.
 */
function strip_chunk( string $fourcc, string $payload ): string {
	$pad = strlen( $payload ) % 2 === 1 ? "\x00" : '';
	return $fourcc . pack( 'V', strlen( $payload ) ) . $payload . $pad;
}

/**
 * Wraps serialised chunks in a RIFF/WEBP container with a correct size field.
 *
 * @param string ...$chunks The serialised chunks, in order.
 * @return string The complete container bytes.
 */
function strip_container( string ...$chunks ): string {
	$body = implode( '', $chunks );
	return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
}

/**
 * Encodes a true-colour image to real WebP bytes via GD.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The WebP bytes.
 */
function strip_real_webp( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 90, 60, 200 ) );
	ob_start();
	imagewebp( $image, null, 80 );
	return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// Dropping metadata chunks and recomputing the RIFF size
// ---------------------------------------------------------------------------

test( 'an EXIF chunk is dropped and the RIFF size recomputed', function (): void {
	// A simple VP8-style layout with a trailing EXIF chunk: the strip must
	// yield the identical container without the EXIF chunk, which is exactly
	// the clean container built without it.
	$image_chunk = strip_chunk( 'VP8 ', 'fake-bitstream-data!' );
	$tainted     = strip_container( $image_chunk, strip_chunk( 'EXIF', 'gps-coordinates-here' ) );
	$clean       = strip_container( $image_chunk );

	expect( Webp_Metadata_Stripper::strip( $tainted ) )->toBe( $clean );
} );

test( 'an XMP chunk is dropped too', function (): void {
	$image_chunk = strip_chunk( 'VP8 ', 'fake-bitstream-data!' );
	$tainted     = strip_container( $image_chunk, strip_chunk( 'XMP ', '<x:xmpmeta>location</x:xmpmeta>' ) );

	expect( Webp_Metadata_Stripper::strip( $tainted ) )->toBe( strip_container( $image_chunk ) );
} );

test( 'metadata chunks between image chunks are dropped without reordering the rest', function (): void {
	// Chunk order of everything kept must be preserved byte-for-byte even when
	// the metadata sits in the middle of the list.
	$alpha = strip_chunk( 'ALPH', 'alpha-plane-data' );
	$image = strip_chunk( 'VP8 ', 'fake-bitstream-data!' );

	$tainted = strip_container( $alpha, strip_chunk( 'EXIF', 'gps' ), $image );

	expect( Webp_Metadata_Stripper::strip( $tainted ) )->toBe( strip_container( $alpha, $image ) );
} );

test( 'odd-sized chunks are walked across their pad byte', function (): void {
	// Both the kept and the dropped chunk have odd payloads, so the walk and
	// the reassembly each must honour the RIFF pad byte.
	$odd_image = strip_chunk( 'VP8 ', 'seven-b' );
	$tainted   = strip_container( $odd_image, strip_chunk( 'EXIF', 'odd' ) );

	expect( strlen( 'seven-b' ) % 2 )->toBe( 1 );
	expect( Webp_Metadata_Stripper::strip( $tainted ) )->toBe( strip_container( $odd_image ) );
} );

// ---------------------------------------------------------------------------
// VP8X feature flags
// ---------------------------------------------------------------------------

test( 'VP8X EXIF and XMP flags are cleared while other bits survive', function (): void {
	// Flags byte 0x3C = ICC (0x20) | alpha (0x10) | EXIF (0x08) | XMP (0x04):
	// the strip must clear exactly the two metadata bits, leaving 0x30, and
	// keep the rest of the VP8X payload (reserved bytes + canvas dimensions)
	// untouched.
	$vp8x_in  = strip_chunk( 'VP8X', "\x3C\x00\x00\x00\x3F\x00\x00\x2F\x00\x00" );
	$vp8x_out = strip_chunk( 'VP8X', "\x30\x00\x00\x00\x3F\x00\x00\x2F\x00\x00" );
	$image    = strip_chunk( 'VP8 ', 'fake-bitstream-data!' );

	$tainted = strip_container( $vp8x_in, $image, strip_chunk( 'EXIF', 'gps' ), strip_chunk( 'XMP ', 'xmp' ) );

	expect( Webp_Metadata_Stripper::strip( $tainted ) )->toBe( strip_container( $vp8x_out, $image ) );
} );

test( 'stale VP8X metadata flags are cleared even with no metadata chunks present', function (): void {
	// A container announcing EXIF in VP8X without carrying the chunk is
	// normalised: the flag is cleared so no consumer goes looking for it.
	$vp8x_in  = strip_chunk( 'VP8X', "\x08\x00\x00\x00\x3F\x00\x00\x2F\x00\x00" );
	$vp8x_out = strip_chunk( 'VP8X', "\x00\x00\x00\x00\x3F\x00\x00\x2F\x00\x00" );
	$image    = strip_chunk( 'VP8 ', 'fake-bitstream-data!' );

	expect( Webp_Metadata_Stripper::strip( strip_container( $vp8x_in, $image ) ) )
		->toBe( strip_container( $vp8x_out, $image ) );
} );

// ---------------------------------------------------------------------------
// Pass-through: never corrupt — when in doubt, hand back the input
// ---------------------------------------------------------------------------

test( 'a container with no metadata is returned as the identical string', function (): void {
	// Byte-identity for clean input is load-bearing: the accept-as-is tests
	// prove "no second lossy pass" by hash comparison against the source.
	$clean = strip_container( strip_chunk( 'VP8 ', 'fake-bitstream-data!' ) );

	expect( Webp_Metadata_Stripper::strip( $clean ) )->toBe( $clean );
} );

test( 'bytes that are not a RIFF WEBP container pass through untouched', function ( string $bytes ): void {
	expect( Webp_Metadata_Stripper::strip( $bytes ) )->toBe( $bytes );
} )->with( [
	'empty'             => [ '' ],
	'plain text'        => [ 'this is not an image' ],
	'too short'         => [ 'RIFF' ],
	'RIFF but not WEBP' => [ 'RIFF' . pack( 'V', 4 ) . 'WAVE' ],
	'a JPEG-ish header' => [ "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 32 ) ],
] );

test( 'structurally broken containers pass through untouched', function ( string $bytes ): void {
	expect( Webp_Metadata_Stripper::strip( $bytes ) )->toBe( $bytes );
} )->with( [
	// The declared RIFF size disagrees with the actual byte length.
	'riff size mismatch'      => [ 'RIFF' . pack( 'V', 9999 ) . 'WEBP' . strip_chunk( 'VP8 ', 'data' ) ],
	// A chunk header is cut off mid-way.
	'truncated chunk header'  => [ 'RIFF' . pack( 'V', 10 ) . "WEBPVP8 \x08\x00" ],
	// A chunk declares more payload than the container holds.
	'truncated chunk payload' => [ 'RIFF' . pack( 'V', 16 ) . 'WEBPEXIF' . pack( 'V', 9999 ) ],
	// An odd-sized final chunk is missing its mandatory pad byte.
	'missing final pad byte'  => [ 'RIFF' . pack( 'V', 15 ) . 'WEBPVP8 ' . pack( 'V', 3 ) . 'odd' ],
] );

// ---------------------------------------------------------------------------
// Against the real thing
// ---------------------------------------------------------------------------

test( 'stripping a tainted real WebP restores the clean original and still decodes', function (): void {
	// Append an EXIF chunk to a real GD-encoded WebP (fixing the RIFF size up),
	// strip it, and require the byte-exact clean original back — which GD must
	// still decode at the original dimensions.
	$clean   = strip_real_webp( 120, 90 );
	$payload = 'GPS-LATITUDE-LONGITUDE';
	$body    = substr( $clean, 12 ) . strip_chunk( 'EXIF', $payload );
	$tainted = 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;

	$stripped = Webp_Metadata_Stripper::strip( $tainted );

	expect( $stripped )->toBe( $clean );
	$decoded = imagecreatefromstring( $stripped );
	expect( imagesx( $decoded ) )->toBe( 120 );
	expect( imagesy( $decoded ) )->toBe( 90 );
} );
