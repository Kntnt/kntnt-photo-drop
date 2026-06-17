/**
 * Gallery breadcrumb default-colour end-to-end spec — robust against theme
 * `figcaption` rules (issue #60).
 *
 * The breadcrumb overlay is a `<figcaption>`, and its legible default appearance
 * (white text on a semi-transparent dark band) must read on an unstyled image.
 * That default once lived in a zero-specificity `:where()` rule, so an ordinary
 * theme rule such as `div[class*="wp-block-"] figcaption { color: … }`
 * (specificity 0-2-1) beat it and the breadcrumb took the theme's caption colour.
 * Only a real browser resolves that cascade against a competing theme rule, so —
 * like `gallery-shadow.spec.ts` — this is pinned at the e2e layer, not faked in a
 * unit assertion over CSS text.
 *
 * Three facts are pinned. With a theme `figcaption` rule present and **no**
 * Colour-panel selection, the breadcrumb still computes to the plugin's default
 * white-on-dark (both foreground and background, per the acceptance criteria). And
 * a Colour-panel foreground/background selection still wins over both the default
 * and the same theme rule — the `:where()` was chosen so a panel selection could
 * override the default, and the fix must not regress that. A panel selection
 * reaches the breadcrumb two ways, and acceptance criterion 2 covers both, so both
 * are pinned here: a *custom* value (a typed hex stored under `style.color`, which
 * the projection serialises as an inline declaration that outranks every selector)
 * and a *preset* value (a palette slug stored under `textColor` / `backgroundColor`,
 * which the projection serialises as a `has-…-color` class — whose theme.json-
 * derived global rule carries `var( --wp--preset--color--… ) !important`, beating
 * the non-important 0-3-0 default and the ordinary theme rule on the `!important`
 * flag). The new default rule's 0-3-0 specificity could in principle interact with
 * the preset path's cascade differently from the custom path, so the preset path
 * gets its own browser-resolved assertion rather than riding on the custom one.
 *
 * @since 0.14.0
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { FIXTURE_ALPHA } from './support/fixture-images';
import {
	createCollection,
	deleteCollection,
	importFixture,
	uniqueSlug,
} from './support/wp';

// One seeded collection serves three galleries: a default one, one carrying a
// custom (typed-hex) Colour-panel selection, and one carrying a preset (palette
// slug) Colour-panel selection.
const slug = uniqueSlug( 'breadcrumb-colours' );
let defaultPageId = 0;
let pickedPageId = 0;
let presetPageId = 0;

// A theme rule that styles every block caption — the canonical shape from the
// issue (specificity 0-2-1), beating the old zero-specificity default. Injecting
// it *after* the document loads also places it last in source order, so a
// specificity tie would resolve in its favour: the breadcrumb default must win on
// specificity alone, not on source order.
const THEME_FIGCAPTION_CSS =
	'div[class*="wp-block-"] figcaption {' +
	'color: rgb(0, 128, 0);' +
	'background: rgb(255, 255, 0);' +
	'}';

// The custom (typed-hex) Colour-panel values, asserted verbatim as computed RGB.
const PICKED_FG = 'rgb(18, 52, 86)';
const PICKED_BG = 'rgb(171, 205, 239)';

// The preset (palette-slug) Colour-panel selection: two Twenty Twenty-Five
// palette slugs and the RGB each `var( --wp--preset--color--… )` resolves to.
// `accent-3` is #503AA8 and `accent-1` is #FFEE58 in this theme; both carry a
// theme.json-derived `has-…-color { … !important }` global rule, which is the
// mechanism the preset half of acceptance criterion 2 relies on.
const PRESET_FG_SLUG = 'accent-3';
const PRESET_BG_SLUG = 'accent-1';
const PRESET_FG = 'rgb(80, 58, 168)';
const PRESET_BG = 'rgb(255, 238, 88)';

test.describe( 'Gallery breadcrumb default colours', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Seed a one-image collection and publish three galleries with breadcrumbs on
		// the thumbnail: one at its defaults, one with a custom foreground/background
		// typed into the Colour panel (stored under style.color.text / .background),
		// and one with a palette foreground/background picked from the Colour panel
		// (stored as the textColor / backgroundColor preset shorthands).
		createCollection( slug );
		importFixture( slug, FIXTURE_ALPHA );

		const defaultPage = await requestUtils.createPage( {
			title: `E2E Breadcrumb default ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ slug }","breadcrumbsVisibility":"thumbnail"} /-->`,
			status: 'publish',
		} );
		defaultPageId = defaultPage.id;

		const pickedPage = await requestUtils.createPage( {
			title: `E2E Breadcrumb picked ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ slug }","breadcrumbsVisibility":"thumbnail",` +
				`"style":{"color":{"text":"#123456","background":"#abcdef"}}} /-->`,
			status: 'publish',
		} );
		pickedPageId = pickedPage.id;

		const presetPage = await requestUtils.createPage( {
			title: `E2E Breadcrumb preset ${ slug }`,
			content:
				`<!-- wp:kntnt-photo-drop/gallery ` +
				`{"collection":"${ slug }","breadcrumbsVisibility":"thumbnail",` +
				`"textColor":"${ PRESET_FG_SLUG }",` +
				`"backgroundColor":"${ PRESET_BG_SLUG }"} /-->`,
			status: 'publish',
		} );
		presetPageId = presetPage.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove every page and the collection so reruns start clean.
		for ( const id of [ defaultPageId, pickedPageId, presetPageId ] ) {
			if ( id !== 0 ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/pages/${ id }`,
					params: { force: true },
				} );
			}
		}
		deleteCollection( slug );
	} );

	test( 'the default breadcrumb survives a theme figcaption rule', async ( {
		page,
	} ) => {
		// Visit the default gallery exactly as the public sees it.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ defaultPageId }` );

		// Inject a theme rule that colours every block caption, last in source order.
		await page.addStyleTag( { content: THEME_FIGCAPTION_CSS } );

		// The breadcrumb still computes to the plugin's default white-on-dark — the
		// theme's caption colour and background do not reach it (both halves).
		const breadcrumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( breadcrumb ).toBeVisible();
		const { color, background } = await breadcrumb.evaluate( ( el ) => {
			const style = getComputedStyle( el );
			return {
				color: style.color,
				background: style.backgroundColor,
			};
		} );
		expect( color ).toBe( 'rgb(255, 255, 255)' );
		expect( background ).toBe( 'rgba(0, 0, 0, 0.5)' );
	} );

	test( 'a custom Colour-panel selection still overrides the default and the theme rule', async ( {
		page,
	} ) => {
		// Visit the gallery whose breadcrumb carries a custom (typed-hex)
		// foreground/background.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ pickedPageId }` );

		// Inject the same theme caption rule; the picked colours must beat both it
		// and the plugin default.
		await page.addStyleTag( { content: THEME_FIGCAPTION_CSS } );

		// The breadcrumb computes to the picked colours, not the default and not the
		// theme rule — the inline declaration the projection emits outranks both.
		const breadcrumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( breadcrumb ).toBeVisible();
		const { color, background } = await breadcrumb.evaluate( ( el ) => {
			const style = getComputedStyle( el );
			return {
				color: style.color,
				background: style.backgroundColor,
			};
		} );
		expect( color ).toBe( PICKED_FG );
		expect( background ).toBe( PICKED_BG );
	} );

	test( 'a preset Colour-panel selection still overrides the default and the theme rule', async ( {
		page,
	} ) => {
		// Visit the gallery whose breadcrumb carries a palette (preset-slug)
		// foreground/background.
		await page.context().clearCookies();
		await page.goto( `/?page_id=${ presetPageId }` );

		// Inject the same theme caption rule; the preset colours must beat both it
		// and the plugin default — here through the preset path, not the inline one.
		await page.addStyleTag( { content: THEME_FIGCAPTION_CSS } );

		// The projection carries a preset selection onto the figcaption as the
		// standard has-…-color classes — the load-bearing fact, since their global
		// `!important` rule is what wins. Pin the classes before the computed colour
		// so a regression in the projection is told apart from a cascade regression.
		const breadcrumb = page
			.locator( '.kntnt-photo-drop-gallery__breadcrumbs' )
			.first();
		await expect( breadcrumb ).toBeVisible();
		await expect( breadcrumb ).toHaveClass(
			new RegExp( `has-${ PRESET_FG_SLUG }-color` )
		);
		await expect( breadcrumb ).toHaveClass(
			new RegExp( `has-${ PRESET_BG_SLUG }-background-color` )
		);

		// The breadcrumb computes to the preset colours, not the default and not the
		// theme rule.
		const { color, background } = await breadcrumb.evaluate( ( el ) => {
			const style = getComputedStyle( el );
			return {
				color: style.color,
				background: style.backgroundColor,
			};
		} );
		expect( color ).toBe( PRESET_FG );
		expect( background ).toBe( PRESET_BG );
	} );
} );
