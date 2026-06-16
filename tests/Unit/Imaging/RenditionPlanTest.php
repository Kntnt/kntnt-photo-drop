<?php
/**
 * Tests for the tier-skip matrix — the single decidable core of the
 * three-rendition model (ADR-0013).
 *
 * `Rendition_Plan::derived()` answers, for one main image's width and a
 * collection's full/thumbnail width+quality settings, exactly which derived
 * files (under `.kntnt-thumbnails/<width>/`) should exist and at which quality.
 * Each tier is derived from the one above it (`main → full → thumbnail`) and
 * skipped when its source is no wider, and degenerate width orderings collapse a
 * tier rather than colliding (ADR-0013). This is a pure numeric function, so the
 * whole matrix is asserted directly here as the foundation every consumer (the
 * deriver, the doctor, the srcset builder) reads from.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Imaging\Rendition_Plan;

// ---------------------------------------------------------------------------
// The tier-skip matrix: (main, full, thumbnail) → which derived files exist
// ---------------------------------------------------------------------------

test(
	'the derived-rendition plan honours the tier-skip matrix',
	function ( int $main_width, array $expected ): void {

		// Hold the full/thumbnail settings fixed across the whole matrix so each row
		// isolates the effect of the main width on which tiers survive: a 1920/85
		// full and a 640/75 thumbnail, the project defaults.
		$plan = Rendition_Plan::derived( $main_width, 1920, 85, 640, 75 );

		// The plan is the exact ascending list of derived files that should exist;
		// the main itself is never in it (it is a separate file, not a derived one).
		expect( $plan )->toBe( $expected );

	},
)->with( [

	// Main far wider than both tiers: a separate full at 1920 and a thumbnail at
	// 640, each at its own quality.
	'wide main keeps full and thumbnail' => [
		4000,
		[
			[
				'width'   => 640,
				'quality' => 75,
			],
			[
				'width'   => 1920,
				'quality' => 85,
			],
		],
	],

	// Main wider than full: the full is still a separate file (main > 1920).
	'main just over full keeps both' => [
		1921,
		[
			[
				'width'   => 640,
				'quality' => 75,
			],
			[
				'width'   => 1920,
				'quality' => 85,
			],
		],
	],

	// Main no wider than full but wider than the thumbnail: no separate full (the
	// main serves that role), only the thumbnail survives.
	'main at or below full skips the full tier' => [
		1500,
		[
			[
				'width'   => 640,
				'quality' => 75,
			],
		],
	],

	// Main exactly the full width: still no separate full (the skip rule is
	// "no wider"), only the thumbnail.
	'main exactly the full width skips the full tier' => [
		1920,
		[
			[
				'width'   => 640,
				'quality' => 75,
			],
		],
	],

	// Main wider than the thumbnail but with nothing below it: a 700px main keeps
	// only the 640 thumbnail.
	'small main keeps only the thumbnail' => [
		700,
		[
			[
				'width'   => 640,
				'quality' => 75,
			],
		],
	],

	// Main exactly the thumbnail width: no thumbnail either (the skip rule is
	// "no wider"), so the main is the only rendition — an empty derived plan.
	'main exactly the thumbnail width collapses to the main alone' => [
		640,
		[],
	],

	// Main smaller than the thumbnail width: every tier collapses into the main.
	'tiny main collapses every tier' => [
		400,
		[],
	],
] );

// ---------------------------------------------------------------------------
// Degenerate width orderings collapse a tier rather than colliding
// ---------------------------------------------------------------------------

test( 'equal full and thumbnail widths produce one file at the full quality', function (): void {

	// Full and thumbnail both want 800px; for a main wider than 800 only one file
	// can live at .kntnt-thumbnails/800/, so the tiers collapse to a single file
	// generated at the larger tier's (full) quality — the thumbnail quality is
	// simply unused (ADR-0013).
	$plan = Rendition_Plan::derived( 2000, 800, 85, 800, 60 );

	expect( $plan )->toBe( [
		[
			'width'   => 800,
			'quality' => 85,
		],
	] );

} );

test( 'a full width at or above the main width yields no separate full', function (): void {

	// The full width (2400) is at or above the main (2000), so no separate full is
	// written — the main serves that role — and only the thumbnail below the main
	// survives.
	$plan = Rendition_Plan::derived( 2000, 2400, 85, 640, 75 );

	expect( $plan )->toBe( [
		[
			'width'   => 640,
			'quality' => 75,
		],
	] );

} );

test( 'a thumbnail width wider than the full width collapses into the full', function (): void {

	// A misconfigured thumbnail wider than the full (1000 > 800) can never be a real
	// thumbnail: once the full caps the rendition at 800, nothing 1000px wide can be
	// derived, so only the full at 800 survives.
	$plan = Rendition_Plan::derived( 3000, 800, 85, 1000, 75 );

	expect( $plan )->toBe( [
		[
			'width'   => 800,
			'quality' => 85,
		],
	] );

} );

// ---------------------------------------------------------------------------
// The full-rendition width — the display ceiling the srcset reads
// ---------------------------------------------------------------------------

test( 'the full-rendition width is the bounded full when the main exceeds it', function (): void {

	// A 4000px main bounded by a 1920 full has a full-rendition width of 1920 — the
	// separate full file is the display ceiling, and the main is download-only.
	expect( Rendition_Plan::full_rendition_width( 4000, 1920 ) )->toBe( 1920 );

} );

test( 'the full-rendition width is the main itself when no wider than the full', function (): void {

	// A 1500px main no wider than the 1920 full is itself the full rendition, so the
	// full-rendition width is the main's own width (nothing is upscaled).
	expect( Rendition_Plan::full_rendition_width( 1500, 1920 ) )->toBe( 1500 );

} );

test( 'a separate full file exists only when the main is strictly wider than the full width', function (): void {

	// The boundary: a main wider than the full width has a separate full file; one
	// at or below the full width does not (the main serves that role).
	expect( Rendition_Plan::has_separate_full( 1921, 1920 ) )->toBeTrue();
	expect( Rendition_Plan::has_separate_full( 1920, 1920 ) )->toBeFalse();
	expect( Rendition_Plan::has_separate_full( 1000, 1920 ) )->toBeFalse();

} );
