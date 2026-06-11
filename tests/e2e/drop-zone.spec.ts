/**
 * Drop Zone end-to-end spec — the upload round-trip.
 *
 * An authenticated admin visits a published page carrying the Photo Drop
 * Zone block, confirms the new DOM shape (the wrapper is itself the layout
 * container and drop surface — no inner surface div, no role/tabindex, a real
 * "Add photos" button), hands a JPEG to the native hidden loose-file input, and
 * the browser pipeline (createImageBitmap → canvas → WebP) uploads it to the
 * REST endpoint. The spec asserts the client-visible truth (the per-file status
 * row and the live summary) and the server truth (the stored
 * `<name>.jpg.webp` is served from the collection directory as
 * `image/webp`). A second pass re-uploads the same file and must be skipped
 * by name dedup. The tests are serial: the skip test depends on the first
 * upload having stored the file.
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
		// block bound to it.
		createCollection( slug );
		const created = await requestUtils.createPage( {
			title: `E2E Drop Zone ${ slug }`,
			content: `<!-- wp:kntnt-photo-drop/drop-zone {"collection":"${ slug }"} /-->`,
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
		// carries the Interactivity directive, no role/tabindex (the keyboard
		// browse path is a real "Add photos" button instead), and there is no
		// inner surface div. The editor spec covers the seeded inner-block
		// template; this page binds a self-closing block, so it has no inner
		// blocks to assert here.
		const wrapper = page.locator( '.kntnt-photo-drop-drop-zone' );
		await expect( wrapper ).toHaveCount( 1 );
		await expect( wrapper ).toHaveAttribute( 'data-wp-interactive', /.+/ );
		expect( await wrapper.getAttribute( 'role' ) ).toBeNull();
		expect( await wrapper.getAttribute( 'tabindex' ) ).toBeNull();
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__surface' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__browse' )
		).toBeVisible();

		// The folder picker is demoted to a quiet, link-style affordance (issue
		// #40): the visible text reads as a link, but the real webkitdirectory
		// input stays focusable and keyboard-operable — never removed from the
		// tab order — so the accessible hierarchy-preserving route survives.
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__folder-text' )
		).toBeVisible();
		const folderInput = page.locator(
			'.kntnt-photo-drop-drop-zone__folder-input'
		);
		await expect( folderInput ).toHaveAttribute( 'webkitdirectory', '' );
		await folderInput.focus();
		await expect( folderInput ).toBeFocused();

		// Hand the fixture to the hidden loose-file input the wrapper click or
		// the "Add photos" button would open; the view module converts it to
		// WebP and POSTs it.
		await page
			.locator( '.kntnt-photo-drop-drop-zone__file-input' )
			.setInputFiles( path.join( FIXTURES_DIR, FIXTURE_ALPHA ) );

		// The file's status row settles on the uploaded state, and the
		// summary counts exactly this one upload.
		const row = page.locator( '.kntnt-photo-drop-drop-zone__status-item' );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toHaveText( `${ FIXTURE_ALPHA }: Uploaded`, {
			timeout: 30_000,
		} );
		await expect( row ).toHaveClass(
			/kntnt-photo-drop-drop-zone__status-item--uploaded/
		);
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__summary' )
		).toHaveText( '1 uploaded · 0 skipped · 0 failed' );

		// Server truth: the stored main exists on disk and is served from
		// the collection directory as WebP.
		const stored = await page.request.get(
			storedImageUrl( slug, FIXTURE_ALPHA )
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

		// The row settles on the skipped state and the summary agrees.
		const row = page.locator( '.kntnt-photo-drop-drop-zone__status-item' );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toHaveText(
			`${ FIXTURE_ALPHA }: Skipped — already present`,
			{ timeout: 30_000 }
		);
		await expect( row ).toHaveClass(
			/kntnt-photo-drop-drop-zone__status-item--skipped/
		);
		await expect(
			page.locator( '.kntnt-photo-drop-drop-zone__summary' )
		).toHaveText( '0 uploaded · 1 skipped · 0 failed' );
	} );
} );
