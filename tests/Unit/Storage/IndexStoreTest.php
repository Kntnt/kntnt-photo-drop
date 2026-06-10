<?php
/**
 * Tests for the self-healing per-folder index — `docs/testing.md`
 * § *Index self-heal via dirMtime*.
 *
 * Every test runs against a real temp directory so the `mtime`-driven self-heal
 * is exercised with real directory mtimes. The dimension reader is injected as
 * a counting stub, so a test can assert exactly how many images were measured —
 * the load-bearing claim that a cache hit reads no image dimensions. The
 * store's clock is injected too: tests of quiescent folders push it far forward
 * so every rebuild persists, and tests of the same-second persist guard freeze
 * it inside the stamped second — both without sleeping across a real second
 * boundary. WordPress functions (`wp_json_encode`, `wp_mkdir_p`) are stubbed
 * via Brain Monkey.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Storage\Index;
use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Wires the WordPress stubs the store depends on.
 *
 * `wp_json_encode()` gets real `json_encode()` behaviour and `wp_mkdir_p()`
 * gets real recursive `mkdir()`, so the index can be written into a real temp
 * tree.
 */
function wire_index_stubs(): void {

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);

}

/**
 * Creates a store whose clock sits far past any stamped mtime — a quiescent folder.
 *
 * The same-second persist guard (ADR-0003) skips writing an index whose
 * stamped second is still running on the wall clock. Tests that pin the
 * cache-hit invariants model folders that have been quiescent past the second
 * boundary, so the clock is pushed far forward and every rebuild persists —
 * keeping those invariants observable without sleeping across a real second.
 *
 * @param Counting_Dimension_Reader $reader The counting reader to inject.
 * @return Index_Store The store with a far-future clock.
 */
function quiescent_index_store( Counting_Dimension_Reader $reader ): Index_Store {
	return new Index_Store( $reader, static fn (): int => PHP_INT_MAX );
}

/**
 * Allocates a fresh temp folder standing in for a collection content folder.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_content_dir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-index-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a real WebP main image of given dimensions into a folder.
 *
 * @param string $folder   The folder to write into.
 * @param string $filename The image filename (must end in `.webp`).
 * @param int    $width    The image width in pixels.
 * @param int    $height   The image height in pixels.
 */
function write_webp( string $folder, string $filename, int $width = 10, int $height = 6 ): void {
	$image = imagecreatetruecolor( $width, $height );
	imagewebp( $image, rtrim( $folder, '/' ) . '/' . $filename );
}

/**
 * Stamps a folder with an explicit mtime so the cache comparison is deterministic.
 *
 * The OS bumps a directory's mtime on any add/remove/rename, but the one-second
 * granularity makes same-second changes invisible. Stamping an explicit mtime
 * models the OS bump faithfully while keeping the test free of sleeps. The
 * *actual* resulting mtime is read back and returned — Patchwork's stream
 * wrapper (loaded with Brain Monkey) can land a touch() offset from the
 * requested second on some platforms, so exact comparisons and frozen test
 * clocks must key off the returned value, never the requested one.
 *
 * @param string $folder The folder to stamp.
 * @param int    $mtime  The mtime to ask for.
 * @return int The mtime the folder actually carries afterwards.
 */
function stamp_mtime( string $folder, int $mtime ): int {
	touch( $folder, $mtime );
	clearstatcache( true, $folder );
	return (int) filemtime( $folder );
}

/**
 * Removes a directory tree used as a temp content folder.
 *
 * @param string $dir The directory to remove.
 */
function index_remove_tree( string $dir ): void {

	// Remove a symlink with unlink rather than recursing into its target.
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		index_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Reads the raw stored index payload for a folder, or null when absent.
 *
 * @param string $folder The content folder.
 * @return array<string,mixed>|null The decoded index, or null.
 */
function read_raw_index( string $folder ): ?array {
	$file = rtrim( $folder, '/' ) . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME;
	if ( ! is_file( $file ) ) {
		return null;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $data ) ? $data : null;
}

// ---------------------------------------------------------------------------
// Cache hit — a matching dirMtime trusts the index and measures no image
// ---------------------------------------------------------------------------

test( 'a matching dirMtime trusts the index and reads no image dimensions', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build the index once (two images measured) with a quiescent-folder clock
	// so the rebuild persists immediately. The rebuild stamps the folder's
	// settled mtime, so a second read with no directory change is a cache hit
	// without any test-side mtime fiddling.
	write_webp( $dir, 'a.jpg.webp', 10, 6 );
	write_webp( $dir, 'b.jpg.webp', 20, 12 );
	$reader = new Counting_Dimension_Reader();
	$store  = quiescent_index_store( $reader );
	$store->get_or_rebuild( $dir );

	// The second read trusts the cache: the reader is not invoked again, yet the
	// dimensions still come back from the stored entries.
	$calls_after_build = $reader->calls;
	$second            = $store->get_or_rebuild( $dir );
	expect( $reader->calls )->toBe( $calls_after_build );
	expect( $second->images )->toHaveCount( 2 );
	expect( $second->images[0]->width )->toBe( 10 );

	index_remove_tree( $dir );
} );

test( 'a fresh store reading a current index measures no image', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build with one store (quiescent clock, so the index persists), then read
	// with a brand-new default-constructed store and its own counting reader —
	// proving the cache-hit path never reads dimensions and never consults the
	// clock, independent of who built the index.
	write_webp( $dir, 'only.jpg.webp', 30, 20 );
	quiescent_index_store( new Counting_Dimension_Reader() )->get_or_rebuild( $dir );

	$reader = new Counting_Dimension_Reader();
	$index  = ( new Index_Store( $reader ) )->get_or_rebuild( $dir );
	expect( $reader->calls )->toBe( 0 );
	expect( $index->images[0]->width )->toBe( 30 );

	index_remove_tree( $dir );
} );

test( 'a freshly built index is a stable cache hit on the very next read', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Creating the hidden thumbnails directory bumps the content folder mtime;
	// the rebuild stamps the mtime *after* that, so the first build does not
	// induce a spurious second rebuild. This pins that regression. The
	// quiescent clock lets the first build persist within the test's second.
	write_webp( $dir, 'stable.jpg.webp', 10, 10 );
	$reader = new Counting_Dimension_Reader();
	$store  = quiescent_index_store( $reader );
	$store->get_or_rebuild( $dir );

	$after_first = $reader->calls;
	$store->get_or_rebuild( $dir );
	expect( $reader->calls )->toBe( $after_first );

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Rebuild triggers — add / remove / rename all bump the mtime
// ---------------------------------------------------------------------------

test( 'adding a file triggers a rebuild on the next read', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build with one image (persisted via the quiescent clock) and pin the
	// matching mtime.
	write_webp( $dir, 'first.jpg.webp', 10, 10 );
	$reader = new Counting_Dimension_Reader();
	$store  = quiescent_index_store( $reader );
	$index  = $store->get_or_rebuild( $dir );
	stamp_mtime( $dir, $index->dir_mtime );

	// Add a second image and bump the mtime (as the OS would); the next read
	// rebuilds and now lists both images.
	write_webp( $dir, 'second.jpg.webp', 20, 20 );
	stamp_mtime( $dir, $index->dir_mtime + 10 );
	$rebuilt = $store->get_or_rebuild( $dir );
	expect( $rebuilt->images )->toHaveCount( 2 );
	$files = array_map( static fn ( $entry ) => $entry->file, $rebuilt->images );
	expect( $files )->toBe( [ 'first.jpg.webp', 'second.jpg.webp' ] );

	index_remove_tree( $dir );
} );

test( 'removing a file triggers a rebuild on the next read', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build with two images (persisted via the quiescent clock), pin the
	// matching mtime, then delete one.
	write_webp( $dir, 'keep.jpg.webp', 10, 10 );
	write_webp( $dir, 'drop.jpg.webp', 20, 20 );
	$store = quiescent_index_store( new Counting_Dimension_Reader() );
	$index = $store->get_or_rebuild( $dir );
	stamp_mtime( $dir, $index->dir_mtime );

	unlink( $dir . '/drop.jpg.webp' );
	stamp_mtime( $dir, $index->dir_mtime + 10 );
	$rebuilt = $store->get_or_rebuild( $dir );
	expect( $rebuilt->images )->toHaveCount( 1 );
	expect( $rebuilt->images[0]->file )->toBe( 'keep.jpg.webp' );

	index_remove_tree( $dir );
} );

test( 'renaming a file triggers a rebuild on the next read', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build with one image (persisted via the quiescent clock), pin the
	// matching mtime, then rename it.
	write_webp( $dir, 'old.jpg.webp', 10, 10 );
	$store = quiescent_index_store( new Counting_Dimension_Reader() );
	$index = $store->get_or_rebuild( $dir );
	stamp_mtime( $dir, $index->dir_mtime );

	rename( $dir . '/old.jpg.webp', $dir . '/new.jpg.webp' );
	stamp_mtime( $dir, $index->dir_mtime + 10 );
	$rebuilt = $store->get_or_rebuild( $dir );
	expect( $rebuilt->images[0]->file )->toBe( 'new.jpg.webp' );

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Move — regenerates both the source and the destination folder indexes
// ---------------------------------------------------------------------------

test( 'a move regenerates both the source and destination indexes', function (): void {
	wire_index_stubs();
	$source      = fresh_content_dir();
	$destination = fresh_content_dir();

	// Build current, persisted indexes for both folders: source has the image,
	// destination is empty. Pin each folder's matching mtime.
	write_webp( $source, 'moving.jpg.webp', 10, 10 );
	$store      = quiescent_index_store( new Counting_Dimension_Reader() );
	$src_index  = $store->get_or_rebuild( $source );
	$dest_index = $store->get_or_rebuild( $destination );
	stamp_mtime( $source, $src_index->dir_mtime );
	stamp_mtime( $destination, $dest_index->dir_mtime );

	// Move the image across; a real move bumps both folders' mtimes, so stamp
	// both forward, then re-read both.
	rename( $source . '/moving.jpg.webp', $destination . '/moving.jpg.webp' );
	stamp_mtime( $source, $src_index->dir_mtime + 10 );
	stamp_mtime( $destination, $dest_index->dir_mtime + 10 );

	// The source index regenerates to empty; the destination regenerates to hold
	// the moved image.
	expect( $store->get_or_rebuild( $source )->images )->toHaveCount( 0 );
	expect( $store->get_or_rebuild( $destination )->images )->toHaveCount( 1 );
	expect( $store->get_or_rebuild( $destination )->images[0]->file )->toBe( 'moving.jpg.webp' );

	index_remove_tree( $source );
	index_remove_tree( $destination );
} );

// ---------------------------------------------------------------------------
// The directory is the truth — a hand-deleted index is rebuilt
// ---------------------------------------------------------------------------

test( 'a hand-deleted index is rebuilt from the directory', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build the index, settle the folder in the past, and re-persist so the
	// cached dirMtime exactly matches the live folder mtime — then hand-delete
	// the index file without touching the directory.
	write_webp( $dir, 'survivor.jpg.webp', 15, 9 );
	$store = quiescent_index_store( new Counting_Dimension_Reader() );
	$store->get_or_rebuild( $dir );
	stamp_mtime( $dir, time() - 100 );
	$store->rebuild( $dir );
	unlink( $dir . '/' . Index::THUMBNAILS_DIRNAME . '/' . Index::FILENAME );

	// Even though the folder mtime is unchanged, a missing index forces a
	// rebuild from the directory — the index is a cache, never authoritative.
	$rebuilt = $store->get_or_rebuild( $dir );
	expect( $rebuilt->images )->toHaveCount( 1 );
	expect( $rebuilt->images[0]->file )->toBe( 'survivor.jpg.webp' );
	expect( read_raw_index( $dir ) )->not->toBeNull();

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Stored shape — sorted images, listed subdirs, recorded dirMtime, dimensions
// ---------------------------------------------------------------------------

test( 'images are stored sorted ascending by filename', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Write images out of order; the rebuilt index must store them ascending.
	// The quiescent clock makes the rebuild persist, so the on-disk order is
	// assertable too.
	write_webp( $dir, 'c.jpg.webp', 10, 10 );
	write_webp( $dir, 'a.jpg.webp', 10, 10 );
	write_webp( $dir, 'b.jpg.webp', 10, 10 );
	$index = quiescent_index_store( new Counting_Dimension_Reader() )->get_or_rebuild( $dir );

	$files = array_map( static fn ( $entry ) => $entry->file, $index->images );
	expect( $files )->toBe( [ 'a.jpg.webp', 'b.jpg.webp', 'c.jpg.webp' ] );

	// The persisted file is sorted too, not just the in-memory result.
	$raw = read_raw_index( $dir );
	expect( array_column( $raw['images'], 'file' ) )->toBe( [ 'a.jpg.webp', 'b.jpg.webp', 'c.jpg.webp' ] );

	index_remove_tree( $dir );
} );

test( 'subdirs are listed and the hidden thumbnails dir is excluded', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Two content sub-folders plus one image; the hidden thumbnails directory is
	// ours and must never appear as a content subdir.
	mkdir( $dir . '/morning', 0700 );
	mkdir( $dir . '/evening', 0700 );
	write_webp( $dir, 'top.jpg.webp', 10, 10 );
	$index = ( new Index_Store( new Counting_Dimension_Reader() ) )->get_or_rebuild( $dir );

	expect( $index->subdirs )->toBe( [ 'evening', 'morning' ] );
	expect( $index->subdirs )->not->toContain( Index::THUMBNAILS_DIRNAME );

	index_remove_tree( $dir );
} );

test( 'the rebuild records the folder mtime and each image dimension', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Rebuild (persisted via the quiescent clock), then assert the stored
	// dirMtime equals the folder's settled mtime (taken after the hidden
	// directory exists) and each entry carries its real pixel dimensions.
	write_webp( $dir, 'sized.jpg.webp', 24, 16 );
	$index = quiescent_index_store( new Counting_Dimension_Reader() )->get_or_rebuild( $dir );

	clearstatcache( true, $dir );
	expect( $index->dir_mtime )->toBe( filemtime( $dir ) );
	expect( $index->images[0]->width )->toBe( 24 );
	expect( $index->images[0]->height )->toBe( 16 );

	$raw = read_raw_index( $dir );
	expect( $raw['schema'] )->toBe( Index::SCHEMA );
	expect( $raw['dirMtime'] )->toBe( $index->dir_mtime );

	index_remove_tree( $dir );
} );

test( 'only main webp images are indexed, not thumbnails or foreign files', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// A main image, a non-webp foreign file, and a thumbnail under the hidden
	// directory: only the main image is indexed.
	write_webp( $dir, 'main.jpg.webp', 10, 10 );
	file_put_contents( $dir . '/notes.txt', 'foreign' );
	mkdir( $dir . '/' . Index::THUMBNAILS_DIRNAME . '/640', 0700, true );
	write_webp( $dir . '/' . Index::THUMBNAILS_DIRNAME . '/640', 'main.jpg.webp', 8, 8 );
	$index = ( new Index_Store( new Counting_Dimension_Reader() ) )->get_or_rebuild( $dir );

	expect( $index->images )->toHaveCount( 1 );
	expect( $index->images[0]->file )->toBe( 'main.jpg.webp' );

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Absent folder — nothing to index
// ---------------------------------------------------------------------------

test( 'get_or_rebuild returns null for a folder that does not exist', function (): void {
	wire_index_stubs();

	$missing = sys_get_temp_dir() . '/kntnt-index-absent-' . bin2hex( random_bytes( 6 ) );
	expect( ( new Index_Store( new Counting_Dimension_Reader() ) )->get_or_rebuild( $missing ) )->toBeNull();

} );

// ---------------------------------------------------------------------------
// Real mutation — a real add bumps the mtime without an explicit stamp
// ---------------------------------------------------------------------------

test( 'a real filesystem add bumps the directory mtime and forces a rebuild', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Build the index, then back-date the folder mtime so a real add lands in a
	// later second and the bump is observable without sleeping.
	write_webp( $dir, 'one.jpg.webp', 10, 10 );
	$store = new Index_Store( new Counting_Dimension_Reader() );
	$index = $store->get_or_rebuild( $dir );
	touch( $dir, time() - 100 );
	clearstatcache( true, $dir );

	// Persist the back-dated mtime into the index so the cache would match — then
	// a real add must still bump the live mtime past it, forcing a rebuild.
	$store->rebuild( $dir );
	write_webp( $dir, 'two.jpg.webp', 20, 20 );
	clearstatcache( true, $dir );
	expect( $store->get_or_rebuild( $dir )->images )->toHaveCount( 2 );

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Same-second persist guard — a stamped second still running is never cached
// ---------------------------------------------------------------------------

test( 'a rebuild within the stamped second does not persist the index', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Pre-create the hidden artifact directory so the rebuild's ensure step
	// cannot bump the folder mtime, then pin the folder inside the clock's
	// current second — the window where another mutation could still land
	// without a visible mtime change.
	write_webp( $dir, 'racing.jpg.webp', 10, 10 );
	mkdir( $dir . '/' . Index::THUMBNAILS_DIRNAME, 0700 );
	$stamped = stamp_mtime( $dir, 1_000_000 );
	$store   = new Index_Store( new Counting_Dimension_Reader(), static fn (): int => $stamped );
	$index   = $store->get_or_rebuild( $dir );

	// The fresh index is served in memory, but the cache file stays unwritten —
	// the next read rebuilds rather than trusting a maybe-stale stamp.
	expect( $index->images )->toHaveCount( 1 );
	expect( read_raw_index( $dir ) )->toBeNull();

	index_remove_tree( $dir );
} );

test( 'the next read after quiescence persists the index and then cache-hits', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// Freeze the clock inside the folder's stamped second: the first read
	// rebuilds but, per the persist guard, writes nothing.
	write_webp( $dir, 'settled.jpg.webp', 10, 10 );
	mkdir( $dir . '/' . Index::THUMBNAILS_DIRNAME, 0700 );
	$stamped = stamp_mtime( $dir, 1_000_000 );
	$now     = $stamped;
	$clock   = static function () use ( &$now ): int {
		return $now;
	};
	$reader = new Counting_Dimension_Reader();
	$store  = new Index_Store( $reader, $clock );
	$store->get_or_rebuild( $dir );
	expect( read_raw_index( $dir ) )->toBeNull();

	// One second later the folder is quiescent past the stamped second: the
	// next read rebuilds once more and persists the settled stamp.
	$now = $stamped + 1;
	$store->get_or_rebuild( $dir );
	expect( read_raw_index( $dir )['dirMtime'] )->toBe( $stamped );

	// From here on the cache holds: a further read measures no image.
	$calls = $reader->calls;
	$index = $store->get_or_rebuild( $dir );
	expect( $reader->calls )->toBe( $calls );
	expect( $index->images )->toHaveCount( 1 );

	index_remove_tree( $dir );
} );

test( 'an image added in the same second as a rebuild is visible on the next read', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// First read inside second T: the rebuild sees one image and, per the
	// persist guard, writes nothing.
	write_webp( $dir, 'first.jpg.webp', 10, 10 );
	mkdir( $dir . '/' . Index::THUMBNAILS_DIRNAME, 0700 );
	$stamped = stamp_mtime( $dir, 1_000_000 );
	$store   = new Index_Store( new Counting_Dimension_Reader(), static fn (): int => $stamped );
	expect( $store->get_or_rebuild( $dir )->images )->toHaveCount( 1 );

	// A second upload lands within the same second T, so the folder mtime is
	// unchanged — the exact interleaving that used to freeze a stale index as
	// fresh forever. The next read must rebuild and surface both images.
	write_webp( $dir, 'second.jpg.webp', 20, 20 );
	stamp_mtime( $dir, 1_000_000 );
	expect( $store->get_or_rebuild( $dir )->images )->toHaveCount( 2 );

	index_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// Symlinks — never walked, never indexed
// ---------------------------------------------------------------------------

test( 'symlinked entries are skipped by the rebuild', function (): void {
	wire_index_stubs();
	$dir     = fresh_content_dir();
	$outside = fresh_content_dir();

	// A symlinked directory (a self-link is a guaranteed walk cycle) and a
	// symlinked main image must both be treated as foreign and skipped — they
	// did not enter through the optimisation boundary.
	write_webp( $dir, 'real.jpg.webp', 10, 10 );
	write_webp( $outside, 'lured.jpg.webp', 10, 10 );
	symlink( $dir, $dir . '/loop' );
	symlink( $outside . '/lured.jpg.webp', $dir . '/lured.jpg.webp' );
	$index = ( new Index_Store( new Counting_Dimension_Reader() ) )->get_or_rebuild( $dir );

	expect( $index->subdirs )->toBe( [] );
	expect( array_map( static fn ( $entry ) => $entry->file, $index->images ) )->toBe( [ 'real.jpg.webp' ] );

	index_remove_tree( $dir );
	index_remove_tree( $outside );
} );

// ---------------------------------------------------------------------------
// Atomic persistence — no staging file survives a successful write
// ---------------------------------------------------------------------------

test( 'a persisted index leaves no staging file in the thumbnails directory', function (): void {
	wire_index_stubs();
	$dir = fresh_content_dir();

	// The index is published atomically; a successful persist must leave no
	// `*.tmp-*` staging file beside index.json.
	write_webp( $dir, 'tidy.jpg.webp', 10, 10 );
	quiescent_index_store( new Counting_Dimension_Reader() )->get_or_rebuild( $dir );

	expect( read_raw_index( $dir ) )->not->toBeNull();
	expect( glob( $dir . '/' . Index::THUMBNAILS_DIRNAME . '/*.tmp-*' ) )->toBe( [] );

	index_remove_tree( $dir );
} );
