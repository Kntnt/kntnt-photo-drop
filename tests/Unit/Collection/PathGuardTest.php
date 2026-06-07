<?php
/**
 * Adversarial tests for path sanitisation and `realpath` confinement.
 *
 * Covers every hostile input enumerated in docs/testing.md § "Path traversal
 * and realpath confinement": traversal, absolute and UNC paths, schemes,
 * percent-encoded / double-encoded / overlong sequences, NUL bytes and control
 * characters, and symlink escape caught on the resolved path. Also asserts
 * benign inputs stay confined and that empty / `.` resolves to the root.
 *
 * A real temp directory is used so realpath() has something to canonicalise;
 * Brain Monkey is not needed because Path_Guard touches no WordPress function.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Collection\Path_Guard;

/**
 * Creates a fresh, unique temporary directory and returns its realpath.
 *
 * Each call yields an isolated root so tests cannot interfere with one
 * another. The path is canonicalised so confinement comparisons are exact even
 * when the system temp dir is itself a symlink (notably /tmp → /private/tmp on
 * macOS).
 *
 * @return string The canonical absolute path of the new directory.
 */
function make_temp_root(): string {
	$base = sys_get_temp_dir() . '/kntnt-pg-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return realpath( $base );
}

/**
 * Recursively removes a directory tree created for a test.
 *
 * Best-effort cleanup; failures are swallowed because a leftover temp dir does
 * not affect correctness and the OS reaps it eventually.
 *
 * @param string $dir The directory to remove.
 */
function remove_tree( string $dir ): void {

	// A symlink (even one pointing at a directory) is removed with unlink, not
	// rmdir, and is never recursed into: deleting the link must not delete its
	// target's contents.
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

test( 'constructor rejects a non-existent root', function (): void {
	new Path_Guard( '/no/such/kntnt/root/at/all' );
} )->throws( InvalidArgumentException::class );

test( 'constructor canonicalises the root via realpath', function (): void {

	// A root reached through a redundant `/.` segment must canonicalise to the
	// plain root, so get_root() never carries the lexical noise.
	$root  = make_temp_root();
	$guard = new Path_Guard( $root . '/.' );

	expect( $guard->get_root() )->toBe( $root );

	remove_tree( $root );

} );

// ---------------------------------------------------------------------------
// Benign inputs — confined to the root
// ---------------------------------------------------------------------------

test( 'empty path resolves to the root itself', function (): void {
	$root = make_temp_root();
	expect( ( new Path_Guard( $root ) )->resolve( '' ) )->toBe( $root );
	remove_tree( $root );
} );

test( 'a single dot resolves to the root itself', function (): void {
	$root = make_temp_root();
	expect( ( new Path_Guard( $root ) )->resolve( '.' ) )->toBe( $root );
	remove_tree( $root );
} );

test( 'a leading ./ resolves to the root itself', function (): void {
	$root = make_temp_root();
	expect( ( new Path_Guard( $root ) )->resolve( './' ) )->toBe( $root );
	remove_tree( $root );
} );

test( 'a benign nested path resolves inside the root', function (): void {

	// A multi-segment relative path that does not yet exist is still resolved;
	// directory creation happens after the guard returns. The result must be
	// the lexical join under the root.
	$root  = make_temp_root();
	$guard = new Path_Guard( $root );

	expect( $guard->resolve( '2024/spring/album' ) )->toBe( $root . '/2024/spring/album' );

	remove_tree( $root );

} );

test( 'redundant separators and single dots collapse', function (): void {
	$root  = make_temp_root();
	$guard = new Path_Guard( $root );

	expect( $guard->resolve( 'a//b/./c' ) )->toBe( $root . '/a/b/c' );

	remove_tree( $root );

} );

test( 'an existing nested path resolves to its realpath inside the root', function (): void {

	// When the path already exists, the resolved value is its realpath, which
	// must still sit inside the root.
	$root = make_temp_root();
	mkdir( $root . '/existing/child', 0700, true );
	$guard = new Path_Guard( $root );

	$resolved = $guard->resolve( 'existing/child' );

	expect( $resolved )->toBe( $root . '/existing/child' );
	expect( str_starts_with( (string) $resolved, $root . '/' ) )->toBeTrue();

	remove_tree( $root );

} );

// ---------------------------------------------------------------------------
// Hostile inputs — every one must be rejected (null)
// ---------------------------------------------------------------------------

test( 'hostile path is rejected', function ( string $hostile ): void {
	$root = make_temp_root();
	expect( ( new Path_Guard( $root ) )->resolve( $hostile ) )->toBeNull();
	remove_tree( $root );
} )->with( [
	'parent traversal'            => [ '../secret' ],
	'deep traversal'              => [ '../../../../etc/passwd' ],
	'traversal mid-path'          => [ 'a/../../b' ],
	'trailing traversal'          => [ 'album/..' ],
	'lone traversal'              => [ '..' ],
	'absolute unix'               => [ '/etc/passwd' ],
	'absolute root'               => [ '/' ],
	'windows drive'               => [ 'C:\\Windows\\System32' ],
	'windows backslash traversal' => [ '..\\..\\secret' ],
	'unc path'                    => [ '\\\\server\\share\\file' ],
	'backslash separator'         => [ 'a\\b' ],
	'file scheme'                 => [ 'file:///etc/passwd' ],
	'php scheme'                  => [ 'php://filter/resource=x' ],
	'http scheme'                 => [ 'http://evil.example/x' ],
	'encoded traversal'           => [ '%2e%2e%2fsecret' ],
	'encoded traversal slash'     => [ '..%2fsecret' ],
	'double-encoded traversal'    => [ '%252e%252e%252fsecret' ],
	'encoded backslash'           => [ 'a%5c..%5cb' ],
	'overlong leading slash'      => [ '%2fetc%2fpasswd' ],
	'nul byte'                    => [ "album\0/passwd" ],
	'encoded nul byte'            => [ 'album%00/passwd' ],
	'control character'           => [ "album\x01/passwd" ],
] );

// ---------------------------------------------------------------------------
// Symlink escape — confinement is on the resolved path, not the lexical one
// ---------------------------------------------------------------------------

test( 'a symlink whose target escapes the root is rejected', function (): void {

	// Build a root containing a symlink that points outside it. The lexical
	// path `escape/loot` looks innocent, but realpath() on the existing
	// `escape` link lands outside the root, so the guard must reject it.
	$parent  = make_temp_root();
	$root    = $parent . '/root';
	$outside = $parent . '/outside';
	mkdir( $root, 0700, true );
	mkdir( $outside . '/loot', 0700, true );
	symlink( $outside, $root . '/escape' );

	$guard = new Path_Guard( $root );

	expect( $guard->resolve( 'escape/loot' ) )->toBeNull();

	remove_tree( $parent );

} )->skip( ! function_exists( 'symlink' ), 'symlink() is unavailable in this environment.' );

test( 'a symlink that stays inside the root is accepted', function (): void {

	// A symlink whose target is still within the root must be accepted: the
	// confinement rule rejects escape, not indirection per se.
	$root = make_temp_root();
	mkdir( $root . '/real/inner', 0700, true );
	symlink( $root . '/real', $root . '/alias' );

	$guard    = new Path_Guard( $root );
	$resolved = $guard->resolve( 'alias/inner' );

	// The resolved path canonicalises through the link back into the root.
	expect( $resolved )->toBe( $root . '/real/inner' );
	expect( str_starts_with( (string) $resolved, $root . '/' ) )->toBeTrue();

	remove_tree( $root );

} )->skip( ! function_exists( 'symlink' ), 'symlink() is unavailable in this environment.' );

// ---------------------------------------------------------------------------
// Confinement invariant — every accepted path is inside the root
// ---------------------------------------------------------------------------

test( 'every accepted path is confined to the root', function ( string $benign ): void {
	$root     = make_temp_root();
	$resolved = ( new Path_Guard( $root ) )->resolve( $benign );

	// Accepted (non-null) results are always the root or a descendant of it.
	expect( $resolved )->not->toBeNull();
	expect( $resolved === $root || str_starts_with( (string) $resolved, $root . '/' ) )->toBeTrue();

	remove_tree( $root );
} )->with( [
	'root via empty'  => [ '' ],
	'root via dot'    => [ '.' ],
	'single segment'  => [ 'album' ],
	'nested'          => [ '2024/summer/beach' ],
	'unicode segment' => [ 'Ñoño/日本語' ],
	'dotted file'     => [ 'a.b.c.jpg.webp' ],
] );
