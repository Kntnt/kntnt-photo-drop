<?php
/**
 * The render-constant chrome shared by every gallery figure.
 *
 * A gallery can hold thousands of images, so the per-figure hot loop must do as
 * little repeated work as possible. Everything about a figure that does not vary
 * from image to image — the overlay download-icon template, the image's escaped
 * class and style, and the caption's escaped class prefix and style — is composed
 * and escaped once per render and carried here, so the loop only fills in the
 * per-image URL, dimensions, srcset, and caption text. This immutable value object
 * is built once by `Render_Gallery::render_gallery()` and threaded into the figure
 * builder.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of a gallery's render-constant figure chrome.
 *
 * The string fields are already HTML-escaped at construction so the figure builder
 * interpolates them verbatim. The `image_style` and `caption_style` are empty when
 * the corresponding block-support panels contributed nothing, which lets the
 * builder omit an empty `style` attribute entirely.
 *
 * @since 0.4.0
 */
final readonly class Figure_Chrome {

	/**
	 * Constructs the render-constant figure chrome.
	 *
	 * @since 0.4.0
	 * @since 0.5.0 The thumbnail anchor's ` download` attribute is gone; the icon
	 *              became a per-figure download anchor, carried as a template with
	 *              an href placeholder the figure builder fills per image.
	 *
	 * @param string $icon_template  The overlay download-icon anchor markup with the
	 *                               href placeholder, or '' when the icon is off.
	 * @param string $image_class    The escaped `<img>` class (base plus border preset classes).
	 * @param string $image_style    The escaped `<img>` style declarations, or '' when none.
	 * @param string $caption_class  The escaped caption class prefix (base, anchor, preset classes).
	 * @param string $caption_style  The escaped caption style declarations, or '' when none.
	 */
	public function __construct(
		public string $icon_template,
		public string $image_class,
		public string $image_style,
		public string $caption_class,
		public string $caption_style,
	) {}

}
