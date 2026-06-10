/**
 * Jest tests for the keyed per-file status list.
 *
 * Verifies the row contract — one row per key, text and class replaced on
 * every transition, `textContent`-only writes — and the live summary totals
 * over the terminal states, in jsdom.
 *
 * @since 0.2.0
 */

import { createStatusList, type StatusList } from './status-list';

/**
 * The summary template fixture mirroring the server-translated string.
 */
const TEMPLATE = '{uploaded} uploaded · {skipped} skipped · {failed} failed';

/**
 * Builds a fresh list/summary pair plus the status list over them.
 *
 * @return The elements and the handle under test.
 */
function freshList(): {
	list: HTMLElement;
	summary: HTMLElement;
	status: StatusList;
} {
	const list = document.createElement( 'ul' );
	const summary = document.createElement( 'p' );
	return {
		list,
		summary,
		status: createStatusList( list, summary, TEMPLATE ),
	};
}

describe( 'createStatusList', () => {
	it( 'creates one row per key and replaces it on transition', () => {
		const { list, status } = freshList();

		status.update( 'a.jpg', 'a.jpg', 'Uploading…', 'pending' );
		status.update( 'a.jpg', 'a.jpg', 'Uploaded', 'uploaded' );

		expect( list.children ).toHaveLength( 1 );
		expect( list.children[ 0 ]?.textContent ).toBe( 'a.jpg: Uploaded' );
	} );

	it( 'keeps separate rows for separate keys', () => {
		const { list, status } = freshList();

		status.update( 'trip/a.jpg', 'a.jpg', 'Queued', 'pending' );
		status.update( 'trip/b.jpg', 'b.jpg', 'Queued', 'pending' );

		expect( list.children ).toHaveLength( 2 );
	} );

	it( 'swaps the state modifier class on every transition', () => {
		const { list, status } = freshList();

		status.update( 'a.jpg', 'a.jpg', 'Uploading…', 'pending' );
		expect( list.children[ 0 ]?.className ).toContain(
			'__status-item--pending'
		);

		status.update( 'a.jpg', 'a.jpg', 'Upload failed', 'failed' );
		expect( list.children[ 0 ]?.className ).toContain(
			'__status-item--failed'
		);
		expect( list.children[ 0 ]?.className ).not.toContain( '--pending' );
	} );

	it( 'writes a hostile filename as inert text', () => {
		const { list, status } = freshList();

		status.update(
			'evil',
			'<img src=x onerror=alert(1)>.jpg',
			'Uploaded',
			'uploaded'
		);

		expect( list.querySelector( 'img' ) ).toBeNull();
		expect( list.children[ 0 ]?.textContent ).toContain( '<img' );
	} );

	it( 'totals only terminal states in the summary', () => {
		const { summary, status } = freshList();

		status.update( 'a.jpg', 'a.jpg', 'Uploaded', 'uploaded' );
		status.update( 'b.jpg', 'b.jpg', 'Skipped', 'skipped' );
		status.update( 'c.jpg', 'c.jpg', 'Failed', 'failed' );
		status.update( 'd.jpg', 'd.jpg', 'Uploading…', 'pending' );

		expect( summary.textContent ).toBe(
			'1 uploaded · 1 skipped · 1 failed'
		);
	} );

	it( 'moves a retried file between summary buckets, never double-counts', () => {
		const { summary, status } = freshList();

		status.update( 'a.jpg', 'a.jpg', 'Upload failed', 'failed' );
		expect( summary.textContent ).toBe(
			'0 uploaded · 0 skipped · 1 failed'
		);

		status.update( 'a.jpg', 'a.jpg', 'Uploading…', 'pending' );
		status.update( 'a.jpg', 'a.jpg', 'Uploaded', 'uploaded' );
		expect( summary.textContent ).toBe(
			'1 uploaded · 0 skipped · 0 failed'
		);
	} );

	it( 'leaves the summary empty until the first row exists', () => {
		const { summary } = freshList();

		expect( summary.textContent ).toBe( '' );
	} );
} );
