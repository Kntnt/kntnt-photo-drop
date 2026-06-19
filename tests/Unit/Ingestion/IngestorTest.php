<?php
/**
 * Tests for the ingestion orchestrator — the one path a file takes into a
 * collection through the contract boundary.
 *
 * Each test ingests a real image into a real temp collection root with the real
 * GD-backed engine, then asserts the on-disk effect and the per-file outcome.
 * It covers the four outcomes (`stored`/`reencoded`/`skipped`/`rejected`),
 * idempotency with and without `--overwrite`, sub-directory recreation confined
 * by `Path_Guard`, hostile-path rejection with no write, the `<original>.webp`
 * naming, and that the index is never written (it self-heals later).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Optimizer;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Ingestion\Ingest_Outcome;
use Kntnt\Photo_Drop\Ingestion\Ingestor;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Tests\Unit\Fixtures\Counting_Codec;

/**
 * Wires the WordPress helper the ingestor's directory creation may reach for.
 *
 * `wp_mkdir_p()` gets real recursive `mkdir()` behaviour so sub-directory
 * recreation works against a real temp tree.
 */
function wire_ingestor_stubs(): void {

	// Real recursive mkdir for the directory helper, and a no-op for the memory
	// raise the optimiser performs before decoding.
	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);
	Functions\when( 'wp_raise_memory_limit' )->justReturn( true );

}

/**
 * Allocates a fresh temp directory standing in for a collection root.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_collection_root(): string {
	$dir = sys_get_temp_dir() . '/kntnt-ingest-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Encodes a solid-colour true-colour image to JPEG bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The JPEG bytes.
 */
function ingest_jpeg( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 80, 140, 60 ) );
	ob_start();
	imagejpeg( $image, null, 90 );
	return (string) ob_get_clean();
}

/**
 * Encodes a true-colour image to WebP bytes at a given size.
 *
 * @param int $width  The image width in pixels.
 * @param int $height The image height in pixels.
 * @return string The WebP bytes.
 */
function ingest_webp( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 60, 120, 200 ) );
	ob_start();
	imagewebp( $image, null, 80 );
	return (string) ob_get_clean();
}

/**
 * Removes a directory tree used as a temp collection root.
 *
 * @param string $dir The directory to remove.
 */
function ingest_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		ingest_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds an ingestor anchored at a root with the real GD-backed engine.
 *
 * @param string     $root       The collection root.
 * @param Descriptor $descriptor The output contract.
 * @return Ingestor The ingestor under test.
 */
function gd_ingestor( string $root, Descriptor $descriptor ): Ingestor {
	$codec = new Gd_Webp_Codec();
	return new Ingestor( $root, $descriptor, new Optimizer( $codec ), new Thumbnailer( $codec ) );
}

/**
 * Builds an ingestor whose optimiser and deriver share one decode-counting codec.
 *
 * The same `Counting_Codec` backs both collaborators, so the tally is the total
 * number of full-resolution decodes one `ingest()` performs across the whole
 * ingestion path — the instrument the equal-widths parity test reads to prove no
 * redundant second decode happens (issue #57).
 *
 * @param string         $root       The collection root.
 * @param Descriptor     $descriptor The output contract.
 * @param Counting_Codec $codec      The shared decode-counting codec.
 * @return Ingestor The ingestor under test, instrumented for decode counting.
 */
function counting_ingestor( string $root, Descriptor $descriptor, Counting_Codec $codec ): Ingestor {
	return new Ingestor( $root, $descriptor, new Optimizer( $codec ), new Thumbnailer( $codec ) );
}

/**
 * Builds a three-rendition descriptor, overriding individual fields.
 *
 * The upload pair fixes the contract (the main width and quality); the full and
 * thumbnail pairs drive which derived files the ingestor writes (ADR-0013). The
 * defaults make a wide main produce both a full (1280) and a thumbnail (320).
 *
 * @param array<string,mixed> $overrides Field overrides keyed by constructor parameter name.
 * @return Descriptor The descriptor under test.
 */
function ingest_descriptor( array $overrides = [] ): Descriptor {
	$fields = array_merge(
		[
			'upload_width'      => 1920,
			'upload_quality'    => 80,
			'full_width'        => 1280,
			'full_quality'      => 80,
			'thumbnail_width'   => 320,
			'thumbnail_quality' => 75,
		],
		$overrides,
	);
	return new Descriptor(
		'X',
		$fields['upload_width'],
		$fields['upload_quality'],
		$fields['full_width'],
		$fields['full_quality'],
		$fields['thumbnail_width'],
		$fields['thumbnail_quality'],
		'%year%',
	);
}

// ---------------------------------------------------------------------------
// The four outcomes
// ---------------------------------------------------------------------------

test( 'an over-ceiling JPEG is stored as a downscaled WebP main with a reencoded outcome', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 3000, 1500 ), 'IMG_2024.jpg' );

	// The source was transformed, so the outcome is reencoded; the stored main is
	// named `<original>.webp` and sits at the root, downscaled to the ceiling.
	expect( $result->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( $result->stored_name )->toBe( 'IMG_2024.jpg.webp' );
	expect( is_file( $root . '/IMG_2024.jpg.webp' ) )->toBeTrue();
	expect( (int) getimagesize( $root . '/IMG_2024.jpg.webp' )[0] )->toBe( 1920 );

	ingest_remove_tree( $root );
} );

test( 'an already-conforming WebP is stored byte-identical with a stored outcome', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();
	$source     = ingest_webp( 800, 600 );

	$result = gd_ingestor( $root, $descriptor )->ingest( $source, 'sunset.webp' );

	// Already WebP and within the ceiling: accepted as-is, so the outcome is stored
	// (not reencoded), the name is not doubled, and the bytes are identical.
	expect( $result->outcome )->toBe( Ingest_Outcome::Stored );
	expect( $result->stored_name )->toBe( 'sunset.webp' );
	expect( file_get_contents( $root . '/sunset.webp' ) )->toBe( $source );

	ingest_remove_tree( $root );
} );

test( 'an existing target is skipped without overwrite and forced with it', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();
	$ingestor   = gd_ingestor( $root, $descriptor );

	// First ingest writes the main; a second without overwrite must skip it
	// untouched, and the stored bytes must be unchanged from the first write.
	$ingestor->ingest( ingest_jpeg( 1000, 800 ), 'photo.jpg' );
	$after_first = file_get_contents( $root . '/photo.jpg.webp' );
	$skip        = $ingestor->ingest( ingest_jpeg( 1200, 900 ), 'photo.jpg' );

	expect( $skip->outcome )->toBe( Ingest_Outcome::Skipped );
	expect( file_get_contents( $root . '/photo.jpg.webp' ) )->toBe( $after_first );

	// With overwrite the second ingest replaces the main, so the bytes change.
	$forced = $ingestor->ingest( ingest_jpeg( 1200, 900 ), 'photo.jpg', true );
	expect( $forced->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( file_get_contents( $root . '/photo.jpg.webp' ) )->not->toBe( $after_first );

	ingest_remove_tree( $root );
} );

test( 'an undecodable source is rejected with nothing written', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( 'not an image', 'broken.jpg' );

	expect( $result->outcome )->toBe( Ingest_Outcome::Rejected );
	expect( $result->stored_name )->toBeNull();
	expect( is_file( $root . '/broken.jpg.webp' ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

test( 'a decompression bomb declaring huge dimensions is a per-file rejection, not a fatal', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	// A PNG header declaring 100000×100000 pixels with no body: the probe
	// reports ten gigapixels, so the ingestion path must reject this one file
	// before any decode could OOM-kill the whole batch.
	$ihdr_data = pack( 'N', 100000 ) . pack( 'N', 100000 ) . "\x08\x06\x00\x00\x00";
	$ihdr      = pack( 'N', 13 ) . 'IHDR' . $ihdr_data . pack( 'N', crc32( 'IHDR' . $ihdr_data ) );
	$bomb      = "\x89PNG\r\n\x1a\n" . $ihdr;

	$result = gd_ingestor( $root, $descriptor )->ingest( $bomb, 'bomb.png' );

	expect( $result->outcome )->toBe( Ingest_Outcome::Rejected );
	expect( is_file( $root . '/bomb.png.webp' ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Sub-directory recreation, confined by Path_Guard
// ---------------------------------------------------------------------------

test( 'a relative path recreates its sub-directories confined inside the root', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1000, 800 ), 'photos/2024/IMG.jpg' );

	// The sub-tree is recreated under the root and the main lands inside it.
	expect( $result->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( is_file( $root . '/photos/2024/IMG.jpg.webp' ) )->toBeTrue();
	expect( realpath( $root . '/photos/2024' ) )->toStartWith( realpath( $root ) );

	ingest_remove_tree( $root );
} );

test( 'a hostile traversal path is rejected and writes nothing outside the root', function ( string $hostile ): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 800, 600 ), $hostile );

	// Every hostile target is rejected with no write; the root holds only the
	// descriptor-less empty tree it started with (no main escaped above it).
	expect( $result->outcome )->toBe( Ingest_Outcome::Rejected );
	expect( glob( $root . '/*' ) )->toBe( [] );

	ingest_remove_tree( $root );
} )->with( [
	'parent traversal'   => [ '../escape.jpg' ],
	'deep traversal'     => [ '../../../../etc/passwd.jpg' ],
	'encoded traversal'  => [ '%2e%2e%2fescape.jpg' ],
	'absolute path'      => [ '/etc/passwd.jpg' ],
	'embedded traversal' => [ 'a/../../b.jpg' ],
] );

// ---------------------------------------------------------------------------
// The full and thumbnail renditions are derived; the index is never written
// ---------------------------------------------------------------------------

test( 'ingestion derives the full and thumbnail renditions but never writes the index', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 2000, 1200 ), 'photo.jpg' );

	// The 2000px source is downscaled to the 1920 upload ceiling, so the main (1920)
	// is wider than the 1280 full and the 1280 full wider than the 320 thumbnail:
	// both derived files appear under the hidden directory, but no index.json is
	// created — the index self-heals on the next gallery view (ADR-0006).
	expect( $result->thumbnails )->toHaveCount( 2 );
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/1280/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Collapse-to-parent — an unset re-derivable field collapses during ingest (#71)
// ---------------------------------------------------------------------------

test( 'an unset full width writes no separate full; the main serves the full role', function (): void {
	wire_ingestor_stubs();
	$root = fresh_collection_root();

	// A null full width is the collapse-to-parent "unset": effective_renditions()
	// resolves it to PHP_INT_MAX, so no main is ever strictly wider than the full
	// ceiling and no separate full file is written — the main itself is the full
	// rendition (#71, ADR-0013). The thumbnail still derives at its own width.
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'full_width'      => null,
		'thumbnail_width' => 320,
	] );
	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1600, 1000 ), 'photo.jpg' );

	// Only the 320 thumbnail bucket exists; there is no separate full bucket at the
	// main's own width, and the main is on disk serving the full role itself.
	expect( $result->thumbnails )->toHaveCount( 1 );
	expect( is_file( $root . '/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp' ) )->toBeTrue();
	expect( is_dir( $root . '/' . Index::THUMBNAILS_DIRNAME . '/1600' ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

test( 'an unset thumbnail width collapses into the full bucket during ingest', function (): void {
	wire_ingestor_stubs();
	$root = fresh_collection_root();

	// A null thumbnail width follows the effective full width (#71), so the thumbnail
	// derives at the full's 1280 width and collapses into the single 1280 bucket rather
	// than producing a second, narrower bucket.
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'full_width'      => 1280,
		'thumbnail_width' => null,
	] );
	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 2000, 1200 ), 'photo.jpg' );

	// The main downscales to the 1920 ceiling and the single derived rendition is the
	// 1280 full, which the thumbnail collapses into — exactly one bucket, no 320.
	expect( $result->thumbnails )->toHaveCount( 1 );
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/1280/photo.jpg.webp' ) )->toBeTrue();
	expect( is_dir( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320' ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

test( 'unset full width and thumbnail width together write no derived buckets', function (): void {
	wire_ingestor_stubs();
	$root = fresh_collection_root();

	// Both re-derivable widths unset: the full collapses to the unbounded ceiling and
	// the thumbnail follows it, so the main serves every role and no derived bucket is
	// written at all (the maximal collapse; #71, ADR-0013).
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'full_width'      => null,
		'thumbnail_width' => null,
	] );
	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1600, 1000 ), 'photo.jpg' );

	// The main is stored and no thumbnail corral bucket exists beneath it.
	expect( $result->thumbnails )->toHaveCount( 0 );
	expect( is_file( $root . '/photo.jpg.webp' ) )->toBeTrue();
	expect( glob( $root . '/' . Index::THUMBNAILS_DIRNAME . '/*', GLOB_ONLYDIR ) )->toBe( [] );

	ingest_remove_tree( $root );
} );

test( 'an unset full quality encodes the full at the upload quality during ingest', function (): void {
	wire_ingestor_stubs();
	$root = fresh_collection_root();

	// A null full quality follows the upload quality (#71). With a distinct upload
	// quality and a full width below the main width, the full is written at the upload
	// quality the descriptor's effective renditions resolve to.
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'upload_quality'  => 90,
		'full_width'      => 1280,
		'full_quality'    => null,
		'thumbnail_width' => 320,
	] );
	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 2000, 1200 ), 'photo.jpg' );

	// The full bucket is written (the unset full quality resolved to the upload 90), and
	// the effective renditions confirm the resolution the ingest acted on.
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/1280/photo.jpg.webp' ) )->toBeTrue();
	expect( $descriptor->effective_renditions()['full_quality'] )->toBe( 90 );
	expect( $result->thumbnails )->toHaveCount( 2 );

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Mains and derived renditions are published atomically
// ---------------------------------------------------------------------------

test( 'a stored main and its derived renditions leave no staging files behind', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1000, 800 ), 'photo.jpg' );

	// The atomic writer stages every file as `<target>.tmp-<random>` beside its
	// target; a clean ingest publishes the main and its derived renditions and
	// removes every staging file from both locations.
	expect( $result->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( glob( $root . '/*.tmp-*' ) )->toBe( [] );
	expect( glob( $root . '/' . Index::THUMBNAILS_DIRNAME . '/*/*.tmp-*' ) )->toBe( [] );

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Per-image decode parity — one decode per ingest, no redundant second pass
// (issue #57: equal-width collections must not cost more than the two-tier baseline)
// ---------------------------------------------------------------------------

test( 'ingesting an accepted-as-is main decodes it exactly once across the whole path', function (): void {
	wire_ingestor_stubs();
	$root  = fresh_collection_root();
	$codec = new Counting_Codec();

	// The equal-widths collection (full = upload): the contract enforcement and the
	// rendition derivation are the same per-image work as the two-tier baseline, so a
	// conforming WebP under the ceiling must be decoded once for integrity validation
	// and never re-read and re-decoded to derive its thumbnail.
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'full_width'      => 1920,
		'thumbnail_width' => 640,
	] );
	counting_ingestor( $root, $descriptor, $codec )->ingest( ingest_webp( 1600, 1000 ), 'photo.webp' );

	// The main and its 640 thumbnail are on disk, yet only a single full-resolution
	// decode happened: the deriver reused the optimiser's already-decoded handle
	// rather than re-reading and re-decoding the freshly stored main.
	expect( is_file( $root . '/photo.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/640/photo.webp' ) )->toBeTrue();
	expect( $codec->decodes )->toBe( 1 );

	ingest_remove_tree( $root );
} );

test( 'ingesting a re-encoded main decodes it exactly once across the whole path', function (): void {
	wire_ingestor_stubs();
	$root  = fresh_collection_root();
	$codec = new Counting_Codec();

	// A 3000px JPEG into an equal-widths collection: the optimiser decodes, downscales
	// to the 1920 upload ceiling, and re-encodes; the deriver then needs the 1920 main
	// to write the 640 thumbnail. That main is exactly the optimiser's scaled handle,
	// so no second decode of the stored main is needed.
	$descriptor = ingest_descriptor( [
		'upload_width'    => 1920,
		'full_width'      => 1920,
		'thumbnail_width' => 640,
	] );
	$result = counting_ingestor( $root, $descriptor, $codec )->ingest( ingest_jpeg( 3000, 1800 ), 'big.jpg' );

	// The downscaled main and its thumbnail are written, and the whole ingest decoded
	// the pixels just once — the redundant re-decode the redesign introduced is gone.
	expect( $result->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/640/big.jpg.webp' ) )->toBeTrue();
	expect( $codec->decodes )->toBe( 1 );

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// A directory-creation failure is a per-file rejection (ADR-0006)
// ---------------------------------------------------------------------------

test( 'a directory-creation failure is a per-file rejection with nothing written', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = ingest_descriptor();

	// Force the directory helper to fail, standing in for disk-full or revoked
	// permissions while recreating the confined sub-tree; this replaces the
	// beforeEach alias for this test only and reaches wp_mkdir_p() because the
	// target's sub-directory does not yet exist (ensure_dir() would otherwise
	// short-circuit on is_dir()).
	Functions\when( 'wp_mkdir_p' )->justReturn( false );

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1000, 800 ), 'new-sub/photo.jpg' );

	// The one file is rejected, no main is written at the would-be target, and no
	// orphan sub-directory is left behind under the root (it holds nothing).
	expect( $result->outcome )->toBe( Ingest_Outcome::Rejected );
	expect( is_file( $root . '/new-sub/photo.jpg.webp' ) )->toBeFalse();
	expect( glob( $root . '/*' ) )->toBe( [] );

	ingest_remove_tree( $root );
} );
