/**
 * Pure overlay band-exclusion logic for the gallery editor (skeleton).
 *
 * Skeleton for the RED step — the exported functions exist with the documented
 * signatures but return placeholder values, so the band tests fail on a real
 * assertion rather than a missing-module error. The GREEN step fills in the
 * mapping and the exclusion.
 *
 * @since 0.11.0
 */

/**
 * The nine-point overlay positions, in row-major order.
 *
 * @since 0.11.0
 */
export type OverlayPosition =
	| 'top-left'
	| 'top-center'
	| 'top-right'
	| 'middle-left'
	| 'middle-center'
	| 'middle-right'
	| 'bottom-left'
	| 'bottom-center'
	| 'bottom-right';

/**
 * The three horizontal bands an overlay position falls into.
 *
 * @since 0.11.0
 */
export type OverlayBand = 'top' | 'middle' | 'bottom';

/**
 * Returns the band a nine-point position falls into (skeleton: always top).
 *
 * @since 0.11.0
 *
 * @param position - The nine-point overlay position.
 * @return The band the position occupies.
 */
export function bandOf( position: OverlayPosition ): OverlayBand {
	void position;
	return 'top';
}

/**
 * Returns the positions a picker must disable for the occupied bands (skeleton).
 *
 * @since 0.11.0
 *
 * @param occupiedBands - The bands occupied by the other overlays.
 * @return The nine-point positions to disable.
 */
export function disabledPositions(
	occupiedBands: readonly OverlayBand[]
): OverlayPosition[] {
	void occupiedBands;
	return [ 'top-left' ];
}
