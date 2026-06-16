<?php
/**
 * The decidable core of the three-rendition model: which derived files exist.
 *
 * Given one main image's pixel width and a collection's full/thumbnail width and
 * quality settings, this pure helper computes the *derived* renditions that
 * should exist on disk under `.kntnt-thumbnails/<width>/` — the **full image**
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
 * WordPress calls — so the whole tier-skip matrix is unit-testable directly.
 *
 * @since 0.7.0
 */
final class Rendition_Plan {

	/**
	 * STUB — replaced by the real implementation after the RED is demonstrated.
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width        The main image's pixel width.
	 * @param int $full_width        The collection's full-image width.
	 * @param int $full_quality      The collection's full-image quality.
	 * @param int $thumbnail_width   The collection's thumbnail width.
	 * @param int $thumbnail_quality The collection's thumbnail quality.
	 * @return array<int,array{width:int,quality:int}> The derived renditions, ascending.
	 */
	public static function derived(
		int $main_width,
		int $full_width,
		int $full_quality,
		int $thumbnail_width,
		int $thumbnail_quality,
	): array {
		return [];
	}

	/**
	 * STUB — replaced by the real implementation after the RED is demonstrated.
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width The main image's pixel width.
	 * @param int $full_width The collection's full-image width.
	 * @return int The full-rendition width.
	 */
	public static function full_rendition_width( int $main_width, int $full_width ): int {
		return 0;
	}

	/**
	 * STUB — replaced by the real implementation after the RED is demonstrated.
	 *
	 * @since 0.7.0
	 *
	 * @param int $main_width The main image's pixel width.
	 * @param int $full_width The collection's full-image width.
	 * @return bool Whether a separate full file exists.
	 */
	public static function has_separate_full( int $main_width, int $full_width ): bool {
		return false;
	}

}
