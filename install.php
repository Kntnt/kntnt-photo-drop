<?php
/**
 * Plugin activation handler.
 *
 * Registered as the activation hook from the main plugin file. Runs in the
 * normal WordPress request context, so the autoloader and the full WordPress
 * API are available — but this file is kept dependency-light on purpose so it
 * can run before the rest of the plugin's components are wired.
 *
 * For this scaffolding slice it ensures the plugin's uploads root exists, so
 * the first collection write has a directory to land in. Capability
 * registration, migrations, and cron wiring land in later slices.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop;

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensures the plugin's uploads root directory exists.
 *
 * The root lives under the site's uploads basedir at `kntnt-photo-drop/` and
 * is filterable via `kntnt_photo_drop_root` so a site can relocate it (it must
 * stay web-served, per-site on multisite). Collections are directories beneath
 * this root; the filesystem is the source of truth, so no database rows are
 * created here.
 *
 * @since 0.1.0
 */
function activate(): void {

	// Resolve the uploads root, allowing a site to relocate it via filter.
	$uploads = wp_upload_dir();
	$default = trailingslashit( $uploads['basedir'] ) . 'kntnt-photo-drop';
	$root    = (string) apply_filters( 'kntnt_photo_drop_root', $default );

	// Create the root directory if it is missing; wp_mkdir_p() is a no-op when
	// it already exists.
	if ( ! is_dir( $root ) ) {
		wp_mkdir_p( $root );
	}

}
