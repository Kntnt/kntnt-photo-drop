/**
 * The safe-canvas-area cap.
 *
 * iOS Safari silently fails on canvases larger than 16,777,216 pixels
 * (4096 × 4096): the canvas allocates but draws nothing, so the encode would
 * produce a valid-looking blank WebP and the pixels would be irrecoverably
 * lost. The Drop Zone therefore refuses to draw above this area and instead
 * uploads the original bytes — the server re-enforces the contract on every
 * file (ADR-0006), so the raw fallback is always contract-correct.
 *
 * The decision is pure over two dimensions so Jest covers it without a canvas;
 * the view module consults it before allocating the downscale canvas.
 *
 * @since 0.2.0
 */

/**
 * The largest canvas area, in pixels, every supported browser can draw.
 *
 * 16,777,216 (4096 × 4096) is iOS Safari's documented safe ceiling — the most
 * restrictive among current evergreen browsers, and exactly the device a field
 * photographer on a mobile connection is most likely to hold.
 *
 * @since 0.2.0
 */
export const MAX_SAFE_CANVAS_AREA = 16_777_216;

/**
 * Decides whether a target canvas size is too large to draw reliably.
 *
 * Returns true when the area exceeds the iOS Safari safe ceiling, in which
 * case the caller must skip the client-side optimisation and upload the
 * original bytes instead of risking a silently blank canvas.
 *
 * @since 0.2.0
 *
 * @param width  - The target canvas width in pixels.
 * @param height - The target canvas height in pixels.
 * @return True when the canvas would exceed the safe area.
 */
export function exceedsSafeCanvasArea(
	width: number,
	height: number
): boolean {
	return width * height > MAX_SAFE_CANVAS_AREA;
}
