<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress loads this file directly when the user deletes the plugin from the
 * admin area. The autoloader is NOT available here — use fully qualified
 * WordPress functions only, no class references.
 *
 * This plugin keeps no database rows: the filesystem is the source of truth
 * for collections, and no post-meta or options are written. There is therefore
 * nothing to purge, and uninstall deliberately leaves the on-disk collections
 * in place (deleting a user's photo library on plugin removal would be
 * destructive and surprising). The file remains a registered uninstall handler
 * so the guard below stays in force and future data-bearing additions have an
 * obvious home.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Abort if WordPress did not trigger this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally a no-op: no database rows, options, or post-meta to remove,
// and on-disk collections are left untouched by design.
