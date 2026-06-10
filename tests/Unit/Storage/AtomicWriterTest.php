<?php
/**
 * Tests for the atomic file writer — `docs/testing.md` § *Storage durability*.
 *
 * Every test runs against a real temp directory so the temp-then-rename
 * publish, the staging-file cleanup, and the permission normalisation are
 * exercised against a real filesystem.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Storage\Atomic_Writer;

/**
 * Allocates a fresh temp folder for one test's writes.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_writer_dir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-atomic-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

test( 'a write publishes the full content under the target name', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/collection.json';

	$result = Atomic_Writer::write( $target, '{"schema":1}' );

	expect( $result )->toBeTrue();
	expect( file_get_contents( $target ) )->toBe( '{"schema":1}' );
} );

test( 'a write replaces existing content completely', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/index.json';
	file_put_contents( $target, str_repeat( 'old', 100 ) );

	$result = Atomic_Writer::write( $target, 'new' );

	expect( $result )->toBeTrue();
	expect( file_get_contents( $target ) )->toBe( 'new' );
} );

test( 'no staging file is left beside the target after a successful write', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/main.webp';

	Atomic_Writer::write( $target, 'RIFFxxxxWEBP' );

	$leftovers = glob( $dir . '/*.tmp-*' );
	expect( $leftovers )->toBe( [] );
} );

test( 'a write into a missing directory fails and leaves nothing behind', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/missing/main.webp';

	// The staging failure inherently raises a (suppressed) PHP warning; keep
	// it away from the test harness's error handler.
	set_error_handler( static fn (): bool => true );
	$result = Atomic_Writer::write( $target, 'bytes' );
	restore_error_handler();

	expect( $result )->toBeFalse();
	expect( is_file( $target ) )->toBeFalse();
} );

test( 'a failed write leaves an existing target untouched', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/collection.json';
	file_put_contents( $target, 'original' );

	// Make the directory unwritable so staging the temp file fails; the
	// already-published target must survive unmodified.
	chmod( $dir, 0500 );
	set_error_handler( static fn (): bool => true );
	$result = Atomic_Writer::write( $target, 'replacement' );
	restore_error_handler();
	chmod( $dir, 0700 );

	expect( $result )->toBeFalse();
	expect( file_get_contents( $target ) )->toBe( 'original' );
} );

test( 'the published file is group- and world-readable for the web server', function (): void {
	$dir    = fresh_writer_dir();
	$target = $dir . '/photo.jpg.webp';

	// A restrictive umask reproduces the hosting case where a plain write
	// would land unreadable to the serving process.
	$previous = umask( 0077 );
	Atomic_Writer::write( $target, 'bytes' );
	umask( $previous );

	expect( fileperms( $target ) & 0644 )->toBe( 0644 );
} );
