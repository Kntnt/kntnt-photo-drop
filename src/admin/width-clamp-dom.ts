/**
 * The Create/Edit tier-width live clamp — the DOM shell over the pure clamp.
 *
 * Wires the rendition width inputs on the collection-lifecycle admin page's Create
 * and Edit forms so the tier ladder stays self-consistent as the builder types: Full
 * may not exceed the upload limit (when one is set), and Thumbnail may not exceed
 * Full (ADR-0013 — each tier is skipped when the source is no wider). All value logic
 * — what clamps to what, the cascade, leaving blank/malformed fields alone — lives in
 * the Jest-tested `clampWidths` core; this module only reads the inputs, calls it on
 * every change, and writes the two lower tiers back. It is purely presentational: the
 * server re-validates every width at submit (blocks.md "Create"/"Edit"), so the page
 * works unchanged when this script is absent.
 *
 * The Create form carries all three tiers plus the upload-width-mode radios; the Edit
 * form has only the re-derivable Full/Thumbnail fields (the upload width is the
 * immutable contract, shown read-only), so there is no live upload ceiling there and
 * the upload mode resolves to "no limit" — only the Thumbnail-to-Full rule applies.
 *
 * @since 0.13.0
 */

import { clampWidths } from './width-clamp';
import type { UploadWidthMode } from './width-clamp';

/**
 * Resolves the live upload-width mode from the Create form's radios.
 *
 * The "Limit to" radio means there is an active pixel ceiling for Full; the "Original
 * dimensions" radio (and the Edit form, where no such radio is rendered) means there
 * is none. A checked radio whose value is the no-limit token resolves to `none`;
 * everything else — including the absent-radio Edit case — resolves to `limit` only
 * when a "Limit to" radio is actually checked, else `none`.
 *
 * @param root - The form element to search within.
 * @return The resolved upload-width mode.
 */
function readUploadMode( root: ParentNode ): UploadWidthMode {
	const checked = root.querySelector< HTMLInputElement >(
		'input[name="upload_width_mode"]:checked'
	);
	return checked?.value === 'limit' ? 'limit' : 'none';
}

/**
 * Reads a single width input's current value by field name, or null when absent.
 *
 * @param root - The form element to search within.
 * @param name - The input's `name` attribute.
 * @return The input element, or null when the form does not render it.
 */
function findInput( root: ParentNode, name: string ): HTMLInputElement | null {
	return root.querySelector< HTMLInputElement >(
		`input[name="${ name }"][type="number"]`
	);
}

/**
 * Recomputes the clamp from the current field values and writes the lower tiers back.
 *
 * Reads the upload mode and the three width strings, asks the pure core for the
 * clamped Full and Thumbnail, and assigns each back only when it actually changed, so
 * the input's caret and the browser's own validation UI are not disturbed on a no-op.
 *
 * @param root      - The form the fields live in.
 * @param upload    - The upload-width number input, or null on the Edit form.
 * @param full      - The Full-width number input.
 * @param thumbnail - The Thumbnail-width number input.
 */
function applyClamp(
	root: ParentNode,
	upload: HTMLInputElement | null,
	full: HTMLInputElement,
	thumbnail: HTMLInputElement
): void {
	// Ask the pure core for the clamped lower tiers from the current field state, then
	// write each back only when it changed so a no-op never disturbs the caret.
	const clamped = clampWidths( {
		uploadMode: readUploadMode( root ),
		upload: upload?.value ?? '',
		full: full.value,
		thumbnail: thumbnail.value,
	} );
	if ( full.value !== clamped.full ) {
		full.value = clamped.full;
	}
	if ( thumbnail.value !== clamped.thumbnail ) {
		thumbnail.value = clamped.thumbnail;
	}
}

/**
 * Finds the width fields on the current form and wires the live clamp.
 *
 * No-ops when neither lower-tier field is present (any other admin view), so it is
 * safe to run on every page the script is enqueued on. The Full/Thumbnail pair is the
 * minimum the clamp needs; the upload input and the mode radios are optional (the Edit
 * form omits them), in which case Full is left unconstrained from above and only the
 * Thumbnail-to-Full rule applies.
 */
function init(): void {
	// Both lower tiers must be present for the ladder to mean anything; without them
	// this is not a width form, so there is nothing to wire.
	const root = document;
	const full = findInput( root, 'full_width' );
	const thumbnail = findInput( root, 'thumbnail_width' );
	if ( ! full || ! thumbnail ) {
		return;
	}

	// The upload input and its mode radios are the optional top of the ladder (absent
	// on the Edit form); collect them so a change to any tier re-runs the clamp.
	const upload = findInput( root, 'upload_width' );
	const modeRadios = Array.from(
		root.querySelectorAll< HTMLInputElement >(
			'input[name="upload_width_mode"]'
		)
	);

	// Re-clamp on any change to any tier or to the upload mode: typing in a field fires
	// `input`, while flipping a radio fires `change`. Each listener is passive — the
	// clamp never needs to cancel the event, only react to it.
	const recompute = (): void => applyClamp( root, upload, full, thumbnail );
	[ upload, full, thumbnail ].forEach(
		( input ) =>
			input?.addEventListener( 'input', recompute, { passive: true } )
	);
	modeRadios.forEach( ( radio ) =>
		radio.addEventListener( 'change', recompute, { passive: true } )
	);
}

// Defer until the DOM is parsed so the fields exist; a footer script may already be
// past DOMContentLoaded, so run immediately in that case.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init, { once: true } );
} else {
	init();
}
