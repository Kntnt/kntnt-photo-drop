/**
 * Gallery slideshow cycle-boundary resync end-to-end spec (ADR-0011).
 *
 * A logged-out visitor starts a slideshow and the collection changes under it
 * mid-playback. The first spec exercises the mainline photo-frame case: an
 * image imported during playback joins the rotation at a later cycle
 * boundary, with no page reload. The second exercises the two hard cases the
 * ADR settles: a *single-image* gallery — which pre-0.9.0 had no cycle at all
 * and would stand frozen forever — picks up a second image, and emptying the
 * collection ends the playback within a cycle so a takedown propagates to a
 * long-running frame.
 *
 * @since 0.9.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	FIXTURE_ALPHA,
	FIXTURE_BETA,
	FIXTURE_GAMMA,
} from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	deleteImage,
	importFixture,
	uniqueSlug,
} from './support/wp';

// Each spec gets its own collection and page so a retry never inherits the
// other spec's mid-playback mutations.
const growSlug = uniqueSlug( 'resync-grow' );
const singleSlug = uniqueSlug( 'resync-single' );
let growPageId = 0;
let singlePageId = 0;

test.describe( 'Gallery slideshow resync', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The grow spec starts from two images; the single spec from one. Both
		// pages use the shortest legal per-slide time so cycle boundaries come
		// around quickly.
		createCollection( growSlug );
		importFixture( growSlug, FIXTURE_ALPHA );
		importFixture( growSlug, FIXTURE_BETA );
		createCollection( singleSlug );
		importFixture( singleSlug, FIXTURE_ALPHA );
		const growPage = await requestUtils.createPage( {
			title: `E2E Slideshow resync grow ${ growSlug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ growSlug }","slideshow":"button","slideshowSeconds":1} /-->`,
			status: 'publish',
		} );
		growPageId = growPage.id;
		const singlePage = await requestUtils.createPage( {
			title: `E2E Slideshow resync single ${ singleSlug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ singleSlug }","slideshow":"button","slideshowSeconds":1} /-->`,
			status: 'publish',
		} );
		singlePageId = singlePage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove both pages and collections so reruns start clean.
		for ( const pageId of [ growPageId, singlePageId ] ) {
			if ( pageId !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ pageId }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( growSlug );
		deleteCollection( singleSlug );
	} );

	test( 'plays an image imported during playback at a later cycle boundary', async ( {
		page,
	} ) => {
		// Exercise the playback exactly as the public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ growPageId }` );
		await page
			.locator( '.kntnt-photo-drop-gallery__slideshow-button' )
			.click();
		const front = page.locator(
			'.kntnt-photo-drop-slideshow__image--front'
		);
		await expect( front ).toHaveAttribute( 'src', /alpha/ );

		// Import a third image while the playback rolls; gamma sorts last, so
		// it joins at the tail of a later cycle — no reload involved.
		importFixture( growSlug, FIXTURE_GAMMA );
		await expect( front ).toHaveAttribute( 'src', /gamma/, {
			timeout: 30_000,
		} );
	} );

	test( 'cycles a single-image gallery into new images and ends on an emptied view', async ( {
		page,
	} ) => {
		// Start a playback over a one-image collection — the photo-frame
		// bootstrap that pre-0.9.0 froze forever.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ singlePageId }` );
		await page
			.locator( '.kntnt-photo-drop-gallery__slideshow-button' )
			.click();
		const front = page.locator(
			'.kntnt-photo-drop-slideshow__image--front'
		);
		await expect( front ).toHaveAttribute( 'src', /alpha/ );

		// The lone slide's visible time is a cycle boundary too: an image
		// imported now enters the rotation without a reload.
		importFixture( singleSlug, FIXTURE_BETA );
		await expect( front ).toHaveAttribute( 'src', /beta/, {
			timeout: 30_000,
		} );

		// Emptying the collection ends the playback within one cycle — the
		// takedown path — returning the visitor to the page.
		deleteImage( singleSlug, `${ FIXTURE_ALPHA }.webp` );
		deleteImage( singleSlug, `${ FIXTURE_BETA }.webp` );
		await expect(
			page.getByRole( 'dialog', { name: 'Slideshow' } )
		).toBeHidden( { timeout: 30_000 } );
	} );
} );
