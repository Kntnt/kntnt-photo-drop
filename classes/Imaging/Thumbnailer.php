<?php
/**
 * Derives a main image's thumbnail(s) into the hidden artifacts directory.
 *
 * For each configured thumbnail width, the thumbnailer downscales the stored
 * main and writes `<folder>/.kntnt-thumbnails/<width>/<name>.webp`, the path
 * convention the index and gallery already assume. Thumbnails are losslessly
 * re-derivable from the main, so this is pure derived-artifact work: it reads
 * the main, never the original, and writes nothing but thumbnails. A main whose
 * own width is at or below a configured width needs no thumbnail there — the
 * main already serves that role in the gallery's `srcset`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Atomic_Writer;
use Kntnt\Photo_Drop\Storage\Index;

/**
 * Writes per-width thumbnails for one stored main image.
 *
 * The external interface is a single deep method, `generate()`, that takes the
 * main's path and the descriptor's thumbnail widths and quality and returns the
 * thumbnail paths it wrote. The decode happens once; every width scales from the
 * same in-memory handle. The codec is injected (GD by default) so the same
 * mechanics back the optimiser and the thumbnailer, and a test can drive the
 * real GD codec end to end. Holds no per-call state; one instance serves any
 * number of mains.
 *
 * @since 0.3.0
 */
final class Thumbnailer {

	/**
	 * The codec that decodes the main and encodes each thumbnail.
	 *
	 * @since 0.3.0
	 * @var Webp_Codec
	 */
	private readonly Webp_Codec $codec;

	/**
	 * Constructs the thumbnailer with a WebP codec.
	 *
	 * Defaults to the GD codec, the tested production path, so production callers
	 * construct it with no arguments; a test injects its own to exercise the
	 * mechanics or force a failure.
	 *
	 * @since 0.3.0
	 *
	 * @param Webp_Codec|null $codec The codec to decode/scale/encode with, or null for GD.
	 */
	public function __construct( ?Webp_Codec $codec = null ) {
		$this->codec = $codec ?? new Gd_Webp_Codec();
	}

	/**
	 * Generates every applicable thumbnail for a stored main image.
	 *
	 * Decodes the main once, then for each configured width strictly below the
	 * main's own width writes `.kntnt-thumbnails/<width>/<name>.webp` scaled to
	 * that width and encoded at the descriptor's quality. A width at or above the
	 * main's width is skipped — the main serves that role itself — and an empty
	 * width list writes nothing. Returns the absolute paths actually written, so a
	 * caller (the doctor) can reconcile against them. An unreadable or undecodable
	 * main — including one whose declared dimensions exceed the megapixel input
	 * ceiling, which is refused before any decode — yields an empty list rather
	 * than an error: the next doctor run heals it.
	 *
	 * @since 0.3.0
	 *
	 * @param string         $main_path        Absolute path to the stored main image.
	 * @param string         $stored_name      The main's `<original>.webp` filename, used for each thumbnail.
	 * @param array<int,int> $thumbnail_widths The descriptor's canonical thumbnail widths.
	 * @param int            $quality          The WebP quality (0–100) from the descriptor.
	 * @return array<int,string> Absolute paths of the thumbnails written, possibly empty.
	 */
	public function generate( string $main_path, string $stored_name, array $thumbnail_widths, int $quality ): array {

		// No configured widths means no thumbnails at all; return before any read.
		if ( $thumbnail_widths === [] ) {
			return [];
		}

		// Read and decode the main exactly once; every width scales from this same
		// handle. A main that cannot be read or decoded leaves the next doctor run
		// to heal, so an empty list is the right degraded answer here.
		$bytes = $this->read_main( $main_path );
		if ( $bytes === null ) {
			return [];
		}

		// Refuse to decode a main whose declared pixel area exceeds the input
		// ceiling — a foreign or tampered file that large would OOM-kill the
		// worker; the same ceiling guards every decode path in the plugin.
		$probe = $this->codec->probe( $bytes );
		if ( $probe === null || ! Input_Ceiling::allows( $probe['width'], $probe['height'] ) ) {
			Plugin::warning( "Refused to decode the main at {$main_path}: unrecognisable or over the input ceiling." );
			return [];
		}
		$image = $this->codec->decode( $bytes );
		if ( $image === null ) {
			Plugin::warning( "Cannot decode the main image at {$main_path} to derive thumbnails." );
			return [];
		}

		// The folder the main lives in is where the hidden thumbnails directory is
		// rooted; the main's own width decides which configured widths apply.
		$folder    = \dirname( $main_path );
		$main_width = $this->codec->width( $image );

		// Write one thumbnail per configured width strictly below the main width,
		// collecting the paths actually written for the caller to reconcile against.
		$written = [];
		foreach ( $thumbnail_widths as $width ) {

			// A width at or above the main's own width needs no separate thumbnail —
			// the main already covers it in the gallery's srcset.
			if ( $width >= $main_width ) {
				continue;
			}

			// Scale and encode this width; a failure on one width is logged and
			// skipped rather than aborting the remaining widths.
			$path = $this->write_one( $image, $folder, $stored_name, $width, $quality );
			if ( $path !== null ) {
				$written[] = $path;
			}
		}

		return $written;

	}

	/**
	 * Returns the absolute thumbnail path for a main name at a given width.
	 *
	 * Exposed so the doctor (#6) and any caller that must locate or remove a
	 * derived thumbnail computes the same `.kntnt-thumbnails/<width>/<name>.webp`
	 * path this class writes, keeping the convention in one place.
	 *
	 * @since 0.3.0
	 *
	 * @param string $folder      Absolute path to the content folder holding the main.
	 * @param string $stored_name The main's stored `<original>.webp` filename.
	 * @param int    $width       The thumbnail width.
	 * @return string The absolute thumbnail path.
	 */
	public static function thumbnail_path( string $folder, string $stored_name, int $width ): string {
		return rtrim( $folder, '/' ) . '/' . Index::THUMBNAILS_DIRNAME . '/' . $width . '/' . $stored_name;
	}

	/**
	 * Scales and writes one thumbnail, returning its path or null on failure.
	 *
	 * Ensures the per-width directory exists, scales the shared main handle to the
	 * width, encodes at the descriptor's quality, and writes the bytes. Any step
	 * failing is a warning and a `null`, so the caller skips this width and tries
	 * the next.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image       The decoded main handle, shared across widths.
	 * @param string $folder      Absolute path to the content folder.
	 * @param string $stored_name The main's stored filename.
	 * @param int    $width       The target thumbnail width.
	 * @param int    $quality     The WebP quality from the descriptor.
	 * @return string|null The written thumbnail path, or null on failure.
	 */
	private function write_one(
		object $image,
		string $folder,
		string $stored_name,
		int $width,
		int $quality,
	): ?string {

		// Ensure the `<width>/` directory under the hidden thumbnails dir exists.
		$path = self::thumbnail_path( $folder, $stored_name, $width );
		if ( ! $this->ensure_dir( \dirname( $path ) ) ) {
			Plugin::warning( "Could not create the thumbnail directory for width {$width} under {$folder}." );
			return null;
		}

		// Scale a fresh copy to this width and encode it at the descriptor quality;
		// a failure on either step skips just this width.
		$scaled = $this->codec->scale( $image, $width );
		if ( $scaled === null ) {
			return null;
		}
		$encoded = $this->codec->encode( $scaled, $quality );
		if ( $encoded === null ) {
			return null;
		}

		// Publish the thumbnail atomically so a concurrent reader (or a re-derive
		// of the same name) never observes a torn file; a failed write leaves the
		// doctor to heal it.
		if ( ! Atomic_Writer::write( $path, $encoded ) ) {
			Plugin::warning( "Failed to write the thumbnail at {$path}." );
			return null;
		}

		return $path;

	}

	/**
	 * Reads a stored main image's bytes, or null when it cannot be read.
	 *
	 * @since 0.3.0
	 *
	 * @param string $main_path Absolute path to the main image.
	 * @return string|null The file bytes, or null when missing or unreadable.
	 */
	private function read_main( string $main_path ): ?string {

		// The plugin owns this directory tree on disk directly (ADR-0001), so it
		// reads the file rather than routing through the Media Library.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = is_file( $main_path ) ? file_get_contents( $main_path ) : false;

		return $bytes === false ? null : $bytes;

	}

	/**
	 * Creates a directory tree, preferring the WordPress helper when present.
	 *
	 * Uses `wp_mkdir_p()` inside WordPress and a recursive `mkdir()` otherwise, so
	 * thumbnails can be written in a unit runtime without a WordPress install.
	 *
	 * @since 0.3.0
	 *
	 * @param string $directory Absolute path of the directory to create.
	 * @return bool True when the directory exists afterwards.
	 */
	private function ensure_dir( string $directory ): bool {

		// An existing directory needs nothing further.
		if ( is_dir( $directory ) ) {
			return true;
		}

		// Prefer the WordPress helper; fall back to a recursive mkdir so the write
		// path works in a plain-PHP test runtime too.
		if ( function_exists( 'wp_mkdir_p' ) ) {
			return wp_mkdir_p( $directory );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		return mkdir( $directory, 0755, true );

	}

}
