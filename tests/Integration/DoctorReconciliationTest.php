<?php
/**
 * Integration tests for the doctor's reconciliation of a real on-disk
 * collection: missing derived artifacts, orphaned thumbnails, foreign files.
 *
 * The drift is injected from the host through the bind mount (deleting a
 * thumbnail, deleting a main, dropping a stray file), exactly the out-of-band
 * interference the doctor exists to reconcile. Assertions cover both halves of
 * the contract: the report (exit code and named findings) and the post-repair
 * filesystem state.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\delete_collection;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\read_descriptor;
use function Tests\Integration\remove_tree;
use function Tests\Integration\run_doctor;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

// Seed one collection with one imported main for the whole file; individual
// tests add their own extra mains for scenarios that destroy one.
$slug      = unique_slug();
$fixtures  = null;
$thumbnail = null;

beforeAll( function () use ( $slug, &$fixtures, &$thumbnail ): void {

	// Establish the collection and import one JPEG so a main plus its
	// thumbnail exist as the healthy baseline.
	create_collection( $slug, '1200', 70 );
	$fixtures = make_fixture_dir();
	write_jpeg( "{$fixtures}/photo.jpg", 1600, 900 );
	import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );

	// Resolve the baseline thumbnail path from the descriptor's first width.
	$width     = read_descriptor( $slug )['thumbnailWidths'][0];
	$thumbnail = collection_path( $slug ) . "/.kntnt-thumbnails/{$width}/photo.jpg.webp";

} );

afterAll( function () use ( $slug, &$fixtures ): void {
	delete_collection( $slug );
	if ( $fixtures !== null ) {
		remove_tree( $fixtures );
	}
} );

test( 'a missing thumbnail is missing-derived and --repair recreates it', function () use ( $slug, &$thumbnail ): void {

	// Inject the drift: delete the thumbnail out-of-band while the main stays.
	expect( is_file( $thumbnail ) )->toBeTrue();
	unlink( $thumbnail );

	// Report-only is the dry run: it exits non-zero on actionable findings
	// and names the missing derived artifact, while the disk stays untouched.
	$report = run_doctor( $slug );
	expect( $report['exit_code'] )->not->toBe( 0 );
	expect( $report['output'] )->toContain( 'missing-derived' );
	expect( $report['output'] )->toContain( basename( dirname( $thumbnail ) ) . '/photo.jpg.webp' );
	expect( is_file( $thumbnail ) )->toBeFalse();

	// --repair acts: the thumbnail is re-derived from the main and the run
	// exits zero.
	$repair = run_doctor( $slug, [ '--repair' ] );
	expect( $repair['exit_code'] )->toBe( 0 );
	expect( is_file( $thumbnail ) )->toBeTrue();

} );

test( 'an orphan thumbnail whose main is gone is removed by --repair', function () use ( $slug, &$fixtures ): void {

	// Import a second main, then delete the main out-of-band so its
	// thumbnail becomes an orphan.
	write_jpeg( "{$fixtures}/orphan.jpg", 1600, 900 );
	import_images( $slug, [ to_container_path( "{$fixtures}/orphan.jpg" ) ] );
	$width  = read_descriptor( $slug )['thumbnailWidths'][0];
	$main   = collection_path( $slug ) . '/orphan.jpg.webp';
	$orphan = collection_path( $slug ) . "/.kntnt-thumbnails/{$width}/orphan.jpg.webp";
	expect( is_file( $orphan ) )->toBeTrue();
	unlink( $main );

	// The derived artifact is slaved to the main: with the main gone,
	// --repair removes the orphan and exits zero.
	$repair = run_doctor( $slug, [ '--repair' ] );
	expect( $repair['exit_code'] )->toBe( 0 );
	expect( is_file( $orphan ) )->toBeFalse();

} );

test( 'a foreign file is reported but never deleted, even by --repair', function () use ( $slug ): void {

	// Drop a stray non-image file into the collection root out-of-band.
	$foreign = collection_path( $slug ) . '/notes.txt';
	file_put_contents( $foreign, 'left here by a human' );
	try {

		// The report names the foreign file and trips the non-zero exit, but
		// must not touch it.
		$report = run_doctor( $slug );
		expect( $report['exit_code'] )->not->toBe( 0 );
		expect( $report['output'] )->toContain( 'foreign' );
		expect( $report['output'] )->toContain( 'notes.txt' );
		expect( is_file( $foreign ) )->toBeTrue();

		// Even an acting repair leaves a foreign file alone — the doctor never
		// deletes what it did not derive.
		run_doctor( $slug, [ '--repair' ] );
		expect( is_file( $foreign ) )->toBeTrue();

	} finally {
		@unlink( $foreign );
	}

} );
