/**
 * Jest tests for the `webkitRelativePath` → upload-metadata mapping.
 *
 * The rule decides which relative path is sent per file: the browser-supplied
 * `webkitRelativePath` for a folder selection, or the plain file name for a loose
 * file. The path is forwarded verbatim — the server is the trust boundary and must
 * see the raw bytes to reject traversal — so these tests confirm the mapping does
 * no sanitisation of its own.
 *
 * @since 0.5.0
 */

import { relativePathForFile } from './relative-path';

describe( 'relativePathForFile', () => {
	it( 'uses the webkitRelativePath when the browser supplied one', () => {
		expect(
			relativePathForFile( {
				name: 'IMG_2024.jpg',
				webkitRelativePath: 'trip/day1/IMG_2024.jpg',
			} )
		).toBe( 'trip/day1/IMG_2024.jpg' );
	} );

	it( 'falls back to the file name when webkitRelativePath is empty', () => {
		expect(
			relativePathForFile( {
				name: 'loose.jpg',
				webkitRelativePath: '',
			} )
		).toBe( 'loose.jpg' );
	} );

	it( 'falls back to the file name when webkitRelativePath is absent', () => {
		expect( relativePathForFile( { name: 'loose.jpg' } ) ).toBe(
			'loose.jpg'
		);
	} );

	it( 'forwards the path verbatim without sanitising traversal', () => {
		// The mapping never sanitises — the server hard-sanitises and
		// realpath-confines the path (ADR-0006), so a hostile webkitRelativePath
		// must reach the wire unchanged to be rejected server-side.
		expect(
			relativePathForFile( {
				name: 'x.jpg',
				webkitRelativePath: '../../escape.jpg',
			} )
		).toBe( '../../escape.jpg' );
	} );

	it( 'preserves a deep nested path exactly', () => {
		expect(
			relativePathForFile( {
				name: 'photo.jpg',
				webkitRelativePath: 'a/b/c/d/photo.jpg',
			} )
		).toBe( 'a/b/c/d/photo.jpg' );
	} );
} );
