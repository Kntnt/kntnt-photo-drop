/**
 * Pure overlay band-exclusion logic for the gallery editor.
 *
 * Breadcrumbs occupy a whole horizontal band (the position's vertical half —
 * top, middle, or bottom), so a breadcrumb and an icon must never share a band
 * (ADR-0015). The editor enforces this by disabling, in each picker, the
 * positions of the bands the *other* overlays occupy. These two pure helpers are
 * that decision core: `bandOf` maps a nine-point position to its band, and
 * `disabledPositions` turns a set of occupied bands into the nine-point positions
 * a picker must disable. The editor wires them; the DOM/disabled-state shell is
 * exercised by e2e (docs/testing.md, "#47 Pure core + non-unit shell"). Icons may
 * still share a position with one another (they auto-cluster), so only the
 * breadcrumb-vs-icon bands are ever excluded — never icon-vs-icon.
 *
 * @since 0.15.0
 */

/**
 * The nine-point overlay positions, in row-major order.
 *
 * @since 0.15.0
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
 * @since 0.15.0
 */
export type OverlayBand = 'top' | 'middle' | 'bottom';

/**
 * The three bands in top-to-bottom order, each with its three positions.
 *
 * The single source of the position↔band mapping, so `bandOf` and
 * `disabledPositions` stay consistent and the emitted order is deterministic
 * (top → middle → bottom, left → centre → right).
 *
 * @since 0.15.0
 */
const BANDS: readonly {
	readonly band: OverlayBand;
	readonly positions: readonly OverlayPosition[];
}[] = [
	{ band: 'top', positions: [ 'top-left', 'top-center', 'top-right' ] },
	{
		band: 'middle',
		positions: [ 'middle-left', 'middle-center', 'middle-right' ],
	},
	{
		band: 'bottom',
		positions: [ 'bottom-left', 'bottom-center', 'bottom-right' ],
	},
];

/**
 * Returns the band a nine-point position falls into.
 *
 * The band is the position's vertical half — the prefix before the hyphen.
 *
 * @since 0.15.0
 *
 * @param position - The nine-point overlay position.
 * @return The band the position occupies.
 */
export function bandOf( position: OverlayPosition ): OverlayBand {
	return position.split( '-' )[ 0 ] as OverlayBand;
}

/**
 * Returns the positions a picker must disable for the occupied bands.
 *
 * Collects the three positions of every occupied band, de-duplicated and in the
 * canonical top→bottom, left→right order, so the same band repeated across two
 * icons contributes its three positions once.
 *
 * @since 0.15.0
 *
 * @param occupiedBands - The bands occupied by the other overlays.
 * @return The nine-point positions to disable, in canonical order.
 */
export function disabledPositions(
	occupiedBands: readonly OverlayBand[]
): OverlayPosition[] {
	// Walk the bands in canonical order, keeping each one's positions only when
	// that band is occupied — so the result is de-duplicated and order-stable
	// regardless of the input order or repeats.
	const occupied = new Set( occupiedBands );
	return BANDS.filter( ( entry ) => occupied.has( entry.band ) ).flatMap(
		( entry ) => entry.positions
	);
}
