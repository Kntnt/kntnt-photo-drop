/**
 * Pure target resolution for the slideshow's custom-trigger contract.
 *
 * A page designer starts a gallery's slideshow from any element carrying the
 * documented `data-kntnt-photo-drop-slideshow` attribute (ADR-0009). The value
 * names the target gallery's HTML anchor; a valueless attribute forgivingly
 * targets the page's first slideshow-enabled gallery, so the common one-gallery
 * page needs no anchor at all. This module is that resolution as a pure
 * function over the attribute value and the enabled galleries' ids — no DOM —
 * so the contract's edges (a pasted `#anchor`, whitespace, anchorless
 * galleries, no match) are unit-testable; the wiring in `view.ts` collects the
 * ids in document order and acts on the returned index.
 *
 * @since 0.7.0
 */

/**
 * Resolves a trigger's attribute value to the index of its target gallery.
 *
 * The value is trimmed and a leading `#` (the href habit) is dropped, so a
 * pasted fragment works. An empty result applies the forgiving rule: the first
 * enabled gallery, whatever its id. A non-empty value must match an id
 * exactly — an anchorless gallery (registered as `''`) is only ever reachable
 * through the forgiving rule, never by comparison.
 *
 * @since 0.7.0
 *
 * @param value - The raw attribute value, or `null` when absent.
 * @param ids   - The enabled galleries' anchor ids in document order; `''` for anchorless.
 * @return The zero-based index of the target gallery, or `-1` when none.
 */
export function resolveSlideshowTarget(
	value: string | null,
	ids: readonly string[]
): number {
	// Normalise the value: trim, then drop one leading `#` so a pasted fragment
	// reference reads as the bare anchor.
	const anchor = ( value ?? '' ).trim().replace( /^#/, '' );

	// The forgiving rule: a valueless trigger targets the first enabled gallery
	// on the page, or nothing when the page has none.
	if ( anchor === '' ) {
		return ids.length > 0 ? 0 : -1;
	}

	return ids.indexOf( anchor );
}
