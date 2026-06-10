<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress loads this file directly when the user deletes the plugin from the
 * admin area. The autoloader is NOT available here — use fully qualified
 * WordPress functions and $wpdb only, no class references.
 *
 * The filesystem is the source of truth for collections, and uninstall
 * deliberately leaves the on-disk collections in place (deleting a user's
 * photo library on plugin removal would be destructive and surprising). The
 * plugin's database footprint is limited to transients: the updater's cached
 * GitHub release lookup (the `kntnt_photo_drop_release` site transient) and
 * the admin page's short-lived per-user notice stashes
 * (`kntnt_photo_drop_admin_notices_{user_id}`). Both are purged below; no
 * options, post-meta, or custom tables exist.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Abort if WordPress did not trigger this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the updater's cached GitHub release lookup through the API, which
// covers both single-site (options) and multisite (sitemeta) storage.
delete_site_transient( 'kntnt_photo_drop_release' );

// Sweep every remaining plugin transient straight from the options table: the
// per-user admin-notice stashes carry a user-id suffix, so no exact-key delete
// can enumerate them. Both value and timeout rows are removed, and the
// `_site_transient_` variants are included for completeness. The instanceof
// guard narrows the global for static analysis and skips the sweep in the
// impossible case core has not set up the database handle.
global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	return;
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall purges transient rows by prefix; no API enumerates them, caching is irrelevant, and the query is prepared right above.
$sql = $wpdb->prepare(
	'DELETE FROM %i
	WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
	$wpdb->options,
	$wpdb->esc_like( '_transient_kntnt_photo_drop_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_kntnt_photo_drop_' ) . '%',
	$wpdb->esc_like( '_site_transient_kntnt_photo_drop_' ) . '%',
	$wpdb->esc_like( '_site_transient_timeout_kntnt_photo_drop_' ) . '%',
);
if ( is_string( $sql ) ) {
	$wpdb->query( $sql );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
