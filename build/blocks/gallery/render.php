<?php
/**
 * Photo Gallery block render proxy.
 *
 * WordPress calls this file for every frontend render of the block, passing
 * the three standard render-callback arguments as variables. The file is a
 * thin proxy — it delegates all logic to the autoloaded Render_Gallery class
 * so the render.php files stay trivial and easy to reason about.
 *
 * Variables injected by WordPress:
 *   $attributes  array      Block attributes as saved in post_content.
 *   $content     string     Inner block HTML (empty — this block has no inner blocks).
 *   $block       \WP_Block  The block instance, carrying block.json metadata.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render_Gallery is responsible for escaping its output.
echo \Kntnt\Photo_Drop\Rendering\Render_Gallery::render( $attributes, $content, $block );
