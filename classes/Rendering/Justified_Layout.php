<?php
/**
 * Pure per-image flex math for the justified-rows gallery layout (mode B).
 *
 * Mode B lays images out in justified rows of a target height, like a classic
 * photo-wall: each row is stretched to the container width while every image in
 * it keeps a common height (ADR-0005). The trick is CSS flexbox with a per-image
 * `flex-grow` and `flex-basis` derived from the image's aspect ratio: an image's
 * natural width at the target row height is `aspectRatio × targetRowHeight`, used
 * as the basis, and the same aspect ratio is the grow factor so a row's spare
 * width is shared in proportion to each image's width — which keeps the row's
 * images at one height. The last, incomplete row must *not* stretch, or its few
 * images would blow up to fill the width; those images get `flex-grow: 0` so they
 * stay at their natural width and the row is left-aligned. This helper is that
 * math as pure data; emitting the inline styles is the renderer's job.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Computes the `{ grow, basis }` flex pair for each image in a justified gallery.
 *
 * A pure function of the images' stored dimensions, the target row height, the
 * gap, and an assumed container width — no I/O — so the row-packing and the
 * per-image flex values are unit-testable without a browser. The packing groups
 * images into rows whose summed natural widths (at the target height) fill the
 * container, then marks the final row so the renderer can left-align it rather
 * than stretch it.
 *
 * @since 0.6.0
 */
final class Justified_Layout {

	/**
	 * The container width assumed when packing rows, in pixels.
	 *
	 * The browser re-flows from the real width via flexbox, so this only decides
	 * how many images the server groups into each row; a typical content width is
	 * a sound assumption and keeps the last-row detection close to what renders.
	 *
	 * @since 0.6.0
	 * @var int
	 */
	public const ASSUMED_CONTAINER_WIDTH = 960;

	/**
	 * Computes the flex pair for every image, flagging the final row.
	 *
	 * Each image's natural width at the target row height is its aspect ratio
	 * times the target height. Images are greedily packed into rows until the next
	 * image would overflow the assumed container width (accounting for the gap),
	 * then a new row begins. Every image gets `grow` equal to its aspect ratio
	 * (so a row shares spare width in proportion to width, holding one height) and
	 * `basis` equal to its natural width. Images in the final, possibly
	 * incomplete, row are flagged `last_row` so the renderer can pin their grow to
	 * zero and left-align the row.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int,array{width:int,height:int}> $images           The images' stored dimensions, in order.
	 * @param int                                    $target_row_height The target row height in pixels.
	 * @param int                                    $gap               The gap between images in pixels.
	 * @param int                                    $container_width   The assumed container width in pixels.
	 * @return array<int,array{grow:float,basis:float,last_row:bool}> One flex descriptor per image, in order.
	 */
	public static function compute(
		array $images,
		int $target_row_height,
		int $gap,
		int $container_width = self::ASSUMED_CONTAINER_WIDTH,
	): array {

		// Guard the divisor: a non-positive target height has no meaningful natural
		// width, so every image falls back to a square-ish unit basis and no row
		// packing — the renderer still gets a usable, if un-justified, layout.
		$height = $target_row_height > 0 ? $target_row_height : 1;
		$width  = $container_width > 0 ? $container_width : self::ASSUMED_CONTAINER_WIDTH;

		// Greedily pack images into rows by accumulating their natural widths until
		// the next image would overflow the container; record each image's row so a
		// second pass can flag the final row.
		$rows        = [];
		$current_row = [];
		$row_width   = 0.0;
		foreach ( $images as $index => $image ) {
			$ratio   = self::aspect_ratio( $image );
			$natural = $ratio * $height;

			// Start a new row when the current one is non-empty and adding this image
			// (plus the gap before it) would exceed the container width.
			if ( $current_row !== [] && $row_width + $gap + $natural > $width ) {
				$rows[]      = $current_row;
				$current_row = [];
				$row_width   = 0.0;
			}

			// Append the image to the current row, advancing the row's accumulated
			// width by the gap (between items) and the image's natural width.
			$row_width    += ( $current_row === [] ? 0.0 : $gap ) + $natural;
			$current_row[] = [
				'index' => $index,
				'grow'  => $ratio,
				'basis' => $natural,
			];
		}

		// Flush the trailing row, then mark which images belong to that final row so
		// the renderer can left-align it instead of stretching it.
		if ( $current_row !== [] ) {
			$rows[] = $current_row;
		}

		return self::flatten_with_last_row_flag( $rows );

	}

	/**
	 * Returns an image's aspect ratio (width ÷ height), guarding zero height.
	 *
	 * A zero or negative stored height — which a healthy index never produces, but
	 * a tampered one might — falls back to a square ratio so the math stays finite.
	 *
	 * @since 0.6.0
	 *
	 * @param array{width:int,height:int} $image The image's stored dimensions.
	 * @return float The aspect ratio, clamped to a finite positive value.
	 */
	private static function aspect_ratio( array $image ): float {

		// A non-positive height cannot give a real ratio; fall back to 1 (square)
		// so a corrupt dimension degrades gracefully rather than dividing by zero.
		if ( $image['height'] <= 0 || $image['width'] <= 0 ) {
			return 1.0;
		}

		return $image['width'] / $image['height'];

	}

	/**
	 * Flattens packed rows back to a per-image list, flagging the final row.
	 *
	 * The packing groups images into rows; the renderer needs one flat,
	 * in-order descriptor per image plus a flag marking the last row (whose images
	 * must not stretch). This restores image order while carrying that flag.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int,array<int,array{index:int,grow:float,basis:float}>> $rows The packed rows.
	 * @return array<int,array{grow:float,basis:float,last_row:bool}> The per-image descriptors, in order.
	 */
	private static function flatten_with_last_row_flag( array $rows ): array {

		// Walk the rows in order, tagging every image with whether it sits in the
		// last row, and key the result by the image's original index so the output
		// is restored to input order.
		$last_row_number = count( $rows ) - 1;
		$by_index        = [];
		foreach ( $rows as $row_number => $row ) {
			$is_last_row = $row_number === $last_row_number;
			foreach ( $row as $entry ) {
				$by_index[ $entry['index'] ] = [
					'grow'     => $entry['grow'],
					'basis'    => $entry['basis'],
					'last_row' => $is_last_row,
				];
			}
		}

		// Restore ascending image order so the descriptors line up with the input.
		ksort( $by_index );

		return array_values( $by_index );

	}

}
