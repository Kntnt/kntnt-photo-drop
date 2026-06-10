<?php
/**
 * The resolved per-figure click behaviour for one gallery render.
 *
 * The gallery's two toggles — Lightbox and Download — fix what a click on a
 * thumbnail does, and where the download icon lives (issue #34). This value object
 * is the resolution of that matrix as it applies to each gallery `<figure>`:
 * whether the thumbnail anchor is a lightbox trigger, whether the anchor carries
 * the `download` attribute so a plain click saves the main image, and whether the
 * overlay download icon is painted on the thumbnail. The icon and the anchor's
 * `download` attribute appear on the thumbnail only when Download is on and the
 * Lightbox is off; with the Lightbox on, the download moves inside the lightbox
 * and the thumbnail stays a bare trigger (resolved separately by the wrapper). The
 * download-icon styling rides along so the figure builder can paint the overlay
 * without re-reading the attributes.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of one gallery figure's click behaviour.
 *
 * Constructed by `Render_Gallery` from the lightbox and download toggles and
 * consumed by the figure builder. It is pure data with no behaviour.
 *
 * @since 0.5.0
 */
final readonly class Click_Behaviour {

	/**
	 * Constructs the resolved per-figure click behaviour.
	 *
	 * @since 0.5.0
	 *
	 * @param bool              $opens_lightbox Whether the thumbnail anchor is a lightbox trigger.
	 * @param bool              $downloads      Whether the anchor carries the `download` attribute.
	 * @param bool              $shows_icon     Whether the overlay download icon is painted on the thumbnail.
	 * @param Download_Settings $download       The resolved download-icon styling.
	 */
	public function __construct(
		public bool $opens_lightbox,
		public bool $downloads,
		public bool $shows_icon,
		public Download_Settings $download,
	) {}

}
