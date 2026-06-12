/**
 * Project ESLint configuration.
 *
 * The `@wordpress/scripts` bundled flat config verbatim — the block code
 * stays on the wp-scripts happy path — with one addition: the Playwright
 * artifact directories are ignored. The HTML report bundles megabytes of
 * minified JavaScript that ESLint would otherwise crawl through (for
 * minutes) whenever `lint:js` runs after an e2e run; all three directories
 * are gitignored build output, never source.
 *
 * @since 0.9.0
 */

const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [
			'tests/e2e/playwright-report/**',
			'tests/e2e/test-results/**',
			'tests/e2e/.auth/**',
		],
	},
	...wpConfig,
];
