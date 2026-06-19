/**
 * Gallery image reachability end-to-end spec.
 *
 * Guards that every image the gallery grid renders is actually served: each
 * `<img>` must decode in the browser (naturalWidth > 0) and its `src` URL must
 * return HTTP 2xx with an `image/` content-type. At least one resolved `src`
 * must pass through the hidden `.kntnt-thumbnails/` renditions directory —
 * confirming the spec actually exercises the derived-rendition path, not just
 * collapsed mains.
 *
 * `@wordpress/env` boots Apache, which serves dot-directories, so this spec
 * covers the "images actually load" invariant rather than any particular web
 * server's dot-path policy.
 *
 * @since 0.15.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA, FIXTURE_BETA } from './support/fixture-images';
import {
	deleteCollection,
	importFixture,
	uniqueSlug,
	wpCli,
} from './support/wp';

// One collection with two images behind one published gallery page.
const slug = uniqueSlug( 'gallery-images' );
let pageId = 0;

test.describe( 'Gallery image reachability', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// An explicit 320px thumbnail width forces a real .kntnt-thumbnails/320/
		// rendition for these ≤640px fixtures; the default 640px width would
		// collapse to the main image and make the rendition assertion vacuous.
		wpCli( [
			'kntnt-photo-drop',
			'collection',
			'create',
			slug,
			'--upload-width=1920',
			'--upload-quality=80',
			'--thumbnail-width=320',
		] );
		importFixture( slug, FIXTURE_ALPHA );
		importFixture( slug, FIXTURE_BETA );

		// Publish a page whose sole content is the gallery block.
		const page = await requestUtils.createPage( {
			title: `E2E Gallery Images ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/gallery {"collection":"${ slug }"} /-->`,
			status: 'publish',
		} );
		pageId = page.id;
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

	test( 'every gallery grid image loads with HTTP 2xx and paints in the browser', async ( {
		page,
		request,
	} ) => {
		// Exercise the gallery as the anonymous public — no admin cookies.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ pageId }` );

		// Collect all gallery grid images.
		const images = page.locator( '.kntnt-photo-drop-gallery__image' );
		const count = await images.count();
		expect( count ).toBeGreaterThan( 0 );

		// Track whether at least one src passes through the renditions directory.
		let renditionSeen = false;

		for ( let i = 0; i < count; i++ ) {
			const img = images.nth( i );
			const src = ( await img.getAttribute( 'src' ) ) ?? '';

			// The browser must have decoded the image (proves it actually painted,
			// not just that the markup was emitted).
			await expect
				.poll( () =>
					img.evaluate( ( el: HTMLImageElement ) => el.naturalWidth )
				)
				.toBeGreaterThan( 0 );

			// The URL must return a successful response with an image content-type.
			const res = await request.get( src );
			expect( res.ok() ).toBe( true );
			expect( res.headers()[ 'content-type' ] ).toContain( 'image/' );

			// Check whether the src passes through the hidden renditions directory
			// rather than collapsing to the main image.
			if ( src.includes( '.kntnt-thumbnails/' ) ) {
				renditionSeen = true;
			}
		}

		// At least one grid image must be a derived rendition — ensuring this spec
		// genuinely covers the served-rendition path.
		expect( renditionSeen ).toBe( true );
	} );
} );
