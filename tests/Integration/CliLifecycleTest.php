<?php
/**
 * Integration tests for the WP-CLI collection lifecycle: create, update,
 * delete, and the required-contract-flag guard.
 *
 * Each test drives `wp kntnt-photo-drop collection …` inside the wp-env `cli`
 * container and asserts the resulting `collection.json` (or its absence) by
 * reading the collections root straight from the host through the bind mount.
 * Every collection carries a uniquely `it-` prefixed slug and is removed in a
 * `finally` block, so the suite never disturbs the concurrently used instance.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\delete_collection;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\run_cli;
use function Tests\Integration\unique_slug;

require_once __DIR__ . '/helpers.php';

test( 'collection create writes a valid descriptor with the given contract and name', function (): void {

	$slug = unique_slug();
	try {

		// Establish the collection with an explicit name and contract.
		create_collection( $slug, '1200', 70, 'Field Trip' );

		// The descriptor on disk must carry the schema, the verbatim name, the
		// immutable upload contract, and the re-derivable full/thumbnail renditions
		// plus the default path-components template.
		$descriptor = read_descriptor( $slug );
		expect( $descriptor )->not->toBeNull();
		expect( $descriptor['schema'] )->toBe( 1 );
		expect( $descriptor['name'] )->toBe( 'Field Trip' );
		expect( $descriptor['uploadWidth'] )->toBe( 1200 );
		expect( $descriptor['uploadQuality'] )->toBe( 70 );
		expect( $descriptor['fullWidth'] )->toBeInt()->toBeGreaterThan( 0 );
		expect( $descriptor['thumbnailWidth'] )->toBeInt()->toBeGreaterThan( 0 );
		expect( $descriptor['pathComponents'] )->toBe( '%year%/%month%/%day%/%uploader%' );
	} finally {
		delete_collection( $slug );
	}

} );

test( 'collection create defaults the name to a humanised slug', function (): void {

	$slug = unique_slug();
	try {

		// Without --name the display name is the slug, humanised: hyphens to
		// spaces, each word capitalised.
		create_collection( $slug, '1600', 80 );
		$expected = implode( ' ', array_map( 'ucfirst', explode( '-', $slug ) ) );
		expect( read_descriptor( $slug )['name'] )->toBe( $expected );

	} finally {
		delete_collection( $slug );
	}

} );

test( 'collection update changes only the display name', function (): void {

	$slug = unique_slug();
	try {

		// Snapshot the descriptor as created, rename the collection, and
		// snapshot again: the name differs and every other field is identical.
		create_collection( $slug, '1200', 70, 'Before' );
		$before = read_descriptor( $slug );
		$result = run_cli( [ 'kntnt-photo-drop', 'collection', 'update', $slug, '--name=After' ] );
		expect( $result['exit_code'] )->toBe( 0 );
		$after = read_descriptor( $slug );
		expect( $after['name'] )->toBe( 'After' );
		unset( $before['name'], $after['name'] );
		expect( $after )->toBe( $before );

	} finally {
		delete_collection( $slug );
	}

} );

test( 'collection update rejects an attempt to change the immutable contract', function (): void {

	$slug = unique_slug();
	try {

		// The upload contract is immutable; passing an upload flag to update must
		// fail hard and leave the descriptor untouched.
		create_collection( $slug, '1200', 70 );
		$before = read_descriptor( $slug );
		$result = run_cli( [ 'kntnt-photo-drop', 'collection', 'update', $slug, '--name=X', '--upload-width=999' ] );
		expect( $result['exit_code'] )->not->toBe( 0 );
		expect( read_descriptor( $slug ) )->toBe( $before );

	} finally {
		delete_collection( $slug );
	}

} );

test( 'collection delete --yes removes the whole directory', function (): void {

	// Create and immediately delete; the directory must be gone, descriptor
	// and all, because the filesystem is the entire source of truth.
	$slug = unique_slug();
	create_collection( $slug, '1200', 70 );
	expect( is_dir( collection_path( $slug ) ) )->toBeTrue();
	$result = run_cli( [ 'kntnt-photo-drop', 'collection', 'delete', $slug, '--yes' ] );
	expect( $result['exit_code'] )->toBe( 0 );
	expect( is_dir( collection_path( $slug ) ) )->toBeFalse();

} );

test( 'collection create defaults every rendition flag when none is given', function (): void {

	// Every rendition flag is optional and falls back to its filter (ADR-0013):
	// a bare create succeeds and writes the documented defaults — an unbounded
	// upload width (the source's own dimensions), upload quality 95, a 1920/85
	// full, and a 640/75 thumbnail.
	$slug = unique_slug();
	try {

		$result = run_cli( [ 'kntnt-photo-drop', 'collection', 'create', $slug ] );
		expect( $result['exit_code'] )->toBe( 0 );
		$descriptor = read_descriptor( $slug );
		expect( $descriptor['uploadWidth'] )->toBeNull();
		expect( $descriptor['uploadQuality'] )->toBe( 95 );
		expect( $descriptor['fullWidth'] )->toBe( 1920 );
		expect( $descriptor['thumbnailWidth'] )->toBe( 640 );

	} finally {
		delete_collection( $slug );
	}

} );
