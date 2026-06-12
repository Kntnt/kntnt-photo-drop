/**
 * Page-refetch resync for the Gallery slideshow (ADR-0011).
 *
 * At each cycle boundary the slideshow re-syncs its slide list to the
 * gallery's *current* view, so images uploaded or deleted during a
 * long-running playback (the photo-frame use) appear or disappear on the next
 * cycle. The fresh view comes from the page itself: the provider fetches the
 * page's own URL uncached, parses the response, locates the same gallery
 * wrapper — by HTML anchor id when one is set, by document-order index among
 * gallery wrappers otherwise — and reads its anchors through the same
 * {@link readSlides} contract the initial mount read. No new server surface
 * exists, and each cycle plays exactly what a page reload would show,
 * including the page's cache behaviour.
 *
 * Every transport failure — network, HTTP status, abort timeout — collapses
 * to `null`, which the controller reads as "keep the stale list and retry at
 * the next boundary"; persistent failure degrades to the pre-resync
 * behaviour. A *successfully fetched* page whose gallery is gone or empty is
 * the opposite, deliberate outcome: `Render_Gallery` renders a deleted (or
 * otherwise broken) collection as no wrapper for the public, and an emptied
 * but still-present one as the empty-message wrapper carrying no slide anchors
 * (ADR-0012), so a missing wrapper and a wrapper with no anchors both mean "a
 * reload would show no images" — the empty view that ends the playback, so a
 * takedown propagates within one cycle.
 *
 * @since 0.9.0
 */

import { readSlides, SLIDE_LINK_SELECTOR, type GallerySlide } from './slides';

/**
 * The class every Gallery block wrapper carries, in the live and the fetched
 * document alike — the candidate set the index-based match counts within.
 *
 * @since 0.9.0
 */
const WRAPPER_SELECTOR = '.kntnt-photo-drop-gallery';

/**
 * How long a resync fetch may run before it is aborted, in milliseconds.
 *
 * The fetch starts when the last slide begins its visible time and the wrap
 * waits for it to settle, so this bounds how long the last slide can stand
 * beyond its configured seconds: with the default five-second slides, at most
 * three extra seconds.
 *
 * @since 0.9.0
 */
const RESYNC_TIMEOUT_MS = 8000;

/**
 * A resync provider: resolves to the gallery's fresh slide list, an empty
 * list when the gallery has been emptied, or `null` when no fresh view could
 * be obtained.
 *
 * @since 0.9.0
 */
export type SlideshowResync = () => Promise< readonly GallerySlide[] | null >;

/**
 * What a settled resync means for the playback: the list to continue with and
 * whether the playback must end at the boundary.
 *
 * @since 0.9.0
 */
export interface ResyncOutcome {
	/** The slide list the next cycle plays. */
	readonly slides: readonly GallerySlide[];
	/** Whether the playback ends at the boundary (the view is empty). */
	readonly end: boolean;
}

/**
 * Reads the gallery's fresh slide list out of a fetched page.
 *
 * The wrapper is matched by anchor id when `id` is non-empty — an id that no
 * longer exists is a failure, not a fallback — and by document-order index
 * among gallery wrappers otherwise. A missing wrapper returns `null` (keep
 * the stale list); a wrapper with no anchors returns `[]` (the view is
 * empty).
 *
 * @since 0.9.0
 *
 * @param html  - The fetched page's HTML.
 * @param id    - The live wrapper's HTML anchor id, or `''` when none is set.
 * @param index - The live wrapper's document-order index among gallery wrappers.
 * @return The fresh slides, `[]` for an emptied view, or `null` when the wrapper cannot be found.
 */
export function freshSlides(
	html: string,
	id: string,
	index: number
): GallerySlide[] | null {
	// Locate the same gallery in the fetched document; the id wins when one is
	// set because it is the stable, designer-chosen identity (ADR-0009).
	const fetched = new DOMParser().parseFromString( html, 'text/html' );
	const candidates = Array.from(
		fetched.querySelectorAll< HTMLElement >( WRAPPER_SELECTOR )
	);
	const wrapper =
		id !== ''
			? candidates.find( ( candidate ) => candidate.id === id ) ?? null
			: candidates[ index ] ?? null;
	if ( ! wrapper ) {
		return null;
	}

	// Read the anchors through the same data contract the mount read.
	const links = Array.from(
		wrapper.querySelectorAll< HTMLAnchorElement >( SLIDE_LINK_SELECTOR )
	);
	return readSlides( links );
}

/**
 * Maps a settled resync onto the playback.
 *
 * A failed fetch (`null`) keeps the stale list — the wrap proceeds and the
 * next boundary retries. An emptied view ends the playback, so a takedown
 * propagates within one cycle. A fresh view replaces the list wholesale:
 * additions, deletions, caption and srcset changes, and ordering all follow
 * the server (ADR-0011's full resync).
 *
 * @since 0.9.0
 *
 * @param fresh   - The settled fetch result.
 * @param current - The list the playback has been playing.
 * @return The list to continue with and whether the playback ends.
 */
export function resolveResync(
	fresh: readonly GallerySlide[] | null,
	current: readonly GallerySlide[]
): ResyncOutcome {
	if ( fresh === null ) {
		return { slides: current, end: false };
	}
	if ( fresh.length === 0 ) {
		return { slides: current, end: true };
	}
	return { slides: fresh, end: false };
}

/**
 * Builds the resync provider for one gallery wrapper.
 *
 * Each call fetches the page's current URL with `cache: 'no-store'` (the
 * *browser* cache must not satisfy the resync; an upstream full-page cache is
 * deliberately honoured — the contract is "what a reload would show") and
 * resolves per {@link freshSlides}, except that a gallery missing from a
 * successfully fetched page resolves to the empty view rather than a failure:
 * a deleted collection renders no wrapper and an emptied one renders the
 * empty-message wrapper with no anchors (ADR-0011, ADR-0012), so "gone" and
 * "empty" are the same takedown signal. The wrapper's identity is read per
 * call, not captured, so a designer-set anchor present at call time is always
 * preferred over the positional fallback.
 *
 * @since 0.9.0
 *
 * @param wrapper   - The live gallery wrapper the slideshow plays.
 * @param timeoutMs - The abort timeout; the default suits production.
 * @return The provider the slideshow controller calls at each cycle boundary.
 */
export function createResync(
	wrapper: HTMLElement,
	timeoutMs: number = RESYNC_TIMEOUT_MS
): SlideshowResync {
	return async () => {
		// The wrapper's window owns the URL and the fetch; a detached wrapper
		// has neither and the resync degrades to a failure.
		const view = wrapper.ownerDocument.defaultView;
		if ( ! view ) {
			return null;
		}

		// Fetch the page bounded by the abort timeout; every failure mode
		// collapses to null so the controller has one stale-list path.
		try {
			const response = await view.fetch( view.location.href, {
				cache: 'no-store',
				signal: AbortSignal.timeout( timeoutMs ),
			} );
			if ( ! response.ok ) {
				return null;
			}

			// Resolve the wrapper's identity in the live document and read the
			// matching gallery out of the fetched one. A gallery missing from
			// a page that fetched fine is the empty view, not a failure: the
			// deleted collection renders no wrapper, an emptied one a wrapper with
			// no anchors — both read as zero slides here.
			const index = Array.from(
				wrapper.ownerDocument.querySelectorAll( WRAPPER_SELECTOR )
			).indexOf( wrapper );
			return (
				freshSlides( await response.text(), wrapper.id, index ) ?? []
			);
		} catch {
			return null;
		}
	};
}
