<?php
/**
 * Tests for the WP-CLI image command's import/delete glue — `docs/testing.md`
 * § *CLI surface*.
 *
 * The thin verbs are driven against a real temp uploads root and the WP_CLI test
 * double, with a real collection established first via `Collection_Command`, so
 * each effect is asserted on real disk through the real GD-backed engine: import
 * optimises to the target contract and is idempotent, requires an existing
 * collection, recreates confined sub-directories and rejects hostile paths, and
 * reports a per-file outcome that is exactly one of the four cases; delete
 * removes a main and its thumbnails, prompts unless `--yes`, and never deletes a
 * foreign file.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Cli\Collection_Command;
use Kntnt\Photo_Drop\Cli\Image_Command;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Index;
use Tests\Unit\Fixtures\Cli_Halt;
use Tests\Unit\Fixtures\Format_Items_Recorder;

/**
 * Wires every WordPress function the image command and its engine touch.
 *
 * The repository needs `wp_upload_dir` / `trailingslashit` / `wp_mkdir_p`; the
 * descriptor needs `wp_json_encode` / `apply_filters`. All get real behaviour
 * against a temp basedir so the command's filesystem effects run end to end.
 * Returns the canonical collection root.
 *
 * @param string $basedir Absolute temp directory standing in for the uploads basedir.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_image_stubs( string $basedir ): string {

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
 * Allocates a fresh temp basedir for one image-command test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_image_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-img-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

/**
 * Writes a JPEG source file of a given size and returns its path.
 *
 * @param string $dir    The directory to write into.
 * @param string $name   The source filename.
 * @param int    $width  The image width in pixels.
 * @param int    $height The image height in pixels.
 * @return string The absolute source path.
 */
function write_jpeg_source( string $dir, string $name, int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 120, 80, 200 ) );
	$path = rtrim( $dir, '/' ) . '/' . $name;
	imagejpeg( $image, $path, 90 );
	return $path;
}

/**
 * Removes a directory tree used as a temp basedir.
 *
 * @param string $dir The directory to remove.
 */
function image_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		image_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Establishes a collection through the real lifecycle command.
 *
 * @param string   $slug      The collection slug.
 * @param int|null $max_width The contract ceiling, or null for no limit.
 * @param int      $quality   The WebP quality.
 */
function establish_collection( string $slug, ?int $max_width, int $quality ): void {
	WP_CLI::reset();
	$max = $max_width === null ? 'none' : (string) $max_width;
	( new Collection_Command( new Repository() ) )->create(
		[ $slug ],
		[
			'max-width' => $max,
			'quality'   => (string) $quality,
		],
	);
}

/**
 * Builds an image command and resets the WP_CLI and format-items recorders.
 *
 * @return Image_Command An image command ready to drive in a test.
 */
function make_image_command(): Image_Command {
	WP_CLI::reset();
	Format_Items_Recorder::reset();
	return new Image_Command( new Repository() );
}

// ---------------------------------------------------------------------------
// import — optimises to the contract, idempotent, requires a collection
// ---------------------------------------------------------------------------

test( 'import optimises a source to the target contract and stores the main', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 3000, 1500 );

	make_image_command()->import( [ 'trip', $source ], [] );

	// The main is stored as `<original>.webp`, downscaled to the contract ceiling,
	// and the per-file row reports a single outcome.
	$main = $root . 'trip/IMG.jpg.webp';
	expect( is_file( $main ) )->toBeTrue();
	expect( (int) getimagesize( $main )[0] )->toBe( 1920 );
	expect( Format_Items_Recorder::$rows )->toHaveCount( 1 );
	expect( Format_Items_Recorder::$rows[0]['outcome'] )->toBe( 'reencoded' );

	image_remove_tree( $basedir );
} );

test( 'import is idempotent: a re-run skips, and --overwrite forces', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 1000, 800 );

	// First import stores the main; a second without --overwrite must skip it.
	make_image_command()->import( [ 'trip', $source ], [] );
	$first = file_get_contents( $root . 'trip/IMG.jpg.webp' );
	make_image_command()->import( [ 'trip', $source ], [] );

	expect( Format_Items_Recorder::$rows[0]['outcome'] )->toBe( 'skipped' );
	expect( file_get_contents( $root . 'trip/IMG.jpg.webp' ) )->toBe( $first );

	// A different source with --overwrite replaces the stored main.
	$bigger = write_jpeg_source( $basedir, 'IMG.jpg', 1400, 1000 );
	make_image_command()->import( [ 'trip', $bigger ], [ 'overwrite' => '1' ] );

	expect( Format_Items_Recorder::$rows[0]['outcome'] )->toBe( 'reencoded' );

	image_remove_tree( $basedir );
} );

test( 'import requires an existing collection', function (): void {
	$basedir = fresh_image_basedir();
	wire_image_stubs( $basedir );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 800, 600 );
	( new Repository() )->get_root();

	$threw = false;
	try {
		make_image_command()->import( [ 'ghost', $source ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();

	image_remove_tree( $basedir );
} );

test( 'import requires at least one source', function (): void {
	$basedir = fresh_image_basedir();
	wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );

	$threw = false;
	try {
		make_image_command()->import( [ 'trip' ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();

	image_remove_tree( $basedir );
} );

test( 'import recreates confined sub-directories for a relative source', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );

	// Write a relative source under a sub-tree and import it from the basedir as
	// the working directory, so the relative path is preserved and recreated.
	$cwd = getcwd();
	mkdir( $basedir . '/album/day1', 0700, true );
	write_jpeg_source( $basedir . '/album/day1', 'IMG.jpg', 1000, 800 );
	chdir( $basedir );
	make_image_command()->import( [ 'trip', 'album/day1/IMG.jpg' ], [] );
	chdir( $cwd === false ? sys_get_temp_dir() : $cwd );

	// The sub-tree is recreated inside the collection and the main is confined.
	$main = $root . 'trip/album/day1/IMG.jpg.webp';
	expect( is_file( $main ) )->toBeTrue();
	expect( realpath( dirname( $main ) ) )->toStartWith( realpath( $root . 'trip' ) );

	image_remove_tree( $basedir );
} );

test( 'import rejects a hostile relative path and writes nothing', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );

	// A source whose relative path tries to escape the collection root must be
	// rejected; the only file in the collection stays the descriptor.
	$cwd = getcwd();
	mkdir( $basedir . '/outside', 0700, true );
	write_jpeg_source( $basedir . '/outside', 'evil.jpg', 800, 600 );
	chdir( $basedir );
	make_image_command()->import( [ 'trip', '../outside/evil.jpg' ], [] );
	chdir( $cwd === false ? sys_get_temp_dir() : $cwd );

	expect( Format_Items_Recorder::$rows[0]['outcome'] )->toBe( 'rejected' );
	expect( glob( $root . 'trip/*' ) )->toBe( [ $root . 'trip/collection.json' ] );

	image_remove_tree( $basedir );
} );

test( 'one failing source never aborts the batch', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$good   = write_jpeg_source( $basedir, 'good.jpg', 1000, 800 );
	$broken = $basedir . '/broken.jpg';
	file_put_contents( $broken, 'not an image' );

	make_image_command()->import( [ 'trip', $broken, $good ], [] );

	// Both sources are reported; the broken one is rejected and the good one is
	// still stored — one failure does not abort the batch.
	$outcomes = array_column( Format_Items_Recorder::$rows, 'outcome' );
	expect( $outcomes )->toContain( 'rejected' );
	expect( $outcomes )->toContain( 'reencoded' );
	expect( is_file( $root . 'trip/good.jpg.webp' ) )->toBeTrue();

	image_remove_tree( $basedir );
} );

test( 'every reported outcome is one of the four legal values', function (): void {
	$basedir = fresh_image_basedir();
	wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$good   = write_jpeg_source( $basedir, 'good.jpg', 1000, 800 );
	$broken = $basedir . '/broken.jpg';
	file_put_contents( $broken, 'not an image' );

	make_image_command()->import( [ 'trip', $good, $broken ], [] );

	// The closed outcome set is the seam the REST endpoint reuses; no row may
	// carry a value outside it.
	foreach ( Format_Items_Recorder::$rows as $row ) {
		expect( $row['outcome'] )->toBeIn( [ 'stored', 'skipped', 'reencoded', 'rejected' ] );
	}

	image_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// delete — removes main + thumbnails, prompts unless --yes, no foreign files
// ---------------------------------------------------------------------------

test( 'delete removes the main and its thumbnails', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 2000, 1200 );
	make_image_command()->import( [ 'trip', $source ], [] );

	// The default thumbnail width (640) produced a thumbnail; confirm it exists,
	// then delete the main and assert both the main and its thumbnail are gone.
	$thumb = $root . 'trip/' . Index::THUMBNAILS_DIRNAME . '/640/IMG.jpg.webp';
	expect( is_file( $thumb ) )->toBeTrue();

	make_image_command()->delete( [ 'trip', 'IMG.jpg.webp' ], [ 'yes' => '1' ] );

	expect( is_file( $root . 'trip/IMG.jpg.webp' ) )->toBeFalse();
	expect( is_file( $thumb ) )->toBeFalse();

	image_remove_tree( $basedir );
} );

test( 'delete accepts the original filename and finds the stored main', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 1000, 800 );
	make_image_command()->import( [ 'trip', $source ], [] );

	// Passing the original name (without the appended .webp) still resolves the
	// stored `<original>.webp` main and removes it.
	make_image_command()->delete( [ 'trip', 'IMG.jpg' ], [ 'yes' => '1' ] );

	expect( is_file( $root . 'trip/IMG.jpg.webp' ) )->toBeFalse();

	image_remove_tree( $basedir );
} );

test( 'delete prompts and aborts when the prompt is declined', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );
	$source = write_jpeg_source( $basedir, 'IMG.jpg', 1000, 800 );
	make_image_command()->import( [ 'trip', $source ], [] );

	// Decline the prompt without --yes: the delete halts before any removal.
	WP_CLI::reset();
	Format_Items_Recorder::reset();
	WP_CLI::$confirm_answer = false;
	$threw = false;
	try {
		( new Image_Command( new Repository() ) )->delete( [ 'trip', 'IMG.jpg.webp' ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_file( $root . 'trip/IMG.jpg.webp' ) )->toBeTrue();

	image_remove_tree( $basedir );
} );

test( 'delete never removes a foreign file', function (): void {
	$basedir = fresh_image_basedir();
	$root    = wire_image_stubs( $basedir );
	establish_collection( 'trip', 1920, 80 );

	// A foreign file the plugin did not create sits in the collection; a delete
	// naming it must not remove it (no main exists for that path).
	$foreign = $root . 'trip/notes.txt';
	file_put_contents( $foreign, 'keep me' );

	$threw = false;
	try {
		make_image_command()->delete( [ 'trip', 'notes.txt' ], [ 'yes' => '1' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	// The path resolves to no main (notes.txt is not a `.webp` and has no
	// `<original>.webp` form on disk), so the command errors and the file stays.
	expect( $threw )->toBeTrue();
	expect( is_file( $foreign ) )->toBeTrue();

	image_remove_tree( $basedir );
} );

test( 'delete rejects an unknown collection', function (): void {
	$basedir = fresh_image_basedir();
	wire_image_stubs( $basedir );
	( new Repository() )->get_root();

	$threw = false;
	try {
		make_image_command()->delete( [ 'phantom', 'x.webp' ], [ 'yes' => '1' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();

	image_remove_tree( $basedir );
} );
