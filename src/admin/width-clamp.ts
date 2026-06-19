/**
 * The pure tier-width clamp — the value core the Create/Edit width wiring rests on.
 *
 * A collection's renditions form a descending tier ladder: the main image is
 * bounded by the upload width, the full image is re-derived from the main, and the
 * thumbnail from the full, each *skipped when the source is no wider* (ADR-0013). A
 * Full wider than the upload limit, or a Thumbnail wider than the Full, is therefore
 * a degenerate ordering the server would collapse — so the Create/Edit forms clamp
 * the lower tiers live to the tier above as the builder types. This module owns that
 * single rule and no DOM: given the three width fields' raw string values and the
 * upload-width mode, it returns the Full and Thumbnail values clamped down to their
 * ceiling. The DOM shell (`width-clamp-dom.ts`) reads the inputs, calls this, and
 * writes the results back; the server re-validates regardless, so this is a
 * convenience that keeps the form self-consistent, not the authority.
 *
 * The clamp is one-directional — it only ever *lowers* a value to its ceiling, never
 * raises one — so a blank field (the empty string) is left untouched: an empty field
 * is valid input (it means "use the default"), so it is never coerced to a number
 * (#71). A non-integer or non-positive value is likewise passed through unchanged,
 * because clamping a value the server will reject anyway would only hide the error.
 *
 * A ceiling below the server's minimum width floor is treated as no ceiling at all:
 * it is not cascaded into lower tiers, so an out-of-range upload or full value never
 * drags the dependent tiers below the minimum. The field that holds the sub-floor
 * value is left for the HTML `min` attribute and the server to reject (@since 0.15.0).
 *
 * @since 0.13.0
 */

/**
 * Minimum accepted width in pixels — mirrors the authoritative PHP constant
 * `Kntnt\Photo_Drop\Cli\Collection_Input::MINIMUM_WIDTH`; the server is the source of
 * truth, this is the client convenience that keeps the clamp consistent with it.
 *
 * @since 0.15.0
 */
const MINIMUM_WIDTH = 320;

/**
 * How the Create form's upload width is expressed: a pixel ceiling or no limit.
 *
 * The upload width is the only nullable tier: the Create form offers an explicit
 * "Original dimensions" radio (`none`) beside the "Limit to N pixels" radio
 * (`limit`). Under `none` there is no upper bound to clamp the Full width against,
 * so the Full tier is unconstrained from above. The Edit form has no editable upload
 * width at all (it is the immutable contract, shown read-only), which the caller
 * models as `none` — there is no live upload ceiling to clamp against there.
 *
 * @since 0.13.0
 */
export type UploadWidthMode = 'limit' | 'none';

/**
 * The three width fields' raw string values plus the upload-width mode.
 *
 * The values are the raw input strings (not numbers) so the empty string — a valid
 * "use the default" entry — round-trips untouched, and so a value the server would
 * reject is passed through rather than silently coerced. `uploadMode` selects
 * whether `upload` is an active ceiling for `full`.
 *
 * @since 0.13.0
 */
export interface WidthFields {
	readonly uploadMode: UploadWidthMode;
	readonly upload: string;
	readonly full: string;
	readonly thumbnail: string;
}

/**
 * The clamped Full and Thumbnail width values.
 *
 * Only the two lower tiers can be clamped; the upload width is the top of the ladder
 * and is never lowered by this rule. Each value is the original string when it was
 * left untouched, or the ceiling's string when it was clamped down.
 *
 * @since 0.13.0
 */
export interface ClampedWidths {
	readonly full: string;
	readonly thumbnail: string;
}

/**
 * Parses a raw width string into a positive integer, or null when it is not one.
 *
 * A blank field, a non-integer, or a non-positive value yields null, so the caller
 * treats it as "no constraint / leave untouched" rather than clamping against or
 * coercing a value the server would reject anyway.
 *
 * @param raw - The raw field value.
 * @return The positive integer, or null when the value is blank or not a positive integer.
 */
function positiveInt( raw: string ): number | null {
	if ( raw.trim() === '' ) {
		return null;
	}
	const value = Number( raw );
	return Number.isInteger( value ) && value > 0 ? value : null;
}

/**
 * Returns the positive-integer parse of `raw` only when it meets the minimum width
 * floor, otherwise null.
 *
 * A sub-floor value is not a usable ceiling: cascading it would drag lower tiers below
 * the server's accepted minimum. The sub-floor field itself is left for the HTML `min`
 * attribute and the server to reject.
 *
 * @since 0.15.0
 *
 * @param raw - The raw field value.
 * @return The positive integer at or above the minimum, or null.
 */
function usableCeiling( raw: string ): number | null {
	const value = positiveInt( raw );
	return value !== null && value >= MINIMUM_WIDTH ? value : null;
}

/**
 * Clamps one tier's value down to its ceiling, leaving everything else untouched.
 *
 * The value is lowered to the ceiling only when both are positive integers and the
 * value exceeds the ceiling; a blank or malformed value, a blank or malformed
 * ceiling, or a value already within the ceiling all pass the original string
 * through. The clamp is one-directional: it never raises a value.
 *
 * @param raw     - The tier's raw value.
 * @param ceiling - The tier-above value to clamp against (null when there is none).
 * @return The value clamped to the ceiling, or the original string when untouched.
 */
function clampTo( raw: string, ceiling: number | null ): string {
	const value = positiveInt( raw );
	if ( value === null || ceiling === null || value <= ceiling ) {
		return raw;
	}
	return String( ceiling );
}

/**
 * Clamps the Full and Thumbnail widths down to the tier above them.
 *
 * Full is clamped to the upload width only when the upload mode is `limit` (an
 * "Original dimensions" upload, or the Edit form's read-only contract, leaves Full
 * unconstrained from above). Thumbnail is then clamped to the *already-clamped* Full,
 * so a single lowering of the upload limit cascades through both lower tiers in one
 * call: lowering the upload limit pulls Full down, and the lowered Full pulls
 * Thumbnail down with it. Blank or malformed fields are passed through untouched, so
 * an empty "use the default" field is never coerced to a number.
 *
 * A ceiling below `MINIMUM_WIDTH` is treated as no ceiling: it is not cascaded into
 * lower tiers, so an out-of-range upload or full value never drags the dependent tiers
 * below the server's accepted minimum (@since 0.15.0).
 *
 * @since 0.13.0
 *
 * @param fields - The three width fields' raw values and the upload-width mode.
 * @return The clamped Full and Thumbnail width strings.
 */
export function clampWidths( fields: WidthFields ): ClampedWidths {
	// Full's ceiling is the upload width, but only under an active pixel limit; an
	// "Original dimensions" upload (or the Edit form's read-only contract) imposes no
	// upper bound on Full. A sub-floor upload value is not a usable ceiling.
	const uploadCeiling =
		fields.uploadMode === 'limit' ? usableCeiling( fields.upload ) : null;
	const full = clampTo( fields.full, uploadCeiling );

	// Thumbnail's ceiling is the already-clamped Full, so a lowered upload limit
	// cascades through Full into Thumbnail in this one call. A sub-floor Full is not a
	// usable ceiling for Thumbnail.
	const thumbnail = clampTo( fields.thumbnail, usableCeiling( full ) );

	return { full, thumbnail };
}
