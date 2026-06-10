/**
 * Jest tests for the upload type pre-filter.
 *
 * The filter is a deny-list over a name and a MIME type: video MIME types and
 * RAW/video extensions are denied, everything else — including HEIC and files
 * with no MIME type — passes through to the server-side contract enforcement.
 *
 * @since 0.2.0
 */

import { shouldUploadFile } from './file-filter';

describe( 'shouldUploadFile', () => {
	it( 'allows a plain JPEG', () => {
		expect( shouldUploadFile( 'IMG_0001.JPG', 'image/jpeg' ) ).toBe( true );
	} );

	it( 'allows HEIC even though the server may re-encode it', () => {
		expect( shouldUploadFile( 'IMG_0002.HEIC', 'image/heic' ) ).toBe(
			true
		);
	} );

	it( 'allows a file with an unknown extension and no MIME type', () => {
		expect( shouldUploadFile( 'mystery.xyz', '' ) ).toBe( true );
	} );

	it( 'allows a file without any extension', () => {
		expect( shouldUploadFile( 'README', '' ) ).toBe( true );
	} );

	it( 'denies any video MIME type regardless of extension', () => {
		expect( shouldUploadFile( 'clip.weird', 'video/x-whatever' ) ).toBe(
			false
		);
	} );

	it.each( [
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
	] )( 'denies the RAW extension .%s', ( extension ) => {
		expect( shouldUploadFile( `IMG_0001.${ extension }`, '' ) ).toBe(
			false
		);
	} );

	it.each( [
		'mov',
		'mp4',
		'm4v',
		'avi',
		'mts',
		'm2ts',
		'mkv',
		'webm',
		'3gp',
	] )( 'denies the video extension .%s', ( extension ) => {
		expect( shouldUploadFile( `clip.${ extension }`, '' ) ).toBe( false );
	} );

	it( 'matches extensions case-insensitively', () => {
		expect( shouldUploadFile( 'IMG_0001.CR2', '' ) ).toBe( false );
		expect( shouldUploadFile( 'CLIP.MOV', '' ) ).toBe( false );
	} );

	it( 'reads only the last dot segment of a multi-dot name', () => {
		expect( shouldUploadFile( 'clip.final.mov', '' ) ).toBe( false );
		expect( shouldUploadFile( 'photo.mov.jpg', 'image/jpeg' ) ).toBe(
			true
		);
	} );

	it( 'allows a name ending in a bare dot', () => {
		expect( shouldUploadFile( 'oddname.', '' ) ).toBe( true );
	} );
} );
