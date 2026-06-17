<?php
/**
 * Pure assembly of an image's responsive `srcset` candidate list.
 *
 * The gallery serves each image responsively: the browser picks a rendition by
 * the rendered size and device pixel ratio. The candidates are exactly the two
 * *display* renditions — the **thumbnail** and the **full image** — each
 * advertised at its real pixel width (ADR-0013). The full image is the display
 * ceiling: the (possibly unbounded) main is download-only and is **not** a
 * candidate beyond the full width, because pulling a multi-megabyte main into a
 * grid tile would defeat the whole point of the tiers. The one exception is a
 * main no wider than the full width — there the main *is* the full rendition, so
 * it stays a candidate at its own width (nothing is upscaled). This helper
 * computes that candidate list as pure data; turning it into markup is the
 * renderer's job.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Imaging\Rendition_Plan;

/**
 * Computes the ordered `{ url, width }` candidates for one image's `srcset`.
 *
 * A pure function of the image's main width, the collection's full and thumbnail
 * widths, and two URL builders — no I/O, no WordPress calls — so the candidate
 * set is unit-testable against real widths. It reads which derived renditions
 * exist from the same `Rendition_Plan` the deriver and doctor use, so the srcset
 * never offers a candidate whose file was not written, and never offers the
 * download-only main beyond the full width.
 *
 * @since 0.6.0
 */
final class Srcset_Builder {

	/**
	 * Builds the ascending list of `srcset` candidates for one image.
	 *
	 * Asks `Rendition_Plan` which derived renditions exist for this main under the
	 * given full/thumbnail widths and emits one candidate per derived file at its
	 * real width, served from the hidden width directory via `$derived_url`. When
	 * the main is no wider than the full width it has no separate full file and is
	 * itself the full rendition, so the main URL is added at the main's own width;
	 * when the main is wider than the full width the separate full file is the
	 * ceiling and the main is omitted (download-only). The list is keyed by width
	 * so a derived width never collides with the main candidate, then sorted
	 * ascending so the emitted srcset reads small-to-large.
	 *
	 * @since 0.6.0
	 *
	 * @param int                  $main_width      The main image width in pixels.
	 * @param int                  $full_width      The collection's full-image width.
	 * @param int                  $thumbnail_width The collection's thumbnail width.
	 * @param string               $main_url        The main image URL.
	 * @param callable(int):string $derived_url     Maps a derived width to that rendition's URL.
	 * @return array<int,array{url:string,width:int}> Ascending, de-duplicated candidates.
	 */
	public static function candidates(
		int $main_width,
		int $full_width,
		int $thumbnail_width,
		string $main_url,
		callable $derived_url,
	): array {

		// Each derived rendition (full and/or thumbnail) is a candidate at its real
		// width, served from the hidden width directory. The quality is irrelevant to
		// the srcset, so only the widths the plan yields are used here.
		$by_width = [];
		foreach ( Rendition_Plan::derived( $main_width, $full_width, 0, $thumbnail_width, 0 ) as $rendition ) {
			$by_width[ $rendition['width'] ] = [
				'url'   => $derived_url( $rendition['width'] ),
				'width' => $rendition['width'],
			];
		}

		// A main no wider than the full width is itself the full rendition (no
		// separate full file), so it is the display-ceiling candidate at its own
		// width; a wider main is download-only and never enters the srcset.
		if ( ! Rendition_Plan::has_separate_full( $main_width, $full_width ) ) {
			$by_width[ $main_width ] = [
				'url'   => $main_url,
				'width' => $main_width,
			];
		}

		// Sort ascending by width so the emitted srcset reads small-to-large and the
		// list is deterministic across renders.
		ksort( $by_width );

		return array_values( $by_width );

	}

	/**
	 * Renders a candidate list as a `srcset` attribute value.
	 *
	 * Joins each candidate as `<url> <width>w`, comma-separated, in the order the
	 * candidates are given. Kept separate from `candidates()` so the candidate
	 * computation can be asserted as data while the string form is a thin join.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int,array{url:string,width:int}> $candidates The candidate list.
	 * @return string The `srcset` attribute value.
	 */
	public static function to_attribute( array $candidates ): string {

		// Format each candidate in the standard "<url> <width>w" descriptor form
		// and join them into one comma-separated srcset value.
		$parts = array_map(
			static fn ( array $candidate ): string => $candidate['url'] . ' ' . $candidate['width'] . 'w',
			$candidates,
		);

		return implode( ', ', $parts );

	}

}
