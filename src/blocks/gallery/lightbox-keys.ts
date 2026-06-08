/**
 * Pure keyboard-event → action mapper for the Gallery lightbox.
 *
 * While the lightbox is open it owns the keyboard: arrows page through the
 * images, Escape closes, and Home/End jump to the ends. This module maps a key
 * name to the abstract action it triggers, with no DOM and no Interactivity API
 * — so the binding is a single table the wiring in `lightbox.ts` consults and
 * unit tests pin directly. Keeping the map pure also keeps the wiring honest:
 * the view module reads `event.key`, asks this module for the action, and only
 * then touches the DOM and calls `preventDefault()` for a recognised key.
 *
 * @since 0.7.0
 */

/**
 * The abstract actions a key press can trigger inside the open lightbox.
 *
 * `'none'` is the explicit no-op for an unhandled key, so the caller branches on
 * a total set rather than a nullable; an unhandled key is left to the browser
 * (so Tab continues to drive the focus trap, for instance).
 *
 * @since 0.7.0
 */
export type LightboxKeyAction =
	| 'prev'
	| 'next'
	| 'close'
	| 'first'
	| 'last'
	| 'none';

/**
 * The fixed binding from `KeyboardEvent.key` values to lightbox actions.
 *
 * The values are exactly what `KeyboardEvent.key` reports for these keys across
 * evergreen browsers, so the lookup needs no normalisation. Left/Right page the
 * images, Escape closes, Home/End clamp to the ends.
 *
 * @since 0.7.0
 */
const KEY_ACTIONS: Readonly< Record< string, LightboxKeyAction > > = {
	ArrowLeft: 'prev',
	ArrowRight: 'next',
	Escape: 'close',
	Home: 'first',
	End: 'last',
};

/**
 * Maps a keyboard key name to the lightbox action it triggers.
 *
 * Returns `'none'` for any key not in the binding table, so the caller can leave
 * unrecognised keys to the browser (notably Tab, which the focus trap handles).
 *
 * @since 0.7.0
 *
 * @param key - The `KeyboardEvent.key` value of the pressed key.
 * @return The action to perform, or `'none'` when the key is not bound.
 */
export function actionForKey( key: string ): LightboxKeyAction {
	return KEY_ACTIONS[ key ] ?? 'none';
}
