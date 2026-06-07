<?php
/**
 * Server-side render for the Photo Drop Zone block (placeholder).
 *
 * This is a scaffolding stub. The real front-end uploader — the FilePond
 * drop zone configured from the selected collection's descriptor, the
 * upload-capability gate, and the REST nonce — lands in a later slice. For
 * now it renders a minimal placeholder so the dynamic block registers, the
 * build resolves render.php, and the block is insertable.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * Renders the Photo Drop Zone block's front-end markup.
 *
 * @package Kntnt\Photo_Drop
 * @since 0.1.0
 */
final class Render_Drop_Zone {

	/**
	 * Returns the block's front-end HTML.
	 *
	 * Placeholder for the scaffolding slice: a single wrapper div with the
	 * core block wrapper attributes applied. Attributes, inner content, and
	 * the block instance are accepted to match the render-callback contract
	 * even though they are unused here.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,mixed> $attributes Block attributes (unused).
	 * @param string              $content    Inner block HTML (unused).
	 * @param \WP_Block           $block      Block instance (unused).
	 * @return string Escaped HTML for the block.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

		// Emit a labelled placeholder wrapped with the standard block-supports
		// attributes so the block renders harmlessly until the uploader ships.
		$wrapper = get_block_wrapper_attributes();
		$label   = esc_html__( 'Photo Drop Zone — placeholder', 'kntnt-photo-drop' );

		return sprintf( '<div %1$s>%2$s</div>', $wrapper, $label );

	}

}
