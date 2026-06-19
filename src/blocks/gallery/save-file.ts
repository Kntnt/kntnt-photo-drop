/**
 * Programmatic image download — save the image without navigating anywhere and
 * without Firefox opening it in a new tab.
 *
 * The common case is a **same-origin** image: the gallery serves its renditions
 * from the site's own uploads directory (ADR-0001). There, a direct
 * same-document `<a download>` is the whole solution — every browser honours the
 * `download` attribute for a same-origin resource and saves the file in the
 * current tab, with no navigation and no new tab. This is the platform feature
 * working as designed; there is no `blob:` URL for Firefox to render inline, so
 * the new-tab problem (Firefox bug 1766420 — it opens a `blob:` URL it can
 * sniff as a renderable image such as WebP in a tab even with `download` set,
 * and re-typing the blob to `application/octet-stream` does not reliably stop
 * it) never arises. The earlier design fetched *every* image into a blob, which
 * is precisely what triggered that Firefox tab; going direct for same-origin
 * removes the cause rather than fighting the symptom.
 *
 * Only a **cross-origin** image (an offloaded-media / CDN host that rewrites the
 * uploads base URL) still needs the blob path: the browser ignores `download` on
 * a direct cross-origin anchor, so the bytes are fetched (CORS permitting) and
 * handed over as a blob, best-effort re-typed to `application/octet-stream`.
 * Firefox may still open a tab for a cross-origin blob it sniffs as an image —
 * that residual is inherent to the cross-origin case, which same-origin
 * deployments never hit. When the fetch itself cannot run — a cross-origin host
 * without CORS, an offline network, a non-OK response — there is no blob, so the
 * save falls back to a *same-document, same-tab* `<a download>` at the remote
 * URL. Where the browser honours `download` that still saves in place; where it
 * cannot (cross-origin without CORS) the browser decides the outcome — Safari,
 * notably, opens the image in a new tab. The gallery never *forces* a current-tab
 * navigation and never opens a new tab of its own; what it cannot do is stop a
 * browser that turns a cross-origin `<a download>` into one.
 *
 * @since 0.5.0
 */

/**
 * How long after the synthetic click the object URL lives, in milliseconds.
 *
 * The click only hands the URL to the browser's download machinery; revoking
 * synchronously can abort the save in some browsers, so the revocation waits
 * long enough for the hand-off to complete.
 *
 * @since 0.5.0
 */
const REVOKE_DELAY = 1000;

/**
 * The saved-file name used when the URL yields none.
 *
 * @since 0.5.0
 */
const FALLBACK_FILENAME = 'image';

/**
 * The mouse-event fields that decide whether a click is a plain primary click.
 *
 * A structural subset of `MouseEvent`, so the predicate can be unit-tested with
 * a plain object and reused across every download trigger and the navigation
 * suppression without coupling to the full DOM event.
 *
 * @since 0.11.0
 */
export interface ClickModifiers {
	/** The pressed button: `0` is the primary (left) button. */
	readonly button: number;
	/** Whether the Meta (Command) key was held. */
	readonly metaKey: boolean;
	/** Whether the Control key was held. */
	readonly ctrlKey: boolean;
	/** Whether the Shift key was held. */
	readonly shiftKey: boolean;
	/** Whether the Alt (Option) key was held. */
	readonly altKey: boolean;
}

/**
 * Whether a click should be intercepted and handled programmatically.
 *
 * True only for a plain primary click — the one the gallery handles itself
 * (a programmatic save, a lightbox open, a slideshow start). Any modifier
 * (Cmd/Ctrl/Shift/Alt) or non-primary button is the visitor asking the browser
 * for its own behaviour — save-as, open in a new tab or window, the context
 * menu — so it passes through untouched, leaving the `<a download>`/`<a href>`
 * semantics as the fallback.
 *
 * @since 0.11.0
 *
 * @param event - The click's modifier and button state.
 * @return True for a plain primary click, false for any modified or non-primary click.
 */
export function shouldInterceptClick( event: ClickModifiers ): boolean {
	return (
		event.button === 0 &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}

/**
 * Derives the saved file's name from a URL's last path segment.
 *
 * Query string and fragment never reach the name (the URL parser strips them
 * from the pathname), percent-encoding is decoded, and a URL with no usable
 * segment — or one that cannot be parsed or decoded at all — falls back to a
 * neutral name rather than throwing mid-download.
 *
 * @since 0.5.0
 *
 * @param url  - The image URL, absolute or relative.
 * @param base - The base for relative URLs; defaults to the document's base.
 * @return The filename to save under.
 */
export function filenameFromUrl( url: string, base?: string ): string {
	try {
		const segments = new URL(
			url,
			base ?? document.baseURI
		).pathname.split( '/' );
		const last =
			segments.filter( ( segment ) => segment !== '' ).pop() ?? '';
		return last === '' ? FALLBACK_FILENAME : decodeURIComponent( last );
	} catch {
		return FALLBACK_FILENAME;
	}
}

/**
 * Clicks a temporary same-document, same-tab `<a download>` and removes it.
 *
 * The shared download primitive: both the blob hand-off and the no-JS fallback
 * drive the browser's download machinery this way. The anchor is appended only
 * for the synthetic click and removed immediately after, so the document is left
 * exactly as it was found. No `target` is set, so the gallery never *forces* a
 * new tab. For a same-origin or CORS-served href the click is a pure download in
 * the current tab; for a cross-origin href without CORS the browser ignores the
 * `download` attribute and decides the outcome itself (Safari, for one, then
 * opens the image in a new tab regardless of `target`) — see `saveFile`.
 *
 * @since 0.11.0
 *
 * @param href         - The href to click (an object URL, or the remote image URL).
 * @param downloadName - The value of the `download` attribute, the saved file name.
 */
function clickDownloadAnchor( href: string, downloadName: string ): void {
	const anchor = document.createElement( 'a' );
	anchor.href = href;
	anchor.download = downloadName;
	document.body.append( anchor );
	anchor.click();
	anchor.remove();
}

/**
 * Whether `url` resolves to the same origin as the current document.
 *
 * Relative URLs resolve against the document base, so they are same-origin by
 * construction. A URL that cannot be parsed is treated as cross-origin: the
 * blob path it then takes ends in the same-tab `<a download>` fallback, which is
 * the safe outcome for an unparseable input.
 *
 * @since 0.11.0
 *
 * @param url - The image URL, absolute or relative.
 * @return True when the resolved origin matches the document's.
 */
function isSameOrigin( url: string ): boolean {
	try {
		return (
			new URL( url, document.baseURI ).origin === window.location.origin
		);
	} catch {
		return false;
	}
}

/**
 * Saves the image at `url` as a local file, without navigating anywhere.
 *
 * Same-origin images — the default, served from the site's own uploads dir —
 * download through a direct same-document `<a download>`: every browser honours
 * the attribute and saves in the current tab, and because there is no `blob:`
 * URL there is nothing for Firefox to render inline in a new tab. Only a
 * cross-origin image takes the blob path: the bytes are fetched (CORS
 * permitting), best-effort re-typed to `application/octet-stream`, and handed
 * over via an object-URL anchor; if the fetch cannot run (no CORS, offline, a
 * non-OK response) the fallback clicks a same-document, same-tab `<a download>`
 * at the remote URL rather than navigating. The gallery never forces a current-
 * tab navigation and never opens a new tab of its own. See the file docblock for
 * the full rationale (Firefox bug 1766420).
 *
 * @since 0.5.0
 * @since 0.11.0 Fetch-failure fallback is a same-tab `<a download>`, not navigation (#59).
 * @since 0.11.0 Same-origin images download via a direct `<a download>`, skipping the blob path that made Firefox open a tab.
 *
 * @param url - The image URL to save.
 */
export async function saveFile( url: string ): Promise< void > {
	// Same-origin: a direct same-document `<a download>` saves in place in every
	// browser, with no blob for Firefox to render inline in a new tab.
	if ( isSameOrigin( url ) ) {
		clickDownloadAnchor( url, filenameFromUrl( url ) );
		return;
	}

	try {
		// Fetch the cross-origin image into a Blob; a non-OK response is a failure.
		const response = await fetch( url );
		if ( ! response.ok ) {
			throw new Error(
				`Unexpected response status ${ response.status }.`
			);
		}
		const blob = await response.blob();

		// Re-wrap the bytes with a non-renderable MIME type before handing them
		// to the download machinery — a best effort to discourage inline
		// rendering. (Firefox may still open a tab for a cross-origin blob it
		// sniffs as an image; that residual is inherent to the cross-origin case,
		// which the same-origin path above never reaches. Firefox bug 1766420.)
		const downloadBlob = new Blob( [ blob ], {
			type: 'application/octet-stream',
		} );

		// Click a temporary object-URL anchor to hand the Blob to the browser's
		// download machinery, then revoke the URL once the hand-off has settled.
		const objectUrl = URL.createObjectURL( downloadBlob );
		clickDownloadAnchor( objectUrl, filenameFromUrl( url ) );
		setTimeout( () => URL.revokeObjectURL( objectUrl ), REVOKE_DELAY );
	} catch {
		// Last resort: a same-tab <a download> at the remote URL still requests a
		// save without the gallery navigating its own tab. Where the browser
		// honours `download` this saves in place; for a cross-origin host without
		// CORS the browser decides (Safari may open a new tab), which the gallery
		// cannot override.
		clickDownloadAnchor( url, filenameFromUrl( url ) );
	}
}
