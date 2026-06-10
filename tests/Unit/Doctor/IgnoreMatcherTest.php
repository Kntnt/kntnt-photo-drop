<?php
/**
 * Tests for the doctor's foreign-file ignore matcher.
 *
 * The matcher is pure — it answers from the path alone — so these tests need no
 * filesystem: they assert the built-in OS-junk list, that a user's own
 * `.thumbnails` is *not* on it (it is foreign, not ours), and that a
 * caller-supplied comma-separated `--ignore` value extends the list by basename
 * and by sub-tree glob.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Doctor\Ignore_Matcher;

// ---------------------------------------------------------------------------
// The built-in OS-junk list
// ---------------------------------------------------------------------------

test( 'the built-in list ignores OS junk wherever it sits', function ( string $path ): void {
	expect( ( new Ignore_Matcher( null ) )->matches( $path ) )->toBeTrue();
} )->with( [
	'macOS Finder metadata'        => [ '.DS_Store' ],
	'macOS Finder metadata nested' => [ 'sub/folder/.DS_Store' ],
	'AppleDouble sidecar'          => [ '._photo.jpg.webp' ],
	'AppleDouble nested'           => [ 'a/b/._anything' ],
	'Spotlight index'              => [ '.Spotlight-V100' ],
	'Trashes'                      => [ '.Trashes' ],
	'fseventsd'                    => [ '.fseventsd' ],
	'Windows thumbnail cache'      => [ 'Thumbs.db' ],
	'Windows desktop.ini'          => [ 'sub/desktop.ini' ],
] );

test( "a user's own .thumbnails directory is foreign, not ignored", function (): void {

	// Our artifacts live under the namespaced `.kntnt-thumbnails`; a bare
	// `.thumbnails` from another photo tool is foreign and must be warned about.
	$matcher = new Ignore_Matcher( null );
	expect( $matcher->matches( '.thumbnails' ) )->toBeFalse();
	expect( $matcher->matches( 'sub/.thumbnails/cache.png' ) )->toBeFalse();
} );

test( 'an ordinary loose file is not ignored by the built-in list', function ( string $path ): void {
	expect( ( new Ignore_Matcher( null ) )->matches( $path ) )->toBeFalse();
} )->with( [
	'a text note'  => [ 'notes.txt' ],
	'a stray jpeg' => [ 'sub/original.jpg' ],
	'a readme'     => [ 'README.md' ],
] );

// ---------------------------------------------------------------------------
// Caller-supplied --ignore globs
// ---------------------------------------------------------------------------

test( 'a caller glob extends the list by basename', function (): void {

	// `*.tmp` matches the basename anywhere in the tree, adding to the built-in list.
	$matcher = new Ignore_Matcher( '*.tmp' );
	expect( $matcher->matches( 'scratch.tmp' ) )->toBeTrue();
	expect( $matcher->matches( 'sub/dir/scratch.tmp' ) )->toBeTrue();
	expect( $matcher->matches( 'scratch.txt' ) )->toBeFalse();
} );

test( 'a caller glob can target a sub-tree by relative path', function (): void {

	// `raw/*` matches against the full relative path, so a whole sub-tree is skipped.
	$matcher = new Ignore_Matcher( 'raw/*' );
	expect( $matcher->matches( 'raw/IMG.dng' ) )->toBeTrue();
	expect( $matcher->matches( 'kept/IMG.dng' ) )->toBeFalse();
} );

test( 'multiple comma-separated globs are all applied', function (): void {

	// A comma-separated value contributes several globs at once; whitespace around
	// each segment is trimmed and empty segments are dropped.
	$matcher = new Ignore_Matcher( ' *.tmp , raw/* , ' );
	expect( $matcher->matches( 'a.tmp' ) )->toBeTrue();
	expect( $matcher->matches( 'raw/b.dng' ) )->toBeTrue();
	expect( $matcher->matches( 'c.txt' ) )->toBeFalse();
} );

test( 'an absent or blank ignore value applies only the built-in list', function ( ?string $value ): void {
	$matcher = new Ignore_Matcher( $value );
	expect( $matcher->matches( '.DS_Store' ) )->toBeTrue();
	expect( $matcher->matches( 'anything.txt' ) )->toBeFalse();
} )->with( [
	'null'         => [ null ],
	'empty string' => [ '' ],
	'only a comma' => [ ',' ],
] );

// ---------------------------------------------------------------------------
// The built-in / caller split — the two lists carry different authority
// ---------------------------------------------------------------------------

test( 'matches_builtin sees only the OS-junk list, never a caller glob', function (): void {

	// The built-in half runs before main classification, so it must answer for
	// OS junk alone — a caller glob has no say there.
	$matcher = new Ignore_Matcher( 'raw/*' );
	expect( $matcher->matches_builtin( '._photo.jpg.webp' ) )->toBeTrue();
	expect( $matcher->matches_builtin( 'sub/.DS_Store' ) )->toBeTrue();
	expect( $matcher->matches_builtin( 'raw/photo.jpg.webp' ) )->toBeFalse();
} );

test( 'matches_caller sees only the caller globs, never the built-in list', function (): void {

	// The caller half is consulted only for non-mains, so it must answer for the
	// --ignore globs alone — OS junk is the built-in half's job.
	$matcher = new Ignore_Matcher( 'raw/*' );
	expect( $matcher->matches_caller( 'raw/IMG.dng' ) )->toBeTrue();
	expect( $matcher->matches_caller( '.DS_Store' ) )->toBeFalse();
	expect( $matcher->matches_caller( 'kept/IMG.dng' ) )->toBeFalse();
} );

test( 'matches stays the union of the two halves', function (): void {
	$matcher = new Ignore_Matcher( '*.tmp' );
	expect( $matcher->matches( 'Thumbs.db' ) )->toBeTrue();
	expect( $matcher->matches( 'scratch.tmp' ) )->toBeTrue();
	expect( $matcher->matches( 'notes.txt' ) )->toBeFalse();
} );
