/**
 * Jest test for the trash overlay confirm popover's message alignment.
 *
 * The inline confirm popover (ADR-0015) is an absolutely-positioned panel on its
 * own dark chrome; its prompt must read as a normal left-to-right (start-aligned)
 * sentence regardless of the surrounding gallery or theme alignment. Because the
 * `&__confirm__message` rule sets no `text-align`, the prompt inherits the
 * ancestor's alignment — often `center` in a centred gallery context — and renders
 * centred (issue #61). The fix is a single explicit declaration on the message
 * rule, and that declaration is exactly what this test pins.
 *
 * The behaviour is purely a compiled-CSS property, so the lowest layer that
 * constrains it meaningfully is to compile the block's `style.scss` with Dart Sass
 * (the same compiler `@wordpress/scripts` uses) and assert that the message rule
 * carries an explicit start-side `text-align`. An explicit declaration is the only
 * thing that overrides the inherited alignment, so pinning it here guards the fix
 * against a later edit silently dropping it.
 *
 * @since 0.13.1
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { compileString } from 'sass';

/**
 * The compiled-CSS declaration block for a single exact selector.
 *
 * Compiles the gallery stylesheet and returns the body of the rule whose selector
 * is exactly `.<className>` — the property declarations between its braces — so a
 * test can assert on one rule without coupling to the order of the whole sheet.
 *
 * @param className - The bare class name (no leading dot) to look up.
 * @return The matched rule's declaration body, or `null` when the selector is absent.
 */
function ruleBody( className: string ): string | null {
	// Compile the gallery stylesheet from source with Dart Sass; loading the SCSS
	// rather than the committed build keeps the test honest about the source of truth.
	const source = readFileSync( resolve( __dirname, 'style.scss' ), 'utf8' );
	const css = compileString( source, { loadPaths: [ __dirname ] } ).css;

	// Extract the body of the rule whose selector is exactly the given class; the
	// anchored selector avoids matching a descendant or modifier rule by accident.
	const pattern = new RegExp(
		`\\.${ className.replace( /[-]/g, '\\$&' ) }\\s*\\{([^}]*)\\}`
	);
	const match = pattern.exec( css );
	return match ? match[ 1 ] : null;
}

describe( 'trash confirm popover message alignment', () => {
	it( 'declares a start-side text-align on the confirm message', () => {
		const body = ruleBody( 'kntnt-photo-drop-gallery__confirm__message' );

		// The message rule must exist and must set text-align explicitly to the start
		// side, so the prompt reads left-to-right even inside a centred gallery context;
		// `left` is the start side for the plugin's LTR default (RTL flips via direction).
		expect( body ).not.toBeNull();
		expect( body ).toMatch( /text-align:\s*left\b/ );
	} );
} );
