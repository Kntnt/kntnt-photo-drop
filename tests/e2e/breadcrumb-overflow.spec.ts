/**
 * Gallery breadcrumb overflow end-to-end spec — leading ellipsis keeps the tail.
 *
 * The breadcrumb overflow is pure CSS (ADR-0015): an overflowing single-line
 * crumb must show a **leading** "…" and keep its **tail** (the deepest segment),
 * with the visible text sitting inside the box's padding on both sides. The
 * earlier rule defeated its own `direction: rtl` with an adjacent
 * `unicode-bidi: plaintext`, so the line laid out LTR and clipped the tail on
 * the right with no leading ellipsis and no perceptible right gutter (issue #58).
 *
 * Only a real layout engine can observe this — jsdom implements neither
 * `text-overflow` nor `direction`-driven clipping — so the check runs in
 * Chromium against a published gallery, exactly as the per-image shadow spec
 * does. It measures the rendered geometry of the crumb's first and last glyphs
 * with a `Range`: a leading ellipsis means the **head** glyph is clipped fully
 * outside the content box on one side while the **tail** glyph stays inside the
 * content box on the other, with a perceptible gutter to the border-box edge. A
 * short, non-overflowing crumb keeps both glyphs inside the box untouched.
 *
 * The cases: an overflowing crumb on a default LTR site (across all nine
 * anchor positions, so the right-half anchors whose text-align and box edge
 * both flip under RTLCSS are exercised, not only the default bottom-left), a
 * non-overflowing crumb on the same, and an overflowing crumb on an RTL-locale
 * site. The crumb's clip direction is keyed to the (left-to-right) path content
 * the test uses, not the site chrome: the fix routes `direction: rtl` through a
 * custom property so the build's RTLCSS pass cannot mirror it, leaving the crumb
 * `direction: rtl` in *both* the LTR and the RTLCSS stylesheets. The RTL case
 * therefore renders identically to the LTR one (head clipped on the left, tail
 * kept inside the right padding) and proves the RTLCSS mirror did not flip the
 * crumb back to LTR.
 *
 * @since 0.11.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	RTL_LOCALE,
	createCollection,
	deleteCollection,
	importFixture,
	setSiteLanguage,
	uniqueSlug,
} from './support/wp';

/**
 * The rendered geometry of a breadcrumb's text relative to its own box.
 *
 * All values are in CSS pixels in the breadcrumb's own coordinate space (the
 * border-box left at 0). The content edges are the padding-inset bounds the
 * text is laid into; the glyph edges are the unclipped positions of the first
 * and last characters, which a `Range` reports regardless of `overflow: hidden`.
 */
interface BreadcrumbGeometry {
	readonly borderBoxWidth: number;
	readonly contentLeft: number;
	readonly contentRight: number;
	readonly firstGlyphLeft: number;
	readonly firstGlyphRight: number;
	readonly lastGlyphLeft: number;
	readonly lastGlyphRight: number;
	readonly overflows: boolean;
}

/**
 * Measures one breadcrumb figcaption's text geometry inside the browser.
 *
 * Runs in the page so it can use `Range.getBoundingClientRect()` on the crumb's
 * text node — the only way to see where the first and last glyphs actually
 * landed once the line overflows and is clipped.
 *
 * @param page     - The Playwright page the gallery is rendered in.
 * @param selector - The breadcrumb figcaption selector.
 * @return The measured geometry, or `null` when the element or its text is absent.
 */
async function measureBreadcrumb(
	page: import('@playwright/test').Page,
	selector: string
): Promise< BreadcrumbGeometry | null > {
	return page.evaluate( ( sel ) => {
		const el = document.querySelector< HTMLElement >( sel );
		// The crumb text lives in a bidi-isolating <bdi> inside the figcaption
		// (so a number-heavy path is not reordered), so descend into it for the
		// text node, falling back to a bare text child if the wrapper is absent.
		const host = el?.querySelector< HTMLElement >( 'bdi' ) ?? el;
		const textNode = host?.firstChild;
		if ( ! el || ! textNode || textNode.nodeType !== Node.TEXT_NODE ) {
			return null;
		}

		// The box's border-box and the padding-inset content bounds, in the
		// element's own left-origin coordinate space.
		const box = el.getBoundingClientRect();
		const style = getComputedStyle( el );
		const padLeft = parseFloat( style.paddingLeft );
		const padRight = parseFloat( style.paddingRight );
		const contentLeft = padLeft;
		const contentRight = box.width - padRight;

		// The whole text run's width and the first/last glyph extents, via a
		// Range — these report true geometry even where overflow is clipped. The
		// first glyph is the logical head (the crumb's start), the last glyph the
		// logical tail (the deepest segment / image name), each measured in the
		// element's own left-origin coordinate space.
		const full = document.createRange();
		full.selectNodeContents( textNode );
		const fullWidth = full.getBoundingClientRect().width;
		const value = textNode.nodeValue ?? '';
		const first = document.createRange();
		first.setStart( textNode, 0 );
		first.setEnd( textNode, Math.min( 1, value.length ) );
		const firstRect = first.getBoundingClientRect();
		const last = document.createRange();
		last.setStart( textNode, Math.max( 0, value.length - 1 ) );
		last.setEnd( textNode, value.length );
		const lastRect = last.getBoundingClientRect();

		return {
			borderBoxWidth: box.width,
			contentLeft,
			contentRight,
			firstGlyphLeft: firstRect.left - box.left,
			firstGlyphRight: firstRect.right - box.left,
			lastGlyphLeft: lastRect.left - box.left,
			lastGlyphRight: lastRect.right - box.left,
			overflows: fullWidth > contentRight - contentLeft + 0.5,
		};
	}, selector );
}

/**
 * Asserts the leading-ellipsis contract on a measured, overflowing breadcrumb.
 *
 * The fix keeps the crumb `direction: rtl` in both stylesheets, so on both an
 * LTR and an RTL site an LTR path clips the head on the visual *left* and
 * keeps the tail on the *right* inside the right padding — the RTL case renders
 * identically to the LTR one, which is the whole point of routing the direction
 * through a custom property RTLCSS cannot mirror. The check is nonetheless kept
 * direction-agnostic as defense: were RTLCSS ever to flip the crumb back to
 * `ltr`, the head would clip on the right and the tail on the left, and the
 * assertion would still hold rather than crash. The invariant in either layout:
 * the **head** (first glyph) is clipped fully outside the content box on one
 * side, while the **tail** (last glyph) sits inside the content box with a
 * perceptible gutter between it and the border-box edge on its side — never
 * running flush to the image edge.
 *
 * @param geo - The measured breadcrumb geometry.
 */
function expectLeadingEllipsisKeepsTail( geo: BreadcrumbGeometry ): void {
	// The head is clipped on one side: its whole glyph rect lies beyond a content
	// edge (left of contentLeft on an LTR crumb, right of contentRight on an RTL
	// one), so the visible start is the ellipsis, not the first crumb.
	const headClippedLeft = geo.firstGlyphRight <= geo.contentLeft + 0.5;
	const headClippedRight = geo.firstGlyphLeft >= geo.contentRight - 0.5;
	expect( headClippedLeft || headClippedRight ).toBe( true );

	// The tail is kept inside the content box on the opposite side, with a real
	// gutter to the border-box edge — the right padding (LTR) or left padding (RTL)
	// is perceptible rather than the text running to the edge.
	if ( headClippedLeft ) {
		expect( geo.lastGlyphRight ).toBeLessThanOrEqual(
			geo.contentRight + 0.5
		);
		expect( geo.borderBoxWidth - geo.lastGlyphRight ).toBeGreaterThan( 1 );
	} else {
		expect( geo.lastGlyphLeft ).toBeGreaterThanOrEqual(
			geo.contentLeft - 0.5
		);
		expect( geo.lastGlyphLeft ).toBeGreaterThan( 1 );
	}
}

// A collection whose long display name (the breadcrumb's first crumb) guarantees
// the single crumb overflows any thumbnail tile, and a second short-named
// collection whose crumb comfortably fits.
const longSlug = uniqueSlug( 'crumb-long' );
const shortSlug = uniqueSlug( 'crumb-short' );
const longName =
	'A Deliberately Very Long Collection Display Name That Overflows Any Thumbnail Breadcrumb Box By A Wide Margin';
let longPageId = 0;
let shortPageId = 0;

test.describe( 'Gallery breadcrumb overflow', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed the overflowing collection: a one-image collection with the long
		// display name, behind a published gallery whose breadcrumb shows on the
		// thumbnail at the default bottom-left anchor.
		createCollection( longSlug, '', longName );
		importFixture( longSlug, FIXTURE_ALPHA );
		const longPage = await requestUtils.createPage( {
			title: `E2E Crumb Long ${ longSlug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ longSlug }","breadcrumbsVisibility":"thumbnail",` +
				`"breadcrumbsPosition":"bottom-left"} /-->`,
			status: 'publish',
		} );
		longPageId = longPage.id;

		// Seed the fitting collection: a short display name so its single crumb
		// never overflows, behind its own published gallery.
		createCollection( shortSlug, '', 'Set' );
		importFixture( shortSlug, FIXTURE_ALPHA );
		const shortPage = await requestUtils.createPage( {
			title: `E2E Crumb Short ${ shortSlug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ shortSlug }","breadcrumbsVisibility":"thumbnail",` +
				`"breadcrumbsPosition":"bottom-left"} /-->`,
			status: 'publish',
		} );
		shortPageId = shortPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove both pages and both collections so reruns start clean.
		for ( const id of [ longPageId, shortPageId ] ) {
			if ( id !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ id }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( longSlug );
		deleteCollection( shortSlug );
	} );

	test( 'an overflowing breadcrumb clips the head and keeps the tail inside the right padding', async ( {
		page,
	} ) => {
		// Visit the gallery exactly as the public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ longPageId }` );
		const crumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( crumb ).toBeVisible();

		// The crumb genuinely overflows its box — otherwise the assertions below
		// would pass vacuously on a crumb that happens to fit.
		const geo = await measureBreadcrumb(
			page,
			'.kntnt-photo-drop-gallery__breadcrumbs'
		);
		expect( geo ).not.toBeNull();
		expect( geo!.overflows ).toBe( true );

		// On this LTR site the crumb keeps its tail and clips the head with a
		// leading ellipsis, the tail inside the right padding.
		expectLeadingEllipsisKeepsTail( geo! );
	} );

	test( 'a non-overflowing breadcrumb is left untouched inside both paddings', async ( {
		page,
	} ) => {
		// Visit the short-named gallery; its single crumb fits the box.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ shortPageId }` );
		const crumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( crumb ).toBeVisible();

		// The crumb fits, and both its first and last glyphs sit inside the
		// padding-inset content box — nothing is clipped on either side.
		const geo = await measureBreadcrumb(
			page,
			'.kntnt-photo-drop-gallery__breadcrumbs'
		);
		expect( geo ).not.toBeNull();
		expect( geo!.overflows ).toBe( false );
		expect( geo!.firstGlyphLeft ).toBeGreaterThanOrEqual(
			geo!.contentLeft - 0.5
		);
		expect( geo!.lastGlyphRight ).toBeLessThanOrEqual(
			geo!.contentRight + 0.5
		);
	} );
} );

// The nine anchor positions the breadcrumb can take. The default bottom-left is
// covered above; this list drives one gallery per position so the right-half
// anchors — whose `text-align` and anchored box edge both flip under RTLCSS — and
// the centre and top/middle anchors are exercised too, closing the gap against
// issue #58's "the other nine-point positions" acceptance criterion.
const ALL_POSITIONS = [
	'top-left',
	'top-center',
	'top-right',
	'middle-left',
	'middle-center',
	'middle-right',
	'bottom-left',
	'bottom-center',
	'bottom-right',
] as const;

// One overflowing gallery per anchor position, sharing the long-named collection
// seeded for this block. The slug is reused for the whole set (one collection,
// nine galleries pointing at it), and each page id is keyed by its position so a
// failing anchor names itself.
const positionsSlug = uniqueSlug( 'crumb-positions' );
const positionPageIds: Record< string, number > = {};

test.describe( 'Gallery breadcrumb overflow at every anchor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// One long-named, one-image collection feeds every position's gallery, so
		// the only variable across the nine pages is `breadcrumbsPosition`.
		createCollection( positionsSlug, '', longName );
		importFixture( positionsSlug, FIXTURE_ALPHA );

		// One published gallery per anchor position, each overflowing because it
		// reuses the long display name as its sole crumb.
		for ( const position of ALL_POSITIONS ) {
			const positionPage = await requestUtils.createPage( {
				title: `E2E Crumb ${ position } ${ positionsSlug }`,
				content:
					`<!-- wp:kntnt-photo-drop/gallery ` +
					`{"collection":"${ positionsSlug }","breadcrumbsVisibility":"thumbnail",` +
					`"breadcrumbsPosition":"${ position }"} /-->`,
				status: 'publish',
			} );
			positionPageIds[ position ] = positionPage.id;
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove every position's page, then the shared collection, so reruns start
		// clean.
		for ( const id of Object.values( positionPageIds ) ) {
			if ( id !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ id }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( positionsSlug );
	} );

	for ( const position of ALL_POSITIONS ) {
		test( `an overflowing breadcrumb keeps its tail at the ${ position } anchor`, async ( {
			page,
		} ) => {
			// Visit this position's gallery as the public sees it.
			await page.context().clearCookies();
			await page.goto( `/?page_id=${ positionPageIds[ position ] }` );
			const crumb = page
				.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
				.first();
			await expect( crumb ).toBeVisible();

			// The crumb overflows, and the leading-ellipsis-keeps-tail contract
			// holds regardless of which anchor (and therefore which `text-align` and
			// box edge) the position applies — the head is clipped on one side, the
			// tail kept inside the padding on the other.
			const geo = await measureBreadcrumb(
				page,
				'.kntnt-photo-drop-gallery__breadcrumbs'
			);
			expect( geo ).not.toBeNull();
			expect( geo!.overflows ).toBe( true );
			expectLeadingEllipsisKeepsTail( geo! );
		} );
	}
} );

// The RTL counterpart of the overflow case. The same long-named collection is
// reused, but the whole site is switched to an RTL locale (Hebrew) so WordPress
// loads the RTLCSS-mirrored stylesheet. The crumb's `direction: rtl` is routed
// through a custom property RTLCSS cannot mirror, so it survives as `rtl` in that
// stylesheet too — the leading-ellipsis-keeps-tail contract must therefore hold
// identically to the LTR case (it is not mirror-imaged), proving the mirror did
// not flip the crumb back to LTR.
const rtlSlug = uniqueSlug( 'crumb-rtl' );
let rtlPageId = 0;

test.describe( 'Gallery breadcrumb overflow (RTL locale)', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Switch the site to the RTL locale (its pack installed once in
		// globalSetup), then seed the overflowing collection and gallery under
		// that direction.
		setSiteLanguage( RTL_LOCALE );
		createCollection( rtlSlug, '', longName );
		importFixture( rtlSlug, FIXTURE_ALPHA );
		const rtlPage = await requestUtils.createPage( {
			title: `E2E Crumb RTL ${ rtlSlug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ rtlSlug }","breadcrumbsVisibility":"thumbnail",` +
				`"breadcrumbsPosition":"bottom-left"} /-->`,
			status: 'publish',
		} );
		rtlPageId = rtlPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Restore the default LTR locale so the next spec is unaffected, then tear
		// the page and collection down.
		if ( rtlPageId !== 0 ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ rtlPageId }`,
				params: { force: true },
			} );
		}
		deleteCollection( rtlSlug );
		setSiteLanguage( '' );
	} );

	test( 'an overflowing breadcrumb keeps its tail under the RTL stylesheet', async ( {
		page,
	} ) => {
		// Visit the gallery on the RTL site as the public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ rtlPageId }` );
		const crumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( crumb ).toBeVisible();

		// The crumb overflows, and the leading-ellipsis-keeps-tail contract holds
		// exactly as on the LTR site (the crumb stays `direction: rtl` under the
		// RTLCSS stylesheet): the head is clipped on the left, the tail kept inside
		// the right padding.
		const geo = await measureBreadcrumb(
			page,
			'.kntnt-photo-drop-gallery__breadcrumbs'
		);
		expect( geo ).not.toBeNull();
		expect( geo!.overflows ).toBe( true );
		expectLeadingEllipsisKeepsTail( geo! );
	} );
} );
