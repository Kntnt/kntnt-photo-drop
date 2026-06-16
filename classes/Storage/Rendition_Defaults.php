<?php
/**
 * The six per-field rendition defaults that pre-fill a create form.
 *
 * Establishing a collection needs a default for each of the six rendition knobs
 * when a flag or form field is left blank (ADR-0013). Each default reads one
 * `kntnt_photo_drop_default_{upload,full,thumbnail}_{width,quality}` filter so a
 * site can change the starting point without touching code, and hardens the
 * filter's return so a buggy filter can never seed a degenerate collection.
 * Both the admin Create form (pre-fill) and the CLI `collection create` (flag
 * default) read these, keeping the two lifecycle surfaces in step.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * Resolves the filter-driven defaults for the six rendition settings.
 *
 * Every method is a thin, hardened read of one filter. STUB — replaced by the
 * real implementation after the RED is demonstrated.
 *
 * @since 0.7.0
 */
final class Rendition_Defaults {

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int|null The default upload-width ceiling, or null for the source dimensions.
	 */
	public static function upload_width(): ?int {
		return 0;
	}

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default upload (main) quality.
	 */
	public static function upload_quality(): int {
		return 0;
	}

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default full-image width.
	 */
	public static function full_width(): int {
		return 0;
	}

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default full-image quality.
	 */
	public static function full_quality(): int {
		return 0;
	}

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default thumbnail width.
	 */
	public static function thumbnail_width(): int {
		return 0;
	}

	/**
	 * STUB.
	 *
	 * @since 0.7.0
	 *
	 * @return int The default thumbnail quality.
	 */
	public static function thumbnail_quality(): int {
		return 0;
	}

}
