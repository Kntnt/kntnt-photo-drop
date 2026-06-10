/**
 * Deterministic JPEG fixture generation for the e2e suite.
 *
 * The suite needs real JPEG bytes — the Drop Zone decodes them with
 * `createImageBitmap` and the server re-encodes them through GD/Imagick — but
 * checking binaries into git is avoidable: Playwright's own Chromium can
 * screenshot a solid-colour page as a JPEG of exact dimensions. Global setup
 * regenerates both fixtures on every run (a second of work), so the files are
 * build artifacts, live under the gitignored `tests/e2e/fixtures/`, and are
 * reachable inside the wp-env containers through the plugin mount.
 *
 * @since 0.2.0
 */

import * as fs from 'fs/promises';
import * as path from 'path';
import { chromium } from '@playwright/test';

/**
 * Host-side directory the generated fixtures land in.
 *
 * Inside the wp-env containers the same directory is reachable at
 * {@link CONTAINER_FIXTURES_DIR} through the plugin mount.
 *
 * @since 0.2.0
 */
export const FIXTURES_DIR = path.join( __dirname, '..', 'fixtures' );

/**
 * The same fixtures directory as seen from inside the wp-env containers.
 *
 * The plugin repo is mounted at `wp-content/plugins/kntnt-photo-drop`, so a
 * fixture written on the host is importable by `wp kntnt-photo-drop image
 * import` at this absolute container path. An absolute source path collapses
 * to its basename on import, which keeps seeded images flat at the
 * collection root.
 *
 * @since 0.2.0
 */
export const CONTAINER_FIXTURES_DIR =
	'/var/www/html/wp-content/plugins/kntnt-photo-drop/tests/e2e/fixtures';

/**
 * First fixture filename; sorts before {@link FIXTURE_BETA} so the gallery's
 * natural-sort ascending order is deterministic.
 *
 * @since 0.2.0
 */
export const FIXTURE_ALPHA = 'e2e-alpha.jpg';

/**
 * Second fixture filename; sorts after {@link FIXTURE_ALPHA}.
 *
 * @since 0.2.0
 */
export const FIXTURE_BETA = 'e2e-beta.jpg';

/**
 * The pixel sizes and colours the two fixtures are generated with.
 *
 * Both stay well under the seeded collections' 1920 px contract ceiling, so
 * a client- or server-side ingest stores them without downscaling, and the
 * distinct landscape/portrait shapes plus colours make the two images
 * trivially distinguishable in failure screenshots.
 *
 * @since 0.2.0
 */
const FIXTURE_RECIPES = [
	{ name: FIXTURE_ALPHA, width: 640, height: 480, color: '#3a7d44' },
	{ name: FIXTURE_BETA, width: 480, height: 640, color: '#9b2226' },
] as const;

/**
 * Generates both JPEG fixtures into {@link FIXTURES_DIR}.
 *
 * Launches one headless Chromium, renders each recipe as a solid-colour page
 * at the recipe's exact viewport, and screenshots it as a JPEG (quality 70,
 * ~4 KB each). Existing files are overwritten so every run starts from the
 * same bytes.
 *
 * @since 0.2.0
 */
export async function generateFixtureImages(): Promise< void > {
	// One browser serves both recipes; the per-page viewport fixes each
	// JPEG's dimensions.
	await fs.mkdir( FIXTURES_DIR, { recursive: true } );
	const browser = await chromium.launch();

	try {
		for ( const { name, width, height, color } of FIXTURE_RECIPES ) {
			const page = await browser.newPage( {
				viewport: { width, height },
			} );
			await page.setContent(
				`<body style="margin:0;background:${ color }"></body>`
			);
			await page.screenshot( {
				path: path.join( FIXTURES_DIR, name ),
				type: 'jpeg',
				quality: 70,
			} );
			await page.close();
		}
	} finally {
		await browser.close();
	}
}
