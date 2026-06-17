/**
 * Drop Zone end-to-end spec — the upload round-trip.
 *
 * An authenticated admin visits a published page carrying the Photo Drop
 * Zone block, confirms the new DOM shape (the wrapper is itself the layout
 * container and drop surface — no inner surface div, no role/tabindex, the
 * builder's tokened "Add photos" link), clicks that link to open the native file
 * chooser, and the browser pipeline (createImageBitmap → canvas → WebP) uploads it
 * to the REST endpoint. The spec asserts the client-visible truth (the aggregate
 * three-bucket summary the view renders on completion) and the server truth (the
 * stored `<name>.jpg.webp` is served from the collection directory as
 * `image/webp`). A second pass re-uploads the same file and must land in the
 * skipped bucket by name dedup. The tests are serial: the skip test depends on
 * the first upload having stored the file.
 *
 * A separate describe closes the one automation gap the unit layer cannot reach
 * (#44): a Playwright route-intercept forces the first POST to the images
 * endpoint to fail so the file lands in the Failed bucket, then Retry-failed is
 * clicked and the previously-failed file is asserted to be re-uploaded with its
 * real bytes — a genuine `<name>.jpg.webp` appears server-side and the bucket
 * moves to Uploaded. This guards the regression the two real #44 bugs caused
 * (Retry re-queuing without re-sending the actual file).
 *
 * @since 0.2.0
 */

import * as path from 'path';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURES_DIR, FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	storedImageUrl,
	uniqueSlug,
} from './support/wp';

// One collection and one published drop-zone page serve the serial pair.
const slug = uniqueSlug( 'drop' );
let pageId = 0;

test.describe( 'Drop Zone upload', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { requestUtils } ) => {
		// Seed an empty collection and publish a page with the drop-zone
		// block bound to it. A deterministic `%uploader%`-only template keeps the
		// uploaded file's placement fixed (no date folder), so the stored URL is
		// assertable: the admin's uploads land under `<slug>/admin/` (ADR-0014).
		createCollection( slug, '%uploader%' );
		const created = await requestUtils.createPage( {
			title: `E2E Drop Zone ${ slug }`,
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

	test( 'uploads a JPEG and the server stores the WebP', async ( {
		page,
	} ) => {
		// Visit the published page as the authenticated admin; the
		// capability-gated uploader renders only for users who can upload.
		await page.goto( `/?page_id=${ pageId }` );

		// The wrapper is itself the layout container and the drop surface: it
		// carries the Interactivity directive, no role/tabindex (the browse path is
		// the builder's tokened links instead), and there is no inner surface div.
		// The two hidden file inputs the tokened links trigger are emitted regardless
		// of the inner blocks.
		const wrapper = page.locator( '.kntnt-photo-drop-drop-zone' );
		await expect( wrapper ).toHaveCount( 1 );
		await expect( wrapper ).toHaveAttribute( 'data-wp-interactive', /.+/ );
		expect( await wrapper.getAttribute( 'role' ) ).toBeNull();
		expect( await wrapper.getAttribute( 'tabindex' ) ).toBeNull();
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__surface' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__folder-input' )
		).toHaveAttribute( 'webkitdirectory', '' );

		// The plugin's default dashed-box styling comes from the stylesheet, not a
		// block-attribute default (which a dynamic block never receives on the front
		// end), so it is actually visible on the published page — not only in the
		// editor. This page binds the block with no custom style, so the dashed border
		// must come from the shared style asset.
		expect(
			await wrapper.evaluate(
				( el ) => getComputedStyle( el ).borderTopStyle
			)
		).toBe( 'dashed' );

		// The "Add photos" control is the builder's own link, wired to the hidden
		// loose-file input by its anchor-token href (ADR-0010). Clicking it must open
		// the native file chooser; handing the fixture to that chooser drives the same
		// pipeline a real user would, proving the token wiring end-to-end.
		const browseLink = page.locator( 'a[href="#kntnt-drop-zone-files"]' );
		await expect( browseLink ).toBeVisible();
		const fileChooserPromise = page.waitForEvent( 'filechooser' );
		await browseLink.click();
		const fileChooser = await fileChooserPromise;
		await fileChooser.setFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		// On completion the view swaps the live bar for the three-bucket summary;
		// this one upload settles into the uploaded count, with no skipped or
		// failed buckets and no Retry-failed button.
		const status = page.locator( '.kntnt-photo-drop-drop-zone__status' );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--uploaded' )
		).toHaveText( '1 uploaded', { timeout: 30_000 } );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--failed' )
		).toHaveCount( 0 );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__retry' )
		).toHaveCount( 0 );

		// Server truth: the pathComponents template is expanded server-side
		// (ADR-0014), so with the `%uploader%` template the upload lands under
		// `<slug>/admin/` and is served from there, as WebP.
		const stored = await page.request.get(
			storedImageUrl( slug, FIXTURE_ALPHA, 'admin' )
		);
		expect( stored.ok() ).toBeTruthy();
		expect( stored.headers()[ 'content-type' ] ).toContain( 'image/webp' );
	} );

	test( 're-uploading the same file is skipped by name dedup', async ( {
		page,
	} ) => {
		// Fresh page load, same file: the server already holds
		// `e2e-alpha.jpg.webp`, so the upload must settle as skipped.
		await page.goto( `/?page_id=${ pageId }` );
		await page
			.locator( '.kntnt-photo-drop-drop-zone__file-input' )
			.setInputFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		// The file lands in the skipped bucket — listed by name — with nothing
		// uploaded and no Retry-failed button.
		const status = page.locator( '.kntnt-photo-drop-drop-zone__status' );
		const skipped = status.locator(
			'.kntnt-photo-drop-drop-zone__bucket--skipped'
		);
		await expect( skipped ).toContainText( FIXTURE_ALPHA, {
			timeout: 30_000,
		} );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--uploaded' )
		).toHaveText( '0 uploaded' );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__retry' )
		).toHaveCount( 0 );
	} );
} );

test.describe( 'Drop Zone Retry-failed', () => {
	const retrySlug = uniqueSlug( 'retry' );
	let retryPageId = 0;

	test.beforeAll( async ( { requestUtils } ) => {
		// A dedicated empty collection and page, so the forced failure here never
		// touches the upload/skip pair above. The `%uploader%`-only template fixes
		// the stored path under `<slug>/admin/`, making the server-truth URL
		// assertable (ADR-0014).
		createCollection( retrySlug, '%uploader%' );
		const created = await requestUtils.createPage( {
			title: `E2E Drop Zone Retry ${ retrySlug }`,
			content:
				`<!-- wp:kntnt-photo-drop/drop-zone {"collection":"${ retrySlug }"} -->\n` +
				`<!-- wp:buttons -->\n` +
				`<div class="wp-block-buttons"><!-- wp:button -->\n` +
				`<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#kntnt-drop-zone-files">Add photos</a></div>\n` +
				`<!-- /wp:button --></div>\n` +
				`<!-- /wp:buttons -->\n` +
				`<!-- /wp:kntnt-photo-drop/drop-zone -->`,
			status: 'publish',
		} );
		retryPageId = created.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( retryPageId !== 0 ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ retryPageId }`,
				params: { force: true },
			} );
		}
		deleteCollection( retrySlug );
	} );

	test( 'a forced upload failure lands in Failed, then Retry re-uploads the real bytes', async ( {
		page,
	} ) => {
		// Force exactly the first POST to the collection's images endpoint to fail with
		// a 500 — a real server-error response the view classifies as a failure — then
		// let every later request (the Retry's POST) through untouched. The route only
		// matches this endpoint, so the server-truth GET to the uploads directory is
		// never intercepted.
		let failedOnce = false;
		await page.route(
			new RegExp( `collections/${ retrySlug }/images` ),
			async ( route ) => {
				if ( route.request().method() === 'POST' && ! failedOnce ) {
					failedOnce = true;
					await route.fulfill( {
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'forced_failure',
							message: 'forced failure',
						} ),
					} );
					return;
				}
				await route.continue();
			}
		);

		await page.goto( `/?page_id=${ retryPageId }` );

		// Upload one file; its first (and only) POST is forced to fail, so it settles
		// into the Failed bucket, listed by name, with nothing uploaded.
		await page
			.locator( '.kntnt-photo-drop-drop-zone__file-input' )
			.setInputFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		const status = page.locator( '.kntnt-photo-drop-drop-zone__status' );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--failed' )
		).toContainText( FIXTURE_ALPHA, { timeout: 30_000 } );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--uploaded' )
		).toHaveText( '0 uploaded' );

		// Server truth before the retry: the forced failure stored nothing, so the
		// would-be stored WebP is genuinely absent.
		const before = await page.request.get(
			storedImageUrl( retrySlug, FIXTURE_ALPHA, 'admin' )
		);
		expect( before.ok() ).toBeFalsy();

		// Click Retry-failed: the queue re-runs exactly the failed file, and with the
		// first failure spent the route now lets the real POST through.
		const retry = status.locator( '.kntnt-photo-drop-drop-zone__retry' );
		await expect( retry ).toBeVisible();
		await retry.click();

		// The previously-failed file now uploads: it moves to the uploaded bucket, the
		// failed bucket clears, and the Retry button is gone.
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--uploaded' )
		).toHaveText( '1 uploaded', { timeout: 30_000 } );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__bucket--failed' )
		).toHaveCount( 0 );
		await expect(
			status.locator( '.kntnt-photo-drop-drop-zone__retry' )
		).toHaveCount( 0 );

		// Server truth after the retry: a real `<name>.jpg.webp` is now stored and
		// served as WebP — proving Retry re-sent the actual file bytes, not an empty
		// re-queue (the #44 regression this guards).
		const after = await page.request.get(
			storedImageUrl( retrySlug, FIXTURE_ALPHA, 'admin' )
		);
		expect( after.ok() ).toBeTruthy();
		expect( after.headers()[ 'content-type' ] ).toContain( 'image/webp' );
	} );
} );
