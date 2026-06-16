<?php
/**
 * Tests for the Drop Zone placement template — normalise, stray-`%`, expand,
 * preview (ADR-0014).
 *
 * Path_Template owns the pure mechanics of the descriptor's `pathComponents`
 * string: normalising the separator structure (split on `/`, strip
 * leading/trailing separators, collapse empty segments, empty → default),
 * spotting a stray `%` left after the four known placeholders (the
 * `%`-reservation, so a mistyped `%moth%` cannot become a literal folder),
 * expanding the four placeholders server-side at upload time, and a
 * presentational sample preview. It touches no WordPress and no filesystem, so
 * it is exercised in complete isolation; the upload-date placeholders are driven
 * with a fixed `DateTimeImmutable` so the assertions are deterministic.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Collection\Path_Template;
use Kntnt\Photo_Drop\Storage\Descriptor;

// ---------------------------------------------------------------------------
// normalise — split/strip/collapse, empty → default
// ---------------------------------------------------------------------------

test( 'normalise strips leading and trailing separators and collapses empties', function ( string $raw, string $expected ): void {
	expect( Path_Template::normalise( $raw ) )->toBe( $expected );
} )->with( [
	'plain'              => [ '%year%/%month%', '%year%/%month%' ],
	'leading separator'  => [ '/%year%/%month%', '%year%/%month%' ],
	'trailing separator' => [ '%year%/%month%/', '%year%/%month%' ],
	'both separators'    => [ '/%year%/%month%/', '%year%/%month%' ],
	'collapsed empties'  => [ '%year%//%month%', '%year%/%month%' ],
	'many separators'    => [ '///%year%////%uploader%///', '%year%/%uploader%' ],
	'single segment'     => [ 'archive', 'archive' ],
	'literal mix'        => [ 'photos/%year%', 'photos/%year%' ],
] );

test( 'normalise returns the default template for an empty or separator-only value', function ( string $raw ): void {

	// An empty field means the default template (ADR-0014); a value that is nothing
	// but separators collapses to empty and therefore to the default too.
	expect( Path_Template::normalise( $raw ) )->toBe( Descriptor::DEFAULT_PATH_COMPONENTS );

} )->with( [
	'empty'            => [ '' ],
	'single slash'     => [ '/' ],
	'several slashes'  => [ '////' ],
	'whitespace only'  => [ '   ' ],
] );

// ---------------------------------------------------------------------------
// has_stray_placeholder — the `%`-reservation
// ---------------------------------------------------------------------------

test( 'has_stray_placeholder is false when only the four known placeholders and literals appear', function ( string $template ): void {
	expect( Path_Template::has_stray_placeholder( $template ) )->toBeFalse();
} )->with( [
	'all four'      => [ '%year%/%month%/%day%/%uploader%' ],
	'subset'        => [ '%year%/%uploader%' ],
	'literal only'  => [ 'photos/2024' ],
	'literal mix'   => [ 'events/%year%/gallery' ],
	'repeated'      => [ '%year%/%year%' ],
] );

test( 'has_stray_placeholder is true when any unrecognised percent survives', function ( string $template ): void {

	// A `%` left after stripping the four known placeholders is a typo or an unknown
	// token; reserving `%` means it must be rejected at save rather than becoming a
	// literal folder named after the broken token (ADR-0014).
	expect( Path_Template::has_stray_placeholder( $template ) )->toBeTrue();

} )->with( [
	'misspelled month'   => [ '%year%/%moth%/%day%' ],
	'unknown token'      => [ '%year%/%hour%' ],
	'lone percent'       => [ 'photos/%/year' ],
	'half-open'          => [ '%year/%month%' ],
	'trailing percent'   => [ '%year%50%' ],
	'uppercase variant'  => [ '%YEAR%' ],
] );

// ---------------------------------------------------------------------------
// expand — server-side substitution at upload time
// ---------------------------------------------------------------------------

test( 'expand substitutes the four placeholders from the upload date and uploader', function (): void {

	// %year%/%month%/%day% come from the supplied upload date (the caller passes the
	// site-timezone date); %uploader% comes from the supplied nicename. Month and day
	// are zero-padded to two digits so folders sort lexically.
	$date     = new DateTimeImmutable( '2024-03-07 13:45:00' );
	$expanded = Path_Template::expand( '%year%/%month%/%day%/%uploader%', $date, 'jane-doe' );

	expect( $expanded )->toBe( '2024/03/07/jane-doe' );

} )->with( [ 'single run' => [] ] );

test( 'expand zero-pads month and day to two digits', function (): void {

	// A December date keeps two digits; a single-digit month/day is padded, so every
	// month/day folder is exactly two characters.
	$december = Path_Template::expand( '%year%/%month%/%day%', new DateTimeImmutable( '2024-12-25' ), 'x' );
	$january  = Path_Template::expand( '%year%/%month%/%day%', new DateTimeImmutable( '2025-01-04' ), 'x' );

	expect( $december )->toBe( '2024/12/25' );
	expect( $january )->toBe( '2025/01/04' );

} );

test( 'expand leaves literal segments untouched and substitutes only placeholders', function (): void {

	// A template mixing literals and placeholders keeps the literals verbatim and
	// substitutes only the known tokens.
	$expanded = Path_Template::expand( 'events/%year%/gallery', new DateTimeImmutable( '2024-06-16' ), 'bob' );

	expect( $expanded )->toBe( 'events/2024/gallery' );

} );

test( 'expand substitutes every occurrence of a repeated placeholder', function (): void {

	// `str_replace`-style substitution replaces all occurrences, not just the first.
	$expanded = Path_Template::expand( '%year%/%year%/%uploader%', new DateTimeImmutable( '2024-06-16' ), 'bob' );

	expect( $expanded )->toBe( '2024/2024/bob' );

} );

// ---------------------------------------------------------------------------
// sample_expansion — the presentational preview substitution
// ---------------------------------------------------------------------------

test( 'sample_expansion substitutes the placeholders with fixed sample values', function (): void {

	// The preview is presentational only: it substitutes each placeholder with a
	// stable, recognisable sample so a builder sees the shape of the resulting path
	// as they type. It carries no safety logic and no real date.
	$preview = Path_Template::sample_expansion( '%year%/%month%/%day%/%uploader%' );

	// The four sample tokens are present in order, separated by `/`, with the
	// literal structure preserved.
	expect( $preview )->toContain( Path_Template::SAMPLE_YEAR );
	expect( $preview )->toContain( Path_Template::SAMPLE_MONTH );
	expect( $preview )->toContain( Path_Template::SAMPLE_DAY );
	expect( $preview )->toContain( Path_Template::SAMPLE_UPLOADER );
	expect( $preview )->toBe(
		Path_Template::SAMPLE_YEAR . '/' . Path_Template::SAMPLE_MONTH
		. '/' . Path_Template::SAMPLE_DAY . '/' . Path_Template::SAMPLE_UPLOADER,
	);

} );

test( 'sample_expansion keeps a literal segment and an unknown token verbatim', function (): void {

	// The preview substitutes only the four known placeholders; a literal stays as
	// typed and an unknown token is shown as-is (the save-time stray-`%` check is
	// what rejects it — the preview only visualises).
	$preview = Path_Template::sample_expansion( 'photos/%moth%' );

	expect( $preview )->toBe( 'photos/%moth%' );

} );
