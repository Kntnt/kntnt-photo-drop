/**
 * Pure advance gate and index wrap for the Gallery slideshow.
 *
 * The slideshow (ADR-0009) is a passive, endlessly looping playback: each slide
 * stands fully visible for the block's configured seconds, then dissolves to
 * the next. Two independent events gate every advance — the visible-time timer
 * firing and the incoming image finishing its load — and the slideshow must
 * never dissolve to an image that has not arrived: a slow image extends the
 * current slide and the transition happens the moment it lands. This module is
 * that decision as pure data — no DOM, no timers — so the invariant is
 * unit-testable; the controller in `slideshow.ts` owns the actual timer and
 * load events and merely feeds them through.
 *
 * @since 0.7.0
 */

/**
 * The two-event gate guarding one slide advance.
 *
 * Both flags start `false` when a slide becomes fully visible and are raised
 * independently by the controller; the advance happens on whichever event
 * completes the pair.
 *
 * @since 0.7.0
 */
export interface AdvanceGate {
	/** Whether the slide's visible-time timer has fired. */
	readonly timerDone: boolean;
	/** Whether the incoming image has finished loading. */
	readonly imageReady: boolean;
}

/**
 * Builds the fresh gate for a slide that just became fully visible.
 *
 * @since 0.7.0
 *
 * @return A gate with neither event seen.
 */
export function createAdvanceGate(): AdvanceGate {
	return { timerDone: false, imageReady: false };
}

/**
 * Records that the slide's visible-time timer has fired.
 *
 * @since 0.7.0
 *
 * @param gate - The current gate.
 * @return The gate with the timer flag raised.
 */
export function timerFired( gate: AdvanceGate ): AdvanceGate {
	return { ...gate, timerDone: true };
}

/**
 * Records that the incoming image has finished loading.
 *
 * @since 0.7.0
 *
 * @param gate - The current gate.
 * @return The gate with the image flag raised.
 */
export function imageLoaded( gate: AdvanceGate ): AdvanceGate {
	return { ...gate, imageReady: true };
}

/**
 * Decides whether the slideshow may dissolve to the next slide.
 *
 * True only when the slide has had its full visible time *and* the next image
 * is ready — the invariant that a transition never reveals a blank or
 * half-loaded image.
 *
 * @since 0.7.0
 *
 * @param gate - The current gate.
 * @return Whether both events have been seen.
 */
export function shouldAdvance( gate: AdvanceGate ): boolean {
	return gate.timerDone && gate.imageReady;
}

/**
 * Steps an index one slide forward, wrapping from the last back to the first.
 *
 * The wrap is the endless loop of ADR-0009. A degenerate count (zero or one)
 * pins the index at `0`, which the controller reads as "nothing to advance to".
 *
 * @since 0.7.0
 *
 * @param index - The current zero-based slide index.
 * @param count - The number of slides.
 * @return The next index, or `0` when the set cannot be stepped.
 */
export function nextIndex( index: number, count: number ): number {
	if ( count < 2 ) {
		return 0;
	}
	return ( Math.trunc( index ) + 1 ) % count;
}
