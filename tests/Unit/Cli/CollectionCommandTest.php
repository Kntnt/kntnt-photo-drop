<?php
/**
 * Tests for the WP-CLI collection lifecycle command's create/update/delete glue.
 *
 * The thin verbs are driven against a real temp directory and a WP_CLI test
 * double (loaded via tests/Pest.php) that records output and turns
 * error()/declined-confirm() into a catchable exception, so each effect (a
 * valid descriptor written, only `name` rewritten, a refused duplicate, a
 * rejected contract change, a guarded delete) is asserted on real disk. The
 * pure flag rules the command delegates to are covered in CollectionInputTest.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Cli\Collection_Command;
use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Tests\Unit\Fixtures\Cli_Halt;

/**
 * Wires every WordPress function the command and its collaborators touch.
 *
 * The repository needs `wp_upload_dir` / `trailingslashit` / `wp_mkdir_p`, and
 * the descriptor needs `wp_json_encode` / `apply_filters` (for the thumbnail
 * width). All are given real behaviour against a temp basedir so the command's
 * filesystem effects are exercised end to end. Returns the canonical root.
 *
 * @param string $basedir Absolute temp directory standing in for the uploads basedir.
 * @return string The canonical trailing-slashed collection root.
 */
function wire_command_stubs( string $basedir ): string {

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

	// apply_filters: pass the root default through, and default the thumbnail
	// width to a single 640 so a created descriptor has a deterministic shape.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ): mixed {
			return $value;
		}
	);

	return rtrim( $basedir, '/' ) . '/kntnt-photo-drop/';

}

/**
 * Allocates a fresh temp basedir for one command test.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_command_basedir(): string {
	$base = sys_get_temp_dir() . '/kntnt-cli-' . bin2hex( random_bytes( 6 ) );
	mkdir( $base, 0700, true );
	return $base;
}

/**
 * Removes a directory tree, used to clean up the temp uploads basedir.
 *
 * @param string $dir The directory to remove.
 */
function command_remove_tree( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		command_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

/**
 * Builds a command backed by a fresh repository, resetting the WP_CLI double.
 *
 * @return Collection_Command A command ready to drive in a test.
 */
function make_command(): Collection_Command {
	WP_CLI::reset();
	return new Collection_Command( new Repository() );
}

// ---------------------------------------------------------------------------
// create — writes a valid descriptor, defaults the name, refuses duplicates
// ---------------------------------------------------------------------------

test( 'create writes a valid collection.json from the flags', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'spring-2024' ], [
		'max-width' => '1920',
		'quality'   => '80',
		'name'      => 'Spring 2024',
	] );

	// A descriptor is on disk carrying the contract verbatim, format WebP implied,
	// and the filter-derived thumbnail width.
	$descriptor = Descriptor::read( $root . 'spring-2024' );
	expect( $descriptor )->not->toBeNull();
	expect( $descriptor->name )->toBe( 'Spring 2024' );
	expect( $descriptor->max_width )->toBe( 1920 );
	expect( $descriptor->quality )->toBe( 80 );
	expect( $descriptor->thumbnail_widths )->toBe( [ 640 ] );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'create defaults the name to a humanised slug', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'autumn-walk' ], [
		'max-width' => '1600',
		'quality'   => '75',
	] );

	expect( Descriptor::read( $root . 'autumn-walk' )->name )->toBe( 'Autumn Walk' );

	command_remove_tree( $basedir );
} );

test( 'create maps --max-width=none to a null ceiling', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'archive' ], [
		'max-width' => 'none',
		'quality'   => '90',
	] );

	expect( Descriptor::read( $root . 'archive' )->max_width )->toBeNull();

	command_remove_tree( $basedir );
} );

test( 'create refuses a duplicate slug and writes nothing new', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// Establish once, then attempt the same slug again: the second create halts
	// via error() and leaves the first descriptor untouched.
	$command->create( [ 'dupe' ], [
		'max-width' => '1920',
		'quality'   => '80',
		'name'      => 'First',
	] );
	$first = file_get_contents( $root . 'dupe/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->create( [ 'dupe' ], [
			'max-width' => '800',
			'quality'   => '50',
			'name'      => 'Second',
		] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );
	expect( file_get_contents( $root . 'dupe/' . Descriptor::FILENAME ) )->toBe( $first );

	command_remove_tree( $basedir );
} );

test( 'create rejects a missing contract flag', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// Either missing contract flag halts the command before any directory is made.
	$threw = false;
	try {
		$command->create( [ 'incomplete' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_dir( $root . 'incomplete' ) )->toBeFalse();

	command_remove_tree( $basedir );
} )->with( [
	'no max-width' => [ [ 'quality' => '80' ] ],
	'no quality'   => [ [ 'max-width' => '1920' ] ],
	'neither'      => [ [] ],
] );

test( 'create rejects an invalid slug before creating anything', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$threw = false;
	try {
		$command->create( [ 'Bad Slug' ], [
			'max-width' => '1920',
			'quality'   => '80',
		] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	command_remove_tree( $basedir );
} );

test( 'create defaults uploader-folders to on when the flag is absent', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'namespaced' ], [
		'max-width' => '1920',
		'quality'   => '80',
	] );

	// Without the flag the placement rule defaults to on (ADR-0008).
	expect( Descriptor::read( $root . 'namespaced' )->uploader_folders )->toBeTrue();

	command_remove_tree( $basedir );
} );

test( 'create records the uploader-folders choice', function ( string|bool $value, bool $expected ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// WP-CLI surfaces an explicit value as a string and --no-uploader-folders as a
	// boolean false; both spellings of "off" must reach the descriptor as false.
	$command->create( [ 'chosen' ], [
		'max-width'        => '1920',
		'quality'          => '80',
		'uploader-folders' => $value,
	] );

	expect( Descriptor::read( $root . 'chosen' )->uploader_folders )->toBe( $expected );

	command_remove_tree( $basedir );
} )->with( [
	'explicit false'  => [ 'false', false ],
	'negated boolean' => [ false, false ],
	'explicit true'   => [ 'true', true ],
	'bare flag (1)'   => [ '1', true ],
] );

test( 'create rejects an undecidable uploader-folders value and writes nothing', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A value the parser cannot decide must halt before any directory is made, so
	// a typo never freezes the placement rule.
	$threw = false;
	try {
		$command->create( [ 'typo' ], [
			'max-width'        => '1920',
			'quality'          => '80',
			'uploader-folders' => 'maybe',
		] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_dir( $root . 'typo' ) )->toBeFalse();

	command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// update — rewrites only the name, rejects contract changes
// ---------------------------------------------------------------------------

test( 'update rewrites only the name and preserves the contract', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'trip' ], [
		'max-width' => '1920',
		'quality'   => '80',
		'name'      => 'Old Name',
	] );
	$before = Descriptor::read( $root . 'trip' );

	WP_CLI::reset();
	$command->update( [ 'trip' ], [ 'name' => 'New Name' ] );
	$after = Descriptor::read( $root . 'trip' );

	// Only the display name changed; the immutable contract and thumbnail widths
	// are carried over verbatim.
	expect( $after->name )->toBe( 'New Name' );
	expect( $after->max_width )->toBe( $before->max_width );
	expect( $after->quality )->toBe( $before->quality );
	expect( $after->thumbnail_widths )->toBe( $before->thumbnail_widths );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'update rejects an attempt to change an immutable flag', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'frozen' ], [
		'max-width' => '1920',
		'quality'   => '80',
		'name'      => 'Frozen',
	] );
	$before = file_get_contents( $root . 'frozen/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'frozen' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	// The immutable flag is refused and the descriptor is left byte-identical.
	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );
	expect( file_get_contents( $root . 'frozen/' . Descriptor::FILENAME ) )->toBe( $before );

	command_remove_tree( $basedir );
} )->with( [
	'max-width'         => [
		[
			'name'      => 'X',
			'max-width' => '800',
		],
	],
	'quality'           => [
		[
			'name'    => 'X',
			'quality' => '50',
		],
	],
	'uploader-folders'  => [
		[
			'name'             => 'X',
			'uploader-folders' => 'false',
		],
	],
	'negated folders'   => [
		[
			'name'             => 'X',
			'uploader-folders' => false,
		],
	],
	'immutable no name' => [ [ 'max-width' => '800' ] ],
] );

test( 'update requires a non-empty name', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'named' ], [
		'max-width' => '1920',
		'quality'   => '80',
		'name'      => 'Keep',
	] );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'named' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( Descriptor::read( $root . 'named' )->name )->toBe( 'Keep' );

	command_remove_tree( $basedir );
} )->with( [
	'missing' => [ [] ],
	'empty'   => [ [ 'name' => '' ] ],
] );

test( 'update rejects an unknown collection', function (): void {
	$basedir = fresh_command_basedir();
	wire_command_stubs( $basedir );
	$command = make_command();
	( new Repository() )->get_root();

	$threw = false;
	try {
		$command->update( [ 'ghost' ], [ 'name' => 'Whatever' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();

	command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// delete — confirmation gate and removal
// ---------------------------------------------------------------------------

test( 'delete removes the collection when confirmed', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'gone' ], [
		'max-width' => '1920',
		'quality'   => '80',
	] );

	// The double's default confirm answer is "accept", standing in for an
	// operator who confirms the prompt.
	WP_CLI::reset();
	$command->delete( [ 'gone' ], [] );

	expect( is_dir( $root . 'gone' ) )->toBeFalse();
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'delete honours --yes without prompting', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'forced' ], [
		'max-width' => '1920',
		'quality'   => '80',
	] );

	// Decline the prompt globally; --yes must still skip it and delete.
	WP_CLI::reset();
	WP_CLI::$confirm_answer = false;
	$command->delete( [ 'forced' ], [ 'yes' => '1' ] );

	expect( is_dir( $root . 'forced' ) )->toBeFalse();

	command_remove_tree( $basedir );
} );

test( 'delete aborts and keeps the collection when the prompt is declined', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'kept' ], [
		'max-width' => '1920',
		'quality'   => '80',
	] );

	// A declined prompt (no --yes) halts before any removal.
	WP_CLI::reset();
	WP_CLI::$confirm_answer = false;
	$threw = false;
	try {
		$command->delete( [ 'kept' ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_dir( $root . 'kept' ) )->toBeTrue();

	command_remove_tree( $basedir );
} );

test( 'delete rejects an unknown collection', function (): void {
	$basedir = fresh_command_basedir();
	wire_command_stubs( $basedir );
	$command = make_command();
	( new Repository() )->get_root();

	$threw = false;
	try {
		$command->delete( [ 'phantom' ], [ 'yes' => '1' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();

	command_remove_tree( $basedir );
} );
