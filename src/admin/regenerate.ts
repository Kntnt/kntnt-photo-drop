/**
 * The admin Edit-page regenerate UI — the DOM/network shell over the pure driver.
 *
 * Wires the regenerate section the admin Edit page renders (ADR-0013): it reads the
 * collection slug and the `wp_rest` nonce from the host element, reads the four
 * target rendition fields, and on the Regenerate click drives the batched
 * re-derive against the manage-gated REST endpoint, rendering progress through the
 * **shared Drop Zone progress view** so the logic is not duplicated. All
 * sequencing — walk the batch cursor, then finalise; stop before the finalise on a
 * failure — lives in the Jest-tested `runRegeneration` driver; this module only
 * supplies the real `fetch` dispatcher and the DOM. The descriptor flips only on a
 * successful finalise, so the gallery keeps serving the current renditions until
 * the run completes and an interrupted run is safe.
 *
 * @since 0.11.0
 */

import { __ } from '@wordpress/i18n';
import { createProgressModel } from '../blocks/drop-zone/progress-model';
import { createProgressView } from '../blocks/drop-zone/progress-view';
import type { ProgressStrings } from '../blocks/drop-zone/progress-view';
import { runRegeneration } from './regenerate-run';
import type { RegenerateTarget } from './regenerate-run';

/**
 * The block-element class the regenerate UI's progress markup is rooted on.
 *
 * Passed to the shared progress view so its bar, read-out, and summary carry this
 * surface's classes rather than the Drop Zone's — the same view, a second surface.
 *
 * @since 0.11.0
 */
const BLOCK = 'kntnt-photo-drop-regenerate';

/**
 * The REST base the regenerate route hangs off; WordPress exposes it on `wpApiSettings`.
 *
 * @since 0.11.0
 */
interface WpApiSettings {
	readonly root: string;
	readonly nonce: string;
}

/**
 * Reads an integer from a number input by selector, or null when malformed.
 *
 * @param root     - The host element to search within.
 * @param selector - The input's CSS selector.
 * @return The parsed integer, or null when the field is absent or not integer-valued.
 */
function readInt( root: HTMLElement, selector: string ): number | null {
	const input = root.querySelector< HTMLInputElement >( selector );
	if ( ! input ) {
		return null;
	}
	const value = Number( input.value );
	return Number.isInteger( value ) ? value : null;
}

/**
 * Reads the four target rendition values from the regenerate fields.
 *
 * Returns null when any width is not a positive integer or any quality is outside
 * 0–100, so the click handler can refuse to start a run that the server would
 * reject anyway — the same bounds the REST endpoint re-enforces.
 *
 * @param root - The regenerate host element.
 * @return The validated target, or null when a field is malformed.
 */
function readTarget( root: HTMLElement ): RegenerateTarget | null {
	const fullWidth = readInt( root, '[name="full_width"]' );
	const fullQuality = readInt( root, '[name="full_quality"]' );
	const thumbnailWidth = readInt( root, '[name="thumbnail_width"]' );
	const thumbnailQuality = readInt( root, '[name="thumbnail_quality"]' );

	// Both widths must be positive and both qualities in range; otherwise the target
	// is not a usable re-derive request.
	const widthsOk =
		fullWidth !== null &&
		fullWidth > 0 &&
		thumbnailWidth !== null &&
		thumbnailWidth > 0;
	const qualitiesOk =
		fullQuality !== null &&
		fullQuality >= 0 &&
		fullQuality <= 100 &&
		thumbnailQuality !== null &&
		thumbnailQuality >= 0 &&
		thumbnailQuality <= 100;
	if ( ! widthsOk || ! qualitiesOk ) {
		return null;
	}

	return { fullWidth, fullQuality, thumbnailWidth, thumbnailQuality };
}

/**
 * Builds the pre-translated strings the shared progress view renders.
 *
 * Reuses the Drop Zone progress view's string shape; the regenerate surface has no
 * skipped bucket and no per-file retry, so those strings are present-but-unused
 * placeholders, while the summary and uploaded (here "regenerated") strings carry
 * the regenerate wording.
 *
 * @return The progress strings for the regenerate UI.
 */
function regenerateStrings(): ProgressStrings {
	return {
		cancel: __( 'Cancel', 'kntnt-photo-drop' ),
		retryFailed: __( 'Retry', 'kntnt-photo-drop' ),
		summaryTemplate: __(
			'Regenerating images: {processed} of {total}.',
			'kntnt-photo-drop'
		),
		bucketUploaded: __(
			'Regenerated {uploaded} image(s).',
			'kntnt-photo-drop'
		),
		bucketSkipped: __( 'Skipped', 'kntnt-photo-drop' ),
		bucketFailed: __( 'Failed', 'kntnt-photo-drop' ),
	};
}

/**
 * Wires one regenerate host element to the batched re-derive run.
 *
 * Reads the slug and nonce, binds a `fetch` dispatcher against the collection's
 * regenerate route, and on the Regenerate click drives `runRegeneration`,
 * projecting cursor progress onto the shared progress view through a progress model
 * keyed by main index. The button is disabled for the duration so a run cannot be
 * double-started, and a malformed target or a failed run is announced through the
 * view's summary rather than silently doing nothing.
 *
 * @param root - The `[data-kntnt-photo-drop-regenerate]` host element.
 */
function wireRegenerate( root: HTMLElement ): void {
	const slug = root.dataset.kntntPhotoDropCollection ?? '';
	const nonce = root.dataset.kntntPhotoDropNonce ?? '';
	const button = root.querySelector< HTMLButtonElement >(
		'[data-kntnt-photo-drop-regenerate-start]'
	);
	const progress = root.querySelector< HTMLElement >(
		'[data-kntnt-photo-drop-regenerate-progress]'
	);
	const status = root.querySelector< HTMLElement >(
		'[data-kntnt-photo-drop-regenerate-status]'
	);
	const summary = root.querySelector< HTMLElement >(
		'[data-kntnt-photo-drop-regenerate-summary]'
	);
	if ( ! slug || ! button || ! progress || ! status || ! summary ) {
		return;
	}

	// The shared progress view over this surface's regions, with the cancel/retry
	// controls inert (a regenerate run is not per-file cancellable here).
	const view = createProgressView(
		{ progress, status, summary },
		regenerateStrings(),
		{ onCancel: () => {}, onRetry: () => {} },
		BLOCK
	);

	// The REST settings WordPress localises onto the page give the API root and a
	// fallback nonce; the host element's own nonce wins when present.
	const settings = ( window as unknown as { wpApiSettings?: WpApiSettings } )
		.wpApiSettings;
	const apiRoot = settings?.root ?? '/wp-json/';
	const effectiveNonce = nonce || settings?.nonce || '';

	// The real dispatcher: POST the JSON body to the collection's regenerate route
	// with the nonce, decoding the reply into the driver's RegenerateResponse shape.
	const url = `${ apiRoot }kntnt-photo-drop/v1/collections/${ encodeURIComponent(
		slug
	) }/regenerate`;
	const dispatch = async (
		body: Record< string, unknown >
	): Promise< import('./regenerate-run').RegenerateResponse > => {
		try {
			const response = await fetch( url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': effectiveNonce,
				},
				body: JSON.stringify( body ),
			} );
			const data = ( await response
				.json()
				.catch( () => ( {} ) ) ) as Record< string, unknown >;
			return { ok: response.ok, ...data };
		} catch {
			return { ok: false };
		}
	};

	button.addEventListener( 'click', async () => {
		// Refuse a run with malformed fields and tell the operator through the live
		// region rather than silently doing nothing.
		const target = readTarget( root );
		if ( ! target ) {
			summary.textContent = __(
				'Enter a positive width and a quality between 0 and 100.',
				'kntnt-photo-drop'
			);
			return;
		}

		// Lock the button for the duration so the run cannot be double-started, and
		// project each settled batch onto the shared progress bar through a model keyed
		// by main index.
		button.disabled = true;
		const model = createProgressModel();
		const onProgress = ( processed: number, total: number ): void => {
			for ( let i = 0; i < processed; i += 1 ) {
				model.record( String( i ), String( i + 1 ), 'uploaded' );
			}
			for ( let i = processed; i < total; i += 1 ) {
				model.record( String( i ), String( i + 1 ), 'pending' );
			}
			view.render( model.snapshot() );
		};

		// Drive the pure loop, then finalise the view: a success shows the regenerated
		// count, a failure announces that the previous renditions are kept.
		const outcome = await runRegeneration( dispatch, target, onProgress );
		button.disabled = false;
		if ( outcome.ok ) {
			model.finalise();
			view.finalise( model.snapshot() );
		} else {
			progress.replaceChildren();
			summary.textContent = __(
				'Regeneration failed. The previous renditions are unchanged; try again.',
				'kntnt-photo-drop'
			);
		}
	} );
}

// Wire every regenerate host element on the page once the DOM is ready; the admin
// Edit page renders exactly one, but the selector keeps the module idempotent.
document
	.querySelectorAll< HTMLElement >( '[data-kntnt-photo-drop-regenerate]' )
	.forEach( ( root ) => wireRegenerate( root ) );
