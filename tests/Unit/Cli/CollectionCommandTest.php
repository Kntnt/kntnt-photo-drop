<?php
/**
 * Tests for the WP-CLI collection lifecycle command's create/update/delete glue.
 *
 * The thin verbs are driven against a real temp directory and a WP_CLI test
 * double (loaded via tests/Pest.php) that records output and turns
 * error()/declined-confirm() into a catchable exception, so each effect (a
 * valid three-rendition descriptor written, the six rendition fields defaulted or
 * recorded, only `name` rewritten, a refused duplicate, a rejected upload-contract
 * change, a guarded delete) is asserted on real disk. The pure flag rules the
 * command delegates to are covered in CollectionInputTest.
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
 * the descriptor needs `wp_json_encode`; the rendition defaults read the six
 * `kntnt_photo_drop_default_*` filters, here left to pass their hardcoded
 * defaults through. All are given real behaviour against a temp basedir so the
 * command's filesystem effects are exercised end to end. Returns the canonical
 * root.
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

	// Pass every filter's default through, so the six rendition defaults resolve to
	// their documented values (upload width null, upload 95, full 1920/85,
	// thumbnail 640/75) without any per-test wiring.
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $value ): mixed => $value
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
// create — writes a valid descriptor, defaults the fields, refuses duplicates
// ---------------------------------------------------------------------------

test( 'create writes a valid three-rendition collection.json from the flags', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'spring-2024' ], [
		'upload-width'      => '4000',
		'upload-quality'    => '92',
		'full-width'        => '1600',
		'full-quality'      => '82',
		'thumbnail-width'   => '480',
		'thumbnail-quality' => '70',
		'path-components'   => '%year%/%uploader%',
		'name'              => 'Spring 2024',
	] );

	// A descriptor is on disk carrying every flag verbatim, format WebP implied.
	$descriptor = Descriptor::read( $root . 'spring-2024' );
	expect( $descriptor )->not->toBeNull();
	expect( $descriptor->name )->toBe( 'Spring 2024' );
	expect( $descriptor->upload_width )->toBe( 4000 );
	expect( $descriptor->upload_quality )->toBe( 92 );
	expect( $descriptor->full_width )->toBe( 1600 );
	expect( $descriptor->full_quality )->toBe( 82 );
	expect( $descriptor->thumbnail_width )->toBe( 480 );
	expect( $descriptor->thumbnail_quality )->toBe( 70 );
	expect( $descriptor->path_components )->toBe( '%year%/%uploader%' );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'create defaults every omitted rendition field from its filter', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// With only the slug, every rendition field falls back to its documented
	// default: upload width unset (source dimensions), upload 95, full 1920/85,
	// thumbnail 640/75, and the default path-components template.
	$command->create( [ 'defaulted' ], [] );

	$descriptor = Descriptor::read( $root . 'defaulted' );
	expect( $descriptor->upload_width )->toBeNull();
	expect( $descriptor->upload_quality )->toBe( 95 );
	expect( $descriptor->full_width )->toBe( 1920 );
	expect( $descriptor->full_quality )->toBe( 85 );
	expect( $descriptor->thumbnail_width )->toBe( 640 );
	expect( $descriptor->thumbnail_quality )->toBe( 75 );
	expect( $descriptor->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	command_remove_tree( $basedir );
} );

test( 'create defaults the name to a humanised slug', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'autumn-walk' ], [] );

	expect( Descriptor::read( $root . 'autumn-walk' )->name )->toBe( 'Autumn Walk' );

	command_remove_tree( $basedir );
} );

test( 'create maps --upload-width=none to a null ceiling', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'archive' ], [ 'upload-width' => 'none' ] );

	expect( Descriptor::read( $root . 'archive' )->upload_width )->toBeNull();

	command_remove_tree( $basedir );
} );

test( 'create refuses a duplicate slug and writes nothing new', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// Establish once, then attempt the same slug again: the second create halts
	// via error() and leaves the first descriptor untouched.
	$command->create( [ 'dupe' ], [ 'name' => 'First' ] );
	$first = file_get_contents( $root . 'dupe/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->create( [ 'dupe' ], [
			'upload-width' => '800',
			'name'         => 'Second',
		] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );
	expect( file_get_contents( $root . 'dupe/' . Descriptor::FILENAME ) )->toBe( $first );

	command_remove_tree( $basedir );
} );

test( 'create rejects a malformed rendition flag before creating anything', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A malformed value for any rendition flag halts the command before any
	// directory is made, so a typo never seeds a degenerate collection.
	$threw = false;
	try {
		$command->create( [ 'malformed' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_dir( $root . 'malformed' ) )->toBeFalse();

	command_remove_tree( $basedir );
} )->with( [
	'bad upload width'   => [ [ 'upload-width' => 'wide' ] ],
	'bad upload quality' => [ [ 'upload-quality' => '101' ] ],
	'bad full width'     => [ [ 'full-width' => '0' ] ],
	'bad thumbnail width' => [ [ 'thumbnail-width' => '-5' ] ],
	'bad thumbnail quality' => [ [ 'thumbnail-quality' => '200' ] ],
] );

test( 'create rejects an invalid slug before creating anything', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$threw = false;
	try {
		$command->create( [ 'Bad Slug' ], [] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( glob( $root . '*', GLOB_ONLYDIR ) )->toBe( [] );

	command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// update — rewrites only the name, rejects upload-contract changes
// ---------------------------------------------------------------------------

test( 'update rewrites only the name and preserves the rendition settings', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'trip' ], [
		'upload-width' => '4000',
		'full-width'   => '1600',
		'name'         => 'Old Name',
	] );
	$before = Descriptor::read( $root . 'trip' );

	WP_CLI::reset();
	$command->update( [ 'trip' ], [ 'name' => 'New Name' ] );
	$after = Descriptor::read( $root . 'trip' );

	// Only the display name changed; the rendition settings are carried over
	// verbatim (the editable re-derive flow is a later issue).
	expect( $after->name )->toBe( 'New Name' );
	expect( $after->upload_width )->toBe( $before->upload_width );
	expect( $after->upload_quality )->toBe( $before->upload_quality );
	expect( $after->full_width )->toBe( $before->full_width );
	expect( $after->thumbnail_width )->toBe( $before->thumbnail_width );
	expect( $after->path_components )->toBe( $before->path_components );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'update rejects an attempt to change an immutable upload-contract flag', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'frozen' ], [ 'name' => 'Frozen' ] );
	$before = file_get_contents( $root . 'frozen/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'frozen' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	// The immutable upload flag is refused and the descriptor is left byte-identical.
	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );
	expect( file_get_contents( $root . 'frozen/' . Descriptor::FILENAME ) )->toBe( $before );

	command_remove_tree( $basedir );
} )->with( [
	'upload-width'      => [
		[
			'name'         => 'X',
			'upload-width' => '800',
		],
	],
	'upload-quality'    => [
		[
			'name'           => 'X',
			'upload-quality' => '50',
		],
	],
	'immutable no name' => [ [ 'upload-width' => '800' ] ],
] );

test( 'update requires a non-empty name', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'named' ], [ 'name' => 'Keep' ] );

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

	$command->create( [ 'gone' ], [] );

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

	$command->create( [ 'forced' ], [] );

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

	$command->create( [ 'kept' ], [] );

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
