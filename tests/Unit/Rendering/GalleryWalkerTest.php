<?php
/**
 * Unit tests for the Gallery walker's flattening order — `docs/testing.md`
 * § *Gallery rendering: ordering* and ADR-0015 § *Ordering — amends ADR-0005*.
 *
 * The walker flattens a collection sub-tree into one ordered `Gallery_Item`
 * list. The order is the behaviour pinned here: a **pre-order tree traversal** —
 * a folder's own images first (natural sort within the level), then each
 * subfolder (natural sort of subfolder names), each subfolder fully explored
 * before the next is visited. So a top-level image precedes every subfolder's
 * contents even when its name sorts after a subfolder's name — the case the
 * earlier natural-sort-over-the-full-path order got wrong. Descending reverses
 * the sort within each level (both the own-images order and the subfolder
 * order) while preserving the own-images-before-subfolders structure.
 *
 * Each test drives a real `Index_Store` over a real temp tree, exactly as the
 * Storage tests do (`tests/Unit/Storage/IndexStoreTest.php`): the ordering is
 * the behaviour under test, and `Index_Store` is a genuine collaborator that
 * yields the real `subdirs`/`images` shape — nothing about the order is mocked.
 * The store's clock is pushed far forward so every rebuild persists, and the
 * two WordPress functions the rebuild calls are aliased to real PHP via Brain
 * Monkey.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Rendering\Gallery_Item;
use Kntnt\Photo_Drop\Rendering\Gallery_Walker;
use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Wires the WordPress stubs the index rebuild depends on.
 *
 * `wp_json_encode()` gets real `json_encode()` behaviour and `wp_mkdir_p()`
 * gets real recursive `mkdir()`, so each folder's index can be written back
 * into the real temp tree during the walk.
 */
function wire_walker_index_stubs(): void {

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);

}

/**
 * Allocates a fresh temp folder standing in for a collection root.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_collection_root(): string {
	$dir = sys_get_temp_dir() . '/kntnt-walker-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Writes a tiny real WebP main image at a collection-root-relative path.
 *
 * Intermediate folders are created as needed, so a test can lay out a whole
 * tree by listing leaf paths. The image is a real WebP so the store measures
 * genuine dimensions through GD.
 *
 * @param string $root          The collection root.
 * @param string $relative_path The image path relative to the root (forward slashes).
 */
function write_tree_image( string $root, string $relative_path ): void {

	// Create the parent folder chain, then write a minimal real WebP into it.
	$absolute = rtrim( $root, '/' ) . '/' . $relative_path;
	$folder   = dirname( $absolute );
	if ( ! is_dir( $folder ) ) {
		mkdir( $folder, 0700, true );
	}
	$image = imagecreatetruecolor( 4, 3 );
	imagewebp( $image, $absolute );

}

/**
 * Builds a walker over a real far-future-clock store.
 *
 * The far-future clock sidesteps the same-second persist guard so every
 * folder's index is written on first read and the tree is walked exactly as a
 * settled collection would be.
 *
 * @return Gallery_Walker The walker under test.
 */
function fresh_walker(): Gallery_Walker {
	return new Gallery_Walker( new Index_Store( null, static fn (): int => PHP_INT_MAX ) );
}

/**
 * Reduces a walk result to the list of full relative paths it yields, in order.
 *
 * @param array<int,Gallery_Item> $items The walk result.
 * @return array<int,string> The relative paths in walk order.
 */
function walk_paths( array $items ): array {
	return array_map( static fn ( Gallery_Item $item ): string => $item->relative_path(), $items );
}

/**
 * Removes a directory tree used as a temp collection root.
 *
 * @param string $dir The directory to remove.
 */
function walker_remove_tree( string $dir ): void {

	// Unlink a symlink without recursing into its target, then recurse for a
	// real directory and finally drop the directory itself.
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		walker_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );

}

// ---------------------------------------------------------------------------
// Pre-order ascending — own images before subfolders, natural sort per level
// ---------------------------------------------------------------------------

test( "a folder's own images come before every subfolder's images, ascending", function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// A root image whose name sorts after the subfolder names, plus two
	// subfolders each holding one image. Under the old natural-sort-by-full-path
	// order `alpha/a` and `beta/b` would precede `zebra`; pre-order puts the
	// root's own `zebra` first.
	write_tree_image( $root, 'zebra.jpg.webp' );
	write_tree_image( $root, 'alpha/a.jpg.webp' );
	write_tree_image( $root, 'beta/b.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', true, Gallery_Walker::ORDER_ASC );

	expect( walk_paths( $items ) )->toBe( [
		'zebra.jpg.webp',
		'alpha/a.jpg.webp',
		'beta/b.jpg.webp',
	] );

	walker_remove_tree( $root );
} );

test( 'subfolders are visited in natural order, each fully before the next', function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// Numbered subfolders prove natural (not lexical) ordering of subfolder
	// names: `2` before `10`. Each subfolder is fully explored — its own image
	// and then its nested child — before the next subfolder is touched, so the
	// nested `2/deep/...` precedes anything in `10`.
	write_tree_image( $root, 'dir2/x.jpg.webp' );
	write_tree_image( $root, 'dir2/deep/y.jpg.webp' );
	write_tree_image( $root, 'dir10/z.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', true, Gallery_Walker::ORDER_ASC );

	expect( walk_paths( $items ) )->toBe( [
		'dir2/x.jpg.webp',
		'dir2/deep/y.jpg.webp',
		'dir10/z.jpg.webp',
	] );

	walker_remove_tree( $root );
} );

test( 'own images at a level are natural-sorted among themselves, ascending', function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// Three root images with numeric names prove `2` precedes `10` within a
	// level, and that they all precede the single subfolder's image.
	write_tree_image( $root, 'img2.jpg.webp' );
	write_tree_image( $root, 'img10.jpg.webp' );
	write_tree_image( $root, 'img1.jpg.webp' );
	write_tree_image( $root, 'sub/inner.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', true, Gallery_Walker::ORDER_ASC );

	expect( walk_paths( $items ) )->toBe( [
		'img1.jpg.webp',
		'img2.jpg.webp',
		'img10.jpg.webp',
		'sub/inner.jpg.webp',
	] );

	walker_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Pre-order descending — sort reversed per level, structure preserved
// ---------------------------------------------------------------------------

test( 'descending reverses each level yet keeps own images before subfolders', function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// Two root images and two subfolders, each subfolder holding two images.
	// Descending must reverse the own-images order (`b2` before `a1`), reverse
	// the subfolder order (`beta` before `alpha`), and reverse each subfolder's
	// own images — while still emitting the root's own images ahead of any
	// subfolder.
	write_tree_image( $root, 'a1.jpg.webp' );
	write_tree_image( $root, 'b2.jpg.webp' );
	write_tree_image( $root, 'alpha/a-one.jpg.webp' );
	write_tree_image( $root, 'alpha/a-two.jpg.webp' );
	write_tree_image( $root, 'beta/b-one.jpg.webp' );
	write_tree_image( $root, 'beta/b-two.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', true, Gallery_Walker::ORDER_DESC );

	expect( walk_paths( $items ) )->toBe( [
		'b2.jpg.webp',
		'a1.jpg.webp',
		'beta/b-two.jpg.webp',
		'beta/b-one.jpg.webp',
		'alpha/a-two.jpg.webp',
		'alpha/a-one.jpg.webp',
	] );

	walker_remove_tree( $root );
} );

// ---------------------------------------------------------------------------
// Single-folder mode — subfolders ignored, own images naturally sorted
// ---------------------------------------------------------------------------

test( 'single-folder mode lists only the start folder, natural-sorted, ignoring subfolders', function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// The start folder holds numeric-named images and a subfolder. With
	// recursion off, only the folder's own images appear, naturally sorted, and
	// the subfolder's image is absent.
	write_tree_image( $root, 'p2.jpg.webp' );
	write_tree_image( $root, 'p10.jpg.webp' );
	write_tree_image( $root, 'p1.jpg.webp' );
	write_tree_image( $root, 'ignored/hidden.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', false, Gallery_Walker::ORDER_ASC );

	expect( walk_paths( $items ) )->toBe( [
		'p1.jpg.webp',
		'p2.jpg.webp',
		'p10.jpg.webp',
	] );

	walker_remove_tree( $root );
} );

test( 'single-folder descending reverses the start folder and still ignores subfolders', function (): void {
	wire_walker_index_stubs();
	$root = fresh_collection_root();

	// Same single-folder shape, descending: the own images reverse and the
	// subfolder stays out.
	write_tree_image( $root, 'q1.jpg.webp' );
	write_tree_image( $root, 'q2.jpg.webp' );
	write_tree_image( $root, 'q10.jpg.webp' );
	write_tree_image( $root, 'ignored/hidden.jpg.webp' );

	$items = fresh_walker()->walk( $root, '', false, Gallery_Walker::ORDER_DESC );

	expect( walk_paths( $items ) )->toBe( [
		'q10.jpg.webp',
		'q2.jpg.webp',
		'q1.jpg.webp',
	] );

	walker_remove_tree( $root );
} );
