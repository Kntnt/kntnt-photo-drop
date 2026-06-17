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
	'bad upload width'      => [ [ 'upload-width' => 'wide' ] ],
	'bad upload quality'    => [ [ 'upload-quality' => '101' ] ],
	'bad full width'        => [ [ 'full-width' => '0' ] ],
	'bad thumbnail width'   => [ [ 'thumbnail-width' => '-5' ] ],
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
// create — the path-components template is normalised and validated (ADR-0014)
// ---------------------------------------------------------------------------

test( 'create normalises the path-components template before storing it', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A template with edge and repeated separators is canonicalised at save, so the
	// stored descriptor carries the normalised form (ADR-0014).
	$command->create( [ 'normalised' ], [ 'path-components' => '/%year%//%uploader%/' ] );

	expect( Descriptor::read( $root . 'normalised' )->path_components )->toBe( '%year%/%uploader%' );

	command_remove_tree( $basedir );
} );

test( 'create rejects a path-components template with a stray percent', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A mistyped placeholder leaves a stray `%`; `%` is reserved, so the create
	// halts before any directory is made rather than storing a broken template
	// (ADR-0014).
	$threw = false;
	try {
		$command->create( [ 'stray' ], [ 'path-components' => '%year%/%moth%/%day%' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( WP_CLI::$errors )->toHaveCount( 1 );
	expect( is_dir( $root . 'stray' ) )->toBeFalse();

	command_remove_tree( $basedir );
} );

test( 'create rejects a path-components template whose expansion is unsafe', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A template whose sample expansion carries a traversal is rejected at save, so
	// it can never break a later upload (ADR-0014).
	$threw = false;
	try {
		$command->create( [ 'unsafe' ], [ 'path-components' => '%year%/../../escape' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( is_dir( $root . 'unsafe' ) )->toBeFalse();

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

	// Only the display name changed; with no re-derivable flag passed, the rendition
	// settings carry over verbatim and no regenerate-then-flip is triggered.
	expect( $after->name )->toBe( 'New Name' );
	expect( $after->upload_width )->toBe( $before->upload_width );
	expect( $after->upload_quality )->toBe( $before->upload_quality );
	expect( $after->full_width )->toBe( $before->full_width );
	expect( $after->thumbnail_width )->toBe( $before->thumbnail_width );
	expect( $after->path_components )->toBe( $before->path_components );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'update mutates the path-components template, affecting only future uploads', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// The template is mutable (unlike the retired uploaderFolders boolean), so
	// update rewrites it — normalised — while leaving the immutable upload contract
	// untouched (ADR-0014).
	$command->create( [ 'mutable' ], [ 'name' => 'Mutable' ] );
	$before = Descriptor::read( $root . 'mutable' );

	WP_CLI::reset();
	$command->update( [ 'mutable' ], [
		'name'            => 'Mutable',
		'path-components' => '/events/%year%/',
	] );
	$after = Descriptor::read( $root . 'mutable' );

	expect( $after->path_components )->toBe( 'events/%year%' );
	expect( $after->upload_width )->toBe( $before->upload_width );
	expect( $after->upload_quality )->toBe( $before->upload_quality );

	command_remove_tree( $basedir );
} );

test( 'update leaves the path-components template untouched when the flag is absent', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A `--path-components` not passed must carry the existing template over; only
	// an explicit flag mutates it.
	$command->create( [ 'kept-template' ], [ 'path-components' => '%year%/%uploader%' ] );

	WP_CLI::reset();
	$command->update( [ 'kept-template' ], [ 'name' => 'Renamed' ] );

	expect( Descriptor::read( $root . 'kept-template' )->path_components )->toBe( '%year%/%uploader%' );

	command_remove_tree( $basedir );
} );

test( 'update rejects an invalid path-components template and writes nothing', function ( string $template ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A stray-`%` or unsafe template is rejected at save, leaving the descriptor
	// byte-identical (ADR-0014).
	$command->create( [ 'guarded' ], [ 'name' => 'Guarded' ] );
	$before = file_get_contents( $root . 'guarded/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'guarded' ], [
			'name'            => 'Guarded',
			'path-components' => $template,
		] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( file_get_contents( $root . 'guarded/' . Descriptor::FILENAME ) )->toBe( $before );

	command_remove_tree( $basedir );
} )->with( [
	'stray percent' => [ '%year%/%moth%' ],
	'traversal'     => [ '%year%/../../x' ],
] );

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

test( 'update rejects an explicit empty name but keeps the existing one', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	$command->create( [ 'named' ], [ 'name' => 'Keep' ] );

	// An explicit blank name is rejected — a collection must keep a display name — and
	// the descriptor is left with its existing name (ADR-0014: --name is optional, but
	// not blankable).
	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'named' ], [ 'name' => '' ] );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( Descriptor::read( $root . 'named' )->name )->toBe( 'Keep' );

	command_remove_tree( $basedir );
} );

// ---------------------------------------------------------------------------
// update — the re-derivable full/thumbnail settings are mutable (ADR-0013)
// ---------------------------------------------------------------------------

test( 'update mutates the re-derivable full and thumbnail settings and flips the descriptor', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// Establish the default re-derivable pair, then change all four re-derivable values.
	// The collection holds no images, so the regenerate-then-flip has nothing to derive
	// and finalises straight to the flipped descriptor.
	$command->create( [ 'rederive' ], [ 'name' => 'Rederive' ] );

	WP_CLI::reset();
	$command->update( [ 'rederive' ], [
		'full-width'        => '1000',
		'full-quality'      => '70',
		'thumbnail-width'   => '300',
		'thumbnail-quality' => '60',
	] );
	$after = Descriptor::read( $root . 'rederive' );

	// The descriptor flipped to the new re-derivable values; the display name carried
	// over (no --name given) and the immutable upload contract is untouched.
	expect( $after->full_width )->toBe( 1000 );
	expect( $after->full_quality )->toBe( 70 );
	expect( $after->thumbnail_width )->toBe( 300 );
	expect( $after->thumbnail_quality )->toBe( 60 );
	expect( $after->name )->toBe( 'Rederive' );
	expect( $after->upload_quality )->toBe( 95 );
	expect( WP_CLI::$successes )->toHaveCount( 1 );

	command_remove_tree( $basedir );
} );

test( 'update carries the name over when --name is absent', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A re-derivable change with no --name must keep the existing display name rather
	// than demand it be re-typed (ADR-0013: --name is optional on update).
	$command->create( [ 'keep-name' ], [ 'name' => 'Original' ] );

	WP_CLI::reset();
	$command->update( [ 'keep-name' ], [ 'thumbnail-width' => '320' ] );
	$after = Descriptor::read( $root . 'keep-name' );

	expect( $after->name )->toBe( 'Original' );
	expect( $after->thumbnail_width )->toBe( 320 );

	command_remove_tree( $basedir );
} );

test( 'update is a clean no-op when no field changes', function (): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A bare update (no flags) changes nothing — the descriptor is preserved and the
	// command reports success rather than demanding a flag.
	$command->create( [ 'untouched' ], [ 'name' => 'Untouched' ] );
	$before = file_get_contents( $root . 'untouched/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$command->update( [ 'untouched' ], [] );

	expect( WP_CLI::$successes )->toHaveCount( 1 );
	expect( file_get_contents( $root . 'untouched/' . Descriptor::FILENAME ) )->toBe( $before );

	command_remove_tree( $basedir );
} );

test( 'update rejects a malformed re-derivable flag and writes nothing', function ( array $args ): void {
	$basedir = fresh_command_basedir();
	$root    = wire_command_stubs( $basedir );
	$command = make_command();

	// A malformed full/thumbnail width or quality halts before the descriptor is
	// rewritten, so a typo never flips a collection to a degenerate width.
	$command->create( [ 'validated' ], [ 'name' => 'Validated' ] );
	$before = file_get_contents( $root . 'validated/' . Descriptor::FILENAME );

	WP_CLI::reset();
	$threw = false;
	try {
		$command->update( [ 'validated' ], $args );
	} catch ( Cli_Halt ) {
		$threw = true;
	}

	expect( $threw )->toBeTrue();
	expect( file_get_contents( $root . 'validated/' . Descriptor::FILENAME ) )->toBe( $before );

	command_remove_tree( $basedir );
} )->with( [
	'bad full width'        => [ [ 'full-width' => 'wide' ] ],
	'zero thumbnail width'  => [ [ 'thumbnail-width' => '0' ] ],
	'bad full quality'      => [ [ 'full-quality' => '101' ] ],
	'bad thumbnail quality' => [ [ 'thumbnail-quality' => '-5' ] ],
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
