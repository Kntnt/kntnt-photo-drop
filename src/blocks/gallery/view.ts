/**
 * Photo Gallery frontend view module (placeholder).
 *
 * Registered as the block's viewScriptModule. The real lightbox — open/close,
 * prev/next, keyboard, swipe, neighbour preload, focus trap, and aria, built
 * with the WordPress Interactivity API — lands in a later slice. For the
 * scaffolding slice this module exists only to anchor the viewScriptModule
 * wiring and confirm the interactivity store namespace.
 *
 * @since 0.1.0
 */

import { store } from '@wordpress/interactivity';

// Register an empty interactivity store under the plugin namespace so the
// store name is reserved for the lightbox actions added later.
store( 'kntnt-photo-drop/gallery', {} );
