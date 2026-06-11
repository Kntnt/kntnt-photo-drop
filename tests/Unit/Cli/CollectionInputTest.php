<?php
/**
 * Tests for the pure flag parser/validator behind the collection command.
 *
 * Collection_Input has no WP-CLI and no filesystem dependency, so it is tested
 * in complete isolation: max-width parsing (including the `none` → `null` "no
 * limit" form and the positive-integer rule), quality bounding to 0–100, the
 * humanised-name default, and the reject-contract-change rule that `update`
 * enforces. These are the decidable rules the thin command delegates to.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Cli\Collection_Input;

// ---------------------------------------------------------------------------
// parse_max_width — the `none` → null form and positive-int validation
// ---------------------------------------------------------------------------

test( 'parse_max_width maps the none keyword to null', function ( string $keyword ): void {
	expect( ( new Collection_Input() )->parse_max_width( $keyword ) )->toBeNull();
} )->with( [
	'lowercase'   => [ 'none' ],
	'capitalised' => [ 'None' ],
	'uppercase'   => [ 'NONE' ],
] );

test( 'parse_max_width accepts a positive integer', function (): void {
	expect( ( new Collection_Input() )->parse_max_width( '1920' ) )->toBe( 1920 );
} );

test( 'parse_max_width rejects non-positive and malformed values', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_max_width( $value ) )->toBeFalse();
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
// parse_uploader_folders — the `none`-of-the-contract placement-rule boolean
// ---------------------------------------------------------------------------

test( 'parse_uploader_folders defaults an absent flag to on', function (): void {
	expect( ( new Collection_Input() )->parse_uploader_folders( null ) )->toBeTrue();
} );

test( 'parse_uploader_folders reads the common truthy spellings as true', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_uploader_folders( $value ) )->toBeTrue();
} )->with( [
	'one'         => [ '1' ],
	'true'        => [ 'true' ],
	'capitalTrUe' => [ 'TrUe' ],
	'yes'         => [ 'yes' ],
	'on'          => [ 'on' ],
] );

test( 'parse_uploader_folders reads the common falsy spellings as false', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_uploader_folders( $value ) )->toBeFalse();
} )->with( [
	'zero'  => [ '0' ],
	'false' => [ 'false' ],
	'no'    => [ 'no' ],
	'off'   => [ 'off' ],
	'empty' => [ '' ],
] );

test( 'parse_uploader_folders returns null for an undecidable value', function ( string $value ): void {
	expect( ( new Collection_Input() )->parse_uploader_folders( $value ) )->toBeNull();
} )->with( [
	'word'    => [ 'maybe' ],
	'numeric' => [ '2' ],
	'noise'   => [ 'truthy' ],
] );

// ---------------------------------------------------------------------------
// find_immutable_flag — the reject-immutable-change-on-update rule
// ---------------------------------------------------------------------------

test( 'find_immutable_flag spots an establishment-fixed flag', function ( array $args, ?string $expected ): void {
	expect( ( new Collection_Input() )->find_immutable_flag( $args ) )->toBe( $expected );
} )->with( [
	'max-width'        => [ [ 'max-width' => '1920' ], 'max-width' ],
	'quality'          => [ [ 'quality' => '80' ], 'quality' ],
	'uploader-folders' => [ [ 'uploader-folders' => 'false' ], 'uploader-folders' ],
	'negated folders'  => [ [ 'uploader-folders' => false ], 'uploader-folders' ],
	'both prefers max' => [
		[
			'quality'   => '80',
			'max-width' => '1920',
		],
		'max-width',
	],
	'only name'        => [ [ 'name' => 'New Name' ], null ],
	'empty'            => [ [], null ],
] );
