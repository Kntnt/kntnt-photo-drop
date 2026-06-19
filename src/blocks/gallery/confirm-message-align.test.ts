/**
 * Jest test for the trash overlay confirm popover's message alignment.
 *
 * The inline confirm popover (ADR-0015) is an absolutely-positioned panel on its
 * own dark chrome; its prompt must read as a normal left-to-right (start-aligned)
 * sentence regardless of the surrounding gallery or theme alignment. Without an
 * explicit `text-align`, the prompt inherits the ancestor's alignment — often
 * `center` in a centred gallery context — and renders centred (issue #61). The fix
 * is a single explicit declaration on the message rule, and that declaration is
 * exactly what this test pins.
 *
 * The behaviour is purely a compiled-CSS property, so the lowest layer that
 * constrains it meaningfully is the shipped stylesheet. The block's `build/` output
 * is committed to git and is the exact CSS WordPress serves, so the test reads the
 * built `style-index.css` and asserts the message rule carries an explicit
 * start-side `text-align`. An explicit declaration is the only thing that overrides
 * the inherited alignment, and asserting on the committed build also keeps the build
 * in lock-step with the source.
 *
 * @since 0.15.0
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The shipped declaration block for a single exact selector.
 *
 * Reads the committed, minified gallery stylesheet and returns the body of the rule
 * whose selector is exactly `.<className>` — the property declarations between its
 * braces — so a test can assert on one rule without coupling to the rest of the
 * sheet.
 *
 * @param className - The bare class name (no leading dot) to look up.
 * @return The matched rule's declaration body, or `null` when the selector is absent.
 */
function ruleBody( className: string ): string | null {
	// Read the committed, built gallery stylesheet — the exact CSS WordPress serves;
	// asserting on the shipped artifact also forces the build to track the source.
	const cssPath = resolve(
		__dirname,
		'../../../build/blocks/gallery/style-index.css'
	);
	const css = readFileSync( cssPath, 'utf8' );

	// Extract the body of the rule whose selector is exactly the given class; the
	// anchored selector avoids matching a descendant or modifier rule by accident.
	const pattern = new RegExp(
		`\\.${ className.replace( /[-]/g, '\\$&' ) }\\{([^}]*)\\}`
	);
	const match = pattern.exec( css );
	return match ? match[ 1 ] : null;
}

describe( 'trash confirm popover message alignment', () => {
	it( 'declares a start-side text-align on the confirm message', () => {
		const body = ruleBody( 'kntnt-photo-drop-gallery__confirm__message' );

		// The message rule must exist and must set text-align explicitly to the start
		// side, so the prompt reads left-to-right even inside a centred gallery context;
		// `left` is the start side for the plugin's LTR default (the RTL build flips it).
		expect( body ).not.toBeNull();
		expect( body ).toMatch( /text-align:\s*left\b/ );
	} );
} );
