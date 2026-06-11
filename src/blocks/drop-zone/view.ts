/**
 * Photo Drop Zone frontend view module — the native drop surface.
 *
 * Registered as the block's viewScriptModule and mounted by the Interactivity API
 * `init` callback against the capability-gated markup `Render_Drop_Zone` emits.
 * The block's wrapper is itself the layout container and a native drag-drop +
 * click-to-browse zone: a pointer click anywhere on it that is not on an
 * interactive child or the upload chrome opens the hidden loose-file input, a drop
 * of loose files queues them, and a dropped *folder* is walked recursively so
 * every image at every level uploads with its source-relative path preserved —
 * the same on-disk placement as picking that folder via "Select folder"
 * (ADR-0008), with no warning or consent step. The keyboard/AT browse path is a
 * real "Add photos" button (the wrapper carries no `role`/`tabindex`). There is no
 * FilePond; the intake, queue, and progress UI are this module's own, built on
 * plain DOM and `XMLHttpRequest`.
 *
 * The heavy lifting lives in pure, separately-tested helpers — the Canvas→WebP
 * encode (`canvas-webp.ts`), the safe-canvas-area cap (`canvas-limit.ts`), the
 * `webkitRelativePath` mapping (`relative-path.ts`), the recursive dragged-folder
 * walk (`folder-detect.ts`), the type pre-filter (`file-filter.ts`), the
 * response-interpretation rules (`upload-response.ts`), and the keyed status list
 * (`status-list.ts`) — and this module is the thin DOM/upload wiring around them.
 *
 * Each file is decoded with `createImageBitmap` (EXIF-oriented), drawn downscaled
 * to the collection's max width onto a canvas, re-encoded to WebP via
 * `canvas.toBlob(…, 'image/webp', q)`, and POSTed one request at a time to the
 * REST endpoint via `XMLHttpRequest` — real upload progress, an inactivity
 * watchdog, and a one-shot automatic retry with a refreshed `wp_rest` nonce when
 * the session's nonce has expired. A bounded number of uploads run concurrently
 * so a several-hundred-file batch keeps the link busy without opening hundreds of
 * sockets at once. A `beforeunload` guard holds the page while uploads are queued
 * or in flight.
 *
 * The client optimisation is a bandwidth optimisation only; the server
 * re-enforces the contract on every file (ADR-0006), so any client-side decode
 * or encode failure simply uploads the original bytes and the server converts
 * them.
 *
 * @since 0.4.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import './view.scss';
import { encodeCanvasToWebp } from './canvas-webp';
import { exceedsSafeCanvasArea } from './canvas-limit';
import { relativePathForFile } from './relative-path';
import {
	hasDirectoryEntry,
	snapshotEntries,
	walkDroppedEntries,
} from './folder-detect';
import { shouldUploadFile } from './file-filter';
import {
	errorLabelFor,
	isNonceRejection,
	labelForOutcome,
	readOutcome,
} from './upload-response';
import { createStatusList, type StatusList } from './status-list';
import { formatUploadingLabel } from './uploading-label';

/**
 * The pre-translated UI strings the module surfaces.
 *
 * View-script modules cannot import `@wordpress/i18n`, so `Render_Drop_Zone`
 * translates every runtime string server-side and passes them through the
 * Interactivity context. The keys mirror `Render_Drop_Zone::translations()`.
 *
 * @since 0.4.0
 */
interface DropZoneStrings {
	readonly outcomeStored: string;
	readonly outcomeReencoded: string;
	readonly outcomeSkipped: string;
	readonly outcomeRejected: string;
	readonly uploadFailed: string;
	readonly uploadStalled: string;
	readonly skippedNotImage: string;
	readonly fileUnreadable: string;
	readonly statusQueued: string;
	readonly statusConverting: string;
	readonly statusUploading: string;
	readonly statusUploadingPercent: string;
	readonly summaryTemplate: string;
	readonly [ key: string ]: string;
}

/**
 * The per-block Interactivity context emitted by `Render_Drop_Zone`.
 *
 * Carries the collection slug, the contract (`maxWidth`/`quality`) that configures
 * the downscale and the WebP encode, the absolute REST `uploadUrl`, the `wp_rest`
 * `nonce`, the admin-ajax URL the nonce-refresh endpoint lives behind, the
 * collection display name, and the pre-translated strings.
 *
 * @since 0.4.0
 */
interface DropZoneContext {
	readonly slug: string;
	/** The contract ceiling in pixels, or `null` for no limit. */
	readonly maxWidth: number | null;
	readonly quality: number;
	readonly uploadUrl: string;
	readonly nonce: string;
	/** The `admin-ajax.php` URL core's `rest-nonce` action answers on. */
	readonly ajaxUrl: string;
	readonly collection: string;
	readonly i18n: DropZoneStrings;
}

/**
 * One file queued for upload, paired with the relative path to send for it.
 *
 * The relative path is the file's status-row key and the `relativePath` field the
 * server recreates sub-directories from; a loose file's path is its plain name.
 *
 * @since 0.4.0
 */
interface QueuedFile {
	readonly file: File;
	readonly relativePath: string;
}

/**
 * The mutable per-mount upload session.
 *
 * The nonce rendered into the context expires after 12–24 hours; when an
 * upload bounces on it, the module fetches a fresh one and retries once. The
 * fresh nonce lives here so every later upload in the same batch uses it too.
 *
 * @since 0.4.0
 */
interface UploadSession {
	nonce: string;
}

/**
 * Milliseconds of upload silence before a request is treated as stalled.
 *
 * An *inactivity* timeout, not a total-duration one: a slow link that keeps
 * making progress is never punished, while a dead connection frees its
 * concurrency slot after one minute instead of blocking the queue forever.
 *
 * @since 0.4.0
 */
const INACTIVITY_TIMEOUT_MS = 60_000;

/**
 * The number of uploads allowed in flight at once.
 *
 * Each file is still one request; this only bounds how many of those requests
 * overlap. Four keeps a fast link busy through the per-file convert→encode
 * latency without opening a socket per file in a several-hundred-file batch.
 *
 * @since 0.4.0
 */
const MAX_CONCURRENT_UPLOADS = 4;

/**
 * The shape of a plausible `wp_rest` nonce — ten lower-case hex characters.
 *
 * Core's `rest-nonce` admin-ajax action answers with the bare nonce as plain
 * text; anything else (a login page, a `0` from a logged-out session) fails
 * this pattern and the refresh is treated as unsuccessful.
 *
 * @since 0.4.0
 */
const NONCE_PATTERN = /^[a-f0-9]{10}$/;

/**
 * The CSS class toggled on the wrapper while a drag hovers the surface.
 *
 * The stylesheet uses it to highlight the whole inner-block area as the active
 * drop target; the module adds it on `dragenter`/`dragover` and removes it on
 * `dragleave`/`drop`.
 *
 * @since 0.4.0
 */
const DRAGOVER_CLASS = 'kntnt-photo-drop-drop-zone--dragover';

/**
 * The selector for elements a click-to-browse pointer click must ignore.
 *
 * The whole wrapper is the click-to-browse surface, but a click that lands on an
 * interactive child (a link, button, input, label, select, or textarea — which
 * covers the "Add photos" button and the "Select folder" control), or on the live
 * summary or the per-file status list, must keep its own behaviour rather than
 * opening the loose-file picker. A click anywhere else on the wrapper opens it.
 *
 * @since 0.5.0
 */
const NON_BROWSE_SELECTOR =
	'a, button, input, label, select, textarea,' +
	' .kntnt-photo-drop-drop-zone__summary,' +
	' .kntnt-photo-drop-drop-zone__status';

/**
 * Tracks which block elements already have the surface wired.
 *
 * Keyed by the block wrapper so the Interactivity API re-running `init` (e.g. on a
 * re-hydration) never wires a second set of listeners over the same surface.
 *
 * @since 0.4.0
 */
const mountedZones = new WeakSet< Element >();

/**
 * Decodes a file to a bitmap, honouring its EXIF orientation.
 *
 * Asks for `imageOrientation: 'from-image'` so a portrait phone photo is not
 * uploaded lying on its side; engines that predate the options bag throw a
 * `TypeError`, in which case the bare call is retried (those engines mostly
 * orient by default anyway). Any other failure propagates to the caller's
 * raw-upload fallback.
 *
 * @since 0.4.0
 *
 * @param file - The source file to decode.
 * @return The decoded, orientation-corrected bitmap.
 */
async function decodeBitmap( file: File ): Promise< ImageBitmap > {
	try {
		return await createImageBitmap( file, {
			imageOrientation: 'from-image',
		} );
	} catch ( error ) {
		// Only the options-bag TypeError warrants a retry; a decode failure
		// must reach the raw-upload fallback instead.
		if ( error instanceof TypeError ) {
			return createImageBitmap( file );
		}
		throw error;
	}
}

/**
 * Draws an image onto a canvas, downscaled to the contract ceiling.
 *
 * Computes the target size from the source dimensions and the contract `maxWidth`
 * — never upscaling (a `null` ceiling or a source already within it keeps the
 * source size) — then paints the bitmap onto a fresh canvas at that size.
 * Throws instead of degrading: a target above the safe canvas area (iOS Safari
 * draws it silently blank) or a null 2D context would otherwise encode an empty
 * canvas into a valid-looking WebP and destroy the pixels, so both abort the
 * client optimisation and route the caller to the raw-upload fallback.
 *
 * @since 0.4.0
 *
 * @param bitmap   - The decoded source image.
 * @param maxWidth - The contract ceiling in pixels, or `null` for no limit.
 * @return The canvas holding the downscaled pixels.
 * @throws Error When the canvas cannot be drawn reliably.
 */
function drawDownscaled(
	bitmap: ImageBitmap,
	maxWidth: number | null
): HTMLCanvasElement {
	// Never upscale: the target width is the source width unless a positive
	// ceiling is smaller, in which case scale height proportionally.
	const scale =
		maxWidth !== null && maxWidth > 0 && bitmap.width > maxWidth
			? maxWidth / bitmap.width
			: 1;
	const width = Math.round( bitmap.width * scale );
	const height = Math.round( bitmap.height * scale );

	// Refuse a canvas iOS Safari would silently leave blank — encoding it
	// would upload an empty image as if it were the photo.
	if ( exceedsSafeCanvasArea( width, height ) ) {
		throw new Error( 'Target size exceeds the safe canvas area.' );
	}

	// Draw onto a fresh canvas; a null context is the same silent-blank risk
	// as the oversized canvas and gets the same refusal.
	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	const renderingContext = canvas.getContext( '2d' );
	if ( ! renderingContext ) {
		throw new Error( 'Canvas 2D context is unavailable.' );
	}
	renderingContext.drawImage( bitmap, 0, 0, width, height );

	return canvas;
}

/**
 * Optimises one source file to a WebP blob per the collection contract.
 *
 * Decodes the file to a bitmap, downscales it to the contract ceiling, and
 * re-encodes to WebP at the contract quality. On any failure — an undecodable
 * file, an oversized canvas, a missing 2D context, or a browser that cannot
 * encode WebP — it resolves to the original file unchanged, because the client
 * optimisation is a bandwidth optimisation only and the server re-enforces the
 * contract on every upload (ADR-0006), so the raw fallback is always
 * contract-correct.
 *
 * @since 0.4.0
 *
 * @param file     - The source file from the drop or the folder picker.
 * @param maxWidth - The contract ceiling in pixels, or `null` for no limit.
 * @param quality  - The contract WebP quality on the 0–100 scale.
 * @return A blob to upload — the optimised WebP, or the original on failure.
 */
async function optimiseToWebp(
	file: File,
	maxWidth: number | null,
	quality: number
): Promise< Blob > {
	try {
		// Close the bitmap whether the draw succeeds or throws; it can pin
		// tens of megabytes of decoded pixels per file.
		const bitmap = await decodeBitmap( file );
		try {
			const canvas = drawDownscaled( bitmap, maxWidth );
			return await encodeCanvasToWebp( canvas, quality );
		} finally {
			bitmap.close();
		}
	} catch {
		// Fall back to the original bytes; the server downscales and converts.
		return file;
	}
}

/**
 * Fetches a fresh `wp_rest` nonce from core's `rest-nonce` admin-ajax action.
 *
 * The action (core since WP 5.3) answers a cookie-authenticated GET with the
 * bare nonce as plain text. Resolves to the nonce when the response is a 200
 * carrying a plausible nonce, null on any failure — the caller then surfaces
 * the server's original error instead of retrying.
 *
 * @since 0.4.0
 *
 * @param ajaxUrl - The `admin-ajax.php` URL from the block context.
 * @return The fresh nonce, or null when no usable nonce was obtained.
 */
async function refreshNonce( ajaxUrl: string ): Promise< string | null > {
	try {
		// Bound the refresh by the same inactivity budget as an upload so a
		// dead connection cannot hang the retry path indefinitely.
		const response = await fetch( `${ ajaxUrl }?action=rest-nonce`, {
			credentials: 'same-origin',
			signal: AbortSignal.timeout( INACTIVITY_TIMEOUT_MS ),
		} );
		if ( ! response.ok ) {
			return null;
		}
		const nonce = ( await response.text() ).trim();
		return NONCE_PATTERN.test( nonce ) ? nonce : null;
	} catch {
		return null;
	}
}

/**
 * Uploads one already-optimised blob to the REST endpoint via `XMLHttpRequest`.
 *
 * POSTs the blob plus its `relativePath` with the session's `X-WP-Nonce`, one
 * request per file. Real upload progress drives the status row's "Uploading…"
 * label and the inactivity watchdog; the watchdog aborts a request after 60 s of
 * silence so a dead connection cannot block the queue. A 401/403 carrying a nonce
 * error code triggers one automatic retry with a freshly fetched nonce. A file is
 * reported successful only on a 2xx response with a parsed outcome; every failure
 * path surfaces the most informative label available — the server's own `message`
 * first — in the status row. The promise always resolves (never rejects), so one
 * failed file never aborts the batch (ADR-0006).
 *
 * @since 0.4.0
 *
 * @param blob    - The optimised (or raw fallback) bytes to upload.
 * @param queued  - The source file and its relative path.
 * @param context - The per-block context with the URLs and strings.
 * @param session - The mutable session holding the current nonce.
 * @param status  - The keyed status list the row is reported to.
 * @return A promise that resolves when the file has settled (uploaded or failed).
 */
function uploadBlob(
	blob: Blob,
	queued: QueuedFile,
	context: DropZoneContext,
	session: UploadSession,
	status: StatusList
): Promise< void > {
	const { file, relativePath } = queued;

	return new Promise< void >( ( resolve ) => {
		let watchdog: number | undefined;

		const clearWatchdog = (): void => {
			window.clearTimeout( watchdog );
		};

		// Every failure path funnels here so the status row always shows one
		// truth and the queue's slot is always released.
		const fail = ( label: string ): void => {
			clearWatchdog();
			status.update( relativePath, file.name, label, 'failed' );
			resolve();
		};

		// One upload attempt; `allowNonceRetry` is spent on the single
		// automatic nonce-refresh retry.
		const send = ( allowNonceRetry: boolean ): void => {
			const request = new XMLHttpRequest();
			let stalled = false;

			// Re-arm the inactivity watchdog on every sign of life; when it
			// fires, the abort is flagged as a stall so the handler can tell
			// it apart from a settled response.
			const touch = (): void => {
				clearWatchdog();
				watchdog = window.setTimeout( () => {
					stalled = true;
					request.abort();
				}, INACTIVITY_TIMEOUT_MS );
			};

			// Interpret the settled response: only a 2xx with a parsed
			// outcome may report success; a nonce rejection gets its one
			// refresh-and-retry; everything else fails with the best label.
			const settle = (): void => {
				clearWatchdog();
				let payload: unknown = null;
				try {
					payload = JSON.parse( request.responseText );
				} catch {
					payload = null;
				}

				// The honest-success gate: 2xx and a validated outcome, or it
				// is not a success at all.
				const ok = request.status >= 200 && request.status < 300;
				const outcome = readOutcome( payload );
				if ( ok && outcome !== null ) {
					const displayName = outcome.name ?? file.name;
					status.update(
						relativePath,
						displayName,
						labelForOutcome( outcome.outcome, context.i18n ),
						outcome.outcome === 'skipped' ? 'skipped' : 'uploaded'
					);
					resolve();
					return;
				}

				// An expired nonce gets one automatic retry with a fresh
				// nonce; the fresh nonce is kept for the rest of the batch.
				if (
					allowNonceRetry &&
					isNonceRejection( request.status, payload )
				) {
					void refreshNonce( context.ajaxUrl ).then( ( fresh ) => {
						if ( fresh !== null ) {
							session.nonce = fresh;
							send( false );
							return;
						}
						fail( errorLabelFor( payload, context.i18n ) );
					} );
					return;
				}

				fail( errorLabelFor( payload, context.i18n ) );
			};

			// Open and wire the request: real upload progress feeds the
			// status row and the watchdog; response activity only feeds the
			// watchdog.
			request.open( 'POST', context.uploadUrl );
			request.setRequestHeader( 'X-WP-Nonce', session.nonce );
			request.upload.onprogress = ( event: ProgressEvent ): void => {
				// Re-arm the watchdog on every byte, and — when the length is known —
				// surface the live percentage on the row so a large file shows real
				// progress instead of a static "Uploading…". The row stays 'pending'.
				touch();
				if ( event.lengthComputable ) {
					status.update(
						relativePath,
						file.name,
						formatUploadingLabel(
							context.i18n.statusUploadingPercent,
							event.loaded,
							event.total
						),
						'pending'
					);
				}
			};
			request.onreadystatechange = (): void => {
				if ( request.readyState !== XMLHttpRequest.DONE ) {
					touch();
				}
			};
			request.onload = settle;
			request.onerror = (): void => {
				fail( context.i18n.uploadFailed );
			};
			request.onabort = (): void => {
				// A stall is this module's own abort, surfaced as an
				// actionable failure; the watchdog is the only thing that
				// aborts, so any abort is a stall.
				clearWatchdog();
				if ( stalled ) {
					fail( context.i18n.uploadStalled );
				}
			};

			// Ship the file and its path; the server hard-sanitises and
			// realpath-confines the path (ADR-0006).
			const body = new FormData();
			body.append( 'file', blob, file.name );
			body.append( 'relativePath', relativePath );
			status.update(
				relativePath,
				file.name,
				context.i18n.statusUploading,
				'pending'
			);
			touch();
			request.send( body );
		};

		send( true );
	} );
}

/**
 * Converts then uploads one queued file, settling its status row either way.
 *
 * Marks the row "Converting…", optimises the file to WebP (falling back to the
 * raw bytes on any client-side failure), then uploads it. The returned promise
 * resolves when the file has settled; it never rejects, so the queue runner can
 * treat every file uniformly.
 *
 * @since 0.4.0
 *
 * @param queued  - The file and its relative path.
 * @param context - The per-block context.
 * @param session - The mutable nonce session.
 * @param status  - The keyed status list.
 * @return A promise resolving once the file is uploaded or failed.
 */
async function processFile(
	queued: QueuedFile,
	context: DropZoneContext,
	session: UploadSession,
	status: StatusList
): Promise< void > {
	status.update(
		queued.relativePath,
		queued.file.name,
		context.i18n.statusConverting,
		'pending'
	);
	const blob = await optimiseToWebp(
		queued.file,
		context.maxWidth,
		context.quality
	);
	await uploadBlob( blob, queued, context, session, status );
}

/**
 * Drains a queue of files through a bounded number of concurrent uploads.
 *
 * Holds at most `MAX_CONCURRENT_UPLOADS` files in flight, pulling the next file
 * the moment a slot frees, so a several-hundred-file batch keeps the link busy
 * without opening a socket per file. Newly enqueued files (a second drop while
 * the first batch is still running) are picked up by the same drain. A file is
 * deduped by its source relative path — the status-row key — so a path already
 * seen this mount is never queued twice; two files sharing a basename in
 * different sub-folders carry distinct relative paths and both upload. The
 * `busy` callback flips true while any file is queued or in flight and false
 * once the queue empties, so the caller can arm and disarm the `beforeunload`
 * guard.
 *
 * @since 0.4.0
 *
 * @param context - The per-block context.
 * @param session - The mutable nonce session.
 * @param status  - The keyed status list.
 * @param onBusy  - Called with the busy state as the queue starts and empties.
 * @return An enqueue function the intake paths push files to.
 */
function createUploadQueue(
	context: DropZoneContext,
	session: UploadSession,
	status: StatusList,
	onBusy: ( busy: boolean ) => void
): ( files: readonly QueuedFile[] ) => void {
	const pending: QueuedFile[] = [];
	const seen = new Set< string >();
	let inFlight = 0;
	let draining = false;

	// Pull the next file into a free slot; when the queue and the in-flight set
	// are both empty, the batch is done and the guard can stand down.
	const pump = (): void => {
		while ( inFlight < MAX_CONCURRENT_UPLOADS && pending.length > 0 ) {
			const next = pending.shift();
			if ( ! next ) {
				break;
			}
			inFlight += 1;
			void processFile( next, context, session, status ).finally( () => {
				inFlight -= 1;
				if ( pending.length === 0 && inFlight === 0 ) {
					draining = false;
					onBusy( false );
				} else {
					pump();
				}
			} );
		}
	};

	return ( files: readonly QueuedFile[] ): void => {
		// Drop any file whose relative path was already queued this mount; the
		// path is the status-row key, so a duplicate would otherwise clobber the
		// in-flight row and double-upload the same bytes.
		const fresh = files.filter( ( queued ) => {
			if ( seen.has( queued.relativePath ) ) {
				return false;
			}
			seen.add( queued.relativePath );
			return true;
		} );
		if ( fresh.length === 0 ) {
			return;
		}

		pending.push( ...fresh );
		if ( ! draining ) {
			draining = true;
			onBusy( true );
		}
		pump();
	};
}

/**
 * Filters a batch and enqueues the survivors, keying each by its relative path.
 *
 * Applies the type pre-filter before any bytes move — a denied file gets an
 * immediate "skipped" row instead of a doomed multi-hundred-MB upload — then
 * hands the accepted files to the upload queue. Used by every intake path (the
 * click/folder pickers, the loose-file drop, and the recursive folder drop).
 *
 * @since 0.4.0
 *
 * @param files   - The files to consider, paired with their relative paths.
 * @param enqueue - The upload queue's enqueue function.
 * @param status  - The keyed status list.
 * @param strings - The pre-translated string map.
 */
function intakeFiles(
	files: readonly QueuedFile[],
	enqueue: ( files: readonly QueuedFile[] ) => void,
	status: StatusList,
	strings: DropZoneStrings
): void {
	const accepted: QueuedFile[] = [];

	for ( const queued of files ) {
		// Deny RAW and video before upload; the row tells the photographer
		// immediately instead of after a wasted transfer.
		if ( ! shouldUploadFile( queued.file.name, queued.file.type ) ) {
			status.update(
				queued.relativePath,
				queued.file.name,
				strings.skippedNotImage,
				'skipped'
			);
			continue;
		}
		status.update(
			queued.relativePath,
			queued.file.name,
			strings.statusQueued,
			'pending'
		);
		accepted.push( queued );
	}

	enqueue( accepted );
}

/**
 * Creates the `beforeunload` guard and returns its busy switch.
 *
 * While busy, leaving the page asks the browser's "unsaved changes" question
 * instead of silently abandoning a half-uploaded batch on a back-swipe; when
 * idle, the handler is unregistered so normal navigation stays silent.
 *
 * @since 0.4.0
 *
 * @return The switch to flip as work starts and settles.
 */
function installUnloadGuard(): ( busy: boolean ) => void {
	let registered = false;

	// The handler only exists while work is pending, so it always blocks.
	const handler = ( event: BeforeUnloadEvent ): void => {
		event.preventDefault();
		event.returnValue = ''; // Required by Chromium for the prompt to show.
	};

	return ( busy: boolean ): void => {
		if ( busy && ! registered ) {
			window.addEventListener( 'beforeunload', handler );
			registered = true;
		} else if ( ! busy && registered ) {
			window.removeEventListener( 'beforeunload', handler );
			registered = false;
		}
	};
}

const { state } = store( 'kntnt-photo-drop/drop-zone', {
	state: {},
	callbacks: {
		/**
		 * Initialise one Drop Zone block.
		 *
		 * Reads the per-block context, wires the whole wrapper as a native
		 * drag-drop + click-to-browse zone, hooks the hidden loose-file input,
		 * the "Add photos" button (the keyboard/AT browse path), and the
		 * "Select folder" input, walks dropped folders recursively (every image
		 * at every level, paths preserved), and arms the `beforeunload` guard.
		 * Idempotent via `mountedZones` so a re-run never double-wires.
		 *
		 * @since 0.4.0
		 */
		init(): void {
			const { ref } = getElement();
			if ( ! ref || ! ( ref instanceof HTMLElement ) ) {
				return;
			}
			if ( mountedZones.has( ref ) ) {
				return;
			}

			// Locate the elements the render handler emitted; without them
			// there is nothing to wire, so bail before reading any further
			// context rather than half-initialise. The wrapper itself (`ref`) is
			// the drop surface, so there is no separate surface element to find.
			const fileInput = ref.querySelector< HTMLInputElement >(
				'.kntnt-photo-drop-drop-zone__file-input'
			);
			const statusListEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__status'
			);
			const summaryEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__summary'
			);
			if ( ! fileInput || ! statusListEl || ! summaryEl ) {
				return;
			}

			mountedZones.add( ref );

			// The "Add photos" button and the folder picker are optional chrome —
			// the render emits both, but a builder could remove them; locate them
			// after the required-element guard.
			const browseButton = ref.querySelector< HTMLButtonElement >(
				'.kntnt-photo-drop-drop-zone__browse'
			);
			const folderInput = ref.querySelector< HTMLInputElement >(
				'.kntnt-photo-drop-drop-zone__folder-input'
			);

			// Read the per-block context and assemble the per-mount state: the
			// keyed status list, the mutable nonce session, the beforeunload
			// guard, and the bounded upload queue the intake paths push to.
			const context = getContext< DropZoneContext >();
			const strings = context.i18n;
			const status = createStatusList(
				statusListEl,
				summaryEl,
				strings.summaryTemplate
			);
			const session: UploadSession = { nonce: context.nonce };
			const setUnloadGuard = installUnloadGuard();
			const enqueue = createUploadQueue(
				context,
				session,
				status,
				setUnloadGuard
			);

			// Map a picked/dropped file list to queued files keyed by each file's
			// own relative path and hand them to the intake. The file-input, the
			// folder-input, and the loose-file drop all take this path (the path
			// rides on the file via `webkitRelativePath`); the recursive folder
			// drop differs only in that the walk carries each path explicitly, so
			// it builds its own queued files inline below.
			const intake = ( list: ArrayLike< File > ): void => {
				intakeFiles(
					Array.from( list ).map( ( file ) => ( {
						file,
						relativePath: relativePathForFile( file ),
					} ) ),
					enqueue,
					status,
					strings
				);
			};

			// The whole wrapper is a click-to-browse trigger for pointer users: a
			// click that does not land on an interactive child or the upload chrome
			// opens the hidden loose-file input. A click on a link, button, or input
			// inside the builder's markup — or on the "Add photos" button, the
			// "Select folder" control, the summary, or the status list — is left to do
			// its own thing. The keyboard/AT browse path is the real button below, so
			// the wrapper carries no role or tabindex and answers no keys.
			ref.addEventListener( 'click', ( event: MouseEvent ) => {
				const target = event.target;
				if (
					target instanceof Element &&
					target.closest( NON_BROWSE_SELECTOR )
				) {
					return;
				}
				fileInput.click();
			} );

			// The "Add photos" button is the accessible browse trigger — a real
			// <button>, so a keyboard or AT user reaches it by Tab and activates it
			// with Enter or Space natively; its click opens the same loose-file input.
			browseButton?.addEventListener(
				'click',
				() => {
					fileInput.click();
				},
				{ passive: true }
			);

			// Wire the hidden loose-file input: each picked file lands at the
			// collection root keyed by its own name.
			fileInput.addEventListener(
				'change',
				() => {
					if ( fileInput.files ) {
						intake( fileInput.files );
						// Reset so picking the same file again re-fires change.
						fileInput.value = '';
					}
				},
				{ passive: true }
			);

			// Wire the native folder picker: each selected file's
			// webkitRelativePath rides along so the server recreates its
			// sub-directories.
			folderInput?.addEventListener(
				'change',
				() => {
					if ( folderInput.files ) {
						intake( folderInput.files );
						folderInput.value = '';
					}
				},
				{ passive: true }
			);

			// Highlight the whole surface while a drag hovers it, and suppress
			// the browser's default "open the file" behaviour so a miss does not
			// navigate away. `dragover` must call preventDefault for `drop` to
			// fire at all.
			const allowDrop = ( event: DragEvent ): void => {
				event.preventDefault();
				ref.classList.add( DRAGOVER_CLASS );
			};
			ref.addEventListener( 'dragenter', allowDrop );
			ref.addEventListener( 'dragover', allowDrop );
			ref.addEventListener( 'dragleave', ( event: DragEvent ): void => {
				// Only clear the highlight when the pointer actually leaves the
				// wrapper, not when it crosses between child elements.
				if (
					event.relatedTarget instanceof Node &&
					ref.contains( event.relatedTarget )
				) {
					return;
				}
				ref.classList.remove( DRAGOVER_CLASS );
			} );

			// Handle the drop: loose files queue straight away; a dropped folder
			// is walked recursively so every image at every level uploads with
			// its source-relative path preserved — the same on-disk placement
			// as the "Select folder" picker (ADR-0008), with no warning step.
			// Entries that cannot be read get an honest failed row instead of
			// vanishing.
			ref.addEventListener( 'drop', ( event: DragEvent ): void => {
				event.preventDefault();
				ref.classList.remove( DRAGOVER_CLASS );

				const items = event.dataTransfer?.items;
				const entries = items
					? snapshotEntries( Array.from( items ) )
					: [];

				// A drop with no directory entry is the loose-file fast path —
				// take the plain `files` list, which avoids the async entry
				// traversal entirely.
				if ( ! hasDirectoryEntry( entries ) ) {
					const dropped = event.dataTransfer?.files;
					if ( dropped && dropped.length > 0 ) {
						intake( dropped );
					}
					return;
				}

				// A folder is present: walk the whole tree, surface any
				// unreadable file or subtree by its relative path, and intake
				// the walked files with their paths carried explicitly (a
				// `File` from `entry.file()` has no `webkitRelativePath`).
				void walkDroppedEntries( entries ).then(
					( { files, unreadable } ) => {
						for ( const path of unreadable ) {
							status.update(
								path,
								path,
								strings.fileUnreadable,
								'failed'
							);
						}
						intakeFiles( files, enqueue, status, strings );
					}
				);
			} );
		},
	},
} );

export { state };
