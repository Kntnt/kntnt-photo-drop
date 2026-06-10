/**
 * Playwright configuration for the block end-to-end suite.
 *
 * Mirrors the recommended config that `@wordpress/scripts` ships
 * (`config/playwright.config.js`), adapted to this repo: the suite runs
 * against the `@wordpress/env` development instance on port 8888 (where the
 * plugin's WP-CLI surface lives in the `cli` container), specs sit next to
 * this file, and artifacts stay inside `tests/e2e/` so they are easy to
 * ignore. The `WP_BASE_URL` / `STORAGE_STATE_PATH` environment defaults are
 * set here because `@wordpress/e2e-test-utils-playwright` reads both at
 * import time in every worker, and Playwright re-evaluates this config file
 * in each worker process — the same mechanism the wp-scripts config relies
 * on.
 *
 * @since 0.2.0
 */

import * as path from 'path';
import { defineConfig, devices } from '@playwright/test';
// Side effect: register the Node loader hooks that transpile the TS-source
// `@wordpress/e2e-test-utils-playwright` package. Playwright re-evaluates
// this config in every process, so the hooks exist wherever specs load.
import './support/transpile-wp-e2e-utils';

// Point the e2e-test-utils at the wp-env development instance and park the
// authenticated storage state under tests/e2e/.auth/ (gitignored). Both env
// vars are read by the package's config module on import, so they must be
// set before any spec imports it.
process.env.WP_BASE_URL ??= 'http://localhost:8888';
process.env.STORAGE_STATE_PATH ??= path.join(
	__dirname,
	'.auth',
	'admin.json'
);

const config = defineConfig( {
	reporter: process.env.CI
		? [
				[ 'github' ],
				[
					'html',
					{
						outputFolder: path.join(
							__dirname,
							'playwright-report'
						),
						open: 'never',
					},
				],
		  ]
		: [
				[ 'list' ],
				[
					'html',
					{
						outputFolder: path.join(
							__dirname,
							'playwright-report'
						),
						open: 'never',
					},
				],
		  ],
	forbidOnly: !! process.env.CI,
	// One worker: the suite shares a single WordPress with the integration
	// layer, and the specs seed and delete real on-disk collections.
	workers: 1,
	retries: process.env.CI ? 2 : 0,
	timeout: 100_000,
	// The single worker runs files in series, so per-file slowness reporting
	// is noise.
	reportSlowTests: null,
	testDir: '.',
	testMatch: '**/*.spec.ts',
	outputDir: path.join( __dirname, 'test-results' ),
	globalSetup: path.join( __dirname, 'global-setup.ts' ),
	expect: {
		// ServerSideRender previews and the in-browser Canvas→WebP upload
		// settle slower than Playwright's 5 s default.
		timeout: 10_000,
	},
	use: {
		baseURL: process.env.WP_BASE_URL,
		headless: true,
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
	},
	webServer: {
		command: 'npm run wp-env start',
		port: 8888,
		timeout: 120_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );

export default config;
