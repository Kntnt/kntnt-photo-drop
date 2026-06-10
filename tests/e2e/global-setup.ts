/**
 * Global setup: admin authentication and fixture generation.
 *
 * Follows the `@wordpress/e2e-test-utils-playwright` convention (mirrored
 * from the wp-scripts global setup): `RequestUtils.setupRest()` logs in as
 * `admin`/`password`, primes a REST nonce, and writes the cookie storage
 * state to `STORAGE_STATE_PATH` — the same file the config hands every
 * browser context and the worker-scoped `requestUtils` fixture reads back.
 * The JPEG fixtures are regenerated here too, so specs can rely on their
 * existence without ordering concerns.
 *
 * @since 0.2.0
 */

import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { generateFixtureImages } from './support/fixture-images';

/**
 * Authenticates against the wp-env instance and generates the fixtures.
 *
 * @since 0.2.0
 *
 * @param config - The resolved Playwright configuration.
 */
async function globalSetup( config: FullConfig ): Promise< void > {
	// Authenticate once and persist the storage state for every context and
	// the worker-scoped requestUtils fixture.
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );
	await requestUtils.setupRest();
	await requestContext.dispose();

	// Regenerate the deterministic JPEG fixtures the specs upload and seed.
	await generateFixtureImages();
}

export default globalSetup;
