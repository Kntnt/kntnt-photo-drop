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
