/**
 * The pure reader that turns the regenerate fields into a `RegenerateTarget`.
 *
 * The admin Edit page renders four number inputs — full width/quality and thumbnail
 * width/quality — that the regenerate run sends to the manage-gated REST endpoint.
 * This module is the pure DOM-to-value reader behind that: it reads the four inputs
 * off a host element and validates them, with no `fetch` and no event wiring, so the
 * empty-means-unset rule (ADR-0013/#71) is Jest-testable in jsdom alone while
 * `regenerate.ts` stays a thin shell.
 *
 * The three re-derivable fields may be left **empty** to leave them unset — the
 * collapse-to-parent state — which reads as `null` (and is sent over the wire as JSON
 * null, distinct from a concrete `0`). A *present* field must be a valid integer; a
 * non-integer makes the whole target malformed so the caller can refuse the run, the
 * same bounds the REST endpoint re-enforces. The thumbnail quality is always concrete:
 * an empty or out-of-range value is malformed, never unset.
 *
 * @since 0.15.0
 */

import type { RegenerateTarget } from './regenerate-run';

/**
 * The three-state outcome of reading one optional re-derivable field.
 *
 * `unset` is a cleared field (collapse-to-parent), `malformed` is a present-but-invalid
 * value the caller must reject, and a numeric value is a concrete entry.
 *
 * @since 0.15.0
 */
type OptionalField = number | 'unset' | 'malformed';

/**
 * Reads one number input's raw value by field name off a host element.
 *
 * @param root - The host element to search within.
 * @param name - The input's `name` attribute.
 * @return The raw string value, or null when the input is absent.
 */
function rawValue( root: HTMLElement, name: string ): string | null {
	const input = root.querySelector< HTMLInputElement >(
		`[name="${ name }"]`
	);
	return input ? input.value : null;
}

/**
 * Reads one re-derivable field three-state: concrete, unset, or malformed.
 *
 * A cleared field (empty string, or an absent input) is `unset` — collapse to the tier
 * above. A present value must parse to an integer; anything else is `malformed`. Bounds
 * beyond integer-ness (a width must be positive, a quality 0–100) are applied by the
 * caller against the predicate it passes.
 *
 * @param root  - The regenerate host element.
 * @param name  - The input's `name` attribute.
 * @param valid - The bound predicate a present integer must satisfy.
 * @return The concrete value, `'unset'`, or `'malformed'`.
 */
function readOptional(
	root: HTMLElement,
	name: string,
	valid: ( value: number ) => boolean
): OptionalField {
	const raw = rawValue( root, name );
	if ( raw === null || raw.trim() === '' ) {
		return 'unset';
	}
	const value = Number( raw );
	return Number.isInteger( value ) && valid( value ) ? value : 'malformed';
}

/**
 * Reads one always-concrete field: a valid integer or malformed (never unset).
 *
 * @param root  - The regenerate host element.
 * @param name  - The input's `name` attribute.
 * @param valid - The bound predicate the integer must satisfy.
 * @return The concrete value, or `'malformed'` when empty, absent, or invalid.
 */
function readConcrete(
	root: HTMLElement,
	name: string,
	valid: ( value: number ) => boolean
): number | 'malformed' {
	const raw = rawValue( root, name );
	if ( raw === null || raw.trim() === '' ) {
		return 'malformed';
	}
	const value = Number( raw );
	return Number.isInteger( value ) && valid( value ) ? value : 'malformed';
}

/**
 * Reads the four regenerate fields into a target, or null when any is malformed.
 *
 * The three re-derivable fields each read three-state (concrete / unset / malformed);
 * an empty one becomes `null` (unset — sent as JSON null) and a present-but-invalid one
 * makes the whole target malformed. The thumbnail quality is always concrete. A null
 * return tells the caller to refuse the run and prompt the operator, the same bounds the
 * REST endpoint re-enforces, so the client never starts a run the server would reject.
 *
 * @since 0.15.0
 *
 * @param root - The regenerate host element holding the four inputs.
 * @return The validated target (re-derivable trio possibly null), or null when malformed.
 */
export function readTarget( root: HTMLElement ): RegenerateTarget | null {
	const isWidth = ( value: number ): boolean => value > 0;
	const isQuality = ( value: number ): boolean => value >= 0 && value <= 100;

	// Read the three re-derivable fields three-state and the always-concrete thumbnail
	// quality; a single malformed field aborts the whole target.
	const fullWidth = readOptional( root, 'full_width', isWidth );
	const fullQuality = readOptional( root, 'full_quality', isQuality );
	const thumbnailWidth = readOptional( root, 'thumbnail_width', isWidth );
	const thumbnailQuality = readConcrete(
		root,
		'thumbnail_quality',
		isQuality
	);
	if (
		fullWidth === 'malformed' ||
		fullQuality === 'malformed' ||
		thumbnailWidth === 'malformed' ||
		thumbnailQuality === 'malformed'
	) {
		return null;
	}

	// Collapse the unset markers to JSON null; the dispatcher serialises null verbatim,
	// which the REST endpoint reads as the collapse-to-parent state.
	return {
		fullWidth: fullWidth === 'unset' ? null : fullWidth,
		fullQuality: fullQuality === 'unset' ? null : fullQuality,
		thumbnailWidth: thumbnailWidth === 'unset' ? null : thumbnailWidth,
		thumbnailQuality,
	};
}
