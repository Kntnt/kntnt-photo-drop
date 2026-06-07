/**
 * Photo Gallery block registration entry point (placeholder).
 *
 * Registers the block type with a minimal Edit component. The save callback
 * returns null because this is a dynamic block — render.php produces the
 * frontend HTML on every page load.
 *
 * This is a scaffolding stub: the real Edit UI (the collection selector, start
 * path, ordering, layout, and caption controls) lands in a later slice. The
 * stylesheet is imported here so @wordpress/scripts' webpack config extracts
 * it into the file declared in block.json.
 *
 * @since 0.1.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import type { JSX } from '@wordpress/element';

import metadata from './block.json';

// Import the stylesheet so webpack extracts it to the build directory.
import './style.scss';

/**
 * Placeholder Edit component for the Photo Gallery block.
 *
 * Renders a single labelled wrapper in the editor. Replaced by the real
 * collection selector and gallery controls in a later slice.
 *
 * @return The block's editor markup.
 */
function GalleryEdit(): JSX.Element {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			{ __( 'Photo Gallery — placeholder', 'kntnt-photo-drop' ) }
		</div>
	);
}

// Register the block type, wiring the edit component and a null save.
registerBlockType( metadata.name, {
	edit: GalleryEdit,
	save: () => null,
} );
