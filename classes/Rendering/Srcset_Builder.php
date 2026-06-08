<?php
/**
 * Pure assembly of an image's responsive `srcset` candidate list.
 *
 * The gallery serves each image responsively: the browser picks a rendition by
 * the rendered size and device pixel ratio. The candidates are the collection's
 * thumbnail widths plus the main image, each advertised at its *real* pixel
 * width so the browser's selection is honest. The main is always a candidate, so
 * the browser never has to upscale a thumbnail — at large rendered sizes or high
 * DPR it reaches for the main (ADR-0005). This helper computes that candidate
 * list as pure data; turning it into markup is the renderer's job.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Computes the ordered `{ url, width }` candidates for one image's `srcset`.
 *
 * A pure function of the image's dimensions, the collection's thumbnail widths,
 * and a URL builder closure — no I/O, no WordPress calls — so the candidate set
 * is unit-testable against real widths. Two invariants hold for every output:
 * the main image is always present (so no candidate is ever an upscale), and a
 * thumbnail width at or above the main width is dropped (the index never stores
 * an upscaled thumbnail, and a duplicate-width candidate would be redundant).
 *
 * @since 0.6.0
 */
final class Srcset_Builder {

	/**
	 * Builds the ascending list of `srcset` candidates for one image.
	 *
	 * Each thumbnail width strictly smaller than the main width contributes one
	 * candidate at that real width; the main image contributes the final
	 * candidate at its own width. The list is sorted ascending by width and
	 * de-duplicated, so a thumbnail width equal to the main never produces two
	 * candidates at the same width. The URL for each candidate is produced by the
	 * injected builder, keeping this helper free of URL arithmetic.
	 *
	 * @since 0.6.0
	 *
	 * @param int                  $main_width       The main image width in pixels.
	 * @param array<int,int>       $thumbnail_widths The collection's thumbnail widths.
	 * @param string               $main_url         The main image URL.
	 * @param callable(int):string $thumbnail_url    Maps a width to that thumbnail's URL.
	 * @return array<int,array{url:string,width:int}> Ascending, de-duplicated candidates.
	 */
	public static function candidates(
		int $main_width,
		array $thumbnail_widths,
		string $main_url,
		callable $thumbnail_url,
	): array {

		// Collect one candidate per thumbnail width that is strictly narrower than
		// the main; a width at or above the main is not a real thumbnail (the index
		// never upscales) and would only duplicate the main candidate.
		$by_width = [];
		foreach ( $thumbnail_widths as $width ) {
			if ( $width > 0 && $width < $main_width ) {
				$by_width[ $width ] = [
					'url'   => $thumbnail_url( $width ),
					'width' => $width,
				];
			}
		}

		// The main is always a candidate, keyed by its own width so it overrides
		// any thumbnail that happened to match it — the browser then never has to
		// upscale a thumbnail, because the full-size rendition is always offered.
		$by_width[ $main_width ] = [
			'url'   => $main_url,
			'width' => $main_width,
		];

		// Sort ascending by width so the emitted srcset reads small-to-large and
		// the list is deterministic across renders.
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
