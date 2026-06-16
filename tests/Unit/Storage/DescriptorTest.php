<?php
/**
 * Tests for the collection descriptor: round-tripping the three-rendition
 * `collection.json` (ADR-0013) and its mutable path-components template
 * (ADR-0014).
 *
 * WordPress functions (`wp_json_encode`) are stubbed via Brain Monkey, but a
 * real temp directory backs the collection root so reads and writes exercise the
 * actual filesystem. Each test seeds and tears down its own temp tree.
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
 * `wp_json_encode()` is given the real `json_encode()` behaviour; the descriptor
 * no longer reads any filter, so nothing else is stubbed.
 */
function wire_descriptor_stubs(): void {

	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
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

/**
 * Builds a descriptor with the three-rendition defaults, overriding fields.
 *
 * @param array<string,mixed> $overrides Field overrides keyed by constructor parameter name.
 * @return Descriptor The descriptor under test.
 */
function make_descriptor( array $overrides = [] ): Descriptor {
	$fields = array_merge(
		[
			'name'              => 'Spring Trip',
			'upload_width'      => null,
			'upload_quality'    => 95,
			'full_width'        => 1920,
			'full_quality'      => 85,
			'thumbnail_width'   => 640,
			'thumbnail_quality' => 75,
			'path_components'   => '%year%/%month%/%day%/%uploader%',
		],
		$overrides,
	);
	return new Descriptor(
		$fields['name'],
		$fields['upload_width'],
		$fields['upload_quality'],
		$fields['full_width'],
		$fields['full_quality'],
		$fields['thumbnail_width'],
		$fields['thumbnail_quality'],
		$fields['path_components'],
	);
}

// ---------------------------------------------------------------------------
// Round-trip — all nine fields survive a write/read cycle
// ---------------------------------------------------------------------------

test( 'a descriptor round-trips every rendition field through disk', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// Write a descriptor with concrete values across all three tiers plus a custom
	// path-components template, then read it back; every field must survive the JSON
	// cycle (ADR-0013, ADR-0014).
	$written = make_descriptor( [
		'name'              => 'Spring Trip',
		'upload_width'      => 4000,
		'upload_quality'    => 92,
		'full_width'        => 1600,
		'full_quality'      => 82,
		'thumbnail_width'   => 480,
		'thumbnail_quality' => 70,
		'path_components'   => '%year%/%uploader%',
	] );
	expect( $written->write( $dir ) )->toBeTrue();

	$read = Descriptor::read( $dir );
	expect( $read )->not->toBeNull();
	expect( $read->name )->toBe( 'Spring Trip' );
	expect( $read->upload_width )->toBe( 4000 );
	expect( $read->upload_quality )->toBe( 92 );
	expect( $read->full_width )->toBe( 1600 );
	expect( $read->full_quality )->toBe( 82 );
	expect( $read->thumbnail_width )->toBe( 480 );
	expect( $read->thumbnail_quality )->toBe( 70 );
	expect( $read->path_components )->toBe( '%year%/%uploader%' );

	descriptor_remove_tree( $dir );
} );

test( 'a descriptor round-trips a null upload width', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// A null upload width means "the source's own dimensions" and must persist as
	// JSON null, not 0 (ADR-0013).
	make_descriptor( [ 'upload_width' => null ] )->write( $dir );

	$read = Descriptor::read( $dir );
	expect( $read->upload_width )->toBeNull();

	descriptor_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// On-disk shape — schema, key order, the dropped legacy keys, JSON null
// ---------------------------------------------------------------------------

test( 'the written file carries the schema and the fixed nine-key order', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	make_descriptor( [ 'upload_width' => 4000 ] )->write( $dir );

	// The schema constant is recorded and the keys appear in the documented order:
	// schema, name, the upload/full/thumbnail width+quality pairs, then
	// pathComponents (ADR-0013, ADR-0014).
	$raw  = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	$data = json_decode( $raw, true );
	expect( $data['schema'] )->toBe( Descriptor::SCHEMA );
	expect( array_keys( $data ) )->toBe( [
		'schema',
		'name',
		'uploadWidth',
		'uploadQuality',
		'fullWidth',
		'fullQuality',
		'thumbnailWidth',
		'thumbnailQuality',
		'pathComponents',
	] );

	descriptor_remove_tree( $dir );
} );

test( 'the written file drops the retired pre-redesign keys', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	make_descriptor()->write( $dir );

	// Pre-1.0 means no compatibility shim: the old maxWidth/quality/thumbnailWidths
	// and uploaderFolders keys are gone entirely (ADR-0013, ADR-0014).
	$data = json_decode( (string) file_get_contents( $dir . '/' . Descriptor::FILENAME ), true );
	foreach ( [ 'maxWidth', 'quality', 'thumbnailWidths', 'uploaderFolders' ] as $retired ) {
		expect( array_key_exists( $retired, $data ) )->toBeFalse();
	}

	descriptor_remove_tree( $dir );
} );

test( 'a null upload width is emitted as JSON null', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	make_descriptor( [ 'upload_width' => null ] )->write( $dir );

	$contents = (string) file_get_contents( $dir . '/' . Descriptor::FILENAME );
	expect( str_contains( $contents, '"uploadWidth": null' ) )->toBeTrue();

	descriptor_remove_tree( $dir );
} );

test( 'a re-write with unchanged data is byte-identical', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// The stable, pretty JSON must be deterministic so diffs stay quiet.
	$descriptor = make_descriptor( [ 'upload_width' => 4000 ] );
	$descriptor->write( $dir );
	$first = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	$descriptor->write( $dir );
	$second = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	expect( $second )->toBe( $first );

	descriptor_remove_tree( $dir );
} );

test( 'a successful write leaves no staging file beside the descriptor', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// The descriptor is published atomically (temp file, then rename); a successful
	// write must leave no `*.tmp-*` staging file beside the irreplaceable
	// collection.json.
	expect( make_descriptor()->write( $dir ) )->toBeTrue();
	expect( glob( $dir . '/*.tmp-*' ) )->toBe( [] );
	expect( is_file( $dir . '/' . Descriptor::FILENAME ) )->toBeTrue();

	descriptor_remove_tree( $dir );
} );

test( 'a failed write reports false and leaves an existing descriptor intact', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// Publish a first descriptor, then make the directory unwritable so the atomic
	// stage cannot be created: the live collection.json must survive byte-for-byte —
	// this is the file a torn write would brick.
	make_descriptor( [ 'name' => 'Original' ] )->write( $dir );
	$before = file_get_contents( $dir . '/' . Descriptor::FILENAME );
	chmod( $dir, 0500 );
	set_error_handler( static fn (): bool => true );
	$result = make_descriptor( [ 'name' => 'Replacement' ] )->write( $dir );
	restore_error_handler();
	chmod( $dir, 0700 );

	expect( $result )->toBeFalse();
	expect( file_get_contents( $dir . '/' . Descriptor::FILENAME ) )->toBe( $before );

	descriptor_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// read() — defensive decoding and the path-components default
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

test( 'read defaults an absent path-components template to the standard template', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// A descriptor that omits pathComponents (or carries a non-string) reads as the
	// default template, since an empty field means the default (ADR-0014). Expansion
	// itself is out of scope here — only the stored/defaulted value is asserted.
	$payload = [
		'schema'           => Descriptor::SCHEMA,
		'name'             => 'No Template',
		'uploadWidth'      => 4000,
		'uploadQuality'    => 95,
		'fullWidth'        => 1920,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 640,
		'thumbnailQuality' => 75,
	];
	file_put_contents( $dir . '/' . Descriptor::FILENAME, json_encode( $payload ) );

	expect( Descriptor::read( $dir )->path_components )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

	descriptor_remove_tree( $dir );
} );

test( 'read coerces a null upload width and keeps a concrete one', function (): void {
	wire_descriptor_stubs();
	$dir = fresh_collection_dir();

	// uploadWidth is the nullable half of the contract: JSON null reads as null (no
	// limit), a concrete integer reads as that integer.
	$payload = [
		'schema'           => Descriptor::SCHEMA,
		'name'             => 'Nullable',
		'uploadWidth'      => null,
		'uploadQuality'    => 95,
		'fullWidth'        => 1920,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 640,
		'thumbnailQuality' => 75,
		'pathComponents'   => '%year%',
	];
	file_put_contents( $dir . '/' . Descriptor::FILENAME, json_encode( $payload ) );

	expect( Descriptor::read( $dir )->upload_width )->toBeNull();

	descriptor_remove_tree( $dir );
} );

// ---------------------------------------------------------------------------
// to_array — the raw on-disk shape callers can read without re-reading
// ---------------------------------------------------------------------------

test( 'to_array exposes the nine-field shape in the fixed key order', function (): void {
	wire_descriptor_stubs();

	$array = make_descriptor( [ 'upload_width' => 4000 ] )->to_array();

	expect( $array )->toBe( [
		'schema'           => Descriptor::SCHEMA,
		'name'             => 'Spring Trip',
		'uploadWidth'      => 4000,
		'uploadQuality'    => 95,
		'fullWidth'        => 1920,
		'fullQuality'      => 85,
		'thumbnailWidth'   => 640,
		'thumbnailQuality' => 75,
		'pathComponents'   => '%year%/%month%/%day%/%uploader%',
	] );

} );

// ---------------------------------------------------------------------------
// DEFAULT_PATH_COMPONENTS — the documented standard template
// ---------------------------------------------------------------------------

test( 'the default path-components template is the documented placeholder string', function (): void {

	// The default template nests uploads under year/month/day/uploader (ADR-0014).
	expect( Descriptor::DEFAULT_PATH_COMPONENTS )->toBe( '%year%/%month%/%day%/%uploader%' );

} );
