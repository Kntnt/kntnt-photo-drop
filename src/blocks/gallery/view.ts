/**
 * Photo Gallery frontend view module — the lightbox mount point.
 *
 * The gallery itself is pure server-rendered HTML; this module is reserved for
 * the Interactivity-API lightbox, which lands as a separate slice (#11). The
 * baseline shipped here is no-JS: every thumbnail is already wrapped in an
 * `<a href="<main>.webp">` by `render.php`, so a click navigates to the full
 * image even with this module inert. The lightbox slice enhances that anchor —
 * it reads the `data-kntnt-photo-drop-lightbox` flag and the per-image
 * `data-kntnt-photo-drop-full` URL the renderer already emits, and adds
 * open/close, prev/next, keyboard, swipe, neighbour preload, focus trap, and
 * aria on top, calling `preventDefault()` so the anchor becomes the trigger.
 *
 * Registering the store namespace here reserves it so the lightbox slice can add
 * its actions without touching the server wiring.
 *
 * @since 0.6.0
 */

import { store } from '@wordpress/interactivity';

// Reserve the gallery's interactivity store namespace for the lightbox slice
// (#11). The render wrapper already binds `data-wp-interactive` to this
// namespace and carries the lightbox flag, so the lightbox can attach its
// actions here with no further server changes.
store( 'kntnt-photo-drop/gallery', {} );
