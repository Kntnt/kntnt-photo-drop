/**
 * Jest tests for the hidden filesystem-noise pre-filter.
 *
 * The filter flags any basename beginning with a dot as hidden OS bookkeeping —
 * AppleDouble sidecars (`._<name>`), `.DS_Store`, and other dotfiles — so the
 * intake can silently drop them; every ordinarily-named file passes.
 *
 * @since 0.10.1
 */

import { isHiddenFile } from './hidden-file';

describe( 'isHiddenFile', () => {
	it( 'flags a macOS AppleDouble sidecar that shadows a real photo', () => {
		expect( isHiddenFile( '._DSCF0012.JPG' ) ).toBe( true );
	} );

	it( 'flags the .DS_Store folder-state file', () => {
		expect( isHiddenFile( '.DS_Store' ) ).toBe( true );
	} );

	it( 'flags any other dotfile swept up by a folder pick', () => {
		expect( isHiddenFile( '.localized' ) ).toBe( true );
	} );

	it( 'passes the real photo the sidecar shadows', () => {
		expect( isHiddenFile( 'DSCF0012.JPG' ) ).toBe( false );
	} );

	it( 'passes an ordinary file whose name merely contains a dot', () => {
		expect( isHiddenFile( 'holiday.photo.jpg' ) ).toBe( false );
	} );

	it( 'passes an empty name rather than mistaking it for hidden', () => {
		expect( isHiddenFile( '' ) ).toBe( false );
	} );
} );
