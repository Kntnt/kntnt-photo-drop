/**
 * Focus trap for the open Gallery lightbox.
 *
 * An accessible modal must keep keyboard focus inside itself while open and hand
 * it back to the trigger on close (WAI-ARIA dialog pattern); this is the
 * load-bearing accessibility reason the lightbox is a real dialog and not a CSS
 * `:target` (ADR-0007). This module is the thin DOM wiring for that contract —
 * it cycles Tab/Shift+Tab within a container's focusable elements and reports no
 * pure logic of its own, so per `docs/testing.md` the trap is a human-
 * verification item rather than a unit test.
 *
 * @since 0.7.0
 */

/**
 * The selector matching the elements a Tab cycle should visit inside the
 * lightbox: its buttons and the navigable links. Disabled controls are excluded
 * so a hidden prev/next on a single-image gallery is skipped.
 *
 * @since 0.7.0
 */
const FOCUSABLE_SELECTOR =
	'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Returns the focusable, visible elements inside a container, in DOM order.
 *
 * Visibility is judged by `offsetParent` (null for `display:none`), so a
 * control hidden for the current state — the prev/next buttons on a one-image
 * gallery — is left out of the cycle.
 *
 * @since 0.7.0
 *
 * @param container - The element to search within.
 * @return The focusable elements, in document order.
 */
function focusableWithin( container: HTMLElement ): HTMLElement[] {
	return Array.from(
		container.querySelectorAll< HTMLElement >( FOCUSABLE_SELECTOR )
	).filter( ( element ) => element.offsetParent !== null );
}

/**
 * Installs a Tab focus trap on a container and returns a teardown function.
 *
 * Cycles focus within the container's focusable elements: Tab past the last
 * wraps to the first, Shift+Tab before the first wraps to the last. The listener
 * is registered in capture phase so it runs before any inner handler, and the
 * returned function removes it — the caller releases the trap on close, just
 * before restoring focus to the trigger.
 *
 * @since 0.7.0
 *
 * @param container - The element to trap focus within.
 * @return A function that removes the trap.
 */
export function trapFocus( container: HTMLElement ): () => void {
	const onKeydown = ( event: KeyboardEvent ): void => {
		if ( event.key !== 'Tab' ) {
			return;
		}

		// Resolve the current edge of the focusable cycle each press, so a control
		// becoming enabled/disabled mid-session (prev/next on a single image) is
		// reflected without re-installing the trap.
		const focusable = focusableWithin( container );
		if ( focusable.length === 0 ) {
			event.preventDefault();
			return;
		}
		const first = focusable[ 0 ];
		const lastElement = focusable[ focusable.length - 1 ];
		if ( first === undefined || lastElement === undefined ) {
			return;
		}

		// Wrap at both edges: Shift+Tab off the first lands on the last, Tab off the
		// last lands on the first; in between, the browser's native order stands.
		// Read the active element from the container's own document, not the global.
		const active = container.ownerDocument.activeElement;
		if ( event.shiftKey && active === first ) {
			event.preventDefault();
			lastElement.focus();
		} else if ( ! event.shiftKey && active === lastElement ) {
			event.preventDefault();
			first.focus();
		}
	};

	container.addEventListener( 'keydown', onKeydown, true );
	return (): void =>
		container.removeEventListener( 'keydown', onKeydown, true );
}
