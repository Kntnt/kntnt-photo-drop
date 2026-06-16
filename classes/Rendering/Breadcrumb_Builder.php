<?php
/**
 * Pure assembly of a gallery image's breadcrumb overlay from its path.
 *
 * Skeleton for the RED step — methods exist with the documented signatures but
 * return empty/placeholder values, so the breadcrumb tests fail on a real
 * assertion rather than a missing-symbol fatal. The GREEN step fills in the
 * derivation.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.11.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Derives the breadcrumb crumbs and text for one image (skeleton).
 *
 * @since 0.11.0
 */
final class Breadcrumb_Builder {

	/**
	 * Returns the ordered breadcrumb crumbs for one image (skeleton: empty).
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $collection_name The collection's display name (the first crumb).
	 * @param int    $hide_count      How many leading crumbs to drop.
	 * @return array<int,string> The crumbs to show.
	 */
	public static function crumbs( string $relative_path, string $collection_name, int $hide_count ): array {
		return [];
	}

	/**
	 * Returns the breadcrumb text joined by the separator (skeleton: empty).
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path   The image path relative to the collection root.
	 * @param string $collection_name The collection's display name (the first crumb).
	 * @param int    $hide_count      How many leading crumbs to drop.
	 * @param string $separator       The crumb separator (free text).
	 * @return string The joined breadcrumb text.
	 */
	public static function text(
		string $relative_path,
		string $collection_name,
		int $hide_count,
		string $separator,
	): string {
		return '';
	}

	/**
	 * Returns the humanised filename of an image (skeleton: empty).
	 *
	 * @since 0.11.0
	 *
	 * @param string $relative_path The image path relative to the collection root.
	 * @return string The humanised filename.
	 */
	public static function humanise_filename( string $relative_path ): string {
		return '';
	}

}
