/**
 * Gallery lightbox end-to-end spec — the public viewing experience.
 *
 * A logged-out visitor opens a published gallery of two seeded images:
 * clicking a thumbnail opens the Interactivity-API lightbox as a modal
 * dialog (focus moved to its close button, the page scroll locked), the
 * counter and arrow keys page through the images, and Escape closes it with
 * focus restored to the thumbnail anchor and the scroll lock released. With
 * JavaScript disabled the same click follows the anchor's plain `href`
 * straight to the stored main image — the no-JS fallback ADR-0007 promises.
 *
 * @since 0.2.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA, FIXTURE_BETA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	siteUrl,
	uniqueSlug,
} from './support/wp';

// One collection with two images (alpha sorts before beta) behind one
// published gallery page serves both tests.
const slug = uniqueSlug( 'lightbox' );
let pageId = 0;

test.describe( 'Gallery lightbox', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed two images so the counter and arrow navigation have a second
		// position to move to.
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );
		importFixture( slug, FIXTURE_BETA );

		// Publish a page with the gallery block bound to the collection;
		// the lightbox is enabled by default.
		const created = await requestUtils.createPage( {
			title: `E2E Lightbox ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }"} /-->`,
			status: 'publish',
		} );
		pageId = created.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove the page and the collection so reruns start clean. The
		// package exports deletePage but does not bind it as a method, so
		// the page goes through the raw REST helper.
		if ( pageId !== 0 ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ pageId }`,
				params: { force: true },
			} );
		}
		deleteCollection( slug );
	} );

	test( 'opens, navigates, closes, and restores focus and scroll for a logged-out visitor', async ( {
		page,
	} ) => {
		// Drop the admin cookies so the gallery is exercised exactly as the
		// public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ pageId }` );

		// The gallery renders both thumbnails, each wrapped in a link to
		// its main image.
		const links = page.locator( '.kntnt-photo-drop-gallery__link' );
		await expect( links ).toHaveCount( 2 );

		// Clicking the first thumbnail opens the modal dialog with focus on
		// the close button, the counter announcing the position, and the
		// page scroll locked.
		const firstLink = links.first();
		await firstLink.click();
		const dialog = page.getByRole( 'dialog', { name: 'Image viewer' } );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toHaveAttribute( 'aria-modal', 'true' );
		await expect(
			page.locator( '.kntnt-photo-drop-lightbox__close' )
		).toBeFocused();
		await expect(
			page.locator( '.kntnt-photo-drop-lightbox__counter' )
		).toHaveText( '1 of 2' );
		await expect
			.poll( () =>
				page.evaluate( () => document.documentElement.style.overflow )
			)
			.toBe( 'hidden' );

		// ArrowRight pages to the second image.
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			page.locator( '.kntnt-photo-drop-lightbox__counter' )
		).toHaveText( '2 of 2' );

		// Escape closes the dialog, returns focus to the thumbnail anchor,
		// and releases the scroll lock.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( firstLink ).toBeFocused();
		await expect
			.poll( () =>
				page.evaluate( () => document.documentElement.style.overflow )
			)
			.toBe( '' );
	} );

	test( 'falls back to plain navigation to the main image without JavaScript', async ( {
		browser,
	} ) => {
		// A dedicated context: JavaScript off and explicitly no storage
		// state, so neither the lightbox module nor the admin session exists.
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: { cookies: [], origins: [] },
			baseURL: siteUrl( '/' ),
		} );

		try {
			// Without the view module, the thumbnail link is a plain anchor
			// pointing at the stored main image.
			const noJsPage = await context.newPage();
			await noJsPage.goto( `/?page_id=${ pageId }` );
			const firstLink = noJsPage
				.locator( '.kntnt-photo-drop-gallery__link' )
				.first();
			const href = await firstLink.getAttribute( 'href' );
			expect( href ).toContain( '.webp' );

			// Clicking navigates straight to that URL.
			await firstLink.click();
			await noJsPage.waitForURL( href ?? '' );

			// The destination really is the WebP main image.
			const response = await context.request.get( href ?? '' );
			expect( response.ok() ).toBeTruthy();
			expect( response.headers()[ 'content-type' ] ).toContain(
				'image/webp'
			);
		} finally {
			await context.close();
		}
	} );
} );
