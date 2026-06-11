<?php
/**
 * The resolved per-figure click behaviour for one gallery render.
 *
 * The gallery's two toggles — Lightbox and Download — fix what a click on a
 * thumbnail does, and where the download icon lives (issue #34). This value object
 * is the resolution of that matrix as it applies to each gallery `<figure>`: the
 * single `on_thumbnail` flag is true only in the download-on / lightbox-off cell,
 * where the overlay download icon — itself a `download` anchor, the sole download
 * trigger — is painted on the thumbnail; in every other cell it is false (with the
 * Lightbox on, the download moves inside the lightbox, resolved separately by the
 * wrapper). A click on the image outside the icon never downloads. The
 * download-icon styling rides along so the figure builder can paint the overlay
 * without re-reading the attributes.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of one gallery figure's click behaviour.
 *
 * Constructed by `Render_Gallery` from the lightbox and download toggles and
 * consumed by the figure builder. It is pure data with no behaviour.
 *
 * @since 0.4.0
 */
final readonly class Click_Behaviour {

	/**
	 * Constructs the resolved per-figure click behaviour.
	 *
	 * @since 0.4.0
	 * @since 0.5.0 The icon anchor is the sole download trigger; the thumbnail
	 *              anchor itself no longer downloads.
	 *
	 * @param bool              $on_thumbnail Whether the download-icon anchor overlays the thumbnail.
	 * @param Download_Settings $settings     The resolved download-icon styling.
	 */
	public function __construct(
		public bool $on_thumbnail,
		public Download_Settings $settings,
	) {}

}
