/**
 * Pure last-row detection for the justified gallery layout (mode B).
 *
 * The server packs justified rows against an assumed container width
 * (`Justified_Layout::ASSUMED_CONTAINER_WIDTH`), so its inline last-row flags —
 * `flex-grow: 0` on the images it believes end the gallery — can disagree with
 * how the real container wraps the rows: a mid-gallery row renders ragged, or
 * the true last row stretches. The view module corrects this client-side by
 * reading each figure's rendered `offsetTop` and asking this module which
 * figures form the actual last row; the server's flags remain the no-JS and
 * first-paint fallback.
 *
 * The decision is trivial on purpose: in a wrapping flex container every figure
 * in a row shares one `offsetTop` (the figures carry a fixed inline height), so
 * the figures with the maximum offset are exactly the last row. Keeping it pure
 * — numbers in, booleans out — makes the boundary cases unit-testable without
 * a layout engine.
 *
 * @since 0.2.0
 */

/**
 * Flags which figures sit in the actual last row of a justified gallery.
 *
 * Figures sharing the maximum top offset form the last row; everything above it
 * does not. An empty input yields an empty output, so the caller needs no
 * special case for an imageless gallery.
 *
 * @since 0.2.0
 *
 * @param tops - Each figure's rendered `offsetTop`, in gallery order.
 * @return One flag per figure, `true` when the figure is in the last row.
 */
export function lastRowFlags( tops: readonly number[] ): boolean[] {
	if ( tops.length === 0 ) {
		return [];
	}

	// Find the maximum offset with a linear scan rather than a spread — a
	// gallery can hold thousands of figures, more than an argument list takes.
	let max = -Infinity;
	for ( const top of tops ) {
		if ( top > max ) {
			max = top;
		}
	}

	return tops.map( ( top ) => top === max );
}
