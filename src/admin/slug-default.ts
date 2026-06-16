/**
 * The pure on-blur unique-slug compute for the Create form (RED stub).
 *
 * Replaced by the real implementation in the GREEN step; for now it returns the
 * empty string so the Jest assertions fail for a real reason rather than a missing
 * export.
 *
 * @since 0.12.0
 */

/**
 * Computes the unique slug a blank Slug field would default to.
 *
 * @param name     - The Display name to slugify.
 * @param existing - The slugs already in use.
 * @return The unique default slug, or '' when the name slugifies to nothing.
 */
export function uniqueSlug( name: string, existing: readonly string[] ): string {
	void name;
	void existing;
	return '';
}
