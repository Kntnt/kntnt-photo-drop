<?php
/**
 * The resolved slideshow settings for one gallery render.
 *
 * The slideshow (ADR-0009) is a visitor-started, endlessly looping fullscreen
 * playback of the gallery's view. Its block-side surface is three attributes —
 * the trigger mode, the built-in button's label, and the seconds each slide
 * stands fully visible — read once per render and applied to the wrapper flags,
 * the button, and the overlay. This immutable value object carries the resolved
 * set so the renderer threads one typed object rather than three loose values.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed snapshot of a gallery's slideshow settings.
 *
 * Constructed by `Render_Gallery` from the block attributes and consumed by the
 * wrapper/button/overlay builders. It is pure data with no behaviour; the
 * `mode` field is already narrowed to one of the three documented values, the
 * `seconds` clamped to at least one, and the `label` resolved to its translated
 * default by the caller.
 *
 * @since 0.7.0
 */
final readonly class Slideshow_Settings {

	/**
	 * The trigger mode for "no slideshow" — the default.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const MODE_OFF = 'off';

	/**
	 * The trigger mode for the built-in quiet button above the gallery.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const MODE_BUTTON = 'button';

	/**
	 * The trigger mode for a designer-supplied element carrying the documented
	 * `data-kntnt-photo-drop-slideshow` attribute (ADR-0009).
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const MODE_CUSTOM = 'custom';

	/**
	 * Constructs the resolved slideshow settings.
	 *
	 * @since 0.7.0
	 *
	 * @param string $mode    The trigger mode: one of the three MODE_* values.
	 * @param int    $seconds How long each slide stands fully visible (≥ 1).
	 * @param string $label   The built-in button's label (never empty).
	 */
	public function __construct(
		public string $mode,
		public int $seconds,
		public string $label,
	) {}

}
