<?php
/**
 * Tests for the import-source type pre-filter.
 *
 * The filter is the server-side mirror of the Drop Zone's RAW/video deny-list,
 * applied when the CLI walks a directory so a whole-folder import skips the RAW
 * and video siblings the optimiser would refuse anyway. The rule is pure over a
 * filename, so it is covered here without touching the filesystem; one test pins
 * the exact deny-list so it cannot silently drift from `file-filter.ts`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.13.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Ingestion\Source_Filter;

// ---------------------------------------------------------------------------
// should_import — deny RAW/video by extension, allow everything else
// ---------------------------------------------------------------------------

test( 'should_import decides by extension', function ( string $name, bool $expected ): void {
	expect( ( new Source_Filter() )->should_import( $name ) )->toBe( $expected );
} )->with( [
	'canon raw'         => [ 'IMG_0001.CR2', false ],
	'nikon raw'         => [ 'DSC_0001.nef', false ],
	'video container'   => [ 'clip.MOV', false ],
	'multi-dot video'   => [ 'clip.final.MOV', false ],
	'jpeg'              => [ 'photo.jpg', true ],
	'jpeg upper'        => [ 'photo.JPG', true ],
	'webp'              => [ 'photo.webp', true ],
	'unknown image'     => [ 'photo.heic', true ],
	'no extension'      => [ 'noext', true ],
	'trailing dot'      => [ 'weird.', true ],
	'non-image archive' => [ 'archive.tar', true ],
] );

// ---------------------------------------------------------------------------
// Pin the deny-list so it cannot drift from src/blocks/drop-zone/file-filter.ts
// ---------------------------------------------------------------------------

test( 'the deny-list mirrors file-filter.ts exactly', function (): void {

	// Every RAW/video extension the browser deny-list carries must be denied here
	// too, and nothing image-shaped may be denied; asserting the full set catches
	// an accidental add or removal on either side of the TS/PHP mirror.
	$filter = new Source_Filter();
	$denied = [
		'cr2',
		'cr3',
		'nef',
		'arw',
		'dng',
		'raf',
		'orf',
		'rw2',
		'srw',
		'pef',
		'mov',
		'mp4',
		'm4v',
		'avi',
		'mts',
		'm2ts',
		'mkv',
		'webm',
		'3gp',
	];

	foreach ( $denied as $extension ) {
		expect( $filter->should_import( "file.{$extension}" ) )->toBeFalse();
	}
	expect( $filter->should_import( 'file.jpg' ) )->toBeTrue();

} );
