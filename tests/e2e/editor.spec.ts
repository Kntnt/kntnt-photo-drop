/**
 * Editor end-to-end specs — the regression suite for the two editor bugs.
 *
 * Both blocks must insert into a new post without tripping the block error
 * boundary (`.block-editor-warning`). This failed two ways on the v0.1.0
 * codebase: the blocks did not register at all on the WordPress floor, and
 * opening the Photo Gallery's Layout panel crashed its UnitControl. These
 * tests pin both behaviours forever, and additionally assert that the
 * gallery's ServerSideRender preview reaches real gallery markup in the
 * canvas.
 *
 * @since 0.2.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One seeded collection with one image serves both editor tests.
const slug = uniqueSlug( 'editor' );

test.describe( 'Editor', () => {
	test.beforeAll( () => {
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );
	} );

	test.afterAll( () => {
		deleteCollection( slug );
	} );

	test( 'Photo Gallery inserts, its Layout panel renders, and the preview shows gallery markup', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Insert the gallery block into a fresh post; an unregistered block
		// makes this insertion itself throw.
		await admin.createNewPost();
		await editor.insertBlock( { name: 'kntnt-photo-drop/gallery' } );

		// Open the block inspector; the inserted block is selected, but the
		// sidebar may still be on the Post tab.
		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		const blockTab = settings.getByRole( 'tab', { name: 'Block' } );
		if ( await blockTab.isVisible() ) {
			await blockTab.click();
		}

		// Bind the block to the seeded collection; selectOption waits for
		// the REST-loaded option to appear.
		await settings
			.getByRole( 'combobox', { name: 'Collection' } )
			.selectOption( slug );

		// The Layout panel must open and render its Minimum-column-width
		// UnitControl — the control that crashed the block in v0.1.0.
		await settings.getByRole( 'button', { name: 'Layout' } ).click();
		await expect(
			settings.getByLabel( 'Minimum column width' )
		).toBeVisible();

		// No block error boundary anywhere in the canvas.
		await expect(
			editor.canvas.locator( '.block-editor-warning' )
		).toHaveCount( 0 );

		// The ServerSideRender preview eventually shows the seeded image as
		// real gallery markup inside the canvas.
		await expect(
			editor.canvas.locator( '.kntnt-photo-drop-gallery__item' ).first()
		).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'Photo Drop Zone inserts and renders its placeholder without a block error', async ( {
		admin,
		editor,
	} ) => {
		// Insert the drop-zone block into a fresh post.
		await admin.createNewPost();
		await editor.insertBlock( { name: 'kntnt-photo-drop/drop-zone' } );

		// The editor placeholder renders, titled with the block name.
		const preview = editor.canvas.locator(
			'.kntnt-photo-drop-drop-zone__preview'
		);
		await expect( preview ).toBeVisible();
		await expect( preview ).toContainText( 'Photo Drop Zone' );

		// No block error boundary anywhere in the canvas.
		await expect(
			editor.canvas.locator( '.block-editor-warning' )
		).toHaveCount( 0 );
	} );
} );
