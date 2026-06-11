<?php
/**
 * Tests for the collection descriptor: round-tripping `collection.json` and
 * normalising the filter-supplied thumbnail width(s).
 *
 * WordPress functions (`apply_filters`, `wp_json_encode`) are stubbed via Brain
 * Monkey, but a real temp directory backs the collection root so reads and
 * writes exercise the actual filesystem. Each test seeds and tears down its own
 * temp tree.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Wires the WordPress stubs the descriptor depends on.
 *
 * `wp_json_encode()` is given the real `json_encode()` behaviour, and
 * `apply_filters()` returns the thumbnail-width override when one is supplied or
 * passes the default through otherwise.
 *
 * @param int|array<int,int>|null $thumbnail_override Filter return value, or null to pass the default through.
 */
function wire_descriptor_stubs( int|array|null $thumbnail_override = null ): void {

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
	);

	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $value ) use ( $thumbnail_override ): mixed {
			return $hook === 'kntnt_photo_drop_thumbnail_width' && $thumbnail_override !== null
				? $thumbnail_override
				: $value;
		}
	);

}

/**
 * Allocates a fresh temp directory standing in for a collection root.
 *
 * @return string The absolute path of the new directory.
 */
function fresh_collection_dir(): string {
	$dir = sys_get_temp_dir() . '/kntnt-descriptor-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700, true );
	return $dir;
}

/**
 * Removes a directory tree used as a temp collection root.
 *
 * @param string $dir The directory to remove.
 */
function descriptor_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	$entries = scandir( $dir );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		descriptor_remove_tree( $dir . '/' . $entry );
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// Round-trip — all six fields survive a write/read cycle
// ---------------------------------------------------------------------------

test( 'a descriptor round-trips all six fields through disk', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// Write a descriptor with a concrete max width and two thumbnail widths,
	// then read it back; every field must survive the JSON cycle.
	$written = new Descriptor( 'Spring Trip', 1920, 80, [ 320, 640 ] );
	expect( $written->write( $dir ) )->toBeTrue();

	$read = Descriptor::read( $dir );
	expect( $read )->not->toBeNull();
	expect( $read->name )->toBe( 'Spring Trip' );
	expect( $read->max_width )->toBe( 1920 );
	expect( $read->quality )->toBe( 80 );
	expect( $read->thumbnail_widths )->toBe( [ 320, 640 ] );
	expect( $read->uploader_folders )->toBeTrue();

	descriptor_remove_tree( $dir );
} );

test( 'a descriptor round-trips uploaderFolders for both values', function ( bool $uploader_folders ): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// The immutable uploader-folders flag must survive the JSON cycle for both
	// on and off, so an off collection is honoured even before the create UI.
	( new Descriptor( 'Namespaced', 1920, 80, [ 640 ], $uploader_folders ) )->write( $dir );

	expect( Descriptor::read( $dir )->uploader_folders )->toBe( $uploader_folders );

	descriptor_remove_tree( $dir );
} )->with( [
	'on'  => [ true ],
	'off' => [ false ],
] );

test( 'a descriptor round-trips a null maxWidth', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// A null max width means "no limit" and must persist as JSON null, not 0.
	( new Descriptor( 'No Limit', null, 75, [ 640 ] ) )->write( $dir );

	$read = Descriptor::read( $dir );
	expect( $read->max_width )->toBeNull();

	descriptor_remove_tree( $dir );
} );

test( 'a descriptor round-trips empty thumbnail widths', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// `[]` is the canonical "no thumbnail" marker and must round-trip as an
	// empty JSON array.
	( new Descriptor( 'Thumbless', 1600, 80, [] ) )->write( $dir );

	$read = Descriptor::read( $dir );
	expect( $read->thumbnail_widths )->toBe( [] );

	descriptor_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// On-disk shape — schema, key order, and JSON null
// ---------------------------------------------------------------------------

test( 'the written file carries the schema and the fixed key order', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	( new Descriptor( 'Album', 1920, 80, [ 640 ] ) )->write( $dir );

	// The schema constant is recorded, the keys appear in the documented order,
	// and a null max width is emitted as JSON null.
	$raw  = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	$data = json_decode( $raw, true );
	expect( $data['schema'] )->toBe( Descriptor::SCHEMA );
	expect( array_keys( $data ) )->toBe(
		[ 'schema', 'name', 'maxWidth', 'quality', 'thumbnailWidths', 'uploaderFolders' ]
	);

	( new Descriptor( 'Album', null, 80, [ 640 ] ) )->write( $dir );
	$contents = (string) file_get_contents( $dir . '/' . Descriptor::FILENAME );
	expect( str_contains( $contents, '"maxWidth": null' ) )->toBeTrue();

	descriptor_remove_tree( $dir );
} );

test( 'a successful write leaves no staging file beside the descriptor', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// The descriptor is published atomically (temp file, then rename); a
	// successful write must leave no `*.tmp-*` staging file beside the
	// irreplaceable collection.json.
	expect( ( new Descriptor( 'Atomic', 1920, 80, [ 640 ] ) )->write( $dir ) )->toBeTrue();
	expect( glob( $dir . '/*.tmp-*' ) )->toBe( [] );
	expect( is_file( $dir . '/' . Descriptor::FILENAME ) )->toBeTrue();

	descriptor_remove_tree( $dir );
} );

test( 'a failed write reports false and leaves an existing descriptor intact', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// Publish a first descriptor, then make the directory unwritable so the
	// atomic stage cannot be created: the live collection.json must survive
	// byte-for-byte — this is the file a torn write would brick.
	( new Descriptor( 'Original', 1920, 80, [ 640 ] ) )->write( $dir );
	$before = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	chmod( $dir, 0500 );
	set_error_handler( static fn (): bool => true );
	$result = ( new Descriptor( 'Replacement', 800, 50, [] ) )->write( $dir );
	restore_error_handler();
	chmod( $dir, 0700 );

	expect( $result )->toBeFalse();
	expect( file_get_contents( $dir . '/' . Descriptor::FILENAME ) )->toBe( $before );

	descriptor_remove_tree( $dir );
} );

test( 'a re-write with unchanged data is byte-identical', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// The stable, pretty JSON must be deterministic so diffs stay quiet.
	$descriptor = new Descriptor( 'Stable', 1920, 80, [ 320, 640 ] );
	$descriptor->write( $dir );
	$first = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	$descriptor->write( $dir );
	$second = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	expect( $second )->toBe( $first );

	descriptor_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// read() — defensive decoding
// ---------------------------------------------------------------------------

test( 'read returns null for a missing descriptor', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	expect( Descriptor::read( $dir ) )->toBeNull();

	descriptor_remove_tree( $dir );
} );

test( 'read returns null for a corrupt descriptor', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// A non-JSON-object payload is a corrupt descriptor we refuse to interpret.
	file_put_contents( $dir . '/' . Descriptor::FILENAME, 'not json' );
	expect( Descriptor::read( $dir ) )->toBeNull();

	descriptor_remove_tree( $dir );
} );

test( 'read re-normalises a hand-edited thumbnailWidths', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// A hand-edited file with out-of-order, duplicate, and non-positive widths
	// is re-normalised on read to the canonical sorted, unique, positive list.
	$payload = [
		'schema'          => Descriptor::SCHEMA,
		'name'            => 'Edited',
		'maxWidth'        => 1920,
		'quality'         => 80,
		'thumbnailWidths' => [ 640, 320, 640, 0, -10, 320 ],
		'uploaderFolders' => true,
	];
	file_put_contents( $dir . '/' . Descriptor::FILENAME, json_encode( $payload ) );

	expect( Descriptor::read( $dir )->thumbnail_widths )->toBe( [ 320, 640 ] );

	descriptor_remove_tree( $dir );
} );

test( 'read returns null when the required uploaderFolders flag is bad', function ( mixed $value ): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// uploaderFolders is required with no default-on-read fallback (pre-1.0,
	// ADR-0008): an omitted flag (null marker) or a non-boolean value is a
	// malformed descriptor read as null, not silently defaulted to on.
	$payload = [
		'schema'          => Descriptor::SCHEMA,
		'name'            => 'Legacy',
		'maxWidth'        => 1920,
		'quality'         => 80,
		'thumbnailWidths' => [ 640 ],
	];
	if ( $value !== null ) {
		$payload['uploaderFolders'] = $value;
	}
	file_put_contents( $dir . '/' . Descriptor::FILENAME, json_encode( $payload ) );

	expect( Descriptor::read( $dir ) )->toBeNull();

	descriptor_remove_tree( $dir );
} )->with( [
	'missing'     => [ null ],
	'string true' => [ 'true' ],
	'integer one' => [ 1 ],
] );

// ---------------------------------------------------------------------------
// from_filter — thumbnail-width normalisation from the filter
// ---------------------------------------------------------------------------

test( 'from_filter defaults to a single 640 thumbnail width', function (): void {
	wire_descriptor_stubs();

	// With no override the default thumbnail width is recorded.
	$descriptor = Descriptor::from_filter( 'Default', 1920, 80 );
	expect( $descriptor->thumbnail_widths )->toBe( [ 640 ] );

} );

test( 'from_filter records an int thumbnail width as a one-element list', function (): void {
	wire_descriptor_stubs( 480 );

	// A scalar filter return becomes a single-element list.
	expect( Descriptor::from_filter( 'Single', 1920, 80 )->thumbnail_widths )->toBe( [ 480 ] );

} );

test( 'from_filter normalises an array thumbnail width', function (): void {
	wire_descriptor_stubs( [ 960, 320, 640 ] );

	// An array filter return is sorted ascending and de-duplicated.
	expect( Descriptor::from_filter( 'Multi', 1920, 80 )->thumbnail_widths )->toBe( [ 320, 640, 960 ] );

} );

test( 'from_filter treats 0 and [] as no thumbnail', function ( int|array $override ): void {
	wire_descriptor_stubs( $override );

	// `0` and `[]` both collapse to the empty "no thumbnail" list.
	expect( Descriptor::from_filter( 'None', 1920, 80 )->thumbnail_widths )->toBe( [] );

} )->with( [
	'zero'        => [ 0 ],
	'empty array' => [ [] ],
] );

test( 'from_filter carries the caller-supplied contract values', function (): void {
	wire_descriptor_stubs();

	// The name, max width, and quality come straight from the caller; only the
	// thumbnail widths are filter-derived.
	$descriptor = Descriptor::from_filter( 'Contract', null, 65 );
	expect( $descriptor->name )->toBe( 'Contract' );
	expect( $descriptor->max_width )->toBeNull();
	expect( $descriptor->quality )->toBe( 65 );

} );

test( 'from_filter defaults the uploader-folders namespace to on', function (): void {
	wire_descriptor_stubs();

	// A caller that does not surface the create-time choice still namespaces per
	// uploader by default (ADR-0008).
	expect( Descriptor::from_filter( 'Default', 1920, 80 )->uploader_folders )->toBeTrue();

} );

test( 'from_filter carries the caller-supplied uploader-folders choice', function ( bool $choice ): void {
	wire_descriptor_stubs();

	// The create-time choice (admin checkbox, CLI flag) is recorded verbatim and
	// fixed at establishment; both values must survive into the descriptor.
	expect( Descriptor::from_filter( 'Chosen', 1920, 80, $choice )->uploader_folders )->toBe( $choice );

} )->with( [
	'on'  => [ true ],
	'off' => [ false ],
] );
