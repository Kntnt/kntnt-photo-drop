<?php
/**
 * Tests for the WP-CLI `collection doctor` verb's thin glue.
 *
 * The verb itself is intentionally thin — it resolves the collection, reads the
 * descriptor, builds the `Doctor`, runs it, and prints the report — so these
 * tests assert the glue, not the reconciliation algorithm (that lives in
 * DoctorTest). They drive the verb against a real temp collection and the WP_CLI
 * test double (which records output and turns error() into a catchable
 * exception) plus the format_items recorder, asserting: an unknown slug halts;
 * `--force` without `--repair` is rejected; a report-only run renders the
 * findings table and a dry-run summary while changing nothing; `--repair` reports
 * its effect; foreign and contract warnings are raised; and `--show-ignored`
 * reveals the skipped files.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Cli\Collection_Command;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Tests\Unit\Fixtures\Cli_Halt;
use Tests\Unit\Fixtures\Format_Items_Recorder;

/**
 * Wires every WordPress function the doctor verb and its collaborators touch.
 *
 * The repository needs `wp_upload_dir` / `trailingslashit` / `wp_mkdir_p`; the
 * descriptor needs `wp_json_encode` / `apply_filters`; the doctor's index store
 * and thumbnailer need `wp_mkdir_p`. All are given real behaviour against a temp
 * basedir so the verb's effects are exercised end to end. Returns the canonical
 * root.
 *
 * @param string $basedir Absolute temp directory standing in for the uploads basedir.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_doctor_command_stubs( string $basedir ): string {

	Functions\when( 'wp_upload_dir' )->justReturn(
		[
			'basedir' => $basedir,
			'error'   => false,
		]
	);

	Functions\when( 'trailingslashit' )->alias(
		static fn ( string $path ): string => rtrim( $path, '/\\' ) . '/'
	);

	Functions\when( 'wp_mkdir_p' )->alias(
		static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
	);

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed => $value
	);

	return rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';

}

/**
 * Allocates a fresh temp basedir for one doctor-command test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_doctor_command_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-doctor-cli-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

/**
 * Removes a directory tree, used to clean up the temp uploads basedir.
 *
 * @param string $dir The directory to remove.
 */
function doctor_command_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		doctor_command_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Writes a real WebP main of given width into a collection folder.
 *
 * @param string $folder   The folder to write into.
 * @param string $filename The stored main filename (ends in `.webp`).
 * @param int    $width    The width in pixels.
 * @return string The absolute path written.
 */
function write_command_main( string $folder, string $filename, int $width ): string {
	$height = (int) round( $width * 0.6 );
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 20, 120, 200 ) );
	$path = rtrim( $folder, '/' ) . '/' . $filename;
	imagewebp( $image, $path, 80 );
	return $path;
}

/**
 * Establishes a collection on disk with a fixed contract and widths.
 *
 * @param string         $root   The canonical collection root.
 * @param string         $slug   The collection slug.
 * @param array<int,int> $widths The thumbnail widths.
 * @return string The absolute collection path.
 */
function establish_doctor_collection( string $root, string $slug, array $widths ): string {
	$path = $root . $slug;
	mkdir( $path, 0700, true );
	( new Descriptor( ucfirst( $slug ), 1920, 80, $widths ) )->write( $path );
	return $path;
}

/**
 * Builds a doctor-capable collection command, resetting the recorders.
 *
 * @return Collection_Command The command under test.
 */
function make_doctor_command(): Collection_Command {
	WP_CLI::reset();
	Format_Items_Recorder::reset();
	return new Collection_Command( new Repository() );
}

// ---------------------------------------------------------------------------
// Guard rails: unknown slug, and --force without --repair
// ---------------------------------------------------------------------------

test( 'doctor rejects an unknown collection', function (): void {
	$basedir = fresh_doctor_command_basedir();
	wire_doctor_command_stubs( $basedir );
	$command = make_doctor_command();
	( new Repository() )->get_root();

	$threw = false;
	try {
		$command->doctor( [ 'ghost' ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );

	doctor_command_remove_tree( $basedir );
} );

test( 'doctor rejects --force without --repair', function (): void {
	$basedir = fresh_doctor_command_basedir();
	$root    = wire_doctor_command_stubs( $basedir );
	establish_doctor_collection( $root, 'trip', [ 320 ] );
	$command = make_doctor_command();

	$threw = false;
	try {
		$command->doctor( [ 'trip' ], [ 'force' => '1' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	// Forcing without repairing implies an act the report-only run never performs,
	// so it halts before doing anything.
	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );

	doctor_command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Report-only: renders the findings table, summarises, changes nothing
// ---------------------------------------------------------------------------

test( 'doctor report-only renders the findings and changes nothing', function (): void {
	$basedir = fresh_doctor_command_basedir();
	$root    = wire_doctor_command_stubs( $basedir );
	$path    = establish_doctor_collection( $root, 'trip', [ 320 ] );
	$main    = write_command_main( $path, 'photo.jpg.webp', 1600 );
	$command = make_doctor_command();

	$before = md5_file( $main );
	$command->doctor( [ 'trip' ], [] );

	// The findings table was rendered with the kind/path/detail columns, a dry-run
	// summary was emitted, and no thumbnail was written (report-only is the dry run).
	expect( Format_Items_Recorder::$fields )->toBe( [ 'kind', 'path', 'detail' ] );
	expect( Format_Items_Recorder::$rows )->not->toBe( [] );
	expect( WP_CLI::$successes )->toHaveCount( 1 );
	expect( WP_CLI::$successes[0] )->toContain( 'report-only' );
	expect( md5_file( $main ) )->toBe( $before );
	expect( is_dir( $path . '/' . Index::THUMBNAILS_DIRNAME ) )->toBeFalse();

	doctor_command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// --repair: acts and reports the effect
// ---------------------------------------------------------------------------

test( 'doctor --repair creates the thumbnail and reports the effect', function (): void {
	$basedir = fresh_doctor_command_basedir();
	$root    = wire_doctor_command_stubs( $basedir );
	$path    = establish_doctor_collection( $root, 'trip', [ 320 ] );
	write_command_main( $path, 'photo.jpg.webp', 1600 );
	$command = make_doctor_command();

	$command->doctor( [ 'trip' ], [ 'repair' => '1' ] );

	// The thumbnail now exists and the summary reports what was created.
	expect( is_file( $path . '/' . Index::THUMBNAILS_DIRNAME . '/320/photo.jpg.webp' ) )->toBeTrue();
	expect( WP_CLI::$successes[0] )->toContain( 'created' );

	doctor_command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// Warnings: contract violation and foreign file
// ---------------------------------------------------------------------------

test( 'doctor warns about a foreign file and a contract-violating main', function (): void {
	$basedir = fresh_doctor_command_basedir();
	$root    = wire_doctor_command_stubs( $basedir );
	$path    = establish_doctor_collection( $root, 'trip', [ 320 ] );

	// A non-WebP main (its bytes are not a WebP image) and a loose text file.
	file_put_contents( $path . '/raw.webp', 'not really an image' );
	file_put_contents( $path . '/notes.txt', 'a note' );
	$command = make_doctor_command();

	$command->doctor( [ 'trip' ], [] );

	// Each warned state produced a WP_CLI warning line naming the path.
	$warnings = implode( "\n", WP_CLI::$warnings );
	expect( $warnings )->toContain( 'raw.webp' );
	expect( $warnings )->toContain( 'notes.txt' );

	doctor_command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// --show-ignored reveals skipped files; default hides them
// ---------------------------------------------------------------------------

test( 'doctor hides ignored files by default and reveals them with --show-ignored', function (): void {
	$basedir = fresh_doctor_command_basedir();
	$root    = wire_doctor_command_stubs( $basedir );
	$path    = establish_doctor_collection( $root, 'trip', [ 320 ] );
	file_put_contents( $path . '/.DS_Store', 'junk' );
	$command = make_doctor_command();

	// Without the flag the OS-junk file is passed over silently — no log mentions it.
	$command->doctor( [ 'trip' ], [] );
	expect( implode( "\n", WP_CLI::$logs ) )->not->toContain( '.DS_Store' );

	// With --show-ignored it appears in the log so an operator can audit it.
	$command = make_doctor_command();
	$command->doctor( [ 'trip' ], [ 'show-ignored' => '1' ] );
	expect( implode( "\n", WP_CLI::$logs ) )->toContain( '.DS_Store' );

	doctor_command_remove_tree( $basedir );
} );
