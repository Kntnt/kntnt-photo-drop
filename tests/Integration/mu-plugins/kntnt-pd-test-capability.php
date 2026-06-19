<?php
/**
 * Integration test scaffold: re-gates the upload capability from an option.
 *
 * Mounted into the wp-env instance via `.wp-env.json` `mappings`. It is inert
 * unless an integration test sets the `kntnt_pd_test_upload_capability` option,
 * so it does not affect normal dev, e2e, or other integration tests. When the
 * option holds a non-empty capability string, it overrides the upload gate
 * through the same `kntnt_photo_drop_upload_capability` filter a site would use,
 * proving the live endpoint honors that filter end-to-end.
 *
 * @package Kntnt\Photo_Drop
 */

declare( strict_types = 1 );

// Override the upload capability from a test option when one is set; otherwise
// return the default untouched so the filter is a no-op for every other test.
add_filter(
	'kntnt_photo_drop_upload_capability',
	static function ( $default_capability ) {
		$override = get_option( 'kntnt_pd_test_upload_capability', '' );
		return is_string( $override ) && $override !== '' ? $override : $default_capability;
	}
);
