/**
 * Photo Drop Zone block registration entry point.
 *
 * Imports the Edit component and the block's shared and editor-only stylesheets,
 * then registers the block type with WordPress. The save callback returns null
 * because this is a dynamic block — render.php produces the frontend HTML (and the
 * capability-gated uploader) on every page load.
 *
 * Stylesheets are imported here so @wordpress/scripts' webpack config picks them
 * up as part of the editorScript entry and extracts them via MiniCSSExtractPlugin
 * into the files declared in block.json. The frontend uploader's FilePond styles
 * are imported in view.ts, not here, so they land in the view-side asset.
 *
 * @since 0.5.0
 */

import { registerBlockType } from '@wordpress/blocks';

import { DropZoneEdit } from './edit';
import metadata from './block.json';

// Import stylesheets so webpack extracts them to the build directory: style.scss
// is shared by editor and frontend, editor.scss is editor-only.
import './style.scss';
import './editor.scss';

// Register the block type, wiring the edit component and a null save (dynamic
// block — render.php owns the frontend output).
registerBlockType( metadata.name, {
	edit: DropZoneEdit,
	save: () => null,
} );
