/**
 * Pure index reducer for the Gallery lightbox.
 *
 * The lightbox is a single-image viewer over the gallery's flattened, ordered
 * image list; its only navigable state is which image is showing and whether it
 * is open at all. This module is that state machine as pure functions — no DOM,
 * no Interactivity API — so the navigation contract (wrap on prev/next, clamp on
 * first/last, no-op on an empty gallery) is unit-testable without a browser. The
 * DOM wiring in `lightbox.ts` holds an instance of {@link LightboxState} and
 * feeds it through these reducers, then renders the result.
 *
 * The wrap rule is deliberate: pressing Next on the last image returns to the
 * first and Previous on the first goes to the last, so a visitor can cycle the
 * whole set without hitting a dead end. Home and End jump to the first and last
 * image respectively (a clamp, not a wrap — there is nothing past either edge).
 *
 * @since 0.7.0
 */

/**
 * The lightbox's navigable state: the total image count, the currently shown
 * index, and whether the overlay is open.
 *
 * `index` is always a valid position in `[0, count)` while `count > 0`; an empty
 * gallery (`count === 0`) is a degenerate state the reducers leave closed at
 * index `0`. `open` is tracked here rather than in the DOM so the reducer owns
 * the whole machine and the wiring merely reflects it.
 *
 * @since 0.7.0
 */
export interface LightboxState {
	/** The total number of images the lightbox can page through. */
	readonly count: number;
	/** The zero-based index of the image currently shown. */
	readonly index: number;
	/** Whether the lightbox overlay is open. */
	readonly open: boolean;
}

/**
 * Builds the initial, closed lightbox state for a gallery of `count` images.
 *
 * @since 0.7.0
 *
 * @param count - The number of images in the gallery (clamped to ≥ 0).
 * @return A closed state positioned at the first image.
 */
export function createLightboxState( count: number ): LightboxState {
	return { count: Math.max( 0, Math.trunc( count ) ), index: 0, open: false };
}

/**
 * Opens the lightbox at a specific image, clamping the index into range.
 *
 * An out-of-range index is clamped to the nearest valid position rather than
 * rejected, so a stale trigger (e.g. an image removed between render and click)
 * still opens on a real image. An empty gallery cannot be opened and is returned
 * unchanged.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @param index - The zero-based index of the image to show.
 * @return The opened state at the clamped index, or the unchanged state when empty.
 */
export function open( state: LightboxState, index: number ): LightboxState {
	if ( state.count === 0 ) {
		return state;
	}
	const clamped = Math.min(
		Math.max( 0, Math.trunc( index ) ),
		state.count - 1
	);
	return { ...state, index: clamped, open: true };
}

/**
 * Closes the lightbox, preserving the current index.
 *
 * The index is kept so a re-open without an explicit target lands where the
 * visitor left off; the wiring always re-opens with an explicit index from the
 * clicked thumbnail, so this is a harmless convenience.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The closed state.
 */
export function close( state: LightboxState ): LightboxState {
	return { ...state, open: false };
}

/**
 * Advances to the next image, wrapping from the last back to the first.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The state advanced one image forward (wrapping), or unchanged when empty.
 */
export function next( state: LightboxState ): LightboxState {
	if ( state.count === 0 ) {
		return state;
	}
	return { ...state, index: ( state.index + 1 ) % state.count };
}

/**
 * Steps back to the previous image, wrapping from the first to the last.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The state moved one image back (wrapping), or unchanged when empty.
 */
export function prev( state: LightboxState ): LightboxState {
	if ( state.count === 0 ) {
		return state;
	}
	return { ...state, index: ( state.index - 1 + state.count ) % state.count };
}

/**
 * Jumps to the first image (Home). A clamp to index `0`, never a wrap.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The state positioned at the first image, or unchanged when empty.
 */
export function first( state: LightboxState ): LightboxState {
	if ( state.count === 0 ) {
		return state;
	}
	return { ...state, index: 0 };
}

/**
 * Jumps to the last image (End). A clamp to the final index, never a wrap.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The state positioned at the last image, or unchanged when empty.
 */
export function last( state: LightboxState ): LightboxState {
	if ( state.count === 0 ) {
		return state;
	}
	return { ...state, index: state.count - 1 };
}

/**
 * The indices of the images adjacent to the current one, for neighbour preload.
 *
 * Both neighbours wrap the same way `next`/`prev` do, so the preload covers the
 * cyclic edges; on a single-image gallery both collapse onto the only image. The
 * wiring uses these to warm the browser cache for the images a visitor is most
 * likely to page to next.
 *
 * @since 0.7.0
 *
 * @param state - The current state.
 * @return The previous and next indices, or `null` for each when the gallery is empty.
 */
export function neighbours( state: LightboxState ): {
	readonly prev: number | null;
	readonly next: number | null;
} {
	if ( state.count === 0 ) {
		return { prev: null, next: null };
	}
	return {
		prev: ( state.index - 1 + state.count ) % state.count,
		next: ( state.index + 1 ) % state.count,
	};
}
