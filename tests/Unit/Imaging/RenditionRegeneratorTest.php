<?php
/**
 * Unit tests for the rendition regenerator's pure decision logic.
 *
 * The regenerator drives the regenerate-then-flip flow (ADR-0013): it enumerates
 * a collection's mains so a browser can re-derive them one batch per request, and
 * once every main is re-derived at the new widths it flips the descriptor and
 * prunes the now-stale width buckets. The filesystem walk and the prune are
 * covered end to end by the integration suite against a real wp-env; what is
 * unit-tested here is the pure, total decision the prune rests on — which
 * `.kntnt-thumbnails/<width>/` buckets are stale once the descriptor flips, given
 * the old and new rendition widths. That decision is the safety hinge (a wrong
 * answer deletes a live bucket or leaks an orphan), so it is pinned directly over
 * plain integers with no filesystem.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Imaging\Rendition_Regenerator;

// ---------------------------------------------------------------------------
// stale_widths — which old width buckets a flip retires
// ---------------------------------------------------------------------------

test( 'stale_widths returns the old widths no longer configured after the flip', function (): void {

	// A flip from full 1920 / thumbnail 640 to full 1280 / thumbnail 320 retires both
	// old buckets, since neither 1920 nor 640 is among the new configured pair.
	$stale = Rendition_Regenerator::stale_widths( [ 1920, 640 ], [ 1280, 320 ] );

	expect( $stale )->toEqualCanonicalizing( [ 1920, 640 ] );
} );

test( 'stale_widths keeps a width that survives the flip', function (): void {

	// Only the thumbnail width changes (640 → 320); the full width 1920 is configured
	// in both, so its bucket must survive and only 640 is stale.
	$stale = Rendition_Regenerator::stale_widths( [ 1920, 640 ], [ 1920, 320 ] );

	expect( $stale )->toBe( [ 640 ] );
} );

test( 'stale_widths returns nothing when the widths are unchanged', function (): void {

	// A flip to the same widths (e.g. only the qualities changed) retires no bucket;
	// the old and new width sets are identical.
	$stale = Rendition_Regenerator::stale_widths( [ 1920, 640 ], [ 1920, 640 ] );

	expect( $stale )->toBe( [] );
} );

test( 'stale_widths never retires a width the new configuration also uses', function (): void {

	// A degenerate flip where the new full width equals the old thumbnail width (640):
	// 640 is configured after the flip, so it is not stale even though it was the
	// thumbnail width before — only the genuinely de-configured 1920 is retired.
	$stale = Rendition_Regenerator::stale_widths( [ 1920, 640 ], [ 640, 320 ] );

	expect( $stale )->toEqualCanonicalizing( [ 1920 ] );
} );
