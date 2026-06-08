/**
 * Photo Gallery block registration entry point.
 *
 * Registers the dynamic Photo Gallery block: the editor wires the `GalleryEdit`
 * inspector and preview, and `save` returns `null` because `render.php` produces
 * the frontend HTML on every page load. The stylesheet is imported here so
 * `@wordpress/scripts`' webpack config extracts it into the file declared in
 * `block.json`.
 *
 * @since 0.6.0
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import { GalleryEdit } from './edit';

// Import the shared editor + frontend stylesheet so webpack extracts it.
import './style.scss';

// Register the block type, wiring the edit component and a null save (dynamic).
registerBlockType( metadata.name, {
	edit: GalleryEdit,
	save: () => null,
} );
