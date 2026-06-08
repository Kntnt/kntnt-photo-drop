/**
 * Pure swipe-gesture decision for the Gallery lightbox.
 *
 * On a touch device a horizontal swipe pages the lightbox: swiping left (the
 * content moves left, the finger travels right-to-left, so the delta is
 * negative) advances to the next image, swiping right goes to the previous. This
 * module turns a raw touch delta into that decision with no DOM — the wiring in
 * `lightbox.ts` records `touchstart`/`touchend` coordinates, hands the delta
 * here, and acts on the result.
 *
 * The decision has two guards a naive sign check misses: a minimum horizontal
 * distance (so a stationary tap or a tiny jitter is not a swipe) and a
 * dominance check (the horizontal travel must exceed the vertical, so a mostly-
 * vertical scroll gesture is left to the browser rather than hijacked as a
 * page). Both thresholds are parameters with sensible defaults, kept pure so the
 * boundary cases are unit-testable.
 *
 * @since 0.7.0
 */

/**
 * The horizontal swipe outcome: page to the previous or next image, or do
 * nothing because the gesture was too small or too vertical to count.
 *
 * @since 0.7.0
 */
export type SwipeAction = 'prev' | 'next' | 'none';

/**
 * The default minimum horizontal travel, in pixels, for a swipe to register.
 *
 * Below this a gesture is treated as a tap or jitter, not a page. 30px is small
 * enough to feel responsive yet large enough to ignore an unsteady finger.
 *
 * @since 0.7.0
 */
export const DEFAULT_SWIPE_THRESHOLD = 30;

/**
 * Decides which way a swipe pages the lightbox, or that it does not.
 *
 * A swipe counts only when its horizontal travel meets the threshold *and*
 * dominates the vertical travel — otherwise a short flick or a mostly-vertical
 * scroll is left alone (`'none'`). A leftward swipe (negative `deltaX`, the
 * content dragged toward the next image) yields `'next'`; a rightward swipe
 * yields `'prev'`.
 *
 * @since 0.7.0
 *
 * @param deltaX    - Horizontal travel: end X minus start X, in pixels.
 * @param deltaY    - Vertical travel: end Y minus start Y, in pixels.
 * @param threshold - Minimum horizontal travel to register, in pixels; defaults to {@link DEFAULT_SWIPE_THRESHOLD}.
 * @return The paging action, or `'none'` when the gesture does not qualify.
 */
export function actionForSwipe(
	deltaX: number,
	deltaY: number,
	threshold: number = DEFAULT_SWIPE_THRESHOLD
): SwipeAction {
	const absX = Math.abs( deltaX );
	if ( absX < threshold || absX <= Math.abs( deltaY ) ) {
		return 'none';
	}
	return deltaX < 0 ? 'next' : 'prev';
}
