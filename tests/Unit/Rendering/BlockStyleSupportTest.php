<?php
/**
 * Unit tests for the block-support style projection helper.
 *
 * Issue #33 moved the gallery's caption colour/typography and the per-image
 * border/shadow off bespoke attributes and onto WordPress block-support panels,
 * serialised onto the right sub-element with `__experimentalSkipSerialization`
 * (the core Image-block pattern). `Block_Style_Support` is the server side of
 * that move: it reads the block's `style` subtree (and the preset shorthand
 * attributes a palette/preset choice writes at the top level) and projects them
 * into the inline `style` declarations and preset classnames for one
 * sub-element — the `<figcaption>` (colour + typography) or each `<img>`
 * (border + shadow). The projection delegates the CSS assembly to the core
 * style engine (`wp_style_engine_get_styles`), so these tests stub that seam to
 * the shape core returns and pin only the slicing logic this helper owns: which
 * style subtree and which preset attributes feed each sub-element.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Rendering\Block_Style_Support;

// ---------------------------------------------------------------------------
// Harness — stub the style engine to a deterministic, inspectable shape
// ---------------------------------------------------------------------------

/**
 * Stubs `wp_style_engine_get_styles` with a minimal but faithful projection.
 *
 * The real engine maps a `block_styles` subtree to a declarations string and
 * the standard preset classnames. The stub reproduces just enough of that for
 * the slicing tests: it walks the documented colour / typography / border /
 * shadow keys, emits a `prop:value;` declaration per custom value (converting a
 * `var:preset|type|slug` token to its CSS custom property), and emits the
 * standard classnames for the preset tokens. This lets the tests assert which
 * subtree reached the engine without depending on core's full CSS machinery.
 *
 * @return void
 */
function stub_style_engine(): void {

	Functions\when( 'wp_style_engine_get_styles' )->alias(
		static function ( array $block_styles ): array {
			$declarations = [];
			$classnames   = [];

			// Colour: text → color, background → background-color, gradient → background.
			$color = $block_styles['color'] ?? [];
			foreach (
				[
					'text'       => 'color',
					'background' => 'background-color',
					'gradient'   => 'background',
				] as $key => $prop
			) {
				$value = $color[ $key ] ?? null;
				if ( is_string( $value ) && $value !== '' ) {
					$preset = style_engine_preset_class( $value, $key );
					if ( $preset !== null ) {
						$classnames[] = $preset;
					}
					$declarations[] = $prop . ':' . style_engine_resolve( $value ) . ';';
				}
			}

			// Typography: a couple of representative properties keyed exactly as core.
			$typography = $block_styles['typography'] ?? [];
			foreach ( [
				'fontSize'   => 'font-size',
				'lineHeight' => 'line-height',
				'fontFamily' => 'font-family',
			] as $key => $prop ) {
				$value = $typography[ $key ] ?? null;
				if ( is_string( $value ) && $value !== '' ) {
					$preset = style_engine_preset_class( $value, $key );
					if ( $preset !== null ) {
						$classnames[] = $preset;
					}
					$declarations[] = $prop . ':' . style_engine_resolve( $value ) . ';';
				}
			}

			// Border: the four flat properties plus radius.
			$border = $block_styles['border'] ?? [];
			foreach ( [
				'color'  => 'border-color',
				'width'  => 'border-width',
				'style'  => 'border-style',
				'radius' => 'border-radius',
			] as $key => $prop ) {
				$value = $border[ $key ] ?? null;
				if ( is_string( $value ) && $value !== '' ) {
					$declarations[] = $prop . ':' . style_engine_resolve( $value ) . ';';
				}
			}

			// Shadow: a single box-shadow declaration.
			$shadow = $block_styles['shadow'] ?? null;
			if ( is_string( $shadow ) && $shadow !== '' ) {
				$declarations[] = 'box-shadow:' . style_engine_resolve( $shadow ) . ';';
			}

			return [
				'css'          => implode( '', $declarations ),
				'declarations' => $declarations,
				'classnames'   => implode( ' ', $classnames ),
			];
		}
	);

}

/**
 * Converts a `var:preset|type|slug` token to its CSS custom property reference.
 *
 * A plain custom value (a hex colour, a length) passes through unchanged.
 *
 * @param string $value The style value, possibly a preset token.
 * @return string The resolved CSS value.
 */
function style_engine_resolve( string $value ): string {
	if ( preg_match( '/^var:preset\|([a-z-]+)\|(.+)$/', $value, $m ) === 1 ) {
		return sprintf( 'var(--wp--preset--%s--%s)', str_replace( 'fontSize', 'font-size', $m[1] ), $m[2] );
	}
	return $value;
}

/**
 * Returns the standard preset classname for a preset token, or null otherwise.
 *
 * @param string $value The style value, possibly a preset token.
 * @param string $key   The style key the value sits under.
 * @return string|null The classname, or null when the value is not a preset.
 */
function style_engine_preset_class( string $value, string $key ): ?string {
	if ( preg_match( '/^var:preset\|([a-z-]+)\|(.+)$/', $value, $m ) !== 1 ) {
		return null;
	}
	$slug = $m[2];
	return match ( $key ) {
		'text'       => "has-{$slug}-color has-text-color",
		'background' => "has-{$slug}-background-color has-background",
		'gradient'   => "has-{$slug}-gradient-background has-background",
		'fontSize'   => "has-{$slug}-font-size",
		'fontFamily' => "has-{$slug}-font-family",
		default      => null,
	};
}

// ---------------------------------------------------------------------------
// caption() — colour + typography only, from the style subtree
// ---------------------------------------------------------------------------

test( 'a custom caption text colour becomes an inline color declaration', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[ 'style' => [ 'color' => [ 'text' => '#ff0000' ] ] ],
	);

	// The custom hex is projected to the figcaption as an inline declaration with
	// no preset classname (a custom value carries no preset class).
	expect( $result['style'] )->toContain( 'color:#ff0000;' );
	expect( $result['class'] )->toBe( '' );

} );

test( 'a custom overlay background becomes an inline background-color declaration', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[ 'style' => [ 'color' => [ 'background' => 'rgba(0,0,0,0.6)' ] ] ],
	);

	expect( $result['style'] )->toContain( 'background-color:rgba(0,0,0,0.6);' );

} );

test( 'a preset text colour becomes a custom-property declaration plus the preset classname', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[ 'textColor' => 'vivid-red' ],
	);

	// A palette choice is stored as the top-level textColor attribute; the helper
	// must feed it to the engine as a preset token so it yields both the var()
	// declaration and the standard has-*-color classname on the figcaption.
	expect( $result['style'] )->toContain( 'color:var(--wp--preset--color--vivid-red);' );
	expect( $result['class'] )->toContain( 'has-vivid-red-color' );
	expect( $result['class'] )->toContain( 'has-text-color' );

} );

test( 'a preset font size becomes its custom property plus the preset classname', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[ 'fontSize' => 'large' ],
	);

	expect( $result['style'] )->toContain( 'font-size:var(--wp--preset--font-size--large);' );
	expect( $result['class'] )->toContain( 'has-large-font-size' );

} );

test( 'a custom typography value is projected to the figcaption', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[ 'style' => [ 'typography' => [ 'lineHeight' => '1.8' ] ] ],
	);

	expect( $result['style'] )->toContain( 'line-height:1.8;' );

} );

test( 'caption ignores border and shadow — those belong to the image', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption(
		[
			'style'  => [
				'color'  => [ 'text' => '#fff' ],
				'border' => [ 'width' => '2px' ],
			],
			'shadow' => 'var:preset|shadow|deep',
		],
	);

	// The caption sub-element carries colour/typography only; border and shadow
	// must not leak onto it (they are projected onto each image instead).
	expect( $result['style'] )->toContain( 'color:#fff;' );
	expect( $result['style'] )->not->toContain( 'border-width' );
	expect( $result['style'] )->not->toContain( 'box-shadow' );

} );

test( 'caption with no colour or typography yields empty style and class', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::caption( [] );

	expect( $result['style'] )->toBe( '' );
	expect( $result['class'] )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// image() — border + shadow only
// ---------------------------------------------------------------------------

test( 'a custom border is projected to the image', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::image(
		[
			'style' => [
				'border' => [
					'width' => '3px',
					'style' => 'solid',
					'color' => '#0000ff',
				],
			],
		],
	);

	expect( $result['style'] )->toContain( 'border-width:3px;' );
	expect( $result['style'] )->toContain( 'border-style:solid;' );
	expect( $result['style'] )->toContain( 'border-color:#0000ff;' );

} );

test( 'a custom border radius is projected to the image', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::image(
		[ 'style' => [ 'border' => [ 'radius' => '8px' ] ] ],
	);

	expect( $result['style'] )->toContain( 'border-radius:8px;' );

} );

test( 'a preset shadow becomes a box-shadow custom-property declaration', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::image(
		[ 'style' => [ 'shadow' => 'var:preset|shadow|deep' ] ],
	);

	// The shadow support stores its preset under style.shadow; the helper feeds it
	// to the engine, which resolves it to the box-shadow custom property.
	expect( $result['style'] )->toContain( 'box-shadow:var(--wp--preset--shadow--deep);' );

} );

test( 'image ignores colour and typography — those belong to the caption', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::image(
		[
			'style' => [
				'color'      => [ 'text' => '#fff' ],
				'typography' => [ 'fontSize' => '2rem' ],
				'border'     => [ 'width' => '1px' ],
			],
		],
	);

	// The image sub-element carries border/shadow only; caption colour/typography
	// must not leak onto each image.
	expect( $result['style'] )->toContain( 'border-width:1px;' );
	expect( $result['style'] )->not->toContain( 'color:#fff' );
	expect( $result['style'] )->not->toContain( 'font-size' );

} );

test( 'image with no border or shadow yields empty style and class', function (): void {

	stub_style_engine();
	$result = Block_Style_Support::image( [] );

	expect( $result['style'] )->toBe( '' );
	expect( $result['class'] )->toBe( '' );

} );

test( 'a custom box-shadow the engine omits from css is recovered from declarations', function (): void {

	// The real style engine returns a custom (non-preset) box-shadow only under
	// `declarations`, never folded into its `css` string (a preset shadow does reach
	// `css`). Reproduce that exact shape and prove the helper recovers the box-shadow
	// so the per-image Shadow support actually paints (the gallery-shadow regression).
	Functions\when( 'wp_style_engine_get_styles' )->justReturn(
		[
			'css'          => 'border-width:2px;',
			'declarations' => [
				'border-width' => '2px',
				'box-shadow'   => '6px 6px 12px rgba(0,0,0,0.6)',
			],
			'classnames'   => '',
		]
	);

	$result = Block_Style_Support::image(
		[
			'style' => [
				'border' => [ 'width' => '2px' ],
				'shadow' => '6px 6px 12px rgba(0,0,0,0.6)',
			],
		],
	);

	expect( $result['style'] )->toContain( 'border-width:2px;' );
	expect( $result['style'] )->toContain( 'box-shadow:6px 6px 12px rgba(0,0,0,0.6);' );

} );

test( 'a box-shadow already serialised into css is not duplicated by the recovery', function (): void {

	// When the engine does fold the box-shadow into `css` (a preset shadow), the
	// recovery must not append a second copy.
	Functions\when( 'wp_style_engine_get_styles' )->justReturn(
		[
			'css'          => 'box-shadow:var(--wp--preset--shadow--deep);',
			'declarations' => [ 'box-shadow' => 'var(--wp--preset--shadow--deep)' ],
			'classnames'   => '',
		]
	);

	$result = Block_Style_Support::image(
		[ 'style' => [ 'shadow' => 'var:preset|shadow|deep' ] ],
	);

	expect( substr_count( $result['style'], 'box-shadow' ) )->toBe( 1 );

} );
