/**
 * The Create/Edit tier-width clamp — the DOM shell over the pure clamp.
 *
 * Wires the rendition width inputs on the collection-lifecycle admin page's Create
 * and Edit forms so the tier ladder stays self-consistent when the builder commits a
 * value: Full may not exceed the upload limit (when one is set), and Thumbnail may not
 * exceed Full (ADR-0013 — each tier is skipped when the source is no wider). All value
 * logic — what clamps to what, the cascade, leaving blank/malformed fields alone —
 * lives in the Jest-tested `clampWidths` core; this module only reads the inputs,
 * calls it when a field is committed (on `change`), and writes the two lower tiers
 * back. It is purely presentational: the server re-validates every width at submit
 * (blocks.md "Create"/"Edit"), so the page works unchanged when this script is absent.
 *
 * The Create form carries all three tiers; the upload width is a single blank-able
 * field (blank = the source's own dimensions, so no ceiling — #70 retired the
 * upload-width-mode radio). The Edit form has only the re-derivable Full/Thumbnail
 * fields (the upload width is the immutable contract, shown read-only), so there is no
 * live upload ceiling there and the upload mode resolves to "no limit" — only the
 * Thumbnail-to-Full rule applies.
 *
 * @since 0.15.0
 */

import { clampWidths } from './width-clamp';
import type { UploadWidthMode } from './width-clamp';

/**
 * Resolves the live upload-width mode from the single blank-able upload-width field.
 *
 * A non-blank upload width is an active pixel ceiling for Full (`limit`); a blank
 * upload width means the source's own dimensions, so there is no ceiling (`none`). The
 * Edit form renders the upload width read-only (the immutable contract) and passes no
 * editable field here, which also resolves to `none` — only the Thumbnail-to-Full rule
 * applies there.
 *
 * @param upload - The upload-width input, or null when the form omits it.
 * @return The resolved upload-width mode.
 */
function readUploadMode( upload: HTMLInputElement | null ): UploadWidthMode {
	return upload && upload.value.trim() !== '' ? 'limit' : 'none';
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
 * @param upload    - The upload-width number input, or null on the Edit form.
 * @param full      - The Full-width number input.
 * @param thumbnail - The Thumbnail-width number input.
 */
function applyClamp(
	upload: HTMLInputElement | null,
	full: HTMLInputElement,
	thumbnail: HTMLInputElement
): void {
	// Ask the pure core for the clamped lower tiers from the current field state, then
	// write each back only when it changed so a no-op never disturbs the caret.
	const clamped = clampWidths( {
		uploadMode: readUploadMode( upload ),
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
 * minimum the clamp needs; the upload input is optional (the Edit form omits it), in
 * which case Full is left unconstrained from above and only the Thumbnail-to-Full rule
 * applies.
 */
export function init(): void {
	// Both lower tiers must be present for the ladder to mean anything; without them
	// this is not a width form, so there is nothing to wire.
	const root = document;
	const full = findInput( root, 'full_width' );
	const thumbnail = findInput( root, 'thumbnail_width' );
	if ( ! full || ! thumbnail ) {
		return;
	}

	// The upload input is the optional top of the ladder (absent on the Edit form); a
	// blank value means no ceiling, so a change to it re-runs the clamp too.
	const upload = findInput( root, 'upload_width' );

	// Re-clamp when any tier's value is committed: `change` fires on blur or Enter,
	// after the final value is set, so a digit-by-digit sequence never strands the
	// lower tiers at an intermediate value. Each listener is passive — the clamp
	// never needs to cancel the event, only react to it.
	const recompute = (): void => applyClamp( upload, full, thumbnail );
	[ upload, full, thumbnail ].forEach(
		( input ) =>
			input?.addEventListener( 'change', recompute, { passive: true } )
	);
}

// Defer until the DOM is parsed so the fields exist; a footer script may already be
// past DOMContentLoaded, so run immediately in that case.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init, { once: true } );
} else {
	init();
}
