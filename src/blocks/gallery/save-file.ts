/**
 * Programmatic image download — fetch the image into a Blob and save it
 * through a temporary object-URL anchor.
 *
 * A plain `<a download>` is at the mercy of its environment: a theme or
 * plugin that rewrites content links with `target="_blank"`, or a media host
 * on another origin (where browsers ignore the `download` attribute), turns
 * the click into navigation — Firefox then opens the image in a new tab
 * alongside (or instead of) the download. Fetching the image and clicking a
 * same-document object-URL anchor downloads in every browser without any
 * navigation the environment could redirect. The fetched bytes are re-typed to
 * `application/octet-stream` before the hand-off so Firefox cannot render them
 * inline (which, for renderable types such as WebP, would open a new tab even
 * with the `download` attribute set). The icon anchor's own `download`
 * attribute remains the no-JS fallback.
 *
 * When the fetch itself cannot run — a cross-origin uploads host without CORS,
 * an offline network, a non-OK response — there is no blob to hand over, so the
 * save falls back to clicking a *same-document, same-tab* `<a download>` at the
 * remote URL: the documented no-JS fallback. This downloads in place wherever
 * the browser honours `download` (same-origin uploads, the default), and where
 * it cannot (a cross-origin host without CORS, where browsers ignore `download`)
 * the browser decides the outcome — Safari, notably, opens the image in a new
 * tab. The gallery never *forces* a current-tab navigation, and never opens a
 * new tab of its own; what it cannot do is stop a browser that turns a
 * cross-origin `<a download>` into one. The earlier fallback was
 * `window.location.assign`, which always navigated the tab away from the gallery;
 * this one never does.
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
 * Saves the image at `url` as a local file, without navigating anywhere.
 *
 * Fetches the image into a Blob, re-types it to `application/octet-stream`, and
 * clicks a temporary anchor pointing at a same-document object URL — a pure
 * download no browser or link-rewriting environment turns into a tab, the
 * octet-stream type being what stops Firefox rendering it inline. When the fetch
 * fails (network error, a non-OK
 * response, or a cross-origin host without CORS) the fallback clicks a
 * same-document, same-tab `<a download>` at the remote URL — the no-JS fallback —
 * rather than navigating, so the gallery itself never sends its own tab away.
 * Where the browser honours `download` (same-origin, or cross-origin with CORS)
 * the image still saves in place; where it cannot (cross-origin without CORS,
 * where browsers ignore `download`) the browser decides the outcome — Safari, for
 * one, opens the image in a new tab — and the gallery cannot override that.
 *
 * @since 0.5.0
 * @since 0.11.0 Fetch-failure fallback is a same-tab `<a download>`, not navigation (#59).
 * @since 0.15.0 Re-type the blob to application/octet-stream so Firefox cannot open it in a tab.
 *
 * @param url - The image URL to save.
 */
export async function saveFile( url: string ): Promise< void > {
	try {
		// Fetch the image into a Blob; a non-OK response is a failure.
		const response = await fetch( url );
		if ( ! response.ok ) {
			throw new Error(
				`Unexpected response status ${ response.status }.`
			);
		}
		const blob = await response.blob();

		// Re-wrap the bytes with a non-renderable MIME type before handing them
		// to the download machinery. Firefox opens a `blob:` URL it can render
		// inline (PDFs, and images such as WebP) in a new tab even with the
		// `download` attribute set — so the fetched `image/webp` blob would both
		// download AND open a tab. `application/octet-stream` is not renderable,
		// so every browser downloads it; the `download` filename keeps the
		// `.webp` extension, so the saved file is unchanged. (Firefox bug 1766420.)
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
