/**
 * Tests for the pure on-blur unique-slug compute.
 *
 * The Create form's Slug field is optional: its placeholder shows the unique slug a
 * blank field would default to, refreshed on-blur of the Display name. The DOM
 * wiring lives in the non-unit shell; the value it shows rests on this pure
 * function, which slugifies the name and suffixes it from `-2` against the rendered
 * existing-slugs list. The server re-verifies uniqueness at submit, so this is a
 * best-effort preview — but it must match `sanitize_title` + the `-2`/`-3`
 * suffixing for the ASCII display names that dominate, and never collide with a slug
 * already in the list.
 *
 * @since 0.12.0
 */

import { uniqueSlug } from './slug-default';

describe( 'uniqueSlug', () => {
	it( 'slugifies the display name when nothing collides', () => {
		// Lowercase, spaces to hyphens, punctuation dropped — the ASCII subset of
		// sanitize_title the placeholder mirrors.
		expect( uniqueSlug( 'Spring 2024 Trip!', [] ) ).toBe(
			'spring-2024-trip'
		);
	} );

	it( 'collapses and trims separators like sanitize_title', () => {
		expect( uniqueSlug( '  Café del   Mar  ', [] ) ).toBe( 'caf-del-mar' );
		expect( uniqueSlug( 'a__b--c', [] ) ).toBe( 'a-b-c' );
	} );

	it( 'suffixes from -2 against the existing slugs', () => {
		// The base collides, so the lowest free numeric suffix from -2 is taken,
		// skipping every taken suffix in turn (never -1).
		expect( uniqueSlug( 'Spring', [ 'spring' ] ) ).toBe( 'spring-2' );
		expect( uniqueSlug( 'Spring', [ 'spring', 'spring-2' ] ) ).toBe(
			'spring-3'
		);
		expect( uniqueSlug( 'Spring', [ 'spring', 'spring-3' ] ) ).toBe(
			'spring-2'
		);
	} );

	it( 'leaves the base unsuffixed when no slug collides', () => {
		expect( uniqueSlug( 'Spring', [ 'summer', 'autumn' ] ) ).toBe(
			'spring'
		);
	} );

	it( 'returns an empty string when the name slugifies to nothing', () => {
		// A name with no sluggable characters yields an empty default; the field then
		// requires the builder to type a slug (the server rejects a blank-with-no-base).
		expect( uniqueSlug( '!!!', [] ) ).toBe( '' );
	} );
} );
