<?php
/**
 * Lossless EXIF/XMP removal from a WebP RIFF container.
 *
 * The optimiser's accept-as-is path stores an already-conforming WebP's source
 * bytes verbatim (ADR-0002: never a second lossy pass), which means a WebP
 * POSTed directly with a GPS EXIF chunk would otherwise be *published* with the
 * coordinates intact — the re-encode path strips metadata inherently, but the
 * pass-through path does not. This class closes that privacy leak at the
 * container level: metadata lives in dedicated RIFF chunks, so it can be
 * dropped losslessly without touching a single compressed pixel.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * Strips `EXIF` and `XMP ` chunks from a WebP byte string, losslessly.
 *
 * Pure and stateless: one static method, bytes in, bytes out. It parses the
 * RIFF header, walks the chunk list (fourcc + uint32-LE size + payload + pad
 * byte on odd sizes), drops the two metadata chunks, clears the corresponding
 * feature flag bits in a `VP8X` chunk when one is present, and recomputes the
 * RIFF size. The guiding rule is *never corrupt*: bytes that are not a
 * RIFF/WEBP container, or whose chunk structure fails to parse cleanly to the
 * exact end, are returned unchanged — when in doubt, pass through. Equally,
 * input with nothing to strip is returned as the identical string, preserving
 * the byte-identity property the accept-as-is tests assert.
 *
 * @since 0.2.0
 */
final class Webp_Metadata_Stripper {

	/**
	 * The VP8X feature flag bits announcing EXIF and XMP chunks.
	 *
	 * EXIF is bit 0x08 and XMP is bit 0x04 of the VP8X payload's first byte;
	 * both must be cleared when the chunks they announce are dropped, or strict
	 * demuxers would reject the file as inconsistent.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private const VP8X_METADATA_FLAGS = 0x08 | 0x04;

	/**
	 * Removes EXIF and XMP metadata from WebP bytes without re-encoding.
	 *
	 * Returns the input unchanged when the bytes are not a RIFF/WEBP container,
	 * when any structural parse step fails (truncated chunk, declared size not
	 * matching the byte length, missing pad byte), or when there is no metadata
	 * to remove. Otherwise returns a rebuilt container holding the same image
	 * chunks byte-for-byte, minus the metadata.
	 *
	 * @since 0.2.0
	 *
	 * @param string $bytes The candidate WebP bytes.
	 * @return string The stripped bytes, or the input unchanged.
	 */
	public static function strip( string $bytes ): string {

		// Gate on the RIFF/WEBP header and require the declared RIFF size to span
		// the rest of the bytes exactly; anything else is not a container this
		// class understands well enough to rewrite safely.
		$length = strlen( $bytes );
		if ( $length < 12 || substr( $bytes, 0, 4 ) !== 'RIFF' || substr( $bytes, 8, 4 ) !== 'WEBP' ) {
			return $bytes;
		}
		if ( self::uint32_le( $bytes, 4 ) !== $length - 8 ) {
			return $bytes;
		}

		// Walk the chunk list, collecting every chunk except the two metadata
		// ones; any structural anomaly aborts the walk and passes the input
		// through untouched.
		$offset  = 12;
		$kept    = [];
		$dropped = false;
		while ( $offset < $length ) {

			// A chunk needs a full 8-byte header and its complete payload plus the
			// pad byte RIFF mandates after an odd-sized payload.
			if ( $offset + 8 > $length ) {
				return $bytes;
			}
			$fourcc  = substr( $bytes, $offset, 4 );
			$size    = self::uint32_le( $bytes, $offset + 4 ) ?? -1;
			$advance = 8 + $size + ( $size % 2 );
			if ( $size < 0 || $offset + $advance > $length ) {
				return $bytes;
			}

			// Drop the metadata chunks; keep everything else byte-for-byte.
			if ( $fourcc === 'EXIF' || $fourcc === 'XMP ' ) {
				$dropped = true;
			} else {
				$kept[] = [ $fourcc, substr( $bytes, $offset + 8, $size ) ];
			}

			$offset += $advance;
		}

		// Clear the EXIF/XMP feature flags in a VP8X chunk so the extended header
		// no longer announces chunks that are gone.
		$flags_cleared = false;
		foreach ( $kept as $i => [ $fourcc, $payload ] ) {
			if ( $fourcc === 'VP8X' && $payload !== '' ) {
				$flags = ord( $payload[0] );
				$clean = $flags & ~self::VP8X_METADATA_FLAGS;
				if ( $clean !== $flags ) {
					$payload[0]    = chr( $clean );
					$kept[ $i ][1] = $payload;
					$flags_cleared = true;
				}
			}
		}

		// Nothing removed and nothing cleared: hand back the identical input so
		// the accept-as-is byte-identity property holds for clean files.
		if ( ! $dropped && ! $flags_cleared ) {
			return $bytes;
		}

		// Reassemble the container around the kept chunks and recompute the RIFF
		// size (the four 'WEBP' bytes plus the chunk list).
		$body = '';
		foreach ( $kept as [ $fourcc, $payload ] ) {
			$size  = strlen( $payload );
			$body .= $fourcc . pack( 'V', $size ) . $payload . ( $size % 2 === 1 ? "\x00" : '' );
		}

		return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;

	}

	/**
	 * Reads an unsigned 32-bit little-endian integer at a byte offset.
	 *
	 * @since 0.2.0
	 *
	 * @param string $bytes  The byte string to read from.
	 * @param int    $offset The offset of the four bytes.
	 * @return int|null The value, or null when fewer than four bytes remain.
	 */
	private static function uint32_le( string $bytes, int $offset ): ?int {

		// Guard the window, then unpack; unpack() itself never sees short input.
		if ( $offset + 4 > strlen( $bytes ) ) {
			return null;
		}
		$parsed = unpack( 'V', substr( $bytes, $offset, 4 ) );

		return is_array( $parsed ) && is_int( $parsed[1] ) ? $parsed[1] : null;

	}

}
