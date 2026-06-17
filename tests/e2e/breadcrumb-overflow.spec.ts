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
 * with a `Range`: a leading ellipsis means the **head** is clipped (the first
 * glyph sits left of the content box) while the **tail** stays inside the right
 * padding (the last glyph's right edge is within the content box, leaving a
 * gutter to the border-box edge). A short, non-overflowing crumb keeps both
 * glyphs inside the box untouched.
 *
 * @since 0.11.1
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
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
	readonly textLeft: number;
	readonly textRight: number;
	readonly firstGlyphLeft: number;
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
		const textNode = el?.firstChild;
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

		// The whole text run's extent and the first/last glyph extents, via a
		// Range — these report true geometry even where overflow is clipped.
		const full = document.createRange();
		full.selectNodeContents( textNode );
		const fullRect = full.getBoundingClientRect();
		const value = textNode.nodeValue ?? '';
		const first = document.createRange();
		first.setStart( textNode, 0 );
		first.setEnd( textNode, Math.min( 1, value.length ) );
		const last = document.createRange();
		last.setStart( textNode, Math.max( 0, value.length - 1 ) );
		last.setEnd( textNode, value.length );

		return {
			borderBoxWidth: box.width,
			contentLeft,
			contentRight,
			textLeft: fullRect.left - box.left,
			textRight: fullRect.right - box.left,
			firstGlyphLeft: first.getBoundingClientRect().left - box.left,
			lastGlyphRight: last.getBoundingClientRect().right - box.left,
			overflows: fullRect.width > contentRight - contentLeft + 0.5,
		};
	}, selector );
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

		// The head is clipped: the first glyph sits left of the content box, so
		// the visible start is a leading ellipsis, not the path's first crumb.
		expect( geo!.firstGlyphLeft ).toBeLessThan( geo!.contentLeft );

		// The tail is kept and stays inside the right padding: the last glyph's
		// right edge is within the content box, leaving a perceptible right gutter
		// to the border-box edge rather than running flush to the image edge.
		expect( geo!.lastGlyphRight ).toBeLessThanOrEqual(
			geo!.contentRight + 0.5
		);
		expect( geo!.borderBoxWidth - geo!.lastGlyphRight ).toBeGreaterThan(
			1
		);
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
