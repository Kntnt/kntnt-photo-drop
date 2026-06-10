/**
 * The pure live upload-progress label formatter.
 *
 * Kept in its own DOM-free module so the per-file `onprogress` handler in
 * `view.ts` (which cannot be imported under Jest, as it pulls in the
 * Interactivity runtime) can use it while it is still unit-testable in isolation
 * (issue #31). View-script modules cannot translate at runtime, so the `%d%%`
 * template is translated server-side and handed across the Interactivity context.
 *
 * @since 0.4.0
 */

/**
 * Fills the percent template with the rounded upload percentage.
 *
 * @since 0.4.0
 *
 * The template is authored as a PHP `sprintf` format (`Uploading… %d%%`), so the
 * literal percent sign is escaped as `%%`; this fills the `%d` token with the
 * rounded percentage and unescapes `%%` to a single `%`, matching what the author
 * sees on the rendered page.
 *
 * @param template - The translated `%d%%` percent template from the context.
 * @param loaded   - Bytes uploaded so far.
 * @param total    - Total bytes to upload; a non-positive total reports 0%.
 * @return The progress label, e.g. `"Uploading… 42%"`.
 */
export function formatUploadingLabel(
	template: string,
	loaded: number,
	total: number
): string {
	const percent = total > 0 ? Math.round( ( loaded / total ) * 100 ) : 0;
	return template.replace( '%d', String( percent ) ).replace( '%%', '%' );
}
