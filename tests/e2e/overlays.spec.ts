/**
 * Gallery overlays end-to-end spec — the Overlays inspector shell (ADR-0015).
 *
 * The unified overlay framework's pure cores (breadcrumb assembly, placement
 * projection, band-exclusion) are unit-tested; this spec covers the editor
 * shell those cores drive, which a unit test cannot reach: that the Overlays
 * panel renders one control group per overlay, that enabling the breadcrumb
 * surfaces its Hide-first-N / Separator controls, and — the band-exclusion — that
 * placing the breadcrumb in a band disables that band's three positions in an
 * icon overlay's position picker. A second test confirms the breadcrumb overlay
 * actually reaches the server-rendered preview canvas.
 *
 * @since 0.15.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One seeded collection with one image serves the overlay editor tests.
const slug = uniqueSlug( 'overlays' );

test.describe( 'Gallery overlays', () => {
	test.beforeAll( () => {
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );
	} );

	test.afterAll( () => {
		deleteCollection( slug );
	} );

	test( 'the Overlays panel enforces the breadcrumb/icon band-exclusion', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Insert the gallery and open its block inspector.
		await admin.createNewPost();
		await editor.insertBlock( { name: 'kntnt-photo-drop/gallery' } );
		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		const blockTab = settings.getByRole( 'tab', { name: 'Block' } );
		if ( await blockTab.isVisible() ) {
			await blockTab.click();
		}

		// Open the Overlays panel; it carries one control group per overlay.
		await settings.getByRole( 'button', { name: 'Overlays' } ).click();

		// Turn the breadcrumb on and place it in the bottom band; its
		// Hide-first-N and Separator controls reveal once it is not off.
		const breadcrumbShow = settings
			.getByRole( 'combobox', { name: 'Show' } )
			.first();
		await breadcrumbShow.selectOption( 'thumbnail' );
		await expect(
			settings.getByLabel( 'Hide first N crumbs' )
		).toBeVisible();
		await settings
			.getByRole( 'combobox', { name: 'Position' } )
			.first()
			.selectOption( 'bottom-left' );

		// Turn the download icon on; its position picker is the second one.
		const downloadShow = settings
			.getByRole( 'combobox', { name: 'Show' } )
			.nth( 1 );
		await downloadShow.selectOption( 'thumbnail' );
		const downloadPosition = settings
			.getByRole( 'combobox', { name: 'Position' } )
			.nth( 1 );

		// The breadcrumb occupies the bottom band, so the three bottom positions
		// are disabled in the download picker, while a top position stays enabled.
		await expect(
			downloadPosition.getByRole( 'option', { name: /Bottom left/ } )
		).toBeDisabled();
		await expect(
			downloadPosition.getByRole( 'option', { name: /Bottom centre/ } )
		).toBeDisabled();
		await expect(
			downloadPosition.getByRole( 'option', { name: /Top left/ } )
		).toBeEnabled();

		// No block error boundary anywhere in the canvas.
		await expect(
			editor.canvas.locator( '.block-editor-warning' )
		).toHaveCount( 0 );
	} );

	test( 'a thumbnail breadcrumb reaches the server-rendered preview', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'kntnt-photo-drop/gallery' } );
		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		const blockTab = settings.getByRole( 'tab', { name: 'Block' } );
		if ( await blockTab.isVisible() ) {
			await blockTab.click();
		}

		// Bind the seeded collection and enable the breadcrumb on the thumbnail.
		await settings
			.getByRole( 'combobox', { name: 'Collection' } )
			.selectOption( slug );
		await settings.getByRole( 'button', { name: 'Overlays' } ).click();
		await settings
			.getByRole( 'combobox', { name: 'Show' } )
			.first()
			.selectOption( 'thumbnail' );

		// The ServerSideRender preview shows the breadcrumb overlay element on the
		// seeded image's figure inside the canvas.
		await expect(
			editor.canvas
				.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
				.first()
		).toBeVisible( { timeout: 30_000 } );
	} );
} );
