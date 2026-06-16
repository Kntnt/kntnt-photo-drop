/**
 * The pure on-blur unique-slug compute for the Create form.
 *
 * The Create form's Slug field is optional: its placeholder previews the unique slug
 * a blank field would default to, refreshed on-blur of the Display name. This module
 * is the pure value core that preview rests on — it owns no DOM. It slugifies the
 * name (an ASCII-faithful subset of `sanitize_title`: lowercase, runs of
 * whitespace/underscores to one hyphen, drop everything but `[a-z0-9-]`, collapse
 * repeated hyphens, trim edge hyphens) and makes it unique against the rendered
 * existing-slugs list by appending the lowest free numeric suffix from `-2` (never
 * `-1`). The server re-verifies uniqueness at submit, so this is a best-effort
 * preview that matches the server for the ASCII display names that dominate; a name
 * that slugifies to nothing yields `''`, leaving the placeholder empty so the
 * builder must type a slug.
 *
 * @since 0.12.0
 */

/**
 * Slugifies a display name into the ASCII subset of `sanitize_title`.
 *
 * @param name - The Display name to slugify.
 * @return The slug, or '' when the name has no sluggable characters.
 */
function slugify( name: string ): string {
	return name
		.toLowerCase()
		.replace( /[\s_]+/g, '-' )
		.replace( /[^a-z0-9-]+/g, '' )
		.replace( /-+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/**
 * Computes the unique slug a blank Slug field would default to.
 *
 * @param name     - The Display name to slugify.
 * @param existing - The slugs already in use.
 * @return The unique default slug, or '' when the name slugifies to nothing.
 */
export function uniqueSlug(
	name: string,
	existing: readonly string[]
): string {
	// A name with no sluggable characters has no base to default from; the caller
	// shows an empty placeholder and the server rejects a blank-with-no-base submit.
	const base = slugify( name );
	if ( base === '' ) {
		return '';
	}

	// The bare base wins when free; otherwise append the lowest free numeric suffix
	// from -2 upward, skipping every taken candidate in turn.
	const taken = new Set( existing );
	if ( ! taken.has( base ) ) {
		return base;
	}
	let suffix = 2;
	while ( taken.has( `${ base }-${ suffix }` ) ) {
		suffix += 1;
	}
	return `${ base }-${ suffix }`;
}
