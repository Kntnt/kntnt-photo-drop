<?php
/**
 * The resolved visibility and position of one gallery overlay (ADR-0015).
 *
 * Every overlay — breadcrumbs, download, add-to-media, trash — carries one
 * visibility (`off` / `thumbnail` / `full` / `both`) and one nine-point position,
 * applied identically wherever it shows. `Overlay_Placement` is the narrowed,
 * typed resolution of that pair: it pins the visibility and position to their
 * documented value sets and answers the two per-surface questions the renderer
 * keys off — whether the overlay shows on the grid thumbnail, and whether it
 * shows on the lightbox ("full") image. "Full" *is* the lightbox, so it is gated
 * on the lightbox being enabled; the slideshow shows no overlays at all, so there
 * is no slideshow surface to ask about here. The `band()` derivation (the
 * vertical half the position occupies) backs the editor's mutual band-exclusion
 * between breadcrumbs and the icon overlays.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable, typed placement (visibility + position) for one overlay.
 *
 * Constructed through {@see from()} (which narrows raw attribute values) and
 * consumed by `Render_Gallery`. Pure data with three decision methods and no
 * I/O, so the projection is unit-testable in isolation. The visibility and
 * position are guaranteed to be one of their documented value sets.
 *
 * @since 0.11.0
 */
final readonly class Overlay_Placement {

	/**
	 * Visibility: the overlay shows nowhere.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const OFF = 'off';

	/**
	 * Visibility: the overlay shows on the grid thumbnail only.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const THUMBNAIL = 'thumbnail';

	/**
	 * Visibility: the overlay shows on the lightbox ("full") image only.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const FULL = 'full';

	/**
	 * Visibility: the overlay shows on both the thumbnail and the lightbox.
	 *
	 * @since 0.11.0
	 * @var string
	 */
	public const BOTH = 'both';

	/**
	 * The nine-point position vocabulary, in row-major order.
	 *
	 * The single source of the allowed positions, so the narrowing here and the
	 * editor's option list never drift apart.
	 *
	 * @since 0.11.0
	 * @var array<int,string>
	 */
	public const POSITIONS = [
		'top-left',
		'top-center',
		'top-right',
		'middle-left',
		'middle-center',
		'middle-right',
		'bottom-left',
		'bottom-center',
		'bottom-right',
	];

	/**
	 * Constructs the resolved placement.
	 *
	 * @since 0.11.0
	 *
	 * @param string $visibility One of OFF, THUMBNAIL, FULL, BOTH.
	 * @param string $position   A nine-point position token.
	 */
	public function __construct(
		public string $visibility,
		public string $position,
	) {}

	/**
	 * Narrows a raw visibility and position into a placement.
	 *
	 * An unrecognised visibility falls back to OFF (the safe default: a stray
	 * value never silently shows an overlay), and an unrecognised position falls
	 * back to `$position_fallback` (each overlay's documented default, so a stray
	 * value lands somewhere sensible rather than at a hardcoded corner).
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

		// Narrow each value to its documented set, defaulting visibility to off
		// (never show on a stray value) and position to the overlay's own default.
		$visibility = in_array( $visibility, [ self::OFF, self::THUMBNAIL, self::FULL, self::BOTH ], true )
			? $visibility
			: self::OFF;
		$position = in_array( $position, self::POSITIONS, true ) ? $position : $position_fallback;

		return new self( $visibility, $position );

	}

	/**
	 * Whether the overlay shows on the grid thumbnail.
	 *
	 * @since 0.11.0
	 *
	 * @return bool True for the THUMBNAIL and BOTH visibilities.
	 */
	public function on_thumbnail(): bool {
		return $this->visibility === self::THUMBNAIL || $this->visibility === self::BOTH;
	}

	/**
	 * Whether the overlay shows on the lightbox ("full") image.
	 *
	 * "Full" is the lightbox surface, so it is gated on the lightbox being
	 * enabled: a FULL/BOTH overlay shows there only when the gallery's lightbox
	 * is on. With the lightbox off there is no full surface, so nothing shows.
	 *
	 * @since 0.11.0
	 *
	 * @param bool $lightbox_on Whether the gallery's lightbox is enabled.
	 * @return bool True for FULL/BOTH only when the lightbox is on.
	 */
	public function on_full( bool $lightbox_on ): bool {
		return $lightbox_on && ( $this->visibility === self::FULL || $this->visibility === self::BOTH );
	}

	/**
	 * The vertical band the position occupies.
	 *
	 * The band is the position's vertical half — `top`, `middle`, or `bottom` —
	 * which is the prefix before the hyphen. Breadcrumbs occupy a whole band, so
	 * this is what the editor mutually excludes against the icon overlays.
	 *
	 * @since 0.11.0
	 *
	 * @return string One of `top`, `middle`, `bottom`.
	 */
	public function band(): string {

		// The band is the segment before the hyphen; every documented position has
		// one, so a simple split suffices.
		$hyphen = strpos( $this->position, '-' );

		return $hyphen === false ? $this->position : substr( $this->position, 0, $hyphen );

	}

}
