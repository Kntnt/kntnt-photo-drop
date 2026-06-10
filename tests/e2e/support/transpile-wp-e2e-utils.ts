/**
 * Node loader hooks that transpile `@wordpress/e2e-test-utils-playwright`.
 *
 * The package ships raw TypeScript (`exports` points at `src/index.ts`) and
 * leans on Babel-style CommonJS interop (e.g. named imports from the CJS
 * `mime` package). Playwright's own transpiler deliberately skips
 * `node_modules`, and Node's native type stripping refuses files under
 * `node_modules` outright — so on modern Node the import fails before any
 * test runs. These synchronous `module.registerHooks()` hooks close that
 * gap for exactly this one package: relative extensionless specifiers are
 * resolved to their `.ts`/`.js` sources, and each file is transpiled with
 * the repo's Babel (present via `@wordpress/scripts`) to CommonJS, which
 * reproduces the interop semantics the package was written for.
 *
 * Imported for its side effect from `playwright.config.ts`, which Playwright
 * re-evaluates in the runner process and in every worker — so the hooks are
 * registered wherever a spec or the global setup imports the package.
 *
 * @since 0.2.0
 */

import * as fs from 'fs';
import { registerHooks } from 'module';
import { fileURLToPath } from 'url';
// The transpiler the rest of the repo already uses, hoisted from
// @wordpress/scripts.
// eslint-disable-next-line import/no-extraneous-dependencies
import { transformSync } from '@babel/core';

/**
 * The path fragment identifying the one package these hooks act on.
 *
 * @since 0.2.0
 */
const PACKAGE_PATH = 'node_modules/@wordpress/e2e-test-utils-playwright/';

/**
 * Process-wide guard so re-evaluation of the config never stacks hooks.
 *
 * @since 0.2.0
 */
const REGISTERED_FLAG = Symbol.for( 'kntnt-photo-drop.e2eUtilsLoader' );

/**
 * Registers the resolve and load hooks once per process.
 *
 * The resolve hook maps the package's extensionless relative imports
 * (`'./admin'`) onto the real `.ts`/`.js` files; the load hook transpiles
 * those files to CommonJS. Everything outside the package falls through to
 * the default chain untouched.
 *
 * @since 0.2.0
 */
function registerOnce(): void {
	// A process only needs the hooks once, however many times Playwright
	// re-evaluates the config that imports this module.
	const holder = globalThis as { [ REGISTERED_FLAG ]?: boolean };
	if ( holder[ REGISTERED_FLAG ] ) {
		return;
	}
	holder[ REGISTERED_FLAG ] = true;

	registerHooks( {
		resolve( specifier, context, nextResolve ) {
			// Only the package's own relative imports need help; bare and
			// external specifiers resolve normally.
			if (
				! specifier.startsWith( '.' ) ||
				! context.parentURL?.includes( PACKAGE_PATH )
			) {
				return nextResolve( specifier, context );
			}

			// Try the source-layout candidates the TS compiler would: the
			// file itself with either extension, then a directory index.
			const base = new URL( specifier, context.parentURL ).href;
			const candidates = [
				`${ base }.ts`,
				`${ base }.js`,
				`${ base }/index.ts`,
				`${ base }/index.js`,
				base,
			];
			for ( const candidate of candidates ) {
				const stat = fs.statSync( fileURLToPath( candidate ), {
					throwIfNoEntry: false,
				} );
				if ( stat?.isFile() ) {
					return { url: candidate, shortCircuit: true };
				}
			}

			return nextResolve( specifier, context );
		},

		load( url, context, nextLoad ) {
			// Leave everything outside the package to the default chain.
			if (
				! url.includes( PACKAGE_PATH ) ||
				! ( url.endsWith( '.ts' ) || url.endsWith( '.js' ) )
			) {
				return nextLoad( url, context );
			}

			// Transpile TS and ESM syntax to CommonJS; Babel's interop is
			// what the package's named-imports-from-CJS rely on.
			const filename = fileURLToPath( url );
			const transformed = transformSync(
				fs.readFileSync( filename, 'utf8' ),
				{
					filename,
					babelrc: false,
					configFile: false,
					presets: [ '@babel/preset-typescript' ],
					plugins: [ '@babel/plugin-transform-modules-commonjs' ],
				}
			);
			if ( ! transformed?.code ) {
				throw new Error( `Could not transpile ${ filename }.` );
			}

			return {
				format: 'commonjs',
				source: transformed.code,
				shortCircuit: true,
			};
		},
	} );
}

registerOnce();
