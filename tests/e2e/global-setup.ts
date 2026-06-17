/**
 * Global setup: admin authentication and fixture generation.
 *
 * Follows the `@wordpress/e2e-test-utils-playwright` convention (mirrored
 * from the wp-scripts global setup): `RequestUtils.setupRest()` logs in as
 * `admin`/`password`, primes a REST nonce, and writes the cookie storage
 * state to `STORAGE_STATE_PATH` — the same file the config hands every
 * browser context and the worker-scoped `requestUtils` fixture reads back.
 * The JPEG fixtures are regenerated here too, so specs can rely on their
 * existence without ordering concerns, and the RTL language pack the
 * breadcrumb-overflow spec switches to is installed here once — a fresh
 * wp-env instance ships only `en_US`, so without this the spec's
 * `site switch-language` would error on a missing pack.
 *
 * @since 0.2.0
 */

import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { generateFixtureImages } from './support/fixture-images';
import { RTL_LOCALE, installSiteLanguage } from './support/wp';

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

	// Install the RTL language pack the breadcrumb-overflow spec switches to;
	// a fresh wp-env instance ships only en_US, so the switch would otherwise
	// error on a missing pack. Idempotent, so a re-run is harmless.
	installSiteLanguage( RTL_LOCALE );
}

export default globalSetup;
