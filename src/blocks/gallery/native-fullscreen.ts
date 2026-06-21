/**
 * Pure guard for user agents whose element-level Fullscreen API is exposed but
 * broken for the slideshow overlay.
 *
 * The slideshow (ADR-0009) goes fullscreen where the API exists and otherwise
 * falls back to its own fixed, viewport-filling overlay — the iPhone-Safari
 * path. This module recognises a narrower failure: a user agent that *exposes*
 * `Element.requestFullscreen` yet composites the fullscreened overlay as a
 * black top-layer whose `<img>` children never paint, leaving the visitor on a
 * black screen with no image. Such an agent must be treated as "fullscreen
 * unavailable" so the overlay fallback carries the playback exactly as it does
 * on the iPhone.
 *
 * The failure is a silent black paint — no rejection, nothing feature-testable —
 * so the only signal is the user agent. This is the single place the plugin
 * sniffs `navigator.userAgent`, deliberately: a targeted guard for a known
 * supported-but-broken environment, kept pure here so the rule is unit-testable
 * away from the DOM controller in `slideshow.ts`.
 *
 * @since 0.13.3
 */

/**
 * Whether a user agent must skip the native fullscreen request and stay on the
 * overlay fallback.
 *
 * The one known case is Firefox for iOS/iPadOS (`FxiOS`): every iOS browser runs
 * on WebKit, but Firefox's shell black-screens an element `requestFullscreen()`
 * on iPad where Safari and Chrome on the same device do not. Broaden the pattern
 * here if another iOS shell (Edge's `EdgiOS`, etc.) proves to share the fault.
 *
 * @since 0.13.3
 *
 * @param userAgent - `navigator.userAgent`, or an empty string when unavailable.
 * @return Whether native fullscreen must be skipped for this agent.
 */
export function hasBrokenElementFullscreen( userAgent: string ): boolean {
	return /FxiOS/.test( userAgent );
}
