/**
 * Pure keyboard-event → action mapper for the Gallery lightbox.
 *
 * While the lightbox is open it owns the keyboard: arrows page through the
 * images, Escape closes, and Home/End jump to the ends. This module maps a key
 * name plus the held modifiers to the abstract action they trigger, with no DOM
 * and no Interactivity API — so the binding is a single table the wiring in
 * `lightbox.ts` consults and unit tests pin directly. Keeping the map pure also
 * keeps the wiring honest: the view module reads `event.key` and the modifier
 * flags, asks this module for the action, and only then touches the DOM and
 * calls `preventDefault()` for a recognised key.
 *
 * A held Alt, Ctrl, or Meta always yields `'none'`: those combinations belong
 * to the browser and the OS — Alt+ArrowLeft and Cmd+ArrowLeft are browser
 * back — and the lightbox must never hijack them.
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
 * The modifier flags of a key press, mirroring `KeyboardEvent`'s booleans.
 *
 * Shift is deliberately absent: it never carries a browser/OS navigation
 * meaning for the bound keys, so a shifted arrow may still page the lightbox.
 *
 * @since 0.2.0
 */
export interface KeyModifiers {
	/** Whether Alt (Option) was held — `KeyboardEvent.altKey`. */
	readonly alt?: boolean;
	/** Whether Ctrl was held — `KeyboardEvent.ctrlKey`. */
	readonly ctrl?: boolean;
	/** Whether Meta (Cmd / Win) was held — `KeyboardEvent.metaKey`. */
	readonly meta?: boolean;
}

/**
 * Maps a keyboard key name and its held modifiers to the lightbox action.
 *
 * Returns `'none'` for any key not in the binding table, so the caller can leave
 * unrecognised keys to the browser (notably Tab, which the focus trap handles),
 * and for any bound key pressed with Alt, Ctrl, or Meta held — those
 * combinations are browser/OS shortcuts (Alt/Cmd+ArrowLeft is browser back)
 * the lightbox must leave alone.
 *
 * @since 0.7.0
 * @since 0.2.0 Added the `modifiers` parameter; a held modifier yields `'none'`.
 *
 * @param key       - The `KeyboardEvent.key` value of the pressed key.
 * @param modifiers - The held modifier flags; all default to unheld.
 * @return The action to perform, or `'none'` when the key is not bound or modified.
 */
export function actionForKey(
	key: string,
	modifiers: KeyModifiers = {}
): LightboxKeyAction {
	if ( modifiers.alt || modifiers.ctrl || modifiers.meta ) {
		return 'none';
	}
	return KEY_ACTIONS[ key ] ?? 'none';
}
