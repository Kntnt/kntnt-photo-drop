/**
 * Jest tests for the Gallery editor's pure caption assembly.
 *
 * The editor preview derives caption text the same way the PHP `Caption_Builder`
 * derives it server-side, so these tests pin the lockstep rule: the `none`
 * content yields nothing, a filename caption maps the stored `<original>.webp`
 * name back and humanises it, and a path breadcrumb joins folder segments and the
 * filename with the separator, optionally prefixed by the collection name.
 *
 * @since 0.6.0
 */

import { buildCaption, toOriginalName, type CaptionSettings } from './caption';

/**
 * Builds a settings object with sensible defaults a test can override.
 *
 * @param overrides - The fields to override.
 * @return The caption settings.
 */
function settings( overrides: Partial< CaptionSettings > ): CaptionSettings {
	return {
		content: 'filename',
		humanize: true,
		includeName: false,
		separator: '›',
		collectionName: 'Trip',
		...overrides,
	};
}

describe( 'toOriginalName', () => {
	it( 'strips the appended .webp when the remainder keeps an extension', () => {
		expect( toOriginalName( 'IMG_2024.jpg.webp' ) ).toBe( 'IMG_2024.jpg' );
	} );

	it( 'leaves an already-webp original unchanged', () => {
		expect( toOriginalName( 'sunset.webp' ) ).toBe( 'sunset.webp' );
	} );

	it( 'returns a non-webp name as-is', () => {
		expect( toOriginalName( 'notes.txt' ) ).toBe( 'notes.txt' );
	} );
} );

describe( 'buildCaption', () => {
	it( 'returns an empty string for the none content', () => {
		expect(
			buildCaption(
				'a/b/IMG_1.jpg.webp',
				settings( { content: 'none' } )
			)
		).toBe( '' );
	} );

	it( 'captions with the humanised filename only', () => {
		expect(
			buildCaption(
				'morning/sun_rise-01.jpg.webp',
				settings( { content: 'filename' } )
			)
		).toBe( 'sun rise 01' );
	} );

	it( 'keeps the original filename verbatim when humanise is off', () => {
		expect(
			buildCaption(
				'morning/sun_rise-01.jpg.webp',
				settings( { content: 'filename', humanize: false } )
			)
		).toBe( 'sun_rise-01.jpg' );
	} );

	it( 'builds a humanised path breadcrumb with the default separator', () => {
		expect(
			buildCaption(
				'2024_summer/day-one/IMG_5.jpg.webp',
				settings( { content: 'path' } )
			)
		).toBe( '2024 summer › day one › IMG 5' );
	} );

	it( 'prefixes the breadcrumb with the collection name when asked', () => {
		expect(
			buildCaption(
				'day-one/IMG_5.jpg.webp',
				settings( {
					content: 'path',
					includeName: true,
					collectionName: 'Trip',
				} )
			)
		).toBe( 'Trip › day one › IMG 5' );
	} );

	it( 'honours a custom separator', () => {
		expect(
			buildCaption(
				'a/b/c.jpg.webp',
				settings( { content: 'path', separator: '/' } )
			)
		).toBe( 'a / b / c' );
	} );

	it( 'captions a root-level image with just its filename', () => {
		expect(
			buildCaption( 'lonely.jpg.webp', settings( { content: 'path' } ) )
		).toBe( 'lonely' );
	} );
} );
