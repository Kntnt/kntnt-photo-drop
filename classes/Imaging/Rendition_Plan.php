<?php
/**
 * The decidable core of the three-rendition model: which derived files exist.
 *
 * Given one main image's pixel width and a collection's full/thumbnail width and
 * quality settings, this pure helper computes the *derived* renditions that
 * should exist on disk under `kntnt-thumbnails/<width>/` — the **full image**
 * and the **thumbnail**, each derived from the tier above it (`main → full →
 * thumbnail`) and skipped when its source is no wider (ADR-0013). Degenerate
 * width orderings collapse a tier into a single file rather than colliding, with
 * the larger tier's quality winning a shared width.
 *
 * It is the single source of truth for the tier-skip matrix: the deriver writes
 * exactly this plan, the doctor reconciles against it, and the srcset builder
 * reads which renditions a viewer may reach. Keeping the matrix in one pure,
 * dependency-free place is what stops the three consumers from drifting.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Imaging;

/**
 * Computes the derived renditions a main image should have under a contract.
 *
 * Every method is a pure, total function of its integer arguments — no I/O, no
 * WordPress calls — so the whole tier-skip matrix is unit-testable directly. The
 * three methods are facets of one model: `derived()` is the full file list the
 * deriver and doctor act on, while `has_separate_full()` and
 * `full_rendition_width()` answer the two srcset questions (is the main
 * download-only, and what is the display ceiling) without rebuilding the list.
 *
 * @since 0.7.0
 */
final class Rendition_Plan {

	/**
	 * Computes the ascending list of derived renditions for a main image.
	 *
	 * Walks the tiers from the top down so the larger tier always wins a shared
	 * width: a separate **full** file is added when the main is strictly wider
	 * than the full width, then a **thumbnail** when the full *rendition* (the
	 * separate full, or the main itself when no wider) is strictly wider than the
	 * thumbnail width. Each rendition is keyed by its width, so two tiers landing
	 * on the same `<width>/` bucket collapse to one file carrying the first
	 * (full-tier) quality — the degenerate equal-widths case where the thumbnail
	 * quality is simply unused (ADR-0013). The result is sorted ascending and the
	 * main itself is never included; an empty list means the main serves every
	 * role.
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width        The main image's pixel width.
	 * @param int $full_width        The collection's full-image width.
	 * @param int $full_quality      The collection's full-image quality.
	 * @param int $thumbnail_width   The collection's thumbnail width.
	 * @param int $thumbnail_quality The collection's thumbnail quality.
	 * @return array<int,array{width:int,quality:int}> The derived renditions, ascending by width.
	 */
	public static function derived(
		int $main_width,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): array {

		// Accumulate one entry per width, full tier first so a width shared with the
		// thumbnail keeps the full (larger-tier) quality and the thumbnail collapses
		// into it rather than overwriting it.
		$by_width = [];

		// A separate full file exists only when the main is strictly wider than the
		// full width; otherwise the main is itself the full rendition.
		if ( self::has_separate_full( $main_width, $full_width ) ) {
			$by_width[ $full_width ] = [
				'width'   => $full_width,
				'quality' => $full_quality,
			];
		}

		// A thumbnail exists only when the full rendition is strictly wider than the
		// thumbnail width; a width already taken by the full tier is left untouched,
		// so equal widths collapse to the single full file.
		$ceiling = self::full_rendition_width( $main_width, $full_width );
		if ( $ceiling > $thumbnail_width && ! isset( $by_width[ $thumbnail_width ] ) ) {
			$by_width[ $thumbnail_width ] = [
				'width'   => $thumbnail_width,
				'quality' => $thumbnail_quality,
			];
		}

		// Emit the renditions ascending by width so the deriver, doctor, and srcset
		// all read a deterministic small-to-large order.
		ksort( $by_width );

		return array_values( $by_width );

	}

	/**
	 * Returns the pixel width of the image's full rendition — the display ceiling.
	 *
	 * When the main is wider than the full width, the full rendition is the bounded
	 * separate full file (`full_width`); otherwise the main is itself the full
	 * rendition and nothing is upscaled, so the width is the main's own. The srcset
	 * never offers anything wider than this, because the (possibly unbounded) main
	 * is download-only beyond the full width (ADR-0013).
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width The main image's pixel width.
	 * @param int $full_width The collection's full-image width.
	 * @return int The full-rendition width.
	 */
	public static function full_rendition_width( int $main_width, int $full_width ): int {
		return self::has_separate_full( $main_width, $full_width ) ? $full_width : $main_width;
	}

	/**
	 * Reports whether a separate full file is written, distinct from the main.
	 *
	 * True only when the main is *strictly* wider than the full width — at or below
	 * it, downscaling would be a pointless re-encode (or an upscale), so the main
	 * serves the full role directly. This is the boundary that also decides whether
	 * the main is a srcset candidate: when there is no separate full, the main *is*
	 * the full rendition and stays a candidate; when there is, the main is excluded
	 * and download-only (ADR-0013).
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width The main image's pixel width.
	 * @param int $full_width The collection's full-image width.
	 * @return bool Whether a separate full file exists.
	 */
	public static function has_separate_full( int $main_width, int $full_width ): bool {
		return $main_width > $full_width;
	}

}
