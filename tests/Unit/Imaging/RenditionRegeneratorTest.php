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
use Tests\Unit\Fixtures\Counting_Codec;
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

test( 'finalise verifies completeness from the index without decoding each main', function (): void {
	$root = fresh_regen_root();

	// Seed a complete collection with a real deriver: the wide main plus both the old
	// 1200/600 and the target 800/300 renditions on disk, so the completeness sweep
	// has every expected file to find.
	$main = write_regen_main( $root, 'wide.webp', 4000, 2400 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 1200, 85, 600, 75 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 800, 85, 300, 75 );
	seed_regen_descriptor( $root, 1200, 600 );

	// Point the regenerator at a decode-counting codec so any full decode the sweep
	// performs is observable; the index supplies the main's width, so a verified flip
	// must not decode a single main.
	$counting    = new Counting_Codec();
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ), new Thumbnailer( $counting ) );

	$result = $regenerator->finalise( 800, 85, 300, 75 );

	// The flip succeeds from the index alone — the completeness sweep read the main's
	// width from the per-folder index and confirmed each rendition with is_file(),
	// allocating no pixel buffer.
	expect( $result )->not->toBeFalse();
	expect( $counting->decodes )->toBe( 0 );

	regen_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// finalise — a raw $flip_to descriptor persists an unset field as null (#71)
// ---------------------------------------------------------------------------

test( 'finalise writes the raw flip-to descriptor so an unset full width persists as null', function (): void {
	$root = fresh_regen_root();

	// Seed a live collection on concrete widths, with the wide main complete at the
	// effective target so verify-then-flip passes: an unset full width collapses to the
	// unbounded effective ceiling (PHP_INT_MAX), and the thumbnail still derives at 300.
	$main = write_regen_main( $root, 'wide.webp', 4000, 2400 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 1200, 85, 600, 75 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', PHP_INT_MAX, 85, 300, 75 );
	$live        = seed_regen_descriptor( $root, 1200, 600 );
	$regenerator = new Rendition_Regenerator( $root, $live );

	// The raw target leaves the full width unset (null); finalise is handed the target's
	// effective widths for the sweep/prune but the *raw* target as $flip_to, mirroring
	// the CLI and the REST Edit path.
	$target    = $live->with_renditions( null, 85, 300, 75 );
	$effective = $target->effective_renditions();
	$result    = $regenerator->finalise(
		$effective['full_width'],
		$effective['full_quality'],
		$effective['thumbnail_width'],
		$effective['thumbnail_quality'],
		$target,
	);

	// The flip succeeds and the on-disk descriptor records the full width as null — unset
	// persists distinct from a concrete value, never frozen (#71, ADR-0013).
	expect( $result )->not->toBeFalse();
	$on_disk = Descriptor::read( $root );
	expect( $on_disk->full_width )->toBeNull();
	expect( $on_disk->thumbnail_width )->toBe( 300 );

	regen_remove_tree( $root );
} );

test( 'finalise without a flip-to descriptor freezes concrete widths, unchanged by #71', function (): void {
	$root = fresh_regen_root();

	// The REST batch path (no $flip_to) still flips from the four concrete arguments via
	// with_renditions(), so the existing concrete-flip behaviour is preserved.
	$main = write_regen_main( $root, 'wide.webp', 4000, 2400 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 1200, 85, 600, 75 );
	regen_gd_thumbnailer()->generate( $main, 'wide.webp', 800, 85, 300, 75 );
	seed_regen_descriptor( $root, 1200, 600 );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ) );

	$result = $regenerator->finalise( 800, 85, 300, 75 );

	expect( $result )->not->toBeFalse();
	$on_disk = Descriptor::read( $root );
	expect( $on_disk->full_width )->toBe( 800 );
	expect( $on_disk->thumbnail_width )->toBe( 300 );

	regen_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// main_complete — the per-batch failure signal the controller propagates
// ---------------------------------------------------------------------------

test( 'main_complete is false when a re-derived main wrote none of its renditions', function (): void {
	$root = fresh_regen_root();

	// The wide main needs renditions at the target widths but the encode-failing deriver
	// wrote nothing, so the per-batch check must report the shortfall as incomplete.
	write_regen_main( $root, 'wide.webp', 4000, 2400 );
	seed_regen_descriptor( $root, 1200, 600 );
	$failing     = new Thumbnailer( new Encode_Failing_Codec() );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ), $failing );

	$regenerator->regenerate_main( 0, 800, 85, 300, 75 );

	expect( $regenerator->main_complete( 0, 800, 85, 300, 75 ) )->toBeFalse();

	regen_remove_tree( $root );
} );

test( 'main_complete is true after a real re-derive and for an out-of-range cursor', function (): void {
	$root = fresh_regen_root();

	// A real re-derive writes the target-width renditions, so the addressed main reads
	// complete; an index past the last main has nothing to write and is complete too.
	write_regen_main( $root, 'wide.webp', 4000, 2400 );
	seed_regen_descriptor( $root, 1200, 600 );
	$regenerator = new Rendition_Regenerator( $root, Descriptor::read( $root ) );

	$regenerator->regenerate_main( 0, 800, 85, 300, 75 );

	expect( $regenerator->main_complete( 0, 800, 85, 300, 75 ) )->toBeTrue();
	expect( $regenerator->main_complete( 99, 800, 85, 300, 75 ) )->toBeTrue();

	regen_remove_tree( $root );
} );
