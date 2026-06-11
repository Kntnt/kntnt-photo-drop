/**
 * Gallery slideshow end-to-end spec — the passive fullscreen surface
 * (ADR-0009).
 *
 * A logged-out visitor opens a published gallery whose slideshow trigger is
 * the built-in button: the quiet button (hidden until the view module wires
 * the playback) is revealed, clicking it opens the dialog-role overlay with
 * focus on the close button and the page scroll locked, the playback advances
 * on its own from the first image to the second, and Escape ends it with
 * focus restored to the button. A second gallery uses the custom-trigger
 * contract instead: no built-in button renders, and a designer-supplied link
 * carrying `data-kntnt-photo-drop-slideshow="<anchor>"` starts the playback
 * of the gallery whose HTML anchor matches.
 *
 * @since 0.7.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA, FIXTURE_BETA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One collection with two images (alpha sorts before beta) serves both
// trigger modes; each mode gets its own published page.
const slug = uniqueSlug( 'slideshow' );
let buttonPageId = 0;
let customPageId = 0;

test.describe( 'Gallery slideshow', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed two images so the auto-advance has a second slide to dissolve
		// to.
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );
		importFixture( slug, FIXTURE_BETA );

		// One page per trigger mode. The button page uses the shortest legal
		// per-slide time so the auto-advance assertion does not idle; the
		// custom page carries the designer's own trigger link targeting the
		// gallery by its HTML anchor.
		const buttonPage = await requestUtils.createPage( {
			title: `E2E Slideshow button ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }","slideshow":"button","slideshowSeconds":1} /-->`,
			status: 'publish',
		} );
		buttonPageId = buttonPage.id;
		const customPage = await requestUtils.createPage( {
			title: `E2E Slideshow custom ${ slug }`,
			content:
				'<!-- wp:html --><p><a href="#" data-kntnt-photo-drop-slideshow="e2e-show">Play</a></p><!-- /wp:html -->' +
				`<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }","slideshow":"custom","anchor":"e2e-show"} /-->`,
			status: 'publish',
		} );
		customPageId = customPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove both pages and the collection so reruns start clean.
		for ( const pageId of [ buttonPageId, customPageId ] ) {
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

	test( 'starts from the built-in button, advances on its own, and ends on Escape', async ( {
		page,
	} ) => {
		// Drop the admin cookies so the gallery is exercised exactly as the
		// public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ buttonPageId }` );

		// The view module reveals the quiet button once the slideshow is
		// wired; the server ships it hidden against no-JS dead controls.
		const button = page.locator(
			'.kntnt-photo-drop-gallery__slideshow-button'
		);
		await expect( button ).toBeVisible();
		await expect( button ).toHaveText( 'Slideshow' );

		// Starting the playback opens the dialog-role overlay with focus on
		// its close button and the page scroll locked behind it.
		await button.click();
		const dialog = page.getByRole( 'dialog', { name: 'Slideshow' } );
		await expect( dialog ).toBeVisible();
		await expect(
			page.locator( '.kntnt-photo-drop-slideshow__close' )
		).toBeFocused();
		await expect
			.poll( () =>
				page.evaluate( () => document.documentElement.style.overflow )
			)
			.toBe( 'hidden' );

		// The first slide (alpha sorts first) fades in as the front image.
		const front = page.locator(
			'.kntnt-photo-drop-slideshow__image--front'
		);
		await expect( front ).toHaveAttribute( 'src', /alpha/ );

		// With one visible second and a one-second dissolve, the playback
		// advances to the second slide on its own — the front class moves to
		// the image holding beta.
		await expect( front ).toHaveAttribute( 'src', /beta/, {
			timeout: 15_000,
		} );

		// Escape ends the playback, hides the overlay, restores focus to the
		// button, and releases the scroll lock.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( button ).toBeFocused();
		await expect
			.poll( () =>
				page.evaluate( () => document.documentElement.style.overflow )
			)
			.toBe( '' );
	} );

	test( 'starts from a designer-supplied element targeting the gallery anchor', async ( {
		page,
	} ) => {
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ customPageId }` );

		// Custom mode renders no built-in button, and the gallery wrapper
		// carries the HTML anchor the trigger targets.
		await expect(
			page.locator( '.kntnt-photo-drop-gallery__slideshow-button' )
		).toHaveCount( 0 );
		await expect( page.locator( '#e2e-show' ) ).toHaveCount( 1 );

		// Clicking the designer's link starts the targeted gallery's playback
		// instead of navigating.
		await page
			.locator( '[data-kntnt-photo-drop-slideshow="e2e-show"]' )
			.click();
		const dialog = page.getByRole( 'dialog', { name: 'Slideshow' } );
		await expect( dialog ).toBeVisible();

		// Escape returns to the page with the overlay hidden.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );
} );
