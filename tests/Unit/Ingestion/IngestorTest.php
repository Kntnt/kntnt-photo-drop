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

// ---------------------------------------------------------------------------
// The four outcomes
// ---------------------------------------------------------------------------

test( 'an over-ceiling JPEG is stored as a downscaled WebP main with a reencoded outcome', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = new Descriptor( 'X', 1920, 80, [ 320 ] );

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
	$descriptor = new Descriptor( 'X', 1920, 80, [] );
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
	$descriptor = new Descriptor( 'X', 1920, 80, [] );
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
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

	$result = gd_ingestor( $root, $descriptor )->ingest( 'not an image', 'broken.jpg' );

	expect( $result->outcome )->toBe( Ingest_Outcome::Rejected );
	expect( $result->stored_name )->toBeNull();
	expect( is_file( $root . '/broken.jpg.webp' ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

test( 'a decompression bomb declaring huge dimensions is a per-file rejection, not a fatal', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

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
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

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
	$descriptor = new Descriptor( 'X', 1920, 80, [] );

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
// Thumbnails are derived; the index is never written
// ---------------------------------------------------------------------------

test( 'ingestion derives thumbnails but never writes the index', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = new Descriptor( 'X', 1920, 80, [ 320, 640 ] );

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 2000, 1200 ), 'photo.jpg' );

	// Both thumbnails are written under the hidden directory, but no index.json is
	// created — the index self-heals on the next gallery view (ADR-0006).
	expect( $result->thumbnails )->toHaveCount( 2 );
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/640/photo.jpg.webp' ) )->toBeTrue();
	expect( is_file( $root . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME ) )->toBeFalse();

	ingest_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Mains and thumbnails are published atomically
// ---------------------------------------------------------------------------

test( 'a stored main and its thumbnails leave no staging files behind', function (): void {
	wire_ingestor_stubs();
	$root       = fresh_collection_root();
	$descriptor = new Descriptor( 'X', 1920, 80, [ 320 ] );

	$result = gd_ingestor( $root, $descriptor )->ingest( ingest_jpeg( 1000, 800 ), 'photo.jpg' );

	// The atomic writer stages every file as `<target>.tmp-<random>` beside its
	// target; a clean ingest publishes the main and thumbnail and removes every
	// staging file from both locations.
	expect( $result->outcome )->toBe( Ingest_Outcome::Reencoded );
	expect( glob( $root . '/*.tmp-*' ) )->toBe( [] );
	expect( glob( $root . '/' . Index::THUMBNAILS_DIRNAME . '/*/*.tmp-*' ) )->toBe( [] );

	ingest_remove_tree( $root );
} );
