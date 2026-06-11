/**
 * Gallery shadow end-to-end spec — the per-image Shadow support is not clipped.
 *
 * The Border and Shadow block supports are projected onto each gallery `<img>`
 * (the core Image-block skip-serialization pattern, issue #33). A border draws on
 * the element edge and always showed; the box-shadow draws *outside* the element,
 * so it was silently clipped when the tile (`__item`) carried `overflow: hidden`.
 * This spec pins the fix: a gallery rendered with a custom shadow must emit a real
 * `box-shadow` on the image **and** the tile must not clip it (its overflow stays
 * visible), so a builder who turns the shadow on actually sees it.
 *
 * @since 0.6.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One collection with one image behind a single published gallery page that
// carries a custom per-image shadow.
const slug = uniqueSlug( 'shadow' );
let pageId = 0;

test.describe( 'Gallery shadow', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed a one-image collection and publish a gallery whose Shadow block
		// support carries a custom box-shadow (stored under style.shadow).
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );

		const created = await requestUtils.createPage( {
			title: `E2E Shadow ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ slug }","style":{"shadow":"6px 6px 12px rgba(0,0,0,0.6)"}} /-->`,
			status: 'publish',
		} );
		pageId = created.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove the page and the collection so reruns start clean.
		if ( pageId !== 0 ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ pageId }`,
				params: { force: true },
			} );
		}
		deleteCollection( slug );
	} );

	test( 'the per-image shadow is emitted on the image and not clipped by the tile', async ( {
		page,
	} ) => {
		// Visit the gallery exactly as the public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ pageId }` );

		// The image carries a real box-shadow projected from the Shadow support.
		const image = page
			.locator( '.kntnt-photo-drop-gallery__image' )
			.first();
		await expect( image ).toBeVisible();
		const boxShadow = await image.evaluate(
			( el ) => getComputedStyle( el ).boxShadow
		);
		expect( boxShadow ).not.toBe( 'none' );

		// The tile no longer clips its overflow, so that shadow actually paints
		// outside the image box instead of being cut at the tile edge (the fix).
		const item = page.locator( '.kntnt-photo-drop-gallery__item' ).first();
		const overflowX = await item.evaluate(
			( el ) => getComputedStyle( el ).overflowX
		);
		expect( overflowX ).not.toBe( 'hidden' );
	} );
} );
