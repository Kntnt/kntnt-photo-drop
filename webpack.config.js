/**
 * Project webpack config: the @wordpress/scripts default plus the admin entries.
 *
 * Block sources stay entirely on the @wordpress/scripts happy path — this file
 * imports the bundled default config unchanged and only *adds* the admin scripts
 * (`src/admin/regenerate.ts` → `build/admin/regenerate.js`, the regenerate UI that
 * shares the Drop Zone's progress view per ADR-0013; `src/admin/slug.ts` →
 * `build/admin/slug.js`, the Create-form slug on-blur default; and
 * `src/admin/width-clamp-dom.ts` → `build/admin/width-clamp.js`, the Create/Edit
 * tier-width live clamp) to the **scripts** config's entry map. None is a block, so
 * the default block-entry resolver would never pick them up; a small entry extension
 * is the minimal way to build them through the same bundler.
 *
 * Under `WP_EXPERIMENTAL_MODULES` the default export is an array
 * `[ scriptsConfig, modulesConfig ]` (the second builds the block view modules);
 * the admin scripts are classic scripts, so the entries are added to the scripts
 * config only and the modules config — and every loader, plugin, and preset —
 * passes through untouched.
 *
 * @see agents.d/coding-standards.md — "stay on @wordpress/scripts' happy path"; a
 *      project-specific entry is the documented reason to extend, not replace, it.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// The admin entries, added to whichever config builds classic scripts: the
// regenerate UI (src/admin/regenerate.ts), the Create-form slug on-blur default
// (src/admin/slug.ts), and the Create/Edit tier-width live clamp
// (src/admin/width-clamp-dom.ts). None is a block, so the default block-entry
// resolver would never pick them up.
const adminEntry = {
	'admin/regenerate': path.resolve( __dirname, 'src/admin/regenerate.ts' ),
	'admin/slug': path.resolve( __dirname, 'src/admin/slug.ts' ),
	'admin/width-clamp': path.resolve(
		__dirname,
		'src/admin/width-clamp-dom.ts'
	),
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
