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
 * navigation the environment could redirect. The icon anchor's own
 * `download` attribute remains the no-JS fallback.
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
 * Saves the image at `url` as a local file, without navigating anywhere.
 *
 * Fetches the image into a Blob and clicks a temporary anchor pointing at a
 * same-document object URL — a pure download no browser or link-rewriting
 * environment turns into a tab. When the fetch fails (network error, a
 * non-OK response, or a cross-origin host without CORS) the fallback is a
 * plain same-tab navigation to the image, so the visitor still reaches it
 * and still no new tab opens.
 *
 * @since 0.5.0
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

		// Click a temporary object-URL anchor to hand the Blob to the browser's
		// download machinery, then revoke the URL once the hand-off has settled.
		const objectUrl = URL.createObjectURL( blob );
		const anchor = document.createElement( 'a' );
		anchor.href = objectUrl;
		anchor.download = filenameFromUrl( url );
		document.body.append( anchor );
		anchor.click();
		anchor.remove();
		setTimeout( () => URL.revokeObjectURL( objectUrl ), REVOKE_DELAY );
	} catch {
		// Last resort: same-tab navigation still shows or saves the image, and
		// no new tab can open.
		window.location.assign( url );
	}
}
