/**
 * Drop Zone padding end-to-end spec — the progress UI honours the *default* padding.
 *
 * The block's default box look — including its padding — lives in the shared
 * `style` asset, not in a block-attribute default (a dynamic block never
 * receives an attribute default on the front end, ADR-0010 / `style.scss`). An
 * explicit inspector padding is serialised by `get_block_wrapper_attributes()`
 * as an inline `style="padding:…"`, which always wins; the default is a
 * low-specificity class rule that must win over WordPress's own constrained-layout
 * CSS so the feedback regions inset identically either way (issue #56).
 *
 * This spec pins that contract on the real published page, exactly as the public
 * sees it: with no explicit padding set, the wrapper carries a real, non-zero
 * computed padding *and* the progress/status feedback regions are geometrically
 * inset from the wrapper's edges by that padding (their content sits inside the
 * box, never flush to it). A sibling case sets an explicit padding and asserts
 * the same insetting holds, so the fix never regresses the explicit path.
 *
 * @since 0.12.0
 */

import * as path from 'path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Locator } from '@playwright/test';
import { FIXTURES_DIR, FIXTURE_ALPHA } from './support/fixture-images';
import { createCollection, deleteCollection, uniqueSlug } from './support/wp';

/**
 * The horizontal box metrics this spec asserts on, all in CSS pixels.
 *
 * `paddingLeft`/`paddingRight` and `borderLeft`/`borderRight` are the wrapper's
 * own computed values; `insetLeft`/`insetRight` are the gap between the wrapper's
 * border-box edge and the measured region's edge on each side.
 */
interface HorizontalInsets {
	readonly paddingLeft: number;
	readonly paddingRight: number;
	readonly borderLeft: number;
	readonly borderRight: number;
	readonly insetLeft: number;
	readonly insetRight: number;
}

/**
 * Reads the wrapper's padding/border and a child region's left/right inset.
 *
 * The status region (`__status`) holds the three-bucket completion summary, so
 * it is populated and measurable once the upload finishes; the progress region
 * collapses (`:empty`) again at that point. Measuring the status region proves
 * the same point — a real child of the padded wrapper must sit inside the box.
 *
 * @param wrapper - The Drop Zone block wrapper locator.
 * @param region  - The child feedback region whose inset is measured.
 * @return The wrapper's horizontal padding/border and the region's insets.
 */
async function readInsets(
	wrapper: Locator,
	region: Locator
): Promise< HorizontalInsets > {
	return wrapper.evaluate(
		( wrapperEl, regionEl ) => {
			const wrapperStyle = getComputedStyle( wrapperEl );
			const wrapperRect = wrapperEl.getBoundingClientRect();
			const regionRect = (
				regionEl as HTMLElement
			 ).getBoundingClientRect();

			return {
				paddingLeft: parseFloat( wrapperStyle.paddingLeft ),
				paddingRight: parseFloat( wrapperStyle.paddingRight ),
				borderLeft: parseFloat( wrapperStyle.borderLeftWidth ),
				borderRight: parseFloat( wrapperStyle.borderRightWidth ),
				insetLeft: regionRect.left - wrapperRect.left,
				insetRight: wrapperRect.right - regionRect.right,
			};
		},
		await region.elementHandle()
	);
}

test.describe( 'Drop Zone padding', () => {
	const slug = uniqueSlug( 'padding' );
	let defaultPageId = 0;
	let explicitPageId = 0;

	test.beforeAll( async ( { requestUtils } ) => {
		// One collection serves both pages. The `%uploader%`-only template keeps
		// the upload placement fixed; this spec never asserts the stored path, but
		// it keeps the upload deterministic alongside the other drop-zone specs.
		createCollection( slug, '%uploader%' );

		// The default-padding page: a plain Drop Zone with no spacing attribute, so
		// the only padding that can apply is the shared style asset's 2rem default.
		const defaultPage = await requestUtils.createPage( {
			title: `E2E Drop Zone padding default ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/drop-zone {"collection":"${ slug }"} -->\n` +
				`<!-- wp:buttons -->\n` +
				`<div class="wp-block-buttons"><!-- wp:button -->\n` +
				`<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#kntnt-drop-zone-files">Add photos</a></div>\n` +
				`<!-- /wp:button --></div>\n` +
				`<!-- /wp:buttons -->\n` +
				`<!-- /wp:kntnt-photo-drop/drop-zone -->`,
			status: 'publish',
		} );
		defaultPageId = defaultPage.id;

		// The explicit-padding page: the same block with an inspector padding of
		// 3rem on every side, serialised as an inline style so it wins over the
		// default. The fix must leave this path inset exactly as before.
		const explicitPage = await requestUtils.createPage( {
			title: `E2E Drop Zone padding explicit ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/drop-zone {"collection":"${ slug }","style":{"spacing":{"padding":{"top":"3rem","right":"3rem","bottom":"3rem","left":"3rem"}}}} -->\n` +
				`<!-- wp:buttons -->\n` +
				`<div class="wp-block-buttons"><!-- wp:button -->\n` +
				`<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#kntnt-drop-zone-files">Add photos</a></div>\n` +
				`<!-- /wp:button --></div>\n` +
				`<!-- /wp:buttons -->\n` +
				`<!-- /wp:kntnt-photo-drop/drop-zone -->`,
			status: 'publish',
		} );
		explicitPageId = explicitPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove both pages and the collection so reruns start clean.
		for ( const id of [ defaultPageId, explicitPageId ] ) {
			if ( id !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ id }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( slug );
	} );

	test( 'with default padding, the feedback regions are inset from the block edges', async ( {
		page,
	} ) => {
		// Visit the published page as the authenticated admin; the capability-gated
		// uploader renders only for users who can upload.
		await page.goto( `/?page_id=${ defaultPageId }` );

		const wrapper = page.locator( '.kntnt-photo-drop-drop-zone' );
		await expect( wrapper ).toHaveCount( 1 );

		// Upload one file so the completion summary populates the status region,
		// giving a real child to measure the inset against.
		const browseLink = page.locator( 'a[href="#kntnt-drop-zone-files"]' );
		const fileChooserPromise = page.waitForEvent( 'filechooser' );
		await browseLink.click();
		const fileChooser = await fileChooserPromise;
		await fileChooser.setFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		const status = page.locator( '.kntnt-photo-drop-drop-zone__status' );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--uploaded' )
		).toHaveText( '1 uploaded', { timeout: 30_000 } );

		// The shared style asset's default padding actually takes effect on the
		// published page — it is not silently zeroed by WordPress's constrained
		// layout CSS — so the wrapper carries a real, non-zero padding.
		const insets = await readInsets( wrapper, status );
		expect( insets.paddingLeft ).toBeGreaterThan( 0 );
		expect( insets.paddingRight ).toBeGreaterThan( 0 );

		// The status region is a real child of the padded box, so it is inset from
		// the wrapper edges by border + padding on each side, never flush to them.
		expect( insets.insetLeft ).toBeCloseTo(
			insets.borderLeft + insets.paddingLeft,
			0
		);
		expect( insets.insetRight ).toBeCloseTo(
			insets.borderRight + insets.paddingRight,
			0
		);
	} );

	test( 'with explicit padding, the feedback regions stay inset (no regression)', async ( {
		page,
	} ) => {
		// The explicit inspector padding is serialised inline and always wins; the
		// fix must leave this path inset exactly as before.
		await page.goto( `/?page_id=${ explicitPageId }` );

		const wrapper = page.locator( '.kntnt-photo-drop-drop-zone' );
		await expect( wrapper ).toHaveCount( 1 );

		const browseLink = page.locator( 'a[href="#kntnt-drop-zone-files"]' );
		const fileChooserPromise = page.waitForEvent( 'filechooser' );
		await browseLink.click();
		const fileChooser = await fileChooserPromise;
		await fileChooser.setFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		const status = page.locator( '.kntnt-photo-drop-drop-zone__status' );
		// A re-upload of the same file settles as skipped; either bucket proves the
		// status region populated, so wait on the region carrying any bucket.
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket' ).first()
		).toBeVisible( { timeout: 30_000 } );

		const insets = await readInsets( wrapper, status );
		expect( insets.paddingLeft ).toBeGreaterThan( 0 );
		expect( insets.insetLeft ).toBeCloseTo(
			insets.borderLeft + insets.paddingLeft,
			0
		);
		expect( insets.insetRight ).toBeCloseTo(
			insets.borderRight + insets.paddingRight,
			0
		);
	} );
} );
