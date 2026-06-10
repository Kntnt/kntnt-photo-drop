/**
 * Photo Drop Zone block registration entry point.
 *
 * Imports the Edit and Save components and the block's shared and editor-only
 * stylesheets, then registers the block type with WordPress. This is a dynamic
 * block *with inner blocks*: the Save component serialises only the inner-block
 * markup (`<InnerBlocks.Content />`), and render.php consumes that markup on every
 * page load — gating it by capability, replacing the collection placeholder, and
 * wrapping it in the native drop surface (ADR-0006).
 *
 * Stylesheets are imported here so @wordpress/scripts' webpack config picks them
 * up as part of the editorScript entry and extracts them via MiniCSSExtractPlugin
 * into the files declared in block.json. The frontend drop-surface styles are
 * imported in view.ts, not here, so they land in the view-side asset.
 *
 * @since 0.5.0
 */

import { registerBlockType } from '@wordpress/blocks';

import { DropZoneEdit } from './edit';
import { DropZoneSave } from './save';
import metadata from './block.json';

// Import stylesheets so webpack extracts them to the build directory: style.scss
// is shared by editor and frontend, editor.scss is editor-only.
import './style.scss';
import './editor.scss';

// Register the block type, wiring the edit component and the inner-block save.
// render.php owns the frontend output, but the save still serialises the inner
// blocks so render.php has markup to gate, transform, and wrap.
registerBlockType( metadata.name, {
	edit: DropZoneEdit,
	save: DropZoneSave,
} );
