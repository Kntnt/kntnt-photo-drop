<?php
/**
 * The resolved caption settings for one gallery render.
 *
 * A gallery's caption attributes (content, humanise, breadcrumb prefix and
 * separator, and the nine-point overlay anchor) are read once per render and
 * apply identically to every image. Captions are always an overlay inside the
 * image (issue #33), so there is no position to carry; their colour and
 * typography arrive through the colour/typography block-support panels and are
 * projected onto the figcaption separately (`Block_Style_Support`), not stored
 * here. This immutable value object carries the resolved set, so the renderer
 * reads the attributes once and passes one typed object to each figure rather
 * than threading loose values through every helper. The enum-style fields
 * (content, anchor) are already narrowed to their documented values by the
 * caller.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of a gallery's caption attributes.
 *
 * Constructed by `Render_Gallery` from the block attributes and consumed by the
 * figure and caption builders. It is pure data with no behaviour beyond holding
 * the resolved settings; the text assembly lives in `Caption_Builder` and the
 * markup placement in `Render_Gallery`.
 *
 * @since 0.6.0
 */
final readonly class Caption_Settings {

	/**
	 * Constructs the resolved caption settings.
	 *
	 * @since 0.6.0
	 *
	 * @param string $content      One of `none`, `filename`, `path`.
	 * @param bool   $humanize     Whether to strip extensions and normalise separators.
	 * @param bool   $include_name Whether a breadcrumb is prefixed with the collection name.
	 * @param string $separator    The breadcrumb separator.
	 * @param string $anchor       The nine-point overlay anchor.
	 */
	public function __construct(
		public string $content,
		public bool $humanize,
		public bool $include_name,
		public string $separator,
		public string $anchor,
	) {}

}
