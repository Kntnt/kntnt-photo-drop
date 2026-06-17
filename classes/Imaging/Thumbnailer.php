<?php
/**
 * Derives a main image's full and thumbnail renditions into the hidden corral.
 *
 * For each derived rendition the tier-skip matrix calls for, the deriver
 * downscales the stored main and writes `<folder>/.kntnt-thumbnails/<width>/<name>.webp`,
 * the path convention the index and gallery already assume — the **full image**
 * and the **thumbnail** are each "just another width" there (ADR-0013). The
 * derived renditions are losslessly re-derivable from the main, so this is pure
 * derived-artifact work: it reads the main, never the original, and writes nothing
 * but derived files. Which files to write — and at which quality — is the single
 * `Rendition_Plan`'s decision, so the deriver never re-implements the tier-skip
 * rules; it just realises the plan on disk.
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
 * Writes the full and thumbnail renditions for one stored main image.
 *
 * The external interface is a single deep method, `generate()`, that takes the
 * main's path and the collection's full/thumbnail width+quality settings and
 * returns the derived paths it wrote. The decode happens once; every rendition
 * scales from the same in-memory handle, and `Rendition_Plan` decides — from the
 * decoded main's own width — which renditions exist and at which quality. The
 * codec is injected (GD by default) so the same mechanics back the optimiser and
 * the deriver, and a test can drive the real GD codec end to end. Holds no
 * per-call state; one instance serves any number of mains.
 *
 * @since 0.3.0
 */
final class Thumbnailer {

	/**
	 * The codec that decodes the main and encodes each derived rendition.
	 *
	 * @since 0.3.0
	 * @var Webp_Codec
	 */
	private readonly Webp_Codec $codec;

	/**
	 * Constructs the deriver with a WebP codec.
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
	 * Generates every derived rendition the tier-skip matrix calls for.
	 *
	 * Decodes the main once, reads its own width, then asks `Rendition_Plan` which
	 * derived renditions should exist for that main under the given full/thumbnail
	 * settings — a separate full only when the main is wider than the full width, a
	 * thumbnail only when the full rendition is wider than the thumbnail width, and
	 * degenerate equal widths collapsed to one file at the larger tier's quality.
	 * Each planned rendition is scaled to its width and encoded at its quality into
	 * `.kntnt-thumbnails/<width>/<name>.webp`. A main no wider than every tier has
	 * an empty plan and writes nothing — it serves every role itself. Returns the
	 * absolute paths actually written, so a caller (the doctor) can reconcile
	 * against them. An unreadable or undecodable main — including one whose declared
	 * dimensions exceed the megapixel input ceiling, refused before any decode —
	 * yields an empty list rather than an error: the next doctor run heals it.
	 *
	 * @since 0.3.0
	 *
	 * @param string $main_path         Absolute path to the stored main image.
	 * @param string $stored_name       The main's `<original>.webp` filename, used for each rendition.
	 * @param int    $full_width        The collection's full-image width.
	 * @param int    $full_quality      The collection's full-image quality.
	 * @param int    $thumbnail_width   The collection's thumbnail width.
	 * @param int    $thumbnail_quality The collection's thumbnail quality.
	 * @return array<int,string> Absolute paths of the derived files written, possibly empty.
	 */
	public function generate(
		string $main_path,
		string $stored_name,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): array {

		// Read and decode the main exactly once; every rendition scales from this
		// same handle. A main that cannot be read, decoded, or is over the input
		// ceiling leaves the next doctor run to heal, so an empty list is the right
		// degraded answer here.
		$image = $this->decode_main( $main_path );
		if ( $image === null ) {
			return [];
		}

		// Realise the plan from the freshly decoded handle; this is the path the
		// regenerator drives, where no decode is yet in hand and the main must be
		// read from disk.
		return $this->generate_from_image(
			$image,
			$main_path,
			$stored_name,
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);

	}

	/**
	 * Derives every rendition the plan calls for from an already-decoded main handle.
	 *
	 * The decode-free twin of `generate()`, for the caller that already holds the
	 * main's decoded pixels: the ingestion path, where the `Optimizer` has just
	 * decoded the source to enforce the contract and hands its upright,
	 * contract-width handle straight on. Scaling the renditions from that handle
	 * spares the redundant full-resolution decode the two-tier baseline never paid —
	 * in the equal-widths case the per-image work then matches the baseline exactly
	 * (issue #57). The handle must be the stored main's own pixels (upright and at
	 * the contract width), so the plan computed from its width and the renditions
	 * scaled from it are byte-for-byte what re-reading the main would yield. The
	 * folder is taken from `$main_path` — only its directory is used; the file need
	 * not be read again. Returns the absolute paths actually written, possibly empty
	 * for a main that serves every role itself.
	 *
	 * @since 0.14.0
	 *
	 * @param object $image             The decoded, upright, contract-width main handle.
	 * @param string $main_path         Absolute path to the stored main image (its folder roots the corral).
	 * @param string $stored_name       The main's `<original>.webp` filename, used for each rendition.
	 * @param int    $full_width        The collection's full-image width.
	 * @param int    $full_quality      The collection's full-image quality.
	 * @param int    $thumbnail_width   The collection's thumbnail width.
	 * @param int    $thumbnail_quality The collection's thumbnail quality.
	 * @return array<int,string> Absolute paths of the derived files written, possibly empty.
	 */
	public function generate_from_image(
		object $image,
		string $main_path,
		string $stored_name,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): array {

		// The folder the main lives in is where the hidden corral is rooted; the
		// main's own decoded width decides which renditions the plan calls for.
		$folder = \dirname( $main_path );
		$plan   = Rendition_Plan::derived(
			$this->codec->width( $image ),
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);

		// Write one file per planned rendition, collecting the paths actually written
		// for the caller to reconcile against. A failure on one rendition is logged
		// and skipped rather than aborting the others.
		$written = [];
		foreach ( $plan as $rendition ) {
			$path = $this->write_one( $image, $folder, $stored_name, $rendition['width'], $rendition['quality'] );
			if ( $path !== null ) {
				$written[] = $path;
			}
		}

		return $written;

	}

	/**
	 * Reports whether every derived rendition a main should have is present on disk.
	 *
	 * The disambiguator that the regenerate-then-flip flow rests on (ADR-0013): a
	 * `generate()` that returns `[]` cannot itself distinguish a legitimately
	 * tier-collapsed small main (nothing to write, every role served by the main
	 * itself) from a main that *should* have produced renditions but failed to —
	 * undecodable, over the input ceiling, or an encode/write failure on every tier.
	 * This method answers that distinction against the given full/thumbnail settings:
	 * it decodes the main, asks `Rendition_Plan` which renditions should exist for the
	 * main's own width, and confirms each one's file is on disk. A main that cannot be
	 * decoded (so no plan can even be computed) is reported as **not** complete, since
	 * the flow must never flip onto renditions it cannot verify. As in `generate()`, a
	 * rendition whose write path is a symlink is excluded from the expectation — the
	 * deriver never writes (and so never demands) a file through a planted link — so a
	 * hostile symlink cannot permanently block a collection's regenerate. An empty plan
	 * (the legitimate collapse) is vacuously complete.
	 *
	 * @since 0.11.0
	 *
	 * @param string $main_path         Absolute path to the stored main image.
	 * @param string $stored_name       The main's `<original>.webp` filename.
	 * @param int    $full_width        The full-image width to check against.
	 * @param int    $full_quality      The full-image quality (unused for presence; kept for plan symmetry).
	 * @param int    $thumbnail_width   The thumbnail width to check against.
	 * @param int    $thumbnail_quality The thumbnail quality (unused for presence; kept for plan symmetry).
	 * @return bool True when every expected, writable rendition exists on disk.
	 */
	public function renditions_present(
		string $main_path,
		string $stored_name,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): bool {

		// Decode the main to learn its own width; a main that cannot be decoded yields
		// no plan, and an unverifiable main is never treated as complete.
		$image = $this->decode_main( $main_path );
		if ( $image === null ) {
			return false;
		}

		// Defer to the width-driven check with the decoded main's own width; the decode
		// existed only to learn that width.
		return $this->renditions_present_for_width(
			$this->codec->width( $image ),
			$main_path,
			$stored_name,
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);

	}

	/**
	 * Reports completeness for a main whose width the caller already knows.
	 *
	 * The decode-free core of `renditions_present()`: given the main's pixel width —
	 * which a caller holding a fresh index already has stored, sparing the full pixel
	 * decode that method performs solely to read it — it asks `Rendition_Plan` which
	 * renditions the width calls for under the target settings and confirms each one's
	 * file is on disk. The symlink exclusion and the empty-plan-is-complete rule are
	 * identical to the decode path, so the two agree on every input bar where the
	 * width comes from. The width must be the main's *actual* current width; a caller
	 * that cannot guarantee that (a stale or missing index entry) must use
	 * `renditions_present()` instead, which reads the width by decoding.
	 *
	 * @since 0.13.0
	 *
	 * @param int    $main_width        The main image's pixel width (from a trusted source).
	 * @param string $main_path         Absolute path to the stored main image.
	 * @param string $stored_name       The main's `<original>.webp` filename.
	 * @param int    $full_width        The full-image width to check against.
	 * @param int    $full_quality      The full-image quality (unused for presence; kept for plan symmetry).
	 * @param int    $thumbnail_width   The thumbnail width to check against.
	 * @param int    $thumbnail_quality The thumbnail quality (unused for presence; kept for plan symmetry).
	 * @return bool True when every expected, writable rendition exists on disk.
	 */
	public function renditions_present_for_width(
		int $main_width,
		string $main_path,
		string $stored_name,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): bool {

		// Ask the plan which renditions this main's width calls for under the target
		// settings; an empty plan is the legitimate collapse and is vacuously complete.
		$folder = \dirname( $main_path );
		$plan   = Rendition_Plan::derived(
			$main_width,
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
		);

		// Every planned rendition whose path is writable (symlink-free, exactly the set
		// the deriver would write) must exist on disk; the first missing one fails the
		// completeness check.
		foreach ( $plan as $rendition ) {
			$path = self::thumbnail_path( $folder, $stored_name, $rendition['width'] );
			if ( ! $this->writes_through_symlink( $folder, $path ) && ! is_file( $path ) ) {
				return false;
			}
		}

		return true;

	}

	/**
	 * Reads and decodes a stored main once, refusing it when unsafe or undecodable.
	 *
	 * The shared front half of `generate()` and `renditions_present()`: it reads the
	 * bytes, refuses a declared pixel area over the input ceiling (a foreign or
	 * tampered file that large would OOM-kill the worker — the same ceiling guards
	 * every decode path in the plugin), and decodes to an upright handle. Returns the
	 * decoded handle, or `null` when the main is missing, unreadable, over the
	 * ceiling, or undecodable — the single point both callers treat as "no usable
	 * main", so the derive and the completeness check agree on what counts as decodable.
	 *
	 * @since 0.11.0
	 *
	 * @param string $main_path Absolute path to the stored main image.
	 * @return object|null The decoded image handle, or null when the main is unusable.
	 */
	private function decode_main( string $main_path ): ?object {

		// A missing or unreadable main is a soft failure both callers degrade on.
		$bytes = $this->read_main( $main_path );
		if ( $bytes === null ) {
			return null;
		}

		// Refuse to decode a main whose declared pixel area exceeds the input ceiling
		// before any pixel buffer is allocated; an unrecognisable header is refused too.
		$probe = $this->codec->probe( $bytes );
		if ( $probe === null || ! Input_Ceiling::allows( $probe['width'], $probe['height'] ) ) {
			Plugin::warning( "Refused to decode the main at {$main_path}: unrecognisable or over the input ceiling." );
			return null;
		}

		// Decode to an upright handle; an undecodable body (a truncated or corrupt file
		// that probed fine) is a soft failure the caller surfaces.
		$image = $this->codec->decode( $bytes );
		if ( $image === null ) {
			Plugin::warning( "Cannot decode the main image at {$main_path} to derive its renditions." );
			return null;
		}

		return $image;

	}

	/**
	 * Returns the absolute derived-file path for a main name at a given width.
	 *
	 * Exposed so the doctor and any caller that must locate or remove a derived
	 * rendition computes the same `.kntnt-thumbnails/<width>/<name>.webp` path this
	 * class writes, keeping the convention in one place. The full image and the
	 * thumbnail share this convention — both are "just another width".
	 *
	 * @since 0.3.0
	 *
	 * @param string $folder      Absolute path to the content folder holding the main.
	 * @param string $stored_name The main's stored `<original>.webp` filename.
	 * @param int    $width       The rendition width.
	 * @return string The absolute derived-file path.
	 */
	public static function thumbnail_path( string $folder, string $stored_name, int $width ): string {
		return rtrim( $folder, '/' ) . '/' . Index::THUMBNAILS_DIRNAME . '/' . $width . '/' . $stored_name;
	}

	/**
	 * Scales and writes one derived rendition, returning its path or null on failure.
	 *
	 * Ensures the per-width directory exists, scales the shared main handle to the
	 * width, encodes at the rendition's quality, and writes the bytes. Any step
	 * failing is a warning and a `null`, so the caller skips this rendition and
	 * tries the next.
	 *
	 * @since 0.3.0
	 *
	 * @param object $image       The decoded main handle, shared across renditions.
	 * @param string $folder      Absolute path to the content folder.
	 * @param string $stored_name The main's stored filename.
	 * @param int    $width       The target rendition width.
	 * @param int    $quality     The WebP quality for this rendition.
	 * @return string|null The written rendition path, or null on failure.
	 */
	private function write_one(
		object $image,
		string $folder,
		string $stored_name,
		int $width,
		int $quality,
	): ?string {

		// Refuse to write through any symlink on the rendition's path — the hidden
		// corral, the `<width>/` bucket, or the target file itself — so a planted
		// link can never route the write outside the collection (defence in depth for
		// every caller, the doctor's symlink-safety in particular).
		$path = self::thumbnail_path( $folder, $stored_name, $width );
		if ( $this->writes_through_symlink( $folder, $path ) ) {
			Plugin::warning( "Refused to write a derived rendition through a symlink at {$path}." );
			return null;
		}

		// Ensure the `<width>/` directory under the hidden corral exists.
		if ( ! $this->ensure_dir( \dirname( $path ) ) ) {
			Plugin::warning( "Could not create the rendition directory for width {$width} under {$folder}." );
			return null;
		}

		// Scale a fresh copy to this width and encode it at the rendition's quality;
		// a failure on either step skips just this rendition.
		$scaled = $this->codec->scale( $image, $width );
		if ( $scaled === null ) {
			return null;
		}
		$encoded = $this->codec->encode( $scaled, $quality );
		if ( $encoded === null ) {
			return null;
		}

		// Publish the rendition atomically so a concurrent reader (or a re-derive of
		// the same name) never observes a torn file; a failed write leaves the doctor
		// to heal it.
		if ( ! Atomic_Writer::write( $path, $encoded ) ) {
			Plugin::warning( "Failed to write the derived rendition at {$path}." );
			return null;
		}

		return $path;

	}

	/**
	 * Reports whether writing the rendition would pass through any symlink.
	 *
	 * Checks the three points on the path a planted link could redirect the write
	 * outside the collection: the hidden corral (`.kntnt-thumbnails`), the
	 * `<width>/` bucket directory, and the target file itself (a dangling or
	 * planted file link). A `true` here means the deriver must refuse the write —
	 * the plugin never writes through a link it does not own.
	 *
	 * @since 0.7.0
	 *
	 * @param string $folder The content folder holding the main.
	 * @param string $path   The intended absolute rendition path.
	 * @return bool True when any segment of the write path is a symlink.
	 */
	private function writes_through_symlink( string $folder, string $path ): bool {

		// The corral and the width bucket are the two directory hops; the target is
		// the file itself. Any of them being a link routes the write off the tree.
		$corral = rtrim( $folder, '/' ) . '/' . Index::THUMBNAILS_DIRNAME;
		return is_link( $corral ) || is_link( \dirname( $path ) ) || is_link( $path );

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
	 * derived files can be written in a unit runtime without a WordPress install.
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
