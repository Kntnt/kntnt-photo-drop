<?php
/**
 * Tests for the `<original>.webp` main-image naming rule.
 *
 * Covers the to-stored direction (`.jpg`/`.png` → `name.ext.webp`, already-WebP
 * not doubled, uppercase extension, multi-dot, unicode) and reversibility back
 * to the original. Pure-function tests: no WordPress stubs, no filesystem.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Collection\Image_Name;

// ---------------------------------------------------------------------------
// to_stored — non-WebP inputs gain a single `.webp`
// ---------------------------------------------------------------------------

test( 'to_stored appends .webp to a JPEG name', function (): void {
	expect( Image_Name::to_stored( 'IMG_2024.jpg' ) )->toBe( 'IMG_2024.jpg.webp' );
} );

test( 'to_stored appends .webp to a PNG name', function (): void {
	expect( Image_Name::to_stored( 'panorama.png' ) )->toBe( 'panorama.png.webp' );
} );

test( 'to_stored appends .webp to a multi-dot name', function (): void {
	expect( Image_Name::to_stored( 'a.b.c.jpg' ) )->toBe( 'a.b.c.jpg.webp' );
} );

test( 'to_stored appends .webp to an extensionless name', function (): void {
	expect( Image_Name::to_stored( 'photo' ) )->toBe( 'photo.webp' );
} );

test( 'to_stored appends .webp to a unicode name', function (): void {
	expect( Image_Name::to_stored( 'смотри-Ñoño-日本語.jpg' ) )->toBe( 'смотри-Ñoño-日本語.jpg.webp' );
} );

// ---------------------------------------------------------------------------
// to_stored — already-WebP inputs are never doubled
// ---------------------------------------------------------------------------

test( 'to_stored leaves a lowercase .webp name unchanged', function (): void {
	expect( Image_Name::to_stored( 'sunset.webp' ) )->toBe( 'sunset.webp' );
} );

test( 'to_stored does not double an uppercase .WEBP name', function (): void {
	expect( Image_Name::to_stored( 'Photo.WEBP' ) )->toBe( 'Photo.WEBP' );
} );

test( 'to_stored does not double a mixed-case .WebP name', function (): void {
	expect( Image_Name::to_stored( 'image.WebP' ) )->toBe( 'image.WebP' );
} );

test( 'to_stored treats only the trailing extension as WebP', function (): void {

	// A `.webp` mid-name is not the extension, so the suffix is still appended.
	expect( Image_Name::to_stored( 'archive.webp.jpg' ) )->toBe( 'archive.webp.jpg.webp' );

} );

// ---------------------------------------------------------------------------
// to_original — strips the appended suffix
// ---------------------------------------------------------------------------

test( 'to_original strips the appended .webp from a JPEG name', function (): void {
	expect( Image_Name::to_original( 'IMG_2024.jpg.webp' ) )->toBe( 'IMG_2024.jpg' );
} );

test( 'to_original strips the appended .webp from a multi-dot name', function (): void {
	expect( Image_Name::to_original( 'a.b.c.jpg.webp' ) )->toBe( 'a.b.c.jpg' );
} );

test( 'to_original leaves a single-extension .webp name unchanged', function (): void {

	// The original was already WebP, so the stored name *is* the original; it
	// must not be stripped to the empty string.
	expect( Image_Name::to_original( 'sunset.webp' ) )->toBe( 'sunset.webp' );

} );

test( 'to_original leaves a non-webp name unchanged', function (): void {
	expect( Image_Name::to_original( 'plain.jpg' ) )->toBe( 'plain.jpg' );
} );

// ---------------------------------------------------------------------------
// Reversibility — to_original( to_stored( x ) ) === x for every input
// ---------------------------------------------------------------------------

test( 'naming round-trips back to the original', function ( string $original ): void {
	expect( Image_Name::to_original( Image_Name::to_stored( $original ) ) )->toBe( $original );
} )->with( [
	'jpeg'            => [ 'IMG_2024.jpg' ],
	'png'             => [ 'panorama.png' ],
	'lowercase webp'  => [ 'sunset.webp' ],
	'uppercase webp'  => [ 'Photo.WEBP' ],
	'mixed-case webp' => [ 'image.WebP' ],
	'multi-dot'       => [ 'a.b.c.jpg' ],
	'webp mid-name'   => [ 'archive.webp.jpg' ],
	'unicode'         => [ 'смотри-Ñoño-日本語.jpg' ],
] );

// ---------------------------------------------------------------------------
// Collision edge case — an extensionless original cannot be reconstructed
// ---------------------------------------------------------------------------

test( 'an extensionless original collides with an already-webp name', function (): void {

	// Both `photo` (→ `photo.webp`) and `photo.webp` (left alone) store as
	// `photo.webp`; the reverse resolves the collision toward the already-WebP
	// reading, per the spec. This documents the one non-reversible input rather
	// than pretending it round-trips.
	expect( Image_Name::to_stored( 'photo' ) )->toBe( 'photo.webp' );
	expect( Image_Name::to_original( 'photo.webp' ) )->toBe( 'photo.webp' );

} );
