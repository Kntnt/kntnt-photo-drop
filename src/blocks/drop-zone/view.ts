/**
 * Photo Drop Zone frontend view module — the native drop surface.
 *
 * Registered as the block's viewScriptModule and mounted by the Interactivity API
 * `init` callback against the capability-gated markup `Render_Drop_Zone` emits.
 * The block's wrapper is itself the layout container and a native drag-drop +
 * click-to-browse zone: a pointer click anywhere on it that is not on an
 * interactive child opens the hidden loose-file input, a drop of loose files queues
 * them, and a dropped *folder* is walked recursively so every image at every level
 * uploads with its source-relative path preserved — the same on-disk placement as
 * picking that folder via the folder picker (ADR-0008), with no warning or consent
 * step. The visible upload controls are builder-authored links wired by an
 * anchor-token href (ADR-0010): a link to `#kntnt-drop-zone-files` opens the
 * loose-file picker, one to `#kntnt-drop-zone-folder` the folder picker; the wrapper
 * carries no `role`/`tabindex`. There is no FilePond; the intake, queue, and
 * progress UI are this module's own, built on plain DOM and `XMLHttpRequest`.
 *
 * The heavy lifting lives in pure, separately-tested helpers — the Canvas→WebP
 * encode (`canvas-webp.ts`), the safe-canvas-area cap (`canvas-limit.ts`), the
 * `webkitRelativePath` mapping (`relative-path.ts`), the recursive dragged-folder
 * walk (`folder-detect.ts`), the type pre-filter (`file-filter.ts`), the
 * response-interpretation rules (`upload-response.ts`), the bucket-accounting
 * model (`progress-model.ts`), the bounded cancellable upload queue
 * (`upload-queue.ts`), and the progress/summary DOM view (`progress-view.ts`) —
 * and this module is the thin DOM/upload wiring around them.
 *
 * Each file is decoded with `createImageBitmap` (EXIF-oriented), drawn downscaled
 * to the collection's max width onto a canvas, re-encoded to WebP via
 * `canvas.toBlob(…, 'image/webp', q)`, and POSTed one request at a time to the
 * REST endpoint via `XMLHttpRequest` — real upload progress, an inactivity
 * watchdog, and a one-shot automatic retry with a refreshed `wp_rest` nonce when
 * the session's nonce has expired. A bounded number of uploads run concurrently
 * so a several-hundred-file batch keeps the link busy without opening hundreds of
 * sockets at once. The aggregate progress bar tracks files reaching a terminal
 * state ÷ total; a Cancel button aborts the in-flight uploads and stops the queue
 * (uploaded files persist); on completion or cancel a three-bucket summary lists
 * the skipped and failed files by name with a Retry-failed button that re-queues
 * only the failures. A `beforeunload` guard holds the page while uploads are
 * queued or in flight.
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
import { isHiddenFile } from './hidden-file';
import { isNonceRejection, readOutcome } from './upload-response';
import {
	createProgressModel,
	type FileState,
	type ProgressModel,
} from './progress-model';
import {
	createUploadQueue,
	type QueuedFile,
	type UploadHandle,
	type UploadQueue,
} from './upload-queue';
import {
	createProgressView,
	type ProgressStrings,
	type ProgressView,
} from './progress-view';

/**
 * The pre-translated UI strings the module surfaces.
 *
 * View-script modules cannot import `@wordpress/i18n`, so `Render_Drop_Zone`
 * translates every runtime string server-side and passes them through the
 * Interactivity context. The keys mirror `Render_Drop_Zone::translations()` and
 * the progress view's {@link ProgressStrings}.
 *
 * @since 0.4.0
 */
interface DropZoneStrings extends ProgressStrings {
	readonly [ key: string ]: string;
}

/**
 * The per-block Interactivity context emitted by `Render_Drop_Zone`.
 *
 * Carries the collection slug, the upload width/quality that configure the
 * downscale and the WebP encode, the absolute REST `uploadUrl`, the `wp_rest`
 * `nonce`, the admin-ajax URL the nonce-refresh endpoint lives behind, the
 * collection display name, and the pre-translated strings.
 *
 * @since 0.4.0
 */
interface DropZoneContext {
	readonly slug: string;
	/** The upload-width ceiling in pixels, or `null` for the source's own dimensions. */
	readonly uploadWidth: number | null;
	readonly uploadQuality: number;
	readonly uploadUrl: string;
	readonly nonce: string;
	/** The `admin-ajax.php` URL core's `rest-nonce` action answers on. */
	readonly ajaxUrl: string;
	readonly collection: string;
	readonly i18n: DropZoneStrings;
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
 * The per-mount progress wiring the upload paths report a file's state to.
 *
 * `report` records one file's state into the bucket-accounting model and
 * redraws the view — the live bar while the batch runs, the three-bucket
 * summary once it settles. The model, view, and queue are the three pieces the
 * `init` callback assembles and every upload path closes over.
 *
 * @since 0.11.0
 */
interface Progress {
	readonly model: ProgressModel;
	readonly view: ProgressView;
	readonly report: (
		key: string,
		fileName: string,
		state: FileState
	) => void;
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
 * covers the builder's tokened upload-control links and the module's own Cancel
 * and Retry buttons), or on the live summary, the progress region, or the status
 * region, must keep its own behaviour rather than opening the loose-file picker.
 * A click anywhere else on the wrapper opens it.
 *
 * @since 0.5.0
 */
const NON_BROWSE_SELECTOR =
	'a, button, input, label, select, textarea,' +
	' .kntnt-photo-drop-drop-zone__summary,' +
	' .kntnt-photo-drop-drop-zone__progress,' +
	' .kntnt-photo-drop-drop-zone__status';

/**
 * The anchor-token href that wires a link to the loose-file picker.
 *
 * Any link inside the wrapper whose href is this fragment is an "Add photos"
 * trigger: its click opens the hidden loose-file input (ADR-0010). A `#fragment`
 * (not a `{…}` token) because the editor's link control and `esc_url()` strip the
 * braces a brace token would need. Kept in sync with `edit.tsx`'s `FILES_TOKEN`.
 *
 * @since 0.6.0
 */
const FILES_TOKEN = '#kntnt-drop-zone-files';

/**
 * The anchor-token href that wires a link to the `webkitdirectory` folder picker.
 *
 * The folder counterpart of {@link FILES_TOKEN}: a matching link's click opens the
 * hidden folder input (ADR-0010). Kept in sync with `edit.tsx`'s `FOLDER_TOKEN`.
 *
 * @since 0.6.0
 */
const FOLDER_TOKEN = '#kntnt-drop-zone-folder';

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
 * the failure as a failed file instead of retrying.
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
 * Maps a parsed REST outcome to the file state the bucket model records.
 *
 * `skipped` is its own bucket (a server-side duplicate); every other written
 * outcome — `stored` or `reencoded` — is an upload. The display name prefers
 * the server's canonical name over the client's relative-path-derived one.
 *
 * @since 0.11.0
 *
 * @param outcome - The validated outcome from the REST response.
 * @return The file state for the bucket model.
 */
function stateForOutcome(
	outcome: NonNullable< ReturnType< typeof readOutcome > >
): FileState {
	return outcome.outcome === 'skipped' ? 'skipped' : 'uploaded';
}

/**
 * Uploads one already-optimised blob, returning an abortable handle.
 *
 * POSTs the blob plus its `relativePath` with the session's `X-WP-Nonce`, one
 * request per file. Real upload progress feeds the inactivity watchdog; the
 * watchdog aborts a request after 60 s of silence so a dead connection cannot
 * block the queue. A 401/403 carrying a nonce error code triggers one automatic
 * retry with a freshly fetched nonce. The returned handle's `abort()` is what
 * Cancel calls; the `settled` promise always resolves (never rejects), so one
 * failed file never aborts the batch (ADR-0006). A file is recorded `uploaded`
 * or `skipped` only on a 2xx with a parsed outcome; a stall or any other failure
 * is recorded `failed`; a *cancel* abort records nothing, leaving the file
 * pending so the model's `finalise()` drops it (it was never given a chance).
 *
 * @since 0.4.0
 *
 * @param blob     - The optimised (or raw fallback) bytes to upload.
 * @param queued   - The source file and its relative path.
 * @param context  - The per-block context with the URLs and strings.
 * @param session  - The mutable session holding the current nonce.
 * @param progress - The progress wiring the file's state is reported to.
 * @return A handle to abort the upload, plus a promise that settles when it ends.
 */
function uploadBlob(
	blob: Blob,
	queued: QueuedFile,
	context: DropZoneContext,
	session: UploadSession,
	progress: Progress
): UploadHandle {
	const { file, relativePath } = queued;

	// The active request and the watchdog are captured so the handle's abort()
	// can stop them; `cancelling` flags a cancel-initiated abort apart from a
	// stall so the file is left pending rather than recorded failed.
	let request: XMLHttpRequest | null = null;
	let watchdog: number | undefined;
	let cancelling = false;

	const settled = new Promise< void >( ( resolve ) => {
		const clearWatchdog = (): void => {
			window.clearTimeout( watchdog );
		};

		// Every failure path funnels here so the file is recorded failed once
		// and the queue's slot is released.
		const fail = (): void => {
			clearWatchdog();
			progress.report( relativePath, file.name, 'failed' );
			resolve();
		};

		// One upload attempt; `allowNonceRetry` is spent on the single
		// automatic nonce-refresh retry.
		const send = ( allowNonceRetry: boolean ): void => {
			const xhr = new XMLHttpRequest();
			request = xhr;
			let stalled = false;

			// Re-arm the inactivity watchdog on every sign of life; when it
			// fires, the abort is flagged as a stall so the handler can tell
			// it apart from a settled response or a cancel.
			const touch = (): void => {
				clearWatchdog();
				watchdog = window.setTimeout( () => {
					stalled = true;
					xhr.abort();
				}, INACTIVITY_TIMEOUT_MS );
			};

			// Interpret the settled response: only a 2xx with a parsed outcome
			// records a success; a nonce rejection gets its one
			// refresh-and-retry; everything else is a failure.
			const settleResponse = (): void => {
				clearWatchdog();
				let payload: unknown = null;
				try {
					payload = JSON.parse( xhr.responseText );
				} catch {
					payload = null;
				}

				// The honest-success gate: 2xx and a validated outcome, or it
				// is not a success at all.
				const ok = xhr.status >= 200 && xhr.status < 300;
				const outcome = readOutcome( payload );
				if ( ok && outcome !== null ) {
					progress.report(
						relativePath,
						outcome.name ?? file.name,
						stateForOutcome( outcome )
					);
					resolve();
					return;
				}

				// An expired nonce gets one automatic retry with a fresh
				// nonce; the fresh nonce is kept for the rest of the batch.
				if (
					allowNonceRetry &&
					isNonceRejection( xhr.status, payload )
				) {
					void refreshNonce( context.ajaxUrl ).then( ( fresh ) => {
						if ( fresh !== null ) {
							session.nonce = fresh;
							send( false );
							return;
						}
						fail();
					} );
					return;
				}

				fail();
			};

			// Open and wire the request: upload progress and response activity
			// only feed the watchdog now (the aggregate bar tracks terminal
			// files, not per-file bytes).
			xhr.open( 'POST', context.uploadUrl );
			xhr.setRequestHeader( 'X-WP-Nonce', session.nonce );
			xhr.upload.onprogress = touch;
			xhr.onreadystatechange = (): void => {
				if ( xhr.readyState !== XMLHttpRequest.DONE ) {
					touch();
				}
			};
			xhr.onload = settleResponse;
			xhr.onerror = fail;
			xhr.onabort = (): void => {
				// A stall is a real, retryable failure; a cancel abort records
				// nothing so the file stays pending and the model's finalise()
				// drops it. The watchdog and Cancel are the only abort sources.
				clearWatchdog();
				if ( stalled ) {
					fail();
				} else if ( cancelling ) {
					resolve();
				}
			};

			// Ship the file and its path; the server hard-sanitises and
			// realpath-confines the path (ADR-0006).
			const body = new FormData();
			body.append( 'file', blob, file.name );
			body.append( 'relativePath', relativePath );
			touch();
			xhr.send( body );
		};

		send( true );
	} );

	return {
		abort: () => {
			cancelling = true;
			request?.abort();
		},
		settled,
	};
}

/**
 * Converts then uploads one queued file, returning its abortable handle.
 *
 * Optimises the file to WebP (falling back to the raw bytes on any client-side
 * failure), then uploads it. The conversion is awaited inside the handle's
 * settled promise so the queue still gets a single handle synchronously; the
 * returned `abort()` stops the upload once it has started (a file aborted during
 * its brief conversion phase simply finishes converting and then uploads, which
 * Cancel's pending-drain and the model's finalise() tidy up).
 *
 * @since 0.4.0
 *
 * @param queued   - The file and its relative path.
 * @param context  - The per-block context.
 * @param session  - The mutable nonce session.
 * @param progress - The progress wiring the file's state is reported to.
 * @return A handle to abort the upload, plus a promise that settles when it ends.
 */
function processFile(
	queued: QueuedFile,
	context: DropZoneContext,
	session: UploadSession,
	progress: Progress
): UploadHandle {
	let inner: UploadHandle | null = null;
	let cancelling = false;

	const settled = optimiseToWebp(
		queued.file,
		context.uploadWidth,
		context.uploadQuality
	).then( ( blob ) => {
		// A Cancel during conversion is honoured the moment conversion ends:
		// the file is left pending (the model's finalise() drops it) rather
		// than starting a doomed upload.
		if ( cancelling ) {
			return;
		}
		inner = uploadBlob( blob, queued, context, session, progress );
		return inner.settled;
	} );

	return {
		abort: () => {
			cancelling = true;
			inner?.abort();
		},
		settled,
	};
}

/**
 * Filters a batch and enqueues the survivors, keying each by its relative path.
 *
 * Applies the type pre-filter before any bytes move — a denied file is recorded
 * `skipped` immediately instead of starting a doomed multi-hundred-MB upload —
 * then hands the accepted files to the upload queue, recording each as `pending`
 * so the total grows the moment a file is queued. Used by every intake path (the
 * click/folder pickers, the loose-file drop, and the recursive folder drop).
 *
 * @since 0.4.0
 *
 * @param files    - The files to consider, paired with their relative paths.
 * @param queue    - The upload queue.
 * @param progress - The progress wiring.
 */
function intakeFiles(
	files: readonly QueuedFile[],
	queue: UploadQueue,
	progress: Progress
): void {
	const accepted: QueuedFile[] = [];

	for ( const queued of files ) {
		// Silently drop hidden OS bookkeeping a folder pick/drop sweeps up — macOS
		// AppleDouble sidecars (`._<name>`), `.DS_Store`, and the like. These never
		// belong to the photographer and are invisible in Finder, so they get no
		// bucket entry at all: a "ghost file" the photographer cannot account for is
		// as confusing skipped as uploaded.
		if ( isHiddenFile( queued.file.name ) ) {
			continue;
		}

		// Deny RAW and video before upload; the file is recorded skipped
		// immediately instead of after a wasted transfer.
		if ( ! shouldUploadFile( queued.file.name, queued.file.type ) ) {
			progress.report( queued.relativePath, queued.file.name, 'skipped' );
			continue;
		}
		progress.report( queued.relativePath, queued.file.name, 'pending' );
		accepted.push( queued );
	}

	queue.enqueue( accepted );
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
		 * drag-drop + click-to-browse zone, hooks the two hidden file inputs to the
		 * builder's tokened upload-control links (ADR-0010), walks dropped folders
		 * recursively (every image at every level, paths preserved), and arms the
		 * `beforeunload` guard. Assembles the bucket-accounting model, the
		 * progress/summary view (its Cancel stops the queue, its Retry re-queues the
		 * failures), and the bounded upload queue the intake paths push files to.
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
			const progressEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__progress'
			);
			const statusEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__status'
			);
			const summaryEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__summary'
			);
			if ( ! fileInput || ! progressEl || ! statusEl || ! summaryEl ) {
				return;
			}

			mountedZones.add( ref );

			// The hidden folder input the folder-token link triggers; render emits it
			// hidden alongside the loose-file input (ADR-0010). It is located after the
			// required-element guard because, unlike the loose-file input, a missing
			// folder input only means the folder control is unavailable.
			const folderInput = ref.querySelector< HTMLInputElement >(
				'.kntnt-photo-drop-drop-zone__folder-input'
			);

			// Read the per-block context and assemble the per-mount state. The
			// bucket model owns the accounting; the view projects it; the queue,
			// the nonce session, and the beforeunload guard are wired below.
			const context = getContext< DropZoneContext >();
			const strings = context.i18n;
			const session: UploadSession = { nonce: context.nonce };
			const setUnloadGuard = installUnloadGuard();

			// The model, the view, the report path, and the queue form a cycle —
			// the view's Cancel/Retry call the queue, the queue's processor reports
			// through `progress`, and `progress` redraws the view — so the queue is
			// forward-declared and the closures below capture it before it is
			// assigned, which is legal because none of them runs during init.
			// eslint-disable-next-line prefer-const -- assigned once, but after the closures that capture it.
			let queue: UploadQueue;
			const model = createProgressModel();
			const view = createProgressView(
				{ progress: progressEl, status: statusEl, summary: summaryEl },
				strings,
				{
					onCancel: () => {
						// Stop the queue (aborts in-flight, clears pending), then
						// finalise: drop the now-pending files and show the summary
						// of what actually settled. Uploaded files persist on disk.
						queue.cancel();
						model.finalise();
						view.finalise( model.snapshot() );
					},
					onRetry: ( failed ) => {
						// Re-queue exactly the failures; they re-enter as pending and
						// the live bar resumes from the next render.
						intakeFiles(
							failed.map( ( item ) => ( {
								file: new File( [], item.fileName ),
								relativePath: item.key,
							} ) ),
							queue,
							progress
						);
					},
				}
			);

			// One report path: record the file's state, then redraw — the live
			// bar while files remain pending, the three-bucket summary the
			// moment every tracked file has settled.
			const progress: Progress = {
				model,
				view,
				report: ( key, fileName, fileState ) => {
					model.record( key, fileName, fileState );
					const snapshot = model.snapshot();
					if ( snapshot.complete ) {
						view.finalise( snapshot );
					} else {
						view.render( snapshot );
					}
				},
			};

			queue = createUploadQueue(
				( queued ) => processFile( queued, context, session, progress ),
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
					queue,
					progress
				);
			};

			// The whole wrapper is a click-to-browse trigger for pointer users: a
			// click that does not land on an interactive child opens the hidden
			// loose-file input. A click on a link, button, or input inside the
			// builder's markup — including the tokened upload-control links and the
			// module's own Cancel/Retry buttons — or on the summary, progress, or
			// status regions is left to do its own thing. The keyboard/AT browse path
			// is the tokened links themselves, so the wrapper carries no role or
			// tabindex and answers no keys.
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

			// The visible upload controls are builder-authored links wired by their
			// anchor-token href (ADR-0010): a link to FILES_TOKEN opens the loose-file
			// picker, one to FOLDER_TOKEN the folder picker. Bind every matching link's
			// click to its input and preventDefault the fragment navigation; a keyboard
			// or AT user reaches the link by Tab and activates it with Enter natively.
			const wireTokenLinks = (
				token: string,
				input: HTMLInputElement | null
			): void => {
				if ( ! input ) {
					return;
				}
				ref.querySelectorAll< HTMLAnchorElement >(
					`a[href="${ token }"]`
				).forEach( ( link ) => {
					link.addEventListener( 'click', ( event: MouseEvent ) => {
						event.preventDefault();
						input.click();
					} );
				} );
			};
			wireTokenLinks( FILES_TOKEN, fileInput );
			wireTokenLinks( FOLDER_TOKEN, folderInput );

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
			// as the folder picker (ADR-0008), with no warning step.
			// Entries that cannot be read are recorded failed instead of
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

				// A folder is present: walk the whole tree, record any
				// unreadable file or subtree as failed by its relative path, and
				// intake the walked files with their paths carried explicitly (a
				// `File` from `entry.file()` has no `webkitRelativePath`).
				void walkDroppedEntries( entries ).then(
					( { files, unreadable } ) => {
						for ( const path of unreadable ) {
							progress.report( path, path, 'failed' );
						}
						intakeFiles( files, queue, progress );
					}
				);
			} );
		},
	},
} );

export { state };
