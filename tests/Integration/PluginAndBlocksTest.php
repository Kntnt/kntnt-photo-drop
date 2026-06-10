<?php
/**
 * Integration tests for plugin wiring: the plugin is active, both block types
 * are registered server-side, and the gallery block renders a real page.
 *
 * Runs against the live `@wordpress/env` instance. The rendering test seeds
 * its own uniquely-slugged collection, imports a real JPEG through the CLI,
 * publishes a page carrying the gallery block, and curls it like a visitor.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

use function Tests\Integration\collection_path;
use function Tests\Integration\create_collection;
use function Tests\Integration\create_gallery_page;
use function Tests\Integration\delete_collection;
use function Tests\Integration\delete_page;
use function Tests\Integration\http_get;
use function Tests\Integration\import_images;
use function Tests\Integration\make_fixture_dir;
use function Tests\Integration\remove_tree;
use function Tests\Integration\run_cli;
use function Tests\Integration\to_container_path;
use function Tests\Integration\unique_slug;
use function Tests\Integration\write_jpeg;

require_once __DIR__ . '/helpers.php';

test( 'the plugin is active in the wp-env instance', function (): void {

	// Ask WP-CLI for the plugin's status; anything but `active` means the
	// environment is not exercising the code under test.
	$result = run_cli( [ 'plugin', 'get', 'kntnt-photo-drop', '--field=status' ] );
	expect( $result['exit_code'] )->toBe( 0 );
	expect( trim( $result['output'] ) )->toBe( 'active' );

} );

test( 'both block types are registered server-side', function (): void {

	// Read the server-side block registry inside the container; both blocks
	// are registered from block.json on init, so they must be present on any
	// WordPress request, not only in the editor.
	$result = run_cli(
		[ 'eval', 'echo implode( "\n", array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) );' ],
	);
	expect( $result['exit_code'] )->toBe( 0 );
	expect( $result['output'] )->toContain( 'kntnt-photo-drop/drop-zone' );
	expect( $result['output'] )->toContain( 'kntnt-photo-drop/gallery' );

} );

test( 'a published gallery page renders the figure and srcset for an imported image', function (): void {

	// Seed a private collection with one imported JPEG and publish a page
	// whose content is a single gallery block selecting it.
	$slug     = unique_slug();
	$fixtures = make_fixture_dir();
	$page_id  = null;
	try {
		create_collection( $slug );
		write_jpeg( "{$fixtures}/photo.jpg" );
		$import = import_images( $slug, [ to_container_path( "{$fixtures}/photo.jpg" ) ] );
		expect( $import['exit_code'] )->toBe( 0 );
		$page    = create_gallery_page( $slug );
		$page_id = $page['id'];

		// Fetch the page as an anonymous visitor and assert the server-rendered
		// gallery markup: a figure per image whose srcset carries the stored
		// main, proving the block render callback ran against the collection.
		$response = http_get( $page['url'] );
		expect( $response['status'] )->toBe( 200 );
		expect( $response['body'] )->toContain( '<figure class="kntnt-photo-drop-gallery__item' );
		expect( $response['body'] )->toContain( 'srcset=' );
		expect( $response['body'] )->toContain( "/kntnt-photo-drop/{$slug}/photo.jpg.webp" );

	} finally {

		// Remove everything this test created, even on failure.
		if ( $page_id !== null ) {
			delete_page( $page_id );
		}
		delete_collection( $slug );
		remove_tree( $fixtures );
		expect( is_dir( collection_path( $slug ) ) )->toBeFalse();

	}

} );
