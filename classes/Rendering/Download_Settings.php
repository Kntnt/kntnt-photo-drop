<?php
/**
 * The resolved download-icon styling for one gallery render.
 *
 * The download icon is always an overlay inside the image, placed by a nine-point
 * anchor (the same vocabulary as the caption) and styled by its own custom
 * controls — size, background, foreground — because the block-support colour panel
 * is already claimed by the caption (issue #34). The four values are read once per
 * render and apply identically to every icon, whether the icon overlays each
 * gallery thumbnail (download on, lightbox off) or sits inside the lightbox
 * (download on, lightbox on). This immutable value object carries the resolved
 * set so the renderer threads one typed object rather than four loose values.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of a gallery's download-icon styling.
 *
 * Constructed by `Render_Gallery` from the block attributes and consumed by the
 * figure and lightbox-overlay builders. It is pure data with no behaviour; the
 * `anchor` field is already narrowed to one of the nine documented values by the
 * caller.
 *
 * @since 0.4.0
 */
final readonly class Download_Settings {

	/**
	 * Constructs the resolved download-icon settings.
	 *
	 * @since 0.4.0
	 *
	 * @param string $size       The icon size as a CSS length (e.g. `2rem`).
	 * @param string $background The icon background colour (a CSS colour).
	 * @param string $foreground The icon foreground (glyph) colour (a CSS colour).
	 * @param string $anchor     The nine-point anchor that places the icon.
	 */
	public function __construct(
		public string $size,
		public string $background,
		public string $foreground,
		public string $anchor,
	) {}

}
