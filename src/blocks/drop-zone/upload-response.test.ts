/**
 * Jest tests for the upload-response interpretation rules.
 *
 * Exercises the two decisions over plain payloads: outcome extraction (a
 * success may never be recorded without a parsed outcome) and nonce-rejection
 * detection (401/403 plus a known code, nothing else).
 *
 * @since 0.2.0
 */

import { isNonceRejection, readOutcome } from './upload-response';

describe( 'readOutcome', () => {
	it( 'extracts a valid outcome with its display name', () => {
		expect(
			readOutcome( { outcome: 'stored', name: 'photo.webp' } )
		).toEqual( { outcome: 'stored', name: 'photo.webp' } );
	} );

	it( 'maps a missing name to null', () => {
		expect( readOutcome( { outcome: 'rejected' } ) ).toEqual( {
			outcome: 'rejected',
			name: null,
		} );
	} );

	it( 'returns null for an unknown outcome value', () => {
		expect( readOutcome( { outcome: 'exploded' } ) ).toBeNull();
	} );

	it( 'returns null for a WP_Error envelope', () => {
		expect(
			readOutcome( { code: 'kntnt_photo_drop_invalid_nonce' } )
		).toBeNull();
	} );

	it( 'returns null for an unparseable body', () => {
		expect( readOutcome( null ) ).toBeNull();
		expect( readOutcome( 'OK' ) ).toBeNull();
	} );
} );

describe( 'isNonceRejection', () => {
	it( 'detects the plugin nonce code on a 401', () => {
		expect(
			isNonceRejection( 401, {
				code: 'kntnt_photo_drop_invalid_nonce',
			} )
		).toBe( true );
	} );

	it( 'detects the core cookie nonce code on a 403', () => {
		expect(
			isNonceRejection( 403, { code: 'rest_cookie_invalid_nonce' } )
		).toBe( true );
	} );

	it( 'ignores a capability rejection — a new nonce cannot fix it', () => {
		expect(
			isNonceRejection( 403, { code: 'kntnt_photo_drop_forbidden' } )
		).toBe( false );
	} );

	it( 'ignores a nonce code on a non-auth status', () => {
		expect(
			isNonceRejection( 500, {
				code: 'kntnt_photo_drop_invalid_nonce',
			} )
		).toBe( false );
	} );

	it( 'ignores a body without a code', () => {
		expect( isNonceRejection( 401, null ) ).toBe( false );
		expect( isNonceRejection( 401, { outcome: 'rejected' } ) ).toBe(
			false
		);
	} );
} );
