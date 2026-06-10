<?php
/**
 * Registers Gutenberg blocks and the custom block category.
 *
 * Iterates over the known block slugs and calls register_block_type() for
 * each one, pointing at the compiled output under build/blocks/<slug>/. Also
 * hooks the block_categories_all filter to expose the "Kntnt" category in
 * the block inserter.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Bootstrap;

use Kntnt\Photo_Drop\Plugin;

/**
 * Handles block and block-category registration for the plugin.
 *
 * Both blocks are dynamic (server-side rendered); their block.json files
 * live in the build output directory produced by @wordpress/scripts.
 *
 * @package Kntnt\Photo_Drop
 * @since 0.1.0
 */
final class Block_Registrar {

	/**
	 * Slugs of all blocks this plugin registers.
	 *
	 * Each slug corresponds to a directory under build/blocks/ that contains a
	 * compiled block.json plus the compiled JS/CSS assets.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private const BLOCK_SLUGS = [ 'drop-zone', 'gallery' ];

	/**
	 * Registers all blocks by pointing register_block_type() at the build dir.
	 *
	 * Must be called on the 'init' action. WordPress reads block.json from the
	 * given directory and automatically enqueues the declared script and style
	 * handles.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {

		// Register each block from its compiled build directory. A false return
		// (typically a missing or incomplete build/ output) would otherwise make
		// the block silently vanish from the inserter, so it is logged loudly.
		foreach ( self::BLOCK_SLUGS as $slug ) {
			$registered = register_block_type( __DIR__ . '/../../build/blocks/' . $slug );
			if ( $registered === false ) {
				Plugin::warning( "Failed to register the block '{$slug}'; check build/blocks/{$slug}/." );
			}
		}

	}

	/**
	 * Prepends the "Kntnt" block category to the inserter category list.
	 *
	 * Wired to the 'block_categories_all' filter. WordPress 5.8 changed the
	 * second argument's type from WP_Post to WP_Block_Editor_Context — the
	 * filter is now invoked from the post editor, the site editor, the widget
	 * editor, and other editor surfaces. The context is unused here since the
	 * Kntnt category is shown unconditionally.
	 *
	 * @since 0.1.0
	 *
	 * @param array<array<string,string|null>> $categories Existing block categories.
	 * @param \WP_Block_Editor_Context         $context    Editor context (unused).
	 * @return array<array<string,string|null>> Modified categories list.
	 */
	public function register_category( array $categories, \WP_Block_Editor_Context $context ): array {

		// Prepend the Kntnt category so plugin blocks appear first in the inserter.
		return [
			[
				'slug'  => 'kntnt',
				'title' => __( 'Kntnt', 'kntnt-photo-drop' ),
				'icon'  => null,
			],
			...$categories,
		];

	}

}
