/**
 * The keyed per-file status list.
 *
 * A 300-file batch needs a scannable report, not an append-only log: one row
 * per file, keyed by the file's relative path, whose text and state class are
 * *replaced* on every transition (queued/converting/uploading → uploaded/
 * skipped/failed) — so a retried file shows its current truth, never a
 * contradictory history. A live summary line keeps the running totals
 * (uploaded · skipped · failed) visible without scrolling the list.
 *
 * All DOM writes go through `textContent`, never `innerHTML`, so a hostile
 * filename cannot inject markup. The factory holds no FilePond state and takes
 * plain elements, so Jest covers it in jsdom alone.
 *
 * @since 0.2.0
 */

/**
 * The state a status row can be in.
 *
 * `pending` covers every transient phase (queued, converting, uploading) and
 * is excluded from the summary totals; the three terminal states are counted.
 * `skipped` covers both server-side duplicates and the client type pre-filter.
 *
 * @since 0.2.0
 */
export type FileState = 'pending' | 'uploaded' | 'skipped' | 'failed';

/**
 * The status list's external interface — one idempotent upsert.
 *
 * @since 0.2.0
 */
export interface StatusList {
	/**
	 * Creates or replaces the row for one file.
	 *
	 * @param key      - The stable per-file key (the relative path).
	 * @param fileName - The display name shown on the row.
	 * @param label    - The pre-translated state label shown after the name.
	 * @param state    - The row state, driving the class and the summary.
	 */
	readonly update: (
		key: string,
		fileName: string,
		label: string,
		state: FileState
	) => void;
}

/**
 * The base class every row carries; the state is appended as a modifier.
 *
 * @since 0.2.0
 */
const ITEM_CLASS = 'kntnt-photo-drop-drop-zone__status-item';

/**
 * Creates the keyed status list over the given elements.
 *
 * The summary text is built from `summaryTemplate` by replacing the
 * `{uploaded}`, `{skipped}` and `{failed}` tokens with the current counts of
 * rows in each terminal state; it stays empty until the first row exists, so
 * an untouched Drop Zone shows no stray zeros.
 *
 * @since 0.2.0
 *
 * @param listElement     - The `<ul>` the per-file rows live in.
 * @param summaryElement  - The element the totals line is written to.
 * @param summaryTemplate - The pre-translated template with count tokens.
 * @return The status list handle.
 */
export function createStatusList(
	listElement: HTMLElement,
	summaryElement: HTMLElement,
	summaryTemplate: string
): StatusList {
	// One row per key; the entry tracks the element to rewrite and the state
	// the summary counts.
	const rows = new Map< string, { item: HTMLElement; state: FileState } >();

	// Recompute the totals over the terminal states and rewrite the summary
	// line; transient rows count toward nothing yet.
	const renderSummary = (): void => {
		if ( rows.size === 0 ) {
			summaryElement.textContent = '';
			return;
		}
		const counts = { uploaded: 0, skipped: 0, failed: 0 };
		for ( const { state } of rows.values() ) {
			if ( state !== 'pending' ) {
				counts[ state ] += 1;
			}
		}
		summaryElement.textContent = summaryTemplate
			.replace( '{uploaded}', String( counts.uploaded ) )
			.replace( '{skipped}', String( counts.skipped ) )
			.replace( '{failed}', String( counts.failed ) );
	};

	return {
		update: ( key, fileName, label, state ) => {
			// Reuse the row for this key or append a fresh one, then replace
			// its text and state class wholesale — the row always shows the
			// file's current truth only.
			let row = rows.get( key );
			if ( ! row ) {
				const item = document.createElement( 'li' );
				listElement.appendChild( item );
				row = { item, state };
				rows.set( key, row );
			}
			row.state = state;
			row.item.className = `${ ITEM_CLASS } ${ ITEM_CLASS }--${ state }`;
			row.item.textContent = `${ fileName }: ${ label }`;

			renderSummary();
		},
	};
}
