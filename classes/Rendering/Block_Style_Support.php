<?php
/**
 * Projects block-support style values onto a gallery sub-element.
 *
 * The Gallery declares its colour, typography, border, and shadow supports with
 * `__experimentalSkipSerialization`, so WordPress does **not** write those values
 * onto the block wrapper — the block owns where they land (the core Image-block
 * pattern). Caption colour and typography belong on each `<figcaption>`; border
 * and shadow belong on each `<img>`. This helper is that projection: given the
 * block attributes, it slices out the right style subtree and the right preset
 * shorthand attributes for one sub-element and hands them to the core style
 * engine (`wp_style_engine_get_styles`), returning the inline `style`
 * declarations and the preset classnames to emit on that element.
 *
 * Two kinds of value reach a block-support panel. A *custom* value (a typed hex
 * colour, an entered length) is stored under `attributes['style']` — e.g.
 * `style.color.text`, `style.border.radius`, `style.shadow`. A *preset* value (a
 * palette colour, a theme font size) is stored as a top-level shorthand
 * attribute — `textColor`, `backgroundColor`, `gradient`, `fontSize`,
 * `fontFamily`, `borderColor`, `shadow` — naming a theme slug. The engine turns
 * a custom value into a literal declaration and a preset token
 * (`var:preset|type|slug`) into both a `var( --wp--preset--… )` declaration and
 * the standard `has-…` classname, so this helper rewrites each preset shorthand
 * back into the token shape the engine expects and lets the engine do the rest.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Slices block-support style values for one gallery sub-element.
 *
 * A pure projection over the block attributes: it never touches the filesystem
 * and reaches WordPress only through the style engine. The two entry points map
 * to the two sub-elements the gallery styles — `caption()` for the figcaption
 * (colour + typography) and `image()` for each image (border + shadow) — and
 * each returns an `array{ style: string, class: string }` the renderer escapes
 * and emits. The two never overlap, so a caption never inherits a border and an
 * image never inherits the caption colour.
 *
 * @since 0.4.0
 */
final class Block_Style_Support {

	/**
	 * Builds the inline style and preset classes for the caption sub-element.
	 *
	 * Slices the colour (text, background, gradient) and the whole typography
	 * subtree out of the attributes — both the custom values under `style` and the
	 * preset shorthand attributes (`textColor`, `backgroundColor`, `gradient`,
	 * `fontSize`, `fontFamily`) — and projects them through the style engine. The
	 * result lands on each `<figcaption>`, never on the block wrapper.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return array{style:string,class:string} The figcaption inline style and classnames.
	 */
	public static function caption( array $attributes ): array {

		// Start from the custom colour/typography subtrees, then fold in any preset
		// shorthand the palette/font-size pickers wrote at the top level.
		$style       = self::as_array( $attributes['style'] ?? null );
		$block_styles = [
			'color'      => self::color_subtree( $style, $attributes ),
			'typography' => self::typography_subtree( $style, $attributes ),
		];

		return self::project( $block_styles );

	}

	/**
	 * Builds the inline style and preset classes for each image sub-element.
	 *
	 * Slices the border subtree (colour, width, style, radius) and the shadow value
	 * out of the attributes — the custom values under `style.border` / `style.shadow`
	 * plus the preset shorthand attributes (`borderColor`, `shadow`) — and projects
	 * them through the style engine. The result lands on each `<img>`.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return array{style:string,class:string} The image inline style and classnames.
	 */
	public static function image( array $attributes ): array {

		// Start from the custom border subtree and the custom shadow, then fold in the
		// border-colour and shadow preset shorthands the pickers wrote at the top level.
		$style       = self::as_array( $attributes['style'] ?? null );
		$block_styles = [
			'border' => self::border_subtree( $style, $attributes ),
			'shadow' => self::shadow_value( $style, $attributes ),
		];

		return self::project( $block_styles );

	}

	/**
	 * Assembles the colour subtree from custom values and preset shorthands.
	 *
	 * A preset shorthand (`textColor`, `backgroundColor`, `gradient`) wins over a
	 * stray custom value under the same key, mirroring how the editor only ever
	 * sets one of the two for a given slot.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $style      The block's `style` subtree.
	 * @param array<string,mixed> $attributes The block attributes (for preset shorthands).
	 * @return array<string,mixed> The colour subtree the engine consumes.
	 */
	private static function color_subtree( array $style, array $attributes ): array {

		// Begin with the custom colour values typed into the picker.
		$custom  = self::as_array( $style['color'] ?? null );
		$subtree = [];
		foreach ( [ 'text', 'background', 'gradient' ] as $key ) {
			$value = $custom[ $key ] ?? null;
			if ( is_string( $value ) && $value !== '' ) {
				$subtree[ $key ] = $value;
			}
		}

		// Override with any palette/gradient preset the shorthand attributes name,
		// expressed as the engine's preset token so it yields the var() + classname.
		self::apply_preset( $subtree, 'text', self::read_slug( $attributes, 'textColor' ), 'color' );
		self::apply_preset( $subtree, 'background', self::read_slug( $attributes, 'backgroundColor' ), 'color' );
		self::apply_preset( $subtree, 'gradient', self::read_slug( $attributes, 'gradient' ), 'gradient' );

		return $subtree;

	}

	/**
	 * Assembles the typography subtree from custom values and preset shorthands.
	 *
	 * The custom subtree is passed through wholesale (the engine knows every
	 * typography key), and the `fontSize` / `fontFamily` preset shorthands are
	 * folded in as engine preset tokens.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $style      The block's `style` subtree.
	 * @param array<string,mixed> $attributes The block attributes (for preset shorthands).
	 * @return array<string,mixed> The typography subtree the engine consumes.
	 */
	private static function typography_subtree( array $style, array $attributes ): array {

		// Pass the custom typography subtree through; the engine handles each key.
		$subtree = self::as_array( $style['typography'] ?? null );

		// Fold in the font-size and font-family presets the pickers wrote at the top
		// level, expressed as preset tokens so the engine emits var() + classname.
		self::apply_preset( $subtree, 'fontSize', self::read_slug( $attributes, 'fontSize' ), 'font-size' );
		self::apply_preset( $subtree, 'fontFamily', self::read_slug( $attributes, 'fontFamily' ), 'font-family' );

		return $subtree;

	}

	/**
	 * Assembles the border subtree from custom values and the colour preset shorthand.
	 *
	 * The custom border subtree (colour, width, style, radius, and any per-side
	 * shape) is passed through, with the `borderColor` preset shorthand folded in as
	 * an engine preset token when present.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $style      The block's `style` subtree.
	 * @param array<string,mixed> $attributes The block attributes (for the colour preset).
	 * @return array<string,mixed> The border subtree the engine consumes.
	 */
	private static function border_subtree( array $style, array $attributes ): array {

		// Pass the custom border subtree through; fold in the border-colour preset
		// the picker wrote at the top level, as a preset token for the engine.
		$subtree = self::as_array( $style['border'] ?? null );
		self::apply_preset( $subtree, 'color', self::read_slug( $attributes, 'borderColor' ), 'color' );

		return $subtree;

	}

	/**
	 * Resolves the shadow value: a preset shorthand, else the custom style value.
	 *
	 * A chosen shadow preset is stored as the top-level `shadow` attribute (a slug),
	 * which becomes the engine's preset token; a custom shadow lives at
	 * `style.shadow` and is passed through. The preset wins when both are present.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $style      The block's `style` subtree.
	 * @param array<string,mixed> $attributes The block attributes (for the preset shorthand).
	 * @return string The shadow value (a preset token or a custom value), or `''`.
	 */
	private static function shadow_value( array $style, array $attributes ): string {

		// Prefer the preset shorthand, expressed as the engine's preset token;
		// otherwise fall through to the custom shadow value under the style subtree.
		$preset = self::read_slug( $attributes, 'shadow' );
		if ( $preset !== '' ) {
			return 'var:preset|shadow|' . $preset;
		}
		$custom = $style['shadow'] ?? null;

		return is_string( $custom ) ? $custom : '';

	}

	/**
	 * Folds a preset slug into a style subtree as the engine's preset token.
	 *
	 * Writes `var:preset|<type>|<slug>` under the given key only when the slug is
	 * non-empty, so an unset preset leaves any custom value already in the subtree
	 * untouched. A present preset overwrites the key, mirroring the editor's
	 * one-or-the-other behaviour for a given slot.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $subtree The subtree to mutate, by reference.
	 * @param string              $key     The key to set.
	 * @param string              $slug    The preset slug, or `''` for none.
	 * @param string              $type    The engine preset type (`color`, `gradient`, …).
	 * @return void
	 */
	private static function apply_preset( array &$subtree, string $key, string $slug, string $type ): void {
		if ( $slug !== '' ) {
			$subtree[ $key ] = 'var:preset|' . $type . '|' . $slug;
		}
	}

	/**
	 * Reads a preset-slug attribute as a string, defaulting to `''`.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @param string              $key        The shorthand attribute key.
	 * @return string The slug, or `''` when absent or non-string.
	 */
	private static function read_slug( array $attributes, string $key ): string {
		$raw = $attributes[ $key ] ?? '';
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Narrows a `mixed` value to a string-keyed array, defaulting to `[]`.
	 *
	 * The block attributes arrive as `mixed` off the render-callback array, so each
	 * `style` branch is narrowed here once rather than inline at every call site;
	 * a non-array (or a list with integer keys) collapses to the empty array the
	 * engine treats as "nothing set".
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $value The raw attribute value.
	 * @return array<string,mixed> The value as a string-keyed array, or `[]`.
	 */
	private static function as_array( mixed $value ): array {

		// Keep only the string-keyed entries so the result matches the engine's
		// associative-subtree shape; anything else degrades to the empty array.
		if ( ! is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $key => $entry ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $entry;
			}
		}

		return $result;

	}

	/**
	 * Runs the style engine over a block-styles subtree, dropping empty branches.
	 *
	 * Prunes empty sub-arrays and empty scalars so the engine never sees a hollow
	 * `color => []` that would still cost a call; an entirely empty input short-
	 * circuits to the empty result. The engine returns the declarations as one CSS
	 * string and the preset classnames as a space-joined string, which the renderer
	 * emits on the sub-element's `style` and `class`.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $block_styles The block-styles subtree for one element.
	 * @return array{style:string,class:string} The inline style and classnames.
	 */
	private static function project( array $block_styles ): array {

		// Drop empty branches so an unset support contributes nothing; bail early when
		// nothing is left to project.
		$block_styles = array_filter(
			$block_styles,
			static fn ( mixed $value ): bool => is_array( $value )
				? $value !== []
				: ( is_string( $value ) && $value !== '' ),
		);
		if ( $block_styles === [] ) {
			return [
				'style' => '',
				'class' => '',
			];
		}

		// Hand the subtree to the core style engine, which returns the inline CSS and
		// the standard preset classnames for the values it recognises.
		$styles = wp_style_engine_get_styles( $block_styles );

		return [
			'style' => is_string( $styles['css'] ?? null ) ? $styles['css'] : '',
			'class' => is_string( $styles['classnames'] ?? null ) ? $styles['classnames'] : '',
		];

	}

}
