<?php
/**
 * Atomic, durability-checked file writer for plugin-owned files on disk.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * Writes plugin-owned files atomically: temp file, full-length check, rename.
 *
 * The filesystem is the source of truth (ADR-0001), and three of its files are
 * served or parsed directly — main images, thumbnails, and the JSON descriptor
 * and index. A bare `file_put_contents()` over the live name has two failure
 * modes this class closes: a reader (or a crash) can observe a half-written
 * file, and a full disk makes `file_put_contents()` write a *prefix* of the
 * bytes while returning the byte count rather than `false`. Writing to a temp
 * name in the same directory, verifying the full length landed, and publishing
 * with `rename()` (atomic within one filesystem) guarantees a reader only ever
 * sees the old content or the complete new content.
 *
 * @since 0.2.0
 */
final class Atomic_Writer {

	/**
	 * Atomically writes bytes to a path, returning whether the write succeeded.
	 *
	 * The temp file is created beside the target so the final `rename()` never
	 * crosses a filesystem boundary. Permissions follow WordPress convention
	 * (`FS_CHMOD_FILE`, falling back to `0644`) so the web server can serve the
	 * file even under a restrictive process umask; a failed `chmod()` is
	 * tolerated because it merely reproduces the plain-write permissions this
	 * class replaces. Any failed or short write removes the temp file and
	 * leaves the target untouched. Callers own logging — this class only
	 * reports the outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path  Absolute target path on the plugin-owned tree.
	 * @param string $bytes The complete file content to publish.
	 * @return bool True when the full content was published atomically.
	 */
	public static function write( string $path, string $bytes ): bool {

		// Stage the bytes under an unpredictable sibling name and require the
		// full length on disk — a short count is a partial write (disk full,
		// quota), not a success.
		$temp = $path . '.tmp-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- The plugin owns this directory tree on disk directly (ADR-0001), and a failed stage (disk full, permissions) is an expected runtime condition reported through the boolean return, not a warning.
		$written = @file_put_contents( $temp, $bytes );
		if ( $written === false || $written !== strlen( $bytes ) ) {
			self::discard( $temp );
			return false;
		}

		// Normalise permissions before publishing; best-effort by design (see
		// the method contract).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort permission normalisation on a plugin-owned file; failure intentionally falls back to the umask-derived mode a plain write would have produced.
		@chmod( $temp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		// Publish atomically; a reader sees the old file or the new file, never
		// a torn one.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic same-directory publish on the plugin-owned tree (ADR-0001); a failed publish is reported through the boolean return.
		if ( ! @rename( $temp, $path ) ) {
			self::discard( $temp );
			return false;
		}

		return true;

	}

	/**
	 * Removes a temp file left behind by a failed write, if it exists.
	 *
	 * @since 0.2.0
	 *
	 * @param string $temp Absolute path of the staging file.
	 */
	private static function discard( string $temp ): void {
		if ( is_file( $temp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup of the plugin's own staging file.
			unlink( $temp );
		}
	}

}
