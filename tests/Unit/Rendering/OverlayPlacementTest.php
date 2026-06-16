<?php
/**
 * Tests for the pure overlay placement value object (ADR-0015, the overlay model).
 *
 * Every overlay carries one visibility (`off` / `thumbnail` / `full` / `both`) and
 * one nine-point position. `Overlay_Placement` is the narrowed, typed resolution
 * of that pair plus the two per-surface decisions the renderer keys off: whether
 * the overlay shows on the grid thumbnail and whether it shows on the lightbox
 * ("full") image — the latter gated by the lightbox being on, since "Full" *is*
 * the lightbox and the slideshow shows no overlays at all. The band derivation
 * (top / middle / bottom from the position's vertical half) backs the editor's
 * mutual band-exclusion. All pure, so the projection is asserted directly here.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Rendering\Overlay_Placement;

// ---------------------------------------------------------------------------
// from_attributes() — narrowing the visibility and position
// ---------------------------------------------------------------------------

test( 'a recognised visibility and position are carried through verbatim', function (): void {

	$placement = Overlay_Placement::from( 'both', 'middle-right' );

	expect( $placement->visibility )->toBe( 'both' );
	expect( $placement->position )->toBe( 'middle-right' );

} );

test( 'an unrecognised visibility falls back to off', function (): void {

	$placement = Overlay_Placement::from( 'sometimes', 'top-left' );

	expect( $placement->visibility )->toBe( 'off' );

} );

test( 'an unrecognised position falls back to the supplied default', function (): void {

	$placement = Overlay_Placement::from( 'thumbnail', 'nowhere', 'bottom-left' );

	expect( $placement->position )->toBe( 'bottom-left' );

} );

// ---------------------------------------------------------------------------
// on_thumbnail() / on_full() — the per-surface projection
// ---------------------------------------------------------------------------

test( 'off shows on neither surface', function (): void {

	$placement = Overlay_Placement::from( 'off', 'top-left' );

	expect( $placement->on_thumbnail() )->toBeFalse();
	expect( $placement->on_full( true ) )->toBeFalse();

} );

test( 'thumbnail shows on the grid tile only, never on the lightbox', function (): void {

	$placement = Overlay_Placement::from( 'thumbnail', 'top-left' );

	expect( $placement->on_thumbnail() )->toBeTrue();
	expect( $placement->on_full( true ) )->toBeFalse();

} );

test( 'full shows on the lightbox only, and only when the lightbox is on', function (): void {

	$placement = Overlay_Placement::from( 'full', 'top-left' );

	expect( $placement->on_thumbnail() )->toBeFalse();
	expect( $placement->on_full( true ) )->toBeTrue();
	expect( $placement->on_full( false ) )->toBeFalse();

} );

test( 'both shows on the thumbnail always and on the lightbox only when it is on', function (): void {

	$placement = Overlay_Placement::from( 'both', 'top-left' );

	expect( $placement->on_thumbnail() )->toBeTrue();
	expect( $placement->on_full( true ) )->toBeTrue();
	expect( $placement->on_full( false ) )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// band() — the vertical band the position occupies
// ---------------------------------------------------------------------------

test( 'the band is the position vertical half: top, middle, or bottom', function (): void {

	expect( Overlay_Placement::from( 'both', 'top-center' )->band() )->toBe( 'top' );
	expect( Overlay_Placement::from( 'both', 'middle-left' )->band() )->toBe( 'middle' );
	expect( Overlay_Placement::from( 'both', 'bottom-right' )->band() )->toBe( 'bottom' );

} );
