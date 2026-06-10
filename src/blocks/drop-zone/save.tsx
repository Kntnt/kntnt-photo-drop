/**
 * Photo Drop Zone save component.
 *
 * The Drop Zone is a dynamic block whose *editable appearance* is its inner
 * blocks. A dynamic block with inner blocks must still serialise that inner
 * markup into `post_content`, so `save` returns `<InnerBlocks.Content />` — the
 * raw inner-block HTML and nothing else. render.php (the block's `render`
 * callback) then receives that markup as `$content`, gates it by the upload
 * capability, replaces the `{kntnt-drop-zone-collection}` placeholder with the
 * selected collection's display name, and wraps the result in the native
 * drop-and-browse surface for a capable visitor (ADR-0006). The block's own
 * wrapper is left to render.php, which calls `get_block_wrapper_attributes()`
 * there, so this component emits no wrapper of its own.
 *
 * @since 0.4.0
 */

import { InnerBlocks } from '@wordpress/block-editor';
import type { JSX } from '@wordpress/element';

/**
 * Serialises the Drop Zone's inner blocks for the database.
 *
 * Returns only the inner-block content. The frontend HTML — the gate, the
 * placeholder replacement, the drop surface, the nonce — is produced by
 * render.php on every request, never stored here.
 *
 * @since 0.4.0
 *
 * @return The serialised inner-block markup.
 */
export function DropZoneSave(): JSX.Element {
	return <InnerBlocks.Content />;
}
