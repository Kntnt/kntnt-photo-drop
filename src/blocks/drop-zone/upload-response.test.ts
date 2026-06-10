/**
 * Jest tests for the upload-response interpretation rules.
 *
 * Exercises the three decisions over plain payloads: outcome extraction (a
 * success may never be reported without a parsed outcome), nonce-rejection
 * detection (401/403 plus a known code, nothing else), and failure labelling
 * (server message over outcome label over the generic fallback).
 *
 * @since 0.2.0
 */

import {
	errorLabelFor,
	isNonceRejection,
	labelForOutcome,
	readErrorMessage,
	readOutcome,
	type OutcomeStrings,
} from './upload-response';

/**
 * The label fixture the tests resolve against.
 */
const strings: OutcomeStrings = {
	outcomeStored: 'Uploaded',
	outcomeReencoded: 'Uploaded (re-encoded)',
	outcomeSkipped: 'Skipped — already present',
	outcomeRejected: 'Rejected',
	uploadFailed: 'Upload failed',
};

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

describe( 'readErrorMessage', () => {
	it( 'returns the server message when present', () => {
		expect(
			readErrorMessage( { code: 'x', message: 'Reload and try again.' } )
		).toBe( 'Reload and try again.' );
	} );

	it( 'returns null for an empty or missing message', () => {
		expect( readErrorMessage( { message: '' } ) ).toBeNull();
		expect( readErrorMessage( { code: 'x' } ) ).toBeNull();
		expect( readErrorMessage( null ) ).toBeNull();
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

describe( 'labelForOutcome', () => {
	it.each( [
		[ 'stored', 'Uploaded' ],
		[ 'reencoded', 'Uploaded (re-encoded)' ],
		[ 'skipped', 'Skipped — already present' ],
		[ 'rejected', 'Rejected' ],
	] as const )( 'labels %s as "%s"', ( outcome, label ) => {
		expect( labelForOutcome( outcome, strings ) ).toBe( label );
	} );
} );

describe( 'errorLabelFor', () => {
	it( 'prefers the server message over everything else', () => {
		expect(
			errorLabelFor(
				{
					code: 'kntnt_photo_drop_invalid_nonce',
					message: 'Your session could not be verified.',
				},
				strings
			)
		).toBe( 'Your session could not be verified.' );
	} );

	it( 'falls back to the outcome label for a 422 rejection body', () => {
		expect(
			errorLabelFor( { outcome: 'rejected', name: null }, strings )
		).toBe( 'Rejected' );
	} );

	it( 'falls back to the generic label for an unparseable body', () => {
		expect( errorLabelFor( null, strings ) ).toBe( 'Upload failed' );
	} );
} );
