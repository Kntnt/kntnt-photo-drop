/**
 * Gallery download end-to-end spec — the icon-only download trigger.
 *
 * A logged-out visitor exercises both download-on cells of the click matrix:
 * with the lightbox off, a click on the thumbnail image does nothing while a
 * click on the overlay icon saves the image — without navigating and without
 * opening a new tab (the regression this spec pins); with the lightbox on,
 * the icon lives inside the lightbox, a click on the enlarged image does
 * nothing, and only the icon click saves the current slide.
 *
 * @since 0.5.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA, FIXTURE_BETA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One collection with two images behind two published pages — one per
// download-on cell of the click matrix.
const slug = uniqueSlug( 'download' );
let thumbnailPageId = 0;
let lightboxPageId = 0;

// How long the spec waits to conclude that a click had no effect, in
// milliseconds. "Nothing happens" has no event to await, so the negative
// assertions need a settle window.
const SETTLE = 300;

test.describe( 'Gallery download', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed two images so the lightbox cell has a second slide to verify
		// the per-slide download href against.
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );
		importFixture( slug, FIXTURE_BETA );

		// One page per matrix cell with download on: lightbox off puts the icon
		// on each thumbnail, lightbox on (the default) moves it into the lightbox.
		const thumbnailPage = await requestUtils.createPage( {
			title: `E2E Download thumbnail ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }","lightbox":false,"download":true} /-->`,
			status: 'publish',
		} );
		thumbnailPageId = thumbnailPage.id;
		const lightboxPage = await requestUtils.createPage( {
			title: `E2E Download lightbox ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }","download":true} /-->`,
			status: 'publish',
		} );
		lightboxPageId = lightboxPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove both pages and the collection so reruns start clean.
		for ( const pageId of [ thumbnailPageId, lightboxPageId ] ) {
			if ( pageId !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ pageId }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( slug );
	} );

	test( 'lightbox off: only the icon downloads; the image itself does nothing', async ( {
		page,
	} ) => {
		// Drop the admin cookies so the gallery is exercised exactly as the
		// public sees it, and count every download the page ever starts.
		await page.context().clearCookies();
		let downloads = 0;
		page.on( 'download', () => {
			downloads++;
		} );
		await page.goto( `/?page_id=${ thumbnailPageId }` );
		const url = page.url();

		// A click on the thumbnail image does nothing: no navigation, no
		// download, no new tab.
		await page
			.locator( '.kntnt-photo-drop-gallery__link img' )
			.first()
			.click();
		await page.waitForTimeout( SETTLE );
		expect( downloads ).toBe( 0 );
		await expect( page ).toHaveURL( url );
		expect( page.context().pages() ).toHaveLength( 1 );

		// A click on the overlay icon saves the image — and only saves it: the
		// page neither navigates nor opens a tab while the file downloads.
		const downloadPromise = page.waitForEvent( 'download' );
		await page
			.locator( '.kntnt-photo-drop-gallery__download' )
			.first()
			.click();
		const download = await downloadPromise;
		expect( download.suggestedFilename() ).toContain( '.webp' );
		await expect( page ).toHaveURL( url );
		expect( page.context().pages() ).toHaveLength( 1 );
	} );

	test( 'lightbox on: only the in-lightbox icon downloads; the enlarged image does nothing', async ( {
		page,
	} ) => {
		await page.context().clearCookies();
		let downloads = 0;
		page.on( 'download', () => {
			downloads++;
		} );
		await page.goto( `/?page_id=${ lightboxPageId }` );
		const url = page.url();

		// A thumbnail click opens the lightbox (never downloads).
		await page.locator( '.kntnt-photo-drop-gallery__link' ).first().click();
		const dialog = page.getByRole( 'dialog', { name: 'Image viewer' } );
		await expect( dialog ).toBeVisible();
		expect( downloads ).toBe( 0 );

		// A click on the enlarged image does nothing: the dialog stays open and
		// nothing downloads or navigates.
		await page.locator( '.kntnt-photo-drop-lightbox__image' ).click();
		await page.waitForTimeout( SETTLE );
		await expect( dialog ).toBeVisible();
		expect( downloads ).toBe( 0 );
		await expect( page ).toHaveURL( url );

		// A click on the icon saves the current slide; the dialog stays open and
		// no tab opens.
		const downloadPromise = page.waitForEvent( 'download' );
		await page.locator( '.kntnt-photo-drop-lightbox__download' ).click();
		const download = await downloadPromise;
		expect( download.suggestedFilename() ).toContain( '.webp' );
		await expect( dialog ).toBeVisible();
		await expect( page ).toHaveURL( url );
		expect( page.context().pages() ).toHaveLength( 1 );
	} );
} );
