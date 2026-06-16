/**
 * Project webpack config: the @wordpress/scripts default plus one admin entry.
 *
 * Block sources stay entirely on the @wordpress/scripts happy path — this file
 * imports the bundled default config unchanged and only *adds* the admin
 * regenerate script (`src/admin/regenerate.ts` → `build/admin/regenerate.js`) to
 * the **scripts** config's entry map. That script is not a block, so the default
 * block-entry resolver would never pick it up, yet it must share the Drop Zone's
 * progress view rather than duplicate it (ADR-0013); a one-entry extension is the
 * minimal way to build it through the same bundler.
 *
 * Under `WP_EXPERIMENTAL_MODULES` the default export is an array
 * `[ scriptsConfig, modulesConfig ]` (the second builds the block view modules);
 * the admin script is a classic script, so the entry is added to the scripts
 * config only and the modules config — and every loader, plugin, and preset —
 * passes through untouched.
 *
 * @see docs/coding-standards.md — "stay on @wordpress/scripts' happy path"; a
 *      project-specific entry is the documented reason to extend, not replace, it.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// The admin regenerate entry, added to whichever config builds classic scripts.
const adminEntry = {
	'admin/regenerate': path.resolve( __dirname, 'src/admin/regenerate.ts' ),
};

/**
 * Returns a copy of one webpack config with the admin entry merged in.
 *
 * The default `entry` is a function that resolves the block entries; this wraps it
 * so the resolved map gains the admin entry while every discovered block entry is
 * preserved. A plain-object `entry` (older shapes) is spread instead.
 *
 * @param {Object} config One @wordpress/scripts webpack config.
 * @return {Object} The config with the admin entry added.
 */
function withAdminEntry( config ) {
	const resolveEntries =
		typeof config.entry === 'function' ? config.entry : () => config.entry;
	return {
		...config,
		entry: ( ...args ) => ( {
			...resolveEntries( ...args ),
			...adminEntry,
		} ),
	};
}

// Under experimental modules the default is [ scriptsConfig, modulesConfig ];
// extend only the scripts config (index 0) and leave the modules config as-is.
// Otherwise the default is a single scripts config to extend directly.
module.exports = Array.isArray( defaultConfig )
	? [ withAdminEntry( defaultConfig[ 0 ] ), ...defaultConfig.slice( 1 ) ]
	: withAdminEntry( defaultConfig );
