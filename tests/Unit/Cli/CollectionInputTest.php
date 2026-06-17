<?php
/**
 * Tests for the pure flag parser/validator behind the collection command.
 *
 * Collection_Input has no WP-CLI and no filesystem dependency, so it is tested
 * in complete isolation: the nullable upload-width parse (the `none` → `null`
 * "source dimensions" form and the positive-integer rule), the positive-int
 * width parse used by the full and thumbnail flags, quality bounding to 0–100,
 * the humanised-name default, and the reject-immutable-change rule that `update`
 * enforces (the upload pair is the only immutable contract). These are the
 * decidable rules the thin command delegates to (ADR-0013).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Cli\Collection_Input;

// ---------------------------------------------------------------------------
// parse_upload_width — the `none` → null form and positive-int validation
// ---------------------------------------------------------------------------

test( 'parse_upload_width maps the none keyword to null', function ( string $keyword ): void {
	expect( ( new Collection_Input() )->parse_upload_width( $keyword ) )->toBeNull();
} )->with( [
	'lowercase'   => [ 'none' ],
	'capitalised' => [ 'None' ],
	'uppercase'   => [ 'NONE' ],
] );

test( 'parse_upload_width accepts a positive integer', function (): void {
	expect( ( new Collection_Input() )->parse_upload_width( '4000' ) )->toBe( 4000 );
} );

test( 'parse_upload_width rejects non-positive and malformed values', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_upload_width( $value ) )->toBeFalse();
} )->with( [
	'zero'         => [ '0' ],
	'negative'     => [ '-5' ],
	'decimal'      => [ '12.5' ],
	'leading zero' => [ '01920' ],
	'noise'        => [ '1920px' ],
	'empty'        => [ '' ],
	'word'         => [ 'wide' ],
] );

// ---------------------------------------------------------------------------
// parse_width — the positive-int parser the full and thumbnail flags use
// ---------------------------------------------------------------------------

test( 'parse_width accepts a positive integer', function (): void {
	expect( ( new Collection_Input() )->parse_width( '1920' ) )->toBe( 1920 );
} );

test( 'parse_width has no "none" form and rejects it', function (): void {

	// Unlike the upload width, the full and thumbnail widths are never unbounded,
	// so "none" is just an invalid value here.
	expect( ( new Collection_Input() )->parse_width( 'none' ) )->toBeFalse();

} );

test( 'parse_width rejects non-positive and malformed values', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_width( $value ) )->toBeFalse();
} )->with( [
	'zero'     => [ '0' ],
	'negative' => [ '-5' ],
	'decimal'  => [ '12.5' ],
	'noise'    => [ '640px' ],
	'empty'    => [ '' ],
] );

// ---------------------------------------------------------------------------
// parse_quality — 0–100 bound
// ---------------------------------------------------------------------------

test( 'parse_quality accepts values from 0 to 100', function ( string $value, int $expected ): void {
	expect( ( new Collection_Input() )->parse_quality( $value ) )->toBe( $expected );
} )->with( [
	'floor'   => [ '0', 0 ],
	'mid'     => [ '80', 80 ],
	'ceiling' => [ '100', 100 ],
] );

test( 'parse_quality rejects out-of-range and malformed values', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_quality( $value ) )->toBeFalse();
} )->with( [
	'over'     => [ '101' ],
	'negative' => [ '-1' ],
	'decimal'  => [ '80.5' ],
	'noise'    => [ '80%' ],
	'empty'    => [ '' ],
] );

// ---------------------------------------------------------------------------
// resolve_name / humanise_slug — display-name defaulting
// ---------------------------------------------------------------------------

test( 'resolve_name keeps a supplied non-empty name verbatim', function (): void {
	expect( ( new Collection_Input() )->resolve_name( 'Spring Trip', 'spring-2024' ) )->toBe( 'Spring Trip' );
} );

test( 'resolve_name humanises the slug when no name is supplied', function ( ?string $name ): void {
	expect( ( new Collection_Input() )->resolve_name( $name, 'spring-2024-trip' ) )->toBe( 'Spring 2024 Trip' );
} )->with( [
	'null'  => [ null ],
	'empty' => [ '' ],
] );

test( 'humanise_slug turns hyphens into capitalised words', function (): void {
	$input = new Collection_Input();
	expect( $input->humanise_slug( 'autumn' ) )->toBe( 'Autumn' );
	expect( $input->humanise_slug( 'a-b-c' ) )->toBe( 'A B C' );
} );

// ---------------------------------------------------------------------------
// find_immutable_flag — only the upload pair is immutable on update
// ---------------------------------------------------------------------------

test( 'find_immutable_flag spots an immutable upload-contract flag', function ( array $args, ?string $expected ): void {
	expect( ( new Collection_Input() )->find_immutable_flag( $args ) )->toBe( $expected );
} )->with( [
	'upload-width'         => [ [ 'upload-width' => '4000' ], 'upload-width' ],
	'upload-quality'       => [ [ 'upload-quality' => '95' ], 'upload-quality' ],
	'both prefers width'   => [
		[
			'upload-quality' => '95',
			'upload-width'   => '4000',
		],
		'upload-width',
	],
	'full is mutable'      => [ [ 'full-width' => '1920' ], null ],
	'thumbnail is mutable' => [ [ 'thumbnail-quality' => '75' ], null ],
	'path is mutable'      => [ [ 'path-components' => '%year%' ], null ],
	'only name'            => [ [ 'name' => 'New Name' ], null ],
	'empty'                => [ [], null ],
] );
