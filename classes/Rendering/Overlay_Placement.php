<?php
/**
 * The resolved visibility and position of one gallery overlay (skeleton).
 *
 * Skeleton for the RED step — the value object and its decision methods exist
 * with the documented signatures but return placeholder values, so the placement
 * tests fail on a real assertion rather than a missing-symbol fatal. The GREEN
 * step fills in the narrowing and the per-surface projection.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed placement (visibility + position) for one overlay (skeleton).
 *
 * @since 0.11.0
 */
final readonly class Overlay_Placement {

	/**
	 * Constructs the resolved placement.
	 *
	 * @since 0.11.0
	 *
	 * @param string $visibility One of `off`, `thumbnail`, `full`, `both`.
	 * @param string $position   A nine-point position token.
	 */
	public function __construct(
		public string $visibility,
		public string $position,
	) {}

	/**
	 * Narrows a raw visibility and position into a placement (skeleton).
	 *
	 * @since 0.11.0
	 *
	 * @param string $visibility        The raw visibility value.
	 * @param string $position          The raw position value.
	 * @param string $position_fallback The default position when the raw one is invalid.
	 * @return self The resolved placement.
	 */
	public static function from(
		string $visibility,
		string $position,
		string $position_fallback = 'top-left',
	): self {
		return new self( '', '' );
	}

	/**
	 * Whether the overlay shows on the grid thumbnail (skeleton: false).
	 *
	 * @since 0.11.0
	 *
	 * @return bool True when the thumbnail surface shows this overlay.
	 */
	public function on_thumbnail(): bool {
		return false;
	}

	/**
	 * Whether the overlay shows on the lightbox image (skeleton: false).
	 *
	 * @since 0.11.0
	 *
	 * @param bool $lightbox_on Whether the lightbox is enabled.
	 * @return bool True when the lightbox surface shows this overlay.
	 */
	public function on_full( bool $lightbox_on ): bool {
		return false;
	}

	/**
	 * The vertical band the position occupies (skeleton: empty).
	 *
	 * @since 0.11.0
	 *
	 * @return string One of `top`, `middle`, `bottom`.
	 */
	public function band(): string {
		return '';
	}

}
