<?php
/**
 * Tests for the pure breadcrumb assembly (ADR-0015, the overlay model).
 *
 * Breadcrumbs replace the former caption: the image's humanised folder path with
 * the collection's display name as the first crumb, a hide-count that drops that
 * many leading crumbs, and a free-text separator joining them. Segments are
 * *always* humanised (there is no humanise toggle any more), the stored
 * `<original>.webp` filename maps back to its original before humanising, and the
 * derivation is pure — no I/O — so every hide-count and path shape is asserted
 * directly here rather than through a render (docs/testing.md, "#47 Pure core").
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Rendering\Breadcrumb_Builder;

// ---------------------------------------------------------------------------
// crumbs() — the collection name leads, segments humanise, hide-count drops
// ---------------------------------------------------------------------------

test( 'the first crumb is the collection display name, then each humanised folder, then the file', function (): void {

	// A two-folder image under "Trip": the crumbs read collection → folders →
	// humanised filename (the stored .webp and the original extension both gone).
	$crumbs = Breadcrumb_Builder::crumbs( '2024_summer/day-one/IMG_5.jpg.webp', 'Trip', 0 );

	expect( $crumbs )->toBe( [ 'Trip', '2024 summer', 'day one', 'IMG 5' ] );

} );

test( 'a root-level image is just the collection name and the humanised filename', function (): void {

	// No folder segments, so the crumbs are only the collection name and the file.
	$crumbs = Breadcrumb_Builder::crumbs( 'lonely.jpg.webp', 'Trip', 0 );

	expect( $crumbs )->toBe( [ 'Trip', 'lonely' ] );

} );

test( 'an already-webp original is humanised without inventing an extensionless name', function (): void {

	// sunset.webp was stored verbatim (no appended .webp); humanising drops the
	// real .webp extension to "sunset" rather than treating it as the stored suffix.
	$crumbs = Breadcrumb_Builder::crumbs( 'sunset.webp', 'Trip', 0 );

	expect( $crumbs )->toBe( [ 'Trip', 'sunset' ] );

} );

// ---------------------------------------------------------------------------
// crumbs() — the hide-count drops leading crumbs (the collection first)
// ---------------------------------------------------------------------------

test( 'hide-count 1 drops the collection name, leaving the path from the first folder', function (): void {

	$crumbs = Breadcrumb_Builder::crumbs( 'a-folder/b-folder/file.jpg.webp', 'Coll', 1 );

	expect( $crumbs )->toBe( [ 'a folder', 'b folder', 'file' ] );

} );

test( 'hide-count 2 drops the collection name and the next leading crumb', function (): void {

	$crumbs = Breadcrumb_Builder::crumbs( 'a-folder/b-folder/file.jpg.webp', 'Coll', 2 );

	expect( $crumbs )->toBe( [ 'b folder', 'file' ] );

} );

test( 'a hide-count at or beyond the crumb count never drops the final filename crumb', function (): void {

	// Hiding everything would leave a blank breadcrumb; the last crumb (the image)
	// is always kept so the overlay still names the image it sits on.
	$crumbs = Breadcrumb_Builder::crumbs( 'a/b.jpg.webp', 'Coll', 99 );

	expect( $crumbs )->toBe( [ 'b' ] );

} );

test( 'a negative hide-count is treated as zero (all crumbs shown)', function (): void {

	$crumbs = Breadcrumb_Builder::crumbs( 'a/b.jpg.webp', 'Coll', -3 );

	expect( $crumbs )->toBe( [ 'Coll', 'a', 'b' ] );

} );

test( 'an empty collection name contributes no leading crumb', function (): void {

	// A collection with no display name yields no empty leading crumb — the path
	// starts at its first real segment.
	$crumbs = Breadcrumb_Builder::crumbs( 'a/b.jpg.webp', '', 0 );

	expect( $crumbs )->toBe( [ 'a', 'b' ] );

} );

// ---------------------------------------------------------------------------
// text() — the crumbs joined by the separator, padded by spaces
// ---------------------------------------------------------------------------

test( 'text joins the crumbs with the separator padded by single spaces', function (): void {

	$text = Breadcrumb_Builder::text( '2024_summer/IMG_5.jpg.webp', 'Trip', 0, '›' );

	expect( $text )->toBe( 'Trip › 2024 summer › IMG 5' );

} );

test( 'an empty separator collapses to a single space so crumbs never run together', function (): void {

	$text = Breadcrumb_Builder::text( 'a/b.jpg.webp', 'Coll', 0, '' );

	expect( $text )->toBe( 'Coll a b' );

} );

test( 'text honours the hide-count, dropping the leading crumbs before the join', function (): void {

	$text = Breadcrumb_Builder::text( 'a-folder/file.jpg.webp', 'Coll', 1, '›' );

	expect( $text )->toBe( 'a folder › file' );

} );

// ---------------------------------------------------------------------------
// humanise_filename() — the alt-text helper (filename only, always humanised)
// ---------------------------------------------------------------------------

test( 'humanise_filename recovers the original name and humanises just the file', function (): void {

	$alt = Breadcrumb_Builder::humanise_filename( 'morning/sun_rise-01.jpg.webp' );

	expect( $alt )->toBe( 'sun rise 01' );

} );
