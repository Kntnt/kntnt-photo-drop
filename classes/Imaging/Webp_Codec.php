<?php
/**
 * The pixel-level seam the optimiser drives: probe, decode, scale, encode.
 *
 * The `Optimizer` owns the *contract* logic — when a source may be accepted
 * as-is, that a `null` ceiling never upscales, that quality comes only from the
 * descriptor. The mechanical work of reading an image's format and width,
 * turning bytes into a pixel buffer, scaling that buffer, and encoding it back
 * to WebP is delegated here, so the optimiser can be unit-tested against the
 * real GD codec while remaining free of any one library's API. A second
 * implementation (Imagick) can be slotted in without touching the optimiser.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * A WebP-capable image codec: format probe plus decode / scale / encode.
 *
 * Implementations operate on opaque image handles (`object` so the interface
 * binds to neither GD's `\GdImage` nor Imagick's `\Imagick`). The contract is
 * total and side-effect-free apart from handle lifetime: a caller that decodes a
 * handle is responsible for nothing — the codec frees handles it returns from
 * `scale()` and the caller need not. A `null` from any method means the codec
 * could not perform the operation (undecodable bytes, an encode failure), which
 * the optimiser surfaces as a rejected source rather than a crash.
 *
 * @since 0.3.0
 */
interface Webp_Codec {

	/**
	 * Reports whether this codec can decode and encode WebP at all.
	 *
	 * Used to choose a working codec at construction and to fail loudly when no
	 * codec on the host can handle WebP, rather than silently producing nothing.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True when the codec's WebP support is present and usable.
	 */
	public function is_supported(): bool;

	/**
	 * Probes raw image bytes for their type and pixel dimensions without decoding.
	 *
	 * Returns `[ 'width' => int, 'height' => int, 'is_webp' => bool ]` for any
	 * image the codec recognises, or `null` when the bytes are not a recognisable
	 * image. Kept separate from `decode()` so callers can make cheap decisions —
	 * the accept-as-is format test, and above all the megapixel input ceiling
	 * that must reject a decompression bomb *before* any pixel buffer is
	 * allocated — on header data alone. The probe is header-only and therefore
	 * advisory: a truncated body still probes fine, so integrity requires a
	 * full decode.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return array{width: int, height: int, is_webp: bool}|null The probed facts, or null when unrecognisable.
	 */
	public function probe( string $bytes ): ?array;

	/**
	 * Decodes raw image bytes into an opaque, upright image handle.
	 *
	 * The handle is *upright*: when the source carries an EXIF Orientation tag
	 * (phones store rotated pixels plus the tag), the codec physically
	 * rotates/flips the pixels during decode, because the later WebP encode
	 * strips the tag and would otherwise publish the image sideways. The
	 * handle's width/height may therefore differ from `probe()`'s.
	 *
	 * @since 0.3.0
	 *
	 * @param string $bytes The raw source image bytes.
	 * @return object|null The decoded image handle, or null when the bytes are undecodable.
	 */
	public function decode( string $bytes ): ?object;

	/**
	 * Returns the pixel width of a decoded image handle.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image The decoded image handle.
	 * @return int The handle's width in pixels.
	 */
	public function width( object $image ): int;

	/**
	 * Scales a decoded image to an exact target width, preserving aspect ratio.
	 *
	 * The height is derived from the source aspect ratio so the result is never
	 * distorted. The optimiser only ever calls this to shrink (it guarantees the
	 * target is below the source width), so no implementation needs an upscaling
	 * path. Returns a fresh handle; the caller may discard the source handle.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image        The decoded source handle.
	 * @param int    $target_width The exact width the result must have.
	 * @return object|null The scaled handle, or null when scaling failed.
	 */
	public function scale( object $image, int $target_width ): ?object;

	/**
	 * Encodes a decoded image handle to WebP bytes at the given quality.
	 *
	 * The quality is the descriptor's, never a client-supplied value. Re-encoding
	 * inherently strips EXIF/IPTC, which is the intended privacy property. Returns
	 * the WebP byte string, or `null` when the encode failed.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image   The decoded image handle to encode.
	 * @param int    $quality The WebP quality (0–100) from the descriptor.
	 * @return string|null The encoded WebP bytes, or null on failure.
	 */
	public function encode( object $image, int $quality ): ?string;

}
