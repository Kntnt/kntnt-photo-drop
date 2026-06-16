/**
 * The Create-form slug on-blur default — the DOM shell over the pure compute.
 *
 * Wires the optional Slug field on the collection-lifecycle admin page's Create form
 * (blocks.md "Create"): on-blur of the Display name it recomputes the unique slug the
 * field would default to and shows it as the Slug field's placeholder, so a blank
 * slug visibly previews what will be stored. All value logic — slugify the name,
 * suffix from `-2` against the existing slugs — lives in the Jest-tested `uniqueSlug`
 * core; this module only reads the rendered existing-slugs list, listens for the
 * blur, and writes the placeholder. It is purely presentational: the server resolves
 * a blank slug to the same unique default and re-verifies uniqueness at submit, so
 * the page works unchanged when this script is absent.
 *
 * @since 0.12.0
 */

import { uniqueSlug } from './slug-default';

/**
 * Parses the JSON existing-slugs list rendered on the Slug input's data attribute.
 *
 * A malformed or non-array value yields an empty list, so a bad attribute degrades
 * to "no collisions known" rather than throwing — the server is the authority on
 * uniqueness regardless.
 *
 * @param raw - The raw `data-kntnt-photo-drop-existing-slugs` attribute value.
 * @return The parsed slug list, or [] when the value is absent or malformed.
 */
function parseExistingSlugs( raw: string | null ): string[] {
	if ( ! raw ) {
		return [];
	}
	try {
		const parsed: unknown = JSON.parse( raw );
		return Array.isArray( parsed )
			? parsed.filter( ( s ): s is string => typeof s === 'string' )
			: [];
	} catch {
		return [];
	}
}

/**
 * Finds the Create-form name/slug fields and wires the on-blur placeholder refresh.
 *
 * No-ops when either field is absent (any other admin view), so it is safe to run on
 * every page the script is enqueued on.
 */
function init(): void {
	// Resolve both fields; without them this is not the Create form, so there is
	// nothing to wire.
	const nameInput = document.querySelector< HTMLInputElement >(
		'[data-kntnt-photo-drop-name-input]'
	);
	const slugInput = document.querySelector< HTMLInputElement >(
		'[data-kntnt-photo-drop-slug-input]'
	);
	if ( ! nameInput || ! slugInput ) {
		return;
	}

	// On-blur of the display name, recompute the unique default from the rendered
	// existing slugs and show it as the slug placeholder; the field's own value is
	// never touched, so a slug the builder typed is left alone.
	const existing = parseExistingSlugs(
		slugInput.getAttribute( 'data-kntnt-photo-drop-existing-slugs' )
	);
	nameInput.addEventListener(
		'blur',
		() => {
			slugInput.placeholder = uniqueSlug( nameInput.value, existing );
		},
		{ passive: true }
	);
}

// Defer until the DOM is parsed so the fields exist; a script in the footer may
// already be past DOMContentLoaded, so run immediately in that case.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init, { once: true } );
} else {
	init();
}
