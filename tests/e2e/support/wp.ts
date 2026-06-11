/**
 * WordPress-side helpers for the e2e suite.
 *
 * Collections are seeded and torn down through the plugin's own WP-CLI
 * surface inside the wp-env `cli` container, shelled from the host via
 * `npx wp-env run cli wp …`. The argv-array form survives wp-env's argument
 * forwarding verbatim, so no shell quoting is involved. Every slug carries a
 * random suffix because a concurrent integration suite shares the same
 * WordPress instance.
 *
 * @since 0.2.0
 */

import { execFileSync } from 'child_process';
import * as path from 'path';
import { CONTAINER_FIXTURES_DIR } from './fixture-images';

/**
 * Runs one `wp …` command in the wp-env `cli` container and returns stdout.
 *
 * Throws when the command exits non-zero, so a failed seed fails the suite
 * loudly instead of letting specs run against a half-built collection.
 *
 * @since 0.2.0
 *
 * @param args - The argv tail after `wp`, e.g. `[ 'kntnt-photo-drop', … ]`.
 * @return The command's stdout.
 */
export function wpCli( args: readonly string[] ): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
	} );
}

/**
 * Builds a collision-free collection slug for one spec run.
 *
 * The `e2e-` prefix marks the collection as this suite's, and the random
 * suffix keeps repeated runs and the concurrent integration suite from
 * colliding on the shared WordPress instance.
 *
 * @since 0.2.0
 *
 * @param label - A short spec identifier, e.g. `editor`.
 * @return A slug such as `e2e-editor-k3f9q2`.
 */
export function uniqueSlug( label: string ): string {
	return `e2e-${ label }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

/**
 * Creates a collection with the suite's standard contract (1920 px, q80).
 *
 * @since 0.2.0
 *
 * @param slug - The collection slug.
 */
export function createCollection( slug: string ): void {
	wpCli( [
		'kntnt-photo-drop',
		'collection',
		'create',
		slug,
		'--max-width=1920',
		'--quality=80',
	] );
}

/**
 * Deletes a collection and everything in it.
 *
 * Swallows failures so an `afterAll` cleanup never masks the real test
 * failure when seeding itself broke and the collection never existed.
 *
 * @since 0.2.0
 *
 * @param slug - The collection slug.
 */
export function deleteCollection( slug: string ): void {
	try {
		wpCli( [ 'kntnt-photo-drop', 'collection', 'delete', slug, '--yes' ] );
	} catch {
		// Cleanup is best-effort; the collection may never have been created.
	}
}

/**
 * Imports one generated fixture into a collection, flat at its root.
 *
 * The source is passed as an absolute container path, which the CLI
 * collapses to its basename — so `e2e-alpha.jpg` is stored as
 * `e2e-alpha.jpg.webp` at the collection root.
 *
 * @since 0.2.0
 *
 * @param slug        - The collection slug.
 * @param fixtureName - A fixture filename from `fixture-images.ts`.
 */
export function importFixture( slug: string, fixtureName: string ): void {
	wpCli( [
		'kntnt-photo-drop',
		'image',
		'import',
		slug,
		// POSIX join: the path is evaluated inside the Linux container.
		`${ CONTAINER_FIXTURES_DIR }/${ fixtureName }`,
	] );
}

/**
 * Builds an absolute URL on the wp-env instance under test.
 *
 * Reads `WP_BASE_URL` (defaulted by `playwright.config.ts`), for the places
 * a config-inherited `baseURL` is not guaranteed — fresh contexts created
 * with `browser.newContext()` and direct API requests.
 *
 * @since 0.2.0
 *
 * @param pathname - A site-relative path, e.g. `/?page_id=7`.
 * @return The absolute URL.
 */
export function siteUrl( pathname: string ): string {
	const base = process.env.WP_BASE_URL ?? 'http://localhost:8888';
	return new URL( pathname, base ).toString();
}

/**
 * The public URL a stored main image is served from.
 *
 * Collections live under `wp_upload_dir()` at
 * `uploads/kntnt-photo-drop/<slug>/`, and a stored main is the original
 * filename with `.webp` appended (ADR-0003). When the collection namespaces
 * per uploader (`uploaderFolders`, on by default — ADR-0008), a Drop Zone
 * upload lands under a first segment derived from the uploader's nicename, so
 * the caller passes that segment to address the prefixed location.
 *
 * @since 0.2.0
 *
 * @param slug           - The collection slug.
 * @param originalName   - The uploaded file's original name, e.g. `a.jpg`.
 * @param uploaderFolder - The uploader-folder segment, or '' for the bare root.
 * @return The absolute URL of the stored `<originalName>.webp`.
 */
export function storedImageUrl(
	slug: string,
	originalName: string,
	uploaderFolder = ''
): string {
	return siteUrl(
		path.posix.join(
			'/wp-content/uploads/kntnt-photo-drop',
			slug,
			uploaderFolder,
			`${ originalName }.webp`
		)
	);
}
