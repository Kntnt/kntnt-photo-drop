<?php
/**
 * Unit tests for the rendition regenerator's pure decision logic and its
 * completeness-gated flip (ADR-0013).
 *
 * The regenerator drives the regenerate-then-flip flow (ADR-0013): it enumerates
 * a collection's mains so a browser can re-derive them one batch per request, and
 * once every main is re-derived at the new widths it flips the descriptor and
 * prunes the now-stale width buckets. The filesystem walk and the prune are
 * covered end to end by the integration suite against a real wp-env; what is
 * unit-tested here is the pure, total decision the prune rests on — which
 * `.kntnt-thumbnails/<width>/` buckets are stale once the descriptor flips, given
 * the old and new rendition widths — plus the safety invariant that the flip and
 * the prune happen *only when every main's expected new-width renditions are
 * present on disk*. A re-derive that silently wrote fewer renditions than its main
 * required must abort the flip (the descriptor stays on the old widths and the old
 * buckets survive), never leave the gallery pointing at files that were never
 * written. Both are safety hinges (a wrong answer deletes a live bucket, leaks an
 * orphan, or flips onto missing files), so they are pinned directly.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Rendition_Regenerator;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Tests\Unit\Fixtures\Encode_Failing_Codec;

// The flip writes collection.json through the descriptor, and the deriver reaches
// for wp_mkdir_p() when it is defined; wire both to real, host-side behaviour so a
// flip and a re-derive exercise the actual filesystem in the unit runtime.
beforeEach( function (): void {
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);
	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);
} );

/**
 * Allocates a fresh temp directory standing in for a collection root.
 *
 * @return string The absolute path of the new collection root.
 */
function fresh_regen_root(): string {
	$dir = sys_get_temp_dir() . '/kntnt-regen-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a real WebP main of given dimensions into a collection root.
 *
 * @param string $root     The collection root directory.
 * @param string $filename The stored main filename (ends in `.webp`).
 * @param int    $width    The image width in pixels.
 * @param int    $height   The image height in pixels.
 * @return string The absolute path of the written main.
 */
function write_regen_main( string $root, string $filename, int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 40, 90, 160 ) );
	$path = rtrim( $root, '/' ) . '/' . $filename;
	imagewebp( $image, $path, 80 );
	return $path;
}

/**
 * Builds and writes a descriptor with the given re-derivable widths to a root.
 *
 * The immutable upload pair and the placement template take fixed values; only
 * the full/thumbnail widths matter to the flip tests, so they are the parameters.
 *
 * @param string $root             The collection root the descriptor is written to.
 * @param int    $full_width       The full-image width recorded as live.
 * @param int    $thumbnail_width  The thumbnail width recorded as live.
 * @return Descriptor The descriptor written to disk.
 */
function seed_regen_descriptor( string $root, int $full_width, int $thumbnail_width ): Descriptor {
	$descriptor = new Descriptor( 'Seed', 1500, 80, $full_width, 85, $thumbnail_width, 75, '%uploader%' );
	$descriptor->write( $root );
	return $descriptor;
}

/**
 * Builds a thumbnailer bound to the real GD codec, for seeding live buckets.
 *
 * @return Thumbnailer The real-codec deriver used to write the old-width renditions.
 */
function regen_gd_thumbnailer(): Thumbnailer {
	return new Thumbnailer( new Gd_Webp_Codec() );
}

/**
 * Removes a directory tree used as a temp collection root.
 *
 * @param string $dir The directory to remove.
 */
function regen_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		regen_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

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

// ---------------------------------------------------------------------------
// finalise — the flip and prune are gated on completeness on disk
// ---------------------------------------------------------------------------

test( 'finalise refuses to flip when a main wrote none of its expected new renditions', function (): void {
	$root = fresh_regen_root();

	// A wide main (4000px) requires both a new 800 full and a new 300 thumbnail under
	// the target widths, but the deriver encodes to null on every rendition, so the
	// re-derive writes nothing — exactly a silent per-image failure.
	write_regen_main( $root, 'wide.webp', 4000, 2400 );
	seed_regen_descriptor( $root, 1200, 600 );
	$failing     = new Thumbnailer( new Encode_Failing_Codec() );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ), $failing );

	// Drive the whole flow as the browser would — re-derive the single main, then
	// finalise at the target widths.
	$regenerator->regenerate_main( 0, 800, 85, 300, 75 );
	$result = $regenerator->finalise( 800, 85, 300, 75 );

	// The flip must be refused: finalise reports failure rather than a prune count, so
	// the controller surfaces a real error instead of a phantom 200.
	expect( $result )->toBeFalse();

	// And the live descriptor on disk still records the OLD widths — the gallery keeps
	// resolving the renditions that actually exist (ADR-0013 "flip only on success").
	$on_disk = Descriptor::read( $root );
	expect( $on_disk->full_width )->toBe( 1200 );
	expect( $on_disk->thumbnail_width )->toBe( 600 );

	regen_remove_tree( $root );
} );

test( 'finalise does not prune the old buckets when a main is incomplete on disk', function (): void {
	$root = fresh_regen_root();

	// Seed the old 1200/600 buckets on disk with a real deriver, then point the
	// regenerator at an encode-failing deriver so the target-width re-derive writes
	// nothing — the old buckets must survive an aborted flip.
	$main = write_regen_main( $root, 'wide.webp', 4000, 2400 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 1200, 85, 600, 75 );
	seed_regen_descriptor( $root, 1200, 600 );
	$failing     = new Thumbnailer( new Encode_Failing_Codec() );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ), $failing );

	// Re-derive the failing main at the target widths, then attempt the finalise.
	$regenerator->regenerate_main( 0, 800, 85, 300, 75 );
	$regenerator->finalise( 800, 85, 300, 75 );

	// The old-width renditions are still on disk: a refused flip prunes nothing, so the
	// gallery's existing srcset never points at a deleted file.
	$corral = $root . '/' . Index::THUMBNAILS_DIRNAME;
	expect( is_file( $corral . '/1200/wide.webp' ) )->toBeTrue();
	expect( is_file( $corral . '/600/wide.webp' ) )->toBeTrue();

	regen_remove_tree( $root );
} );

test( 'finalise flips and prunes when every main is complete on disk', function (): void {
	$root = fresh_regen_root();

	// A real deriver re-derives the wide main at the target widths, so the new 800/300
	// renditions truly land on disk — the completeness sweep must then permit the flip.
	$main = write_regen_main( $root, 'wide.webp', 4000, 2400 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 1200, 85, 600, 75 );
	seed_regen_descriptor( $root, 1200, 600 );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ) );

	// Re-derive at the target widths (new buckets beside the old), then finalise.
	$regenerator->regenerate_main( 0, 800, 85, 300, 75 );
	$result = $regenerator->finalise( 800, 85, 300, 75 );

	// The flip succeeds (a non-false prune count) and the descriptor now records the new
	// widths — the success path is unbroken by the new completeness gate.
	expect( $result )->not->toBeFalse();
	$on_disk = Descriptor::read( $root );
	expect( $on_disk->full_width )->toBe( 800 );
	expect( $on_disk->thumbnail_width )->toBe( 300 );

	regen_remove_tree( $root );
} );
