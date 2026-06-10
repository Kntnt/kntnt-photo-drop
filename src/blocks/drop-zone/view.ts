/**
 * Photo Drop Zone frontend view module — the FilePond uploader.
 *
 * Registered as the block's viewScriptModule and mounted by the Interactivity API
 * `init` callback against the capability-gated markup `Render_Drop_Zone` emits.
 * The heavy lifting lives in pure, separately-tested helpers — the Canvas→WebP
 * encode (`canvas-webp.ts`), the safe-canvas-area cap (`canvas-limit.ts`), the
 * `webkitRelativePath` mapping (`relative-path.ts`), the dragged-folder rules
 * (`folder-detect.ts`), the type pre-filter (`file-filter.ts`), the
 * response-interpretation rules (`upload-response.ts`), and the keyed status
 * list (`status-list.ts`) — and this module is the thin DOM/FilePond wiring
 * around them.
 *
 * The optimisation pipeline is a plain Canvas pipeline, no FilePond image
 * plugins: each file is decoded with `createImageBitmap` (EXIF-oriented), drawn
 * downscaled to the collection's max width onto a canvas, re-encoded to WebP via
 * `canvas.toBlob(…, 'image/webp', q)`, and POSTed one request at a time to the
 * REST endpoint via `XMLHttpRequest` — real upload progress, an inactivity
 * watchdog, and a one-shot automatic retry with a refreshed `wp_rest` nonce when
 * the session's nonce has expired. A folder dragged onto the zone is intercepted
 * before FilePond's own recursive traversal can flatten the whole tree: the
 * visitor is warned and, on consent, only the top-level images are added, flat
 * (folder structure is the "Select folder" picker's job). A `beforeunload`
 * guard holds the page while uploads are queued or in flight.
 *
 * The client optimisation is a bandwidth optimisation only; the server
 * re-enforces the contract on every file (ADR-0006), so any client-side decode
 * or encode failure simply uploads the original bytes and the server converts
 * them.
 *
 * FilePond's CSS is imported here so @wordpress/scripts bundles it into the
 * view-side asset declared as `viewStyle` in block.json.
 *
 * @since 0.5.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import { create, FileStatus } from 'filepond';
import type { FilePond, FilePondFile } from 'filepond';
import 'filepond/dist/filepond.css';
import './view.scss';
import { encodeCanvasToWebp } from './canvas-webp';
import { exceedsSafeCanvasArea } from './canvas-limit';
import { relativePathForFile } from './relative-path';
import {
	collectTopLevelFiles,
	hasDirectoryEntry,
	snapshotEntries,
} from './folder-detect';
import { shouldUploadFile } from './file-filter';
import {
	errorLabelFor,
	isNonceRejection,
	labelForOutcome,
	readOutcome,
} from './upload-response';
import { createStatusList, type StatusList } from './status-list';

/**
 * The pre-translated UI strings the module surfaces.
 *
 * View-script modules cannot import `@wordpress/i18n`, so `Render_Drop_Zone`
 * translates every runtime string server-side and passes them through the
 * Interactivity context. The keys mirror `Render_Drop_Zone::translations()`;
 * the `label*` keys are handed to FilePond verbatim.
 *
 * @since 0.5.0
 */
interface DropZoneStrings {
	readonly folderWarningBody: string;
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
	readonly summaryTemplate: string;
	readonly labelIdle: string;
	readonly labelFileProcessing: string;
	readonly labelFileProcessingComplete: string;
	readonly labelFileProcessingError: string;
	readonly labelFileProcessingAborted: string;
	readonly labelTapToCancel: string;
	readonly labelTapToRetry: string;
	readonly labelTapToUndo: string;
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
 * @since 0.5.0
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
 * The mutable per-mount upload session.
 *
 * The nonce rendered into the context expires after 12–24 hours; when an
 * upload bounces on it, the module fetches a fresh one and retries once. The
 * fresh nonce lives here so every later upload in the same batch uses it too.
 *
 * @since 0.2.0
 */
interface UploadSession {
	nonce: string;
}

/**
 * Milliseconds of upload silence before a request is treated as stalled.
 *
 * An *inactivity* timeout, not a total-duration one: a slow link that keeps
 * making progress is never punished, while a dead connection frees its
 * parallel-upload slot after one minute instead of blocking the queue forever.
 *
 * @since 0.2.0
 */
const INACTIVITY_TIMEOUT_MS = 60_000;

/**
 * The shape of a plausible `wp_rest` nonce — ten lower-case hex characters.
 *
 * Core's `rest-nonce` admin-ajax action answers with the bare nonce as plain
 * text; anything else (a login page, a `0` from a logged-out session) fails
 * this pattern and the refresh is treated as unsuccessful.
 *
 * @since 0.2.0
 */
const NONCE_PATTERN = /^[a-f0-9]{10}$/;

/**
 * The FilePond item statuses that count as "work still pending".
 *
 * A file in any of these states would be lost by a navigation, so the
 * `beforeunload` guard holds the page while any item is in one of them.
 * Completed and failed items are settled and hold nothing. `IDLE` is
 * deliberately excluded: FilePond parks a *user-cancelled* item back in
 * `IDLE`, and counting it would leave the guard stuck on after a cancel —
 * with `instantUpload` the loaded→queued transition through `IDLE` is
 * otherwise momentary.
 *
 * @since 0.2.0
 */
const BUSY_STATUSES: ReadonlySet< FileStatus > = new Set( [
	FileStatus.INIT,
	FileStatus.LOADING,
	FileStatus.PROCESSING_QUEUED,
	FileStatus.PROCESSING,
] );

/**
 * Tracks which block elements already have a pond mounted.
 *
 * Keyed by the block wrapper so the Interactivity API re-running `init` (e.g. on a
 * re-hydration) never mounts a second FilePond over the same drop area.
 *
 * @since 0.5.0
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
 * @since 0.2.0
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
 * @since 0.5.0
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
 * @since 0.5.0
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
 * @since 0.2.0
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
 * Builds FilePond's custom `server.process` for one collection.
 *
 * Returns the process function FilePond calls per file: it optimises the file
 * to WebP, then POSTs the blob plus its `relativePath` to the REST endpoint
 * via `XMLHttpRequest` with the session's `X-WP-Nonce`, one request per file.
 * Real upload progress reaches FilePond through `xhr.upload.onprogress`; an
 * inactivity watchdog aborts a request after 60 s of silence so a dead
 * connection cannot block the parallel-upload queue forever. A 401/403 carrying
 * a nonce error code triggers one automatic retry with a freshly fetched nonce.
 * A file is reported successful only on a 2xx response with a parsed outcome;
 * every failure path surfaces the most informative label available — the
 * server's own `message` first — in both the status row and the FilePond item.
 *
 * @since 0.5.0
 *
 * @param context - The per-block context with the contract, URLs, and strings.
 * @param session - The mutable session holding the current nonce.
 * @param status  - The keyed status list rows are reported to.
 * @return The FilePond `server.process` function.
 */
function makeProcess(
	context: DropZoneContext,
	session: UploadSession,
	status: StatusList
) {
	return (
		fieldName: string,
		file: File,
		metadata: { [ key: string ]: unknown },
		load: ( serverId: string ) => void,
		error: ( message: string ) => void,
		progress: (
			lengthComputable: boolean,
			loaded: number,
			total: number
		) => void,
		abort: () => void
	): { abort: () => void } => {
		// Resolve the relative path from the metadata the folder picker / drop
		// handler attached, falling back to the file's own name for a loose
		// file. The path doubles as the file's status-row key.
		const relativePath =
			typeof metadata.relativePath === 'string' &&
			metadata.relativePath !== ''
				? metadata.relativePath
				: relativePathForFile( file );

		// The in-flight request and its inactivity watchdog; `cancelled` stops
		// the pipeline when FilePond aborts between async steps.
		let activeRequest: XMLHttpRequest | null = null;
		let watchdog: number | undefined;
		let cancelled = false;

		const clearWatchdog = (): void => {
			window.clearTimeout( watchdog );
		};

		// Every failure path funnels here so the status row and the FilePond
		// item always agree on the same label.
		const fail = ( label: string ): void => {
			clearWatchdog();
			status.update( relativePath, file.name, label, 'failed' );
			error( label );
		};

		// One upload attempt; `allowNonceRetry` is spent on the single
		// automatic nonce-refresh retry.
		const send = ( blob: Blob, allowNonceRetry: boolean ): void => {
			const request = new XMLHttpRequest();
			activeRequest = request;
			let stalled = false;

			// Re-arm the inactivity watchdog on every sign of life; when it
			// fires, the abort is flagged as a stall so the handler can tell
			// it apart from a user cancel.
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
					progress( true, 1, 1 );
					const displayName = outcome.name ?? file.name;
					status.update(
						relativePath,
						displayName,
						labelForOutcome( outcome.outcome, context.i18n ),
						outcome.outcome === 'skipped' ? 'skipped' : 'uploaded'
					);
					load( displayName );
					return;
				}

				// An expired nonce gets one automatic retry with a fresh
				// nonce; the fresh nonce is kept for the rest of the batch.
				if (
					allowNonceRetry &&
					isNonceRejection( request.status, payload )
				) {
					void refreshNonce( context.ajaxUrl ).then( ( fresh ) => {
						if ( cancelled ) {
							return;
						}
						if ( fresh !== null ) {
							session.nonce = fresh;
							send( blob, false );
							return;
						}
						fail( errorLabelFor( payload, context.i18n ) );
					} );
					return;
				}

				fail( errorLabelFor( payload, context.i18n ) );
			};

			// Open and wire the request: real upload progress feeds FilePond
			// and the watchdog; response activity only feeds the watchdog.
			request.open( 'POST', context.uploadUrl );
			request.setRequestHeader( 'X-WP-Nonce', session.nonce );
			request.upload.onprogress = ( progressEvent ) => {
				touch();
				progress(
					progressEvent.lengthComputable,
					progressEvent.loaded,
					progressEvent.total
				);
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
				// A stall is this module's own abort and is surfaced as an
				// actionable failure; a user cancel was already reported to
				// FilePond by the returned abort handler.
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

		// Optimise then upload. A network or server failure is surfaced per
		// file via `fail()` so one bad file never aborts the batch (ADR-0006).
		status.update(
			relativePath,
			file.name,
			context.i18n.statusConverting,
			'pending'
		);
		void optimiseToWebp( file, context.maxWidth, context.quality ).then(
			( blob ) => {
				if ( ! cancelled ) {
					send( blob, true );
				}
			}
		);

		// Hand FilePond an abort hook that cancels the in-flight request and
		// settles the status row honestly.
		return {
			abort: () => {
				cancelled = true;
				clearWatchdog();
				activeRequest?.abort();
				status.update(
					relativePath,
					file.name,
					context.i18n.labelFileProcessingAborted,
					'failed'
				);
				abort();
			},
		};
	};
}

/**
 * Adds a batch of picked or dropped files to the pond, pre-filtered and keyed.
 *
 * Applies the type pre-filter before any bytes move — a denied file gets an
 * immediate "skipped" row instead of a doomed multi-hundred-MB upload — and
 * attaches each accepted file's relative path as FilePond item metadata so
 * `server.process` sends it as `relativePath`. An `addFile` rejection becomes
 * a failed row rather than a silent loss.
 *
 * @since 0.2.0
 *
 * @param pond    - The mounted FilePond instance.
 * @param files   - The files to add, paired with their relative paths.
 * @param status  - The keyed status list rows are reported to.
 * @param strings - The pre-translated string map.
 */
function addFilesToPond(
	pond: FilePond,
	files: ReadonlyArray< { file: File; relativePath: string } >,
	status: StatusList,
	strings: DropZoneStrings
): void {
	for ( const { file, relativePath } of files ) {
		// Deny RAW and video before upload; the row tells the photographer
		// immediately instead of after a wasted transfer.
		if ( ! shouldUploadFile( file.name, file.type ) ) {
			status.update(
				relativePath,
				file.name,
				strings.skippedNotImage,
				'skipped'
			);
			continue;
		}

		// Carry the per-file relative path so the structure is recreated
		// server-side; a rejected add is an honest failed row, never silence.
		pond.addFile( file, { metadata: { relativePath } } ).catch( () => {
			status.update(
				relativePath,
				file.name,
				strings.uploadFailed,
				'failed'
			);
		} );
	}
}

/**
 * Clears FilePond's drag indicator after a swallowed drop.
 *
 * When the capture-phase folder handler stops a drop, FilePond's own drop
 * handler never runs and its hopper is left in the "drag over" state. A
 * synthetic `dragenter` immediately followed by `dragleave` on the pond root
 * resets it: the dragenter makes the root the hopper's `initialTarget`, which
 * is exactly the condition its dragleave handler requires before it dispatches
 * the drag-end cleanup (verified against FilePond 4.32.12).
 *
 * @since 0.2.0
 *
 * @param pond - The mounted FilePond instance.
 */
function resetPondDragState( pond: FilePond ): void {
	const root = pond.element;
	if ( root ) {
		root.dispatchEvent( new DragEvent( 'dragenter', { bubbles: true } ) );
		root.dispatchEvent( new DragEvent( 'dragleave', { bubbles: true } ) );
	}
}

/**
 * Creates the `beforeunload` guard and returns its busy switch.
 *
 * While busy, leaving the page asks the browser's "unsaved changes" question
 * instead of silently abandoning a half-uploaded batch on a back-swipe; when
 * idle, the handler is unregistered so normal navigation stays silent.
 *
 * @since 0.2.0
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

/**
 * Reads the status-row key for a FilePond item.
 *
 * Prefers the `relativePath` metadata the folder picker or drop handler
 * attached; a loose file added by FilePond itself falls back to the shared
 * name mapping, matching what `server.process` will send.
 *
 * @since 0.2.0
 *
 * @param item - The FilePond item to key.
 * @return The stable per-file key.
 */
function itemKey( item: FilePondFile ): string {
	const metadataPath: unknown = item.getMetadata( 'relativePath' );
	return typeof metadataPath === 'string' && metadataPath !== ''
		? metadataPath
		: relativePathForFile( item.file );
}

const { state } = store( 'kntnt-photo-drop/drop-zone', {
	state: {},
	callbacks: {
		/**
		 * Initialise one Drop Zone block.
		 *
		 * Reads the per-block context, mounts FilePond on the drop area with
		 * the custom Canvas→WebP upload process and the translated labels,
		 * wires the "Select folder" input and the type pre-filter, intercepts
		 * dragged folders ahead of FilePond's recursive traversal, and arms
		 * the `beforeunload` guard. Idempotent via `mountedZones` so a re-run
		 * never double-mounts.
		 *
		 * @since 0.5.0
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
			// there is nothing to mount, so bail before reading any further
			// context rather than half-initialise.
			const pondEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__pond'
			);
			const statusListEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__status'
			);
			const summaryEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__summary'
			);
			if ( ! pondEl || ! statusListEl || ! summaryEl ) {
				return;
			}

			mountedZones.add( ref );

			// Read the per-block context and assemble the per-mount state:
			// the keyed status list, the mutable nonce session, and the
			// beforeunload guard switch.
			const context = getContext< DropZoneContext >();
			const strings = context.i18n;
			const folderInput = ref.querySelector< HTMLInputElement >(
				'.kntnt-photo-drop-drop-zone__folder-input'
			);
			const status = createStatusList(
				statusListEl,
				summaryEl,
				strings.summaryTemplate
			);
			const session: UploadSession = { nonce: context.nonce };
			const setUnloadGuard = installUnloadGuard();

			// Recompute the guard from the pond's own item statuses on every
			// FilePond transition; `pond` is assigned right below and the
			// callbacks only fire afterwards.
			let pond: FilePond | null = null;
			const refreshGuard = (): void => {
				if ( pond ) {
					setUnloadGuard(
						pond
							.getFiles()
							.some( ( item ) =>
								BUSY_STATUSES.has( item.status )
							)
					);
				}
			};

			// Mount FilePond: the custom process runs the Canvas→WebP
			// pipeline and uploads one file per request; the translated
			// labels replace FilePond's hardcoded English, with the per-file
			// error label resolved from the error body so the translated
			// message reaches the item UI; `beforeAddFile` applies the type
			// pre-filter to every entry path, including FilePond's own
			// browse and loose-file drops.
			pond = create( pondEl, {
				allowMultiple: true,
				allowProcess: true,
				instantUpload: true,
				labelIdle: strings.labelIdle,
				labelFileProcessing: strings.labelFileProcessing,
				labelFileProcessingComplete:
					strings.labelFileProcessingComplete,
				labelFileProcessingError: ( processError?: {
					body?: string;
				} ): string =>
					// An empty body must fall back too, hence `||`.
					processError?.body || strings.labelFileProcessingError,
				labelFileProcessingAborted: strings.labelFileProcessingAborted,
				labelTapToCancel: strings.labelTapToCancel,
				labelTapToRetry: strings.labelTapToRetry,
				labelTapToUndo: strings.labelTapToUndo,
				beforeAddFile: ( item: FilePondFile ): boolean => {
					const key = itemKey( item );
					if ( ! shouldUploadFile( item.filename, item.fileType ) ) {
						status.update(
							key,
							item.filename,
							strings.skippedNotImage,
							'skipped'
						);
						return false;
					}
					status.update(
						key,
						item.filename,
						strings.statusQueued,
						'pending'
					);
					return true;
				},
				server: {
					process: makeProcess( context, session, status ),
				},
				onupdatefiles: refreshGuard,
				onaddfile: refreshGuard,
				onprocessfilestart: refreshGuard,
				onprocessfile: refreshGuard,
				onprocessfileabort: refreshGuard,
				onprocessfiles: refreshGuard,
				onremovefile: refreshGuard,
			} );
			const mountedPond = pond;

			// Wire the native folder picker: each selected file's
			// webkitRelativePath rides along as metadata so the server
			// recreates its sub-directories.
			folderInput?.addEventListener(
				'change',
				() => {
					if ( folderInput.files ) {
						addFilesToPond(
							mountedPond,
							Array.from( folderInput.files ).map( ( file ) => ( {
								file,
								relativePath: relativePathForFile( file ),
							} ) ),
							status,
							strings
						);
					}
				},
				{ passive: true }
			);

			// Intercept dragged folders on the wrapper in the capture phase:
			// FilePond replaces the mount element and listens on its own root
			// in the bubble phase, so this listener both survives the mount
			// and runs first (verified against FilePond 4.32.12). Left alone,
			// FilePond would recursively ingest the whole tree and flatten
			// it, silently colliding same-named files from different camera
			// folders — instead the design contract applies: warn, and on
			// consent add only the top-level images, flat.
			ref.addEventListener(
				'drop',
				( event: DragEvent ) => {
					const items = event.dataTransfer?.items;
					if ( ! items ) {
						return;
					}

					// Snapshot the entries synchronously — they are only
					// readable while the drop event is being dispatched. A
					// drop without a directory is the loose-file fast path
					// and FilePond handles it untouched.
					const entries = snapshotEntries( Array.from( items ) );
					if ( ! hasDirectoryEntry( entries ) ) {
						return;
					}

					// A folder is present: take the drop away from FilePond
					// and ask before uploading anything.
					event.preventDefault();
					event.stopPropagation();
					resetPondDragState( mountedPond );
					// eslint-disable-next-line no-alert
					if ( ! window.confirm( strings.folderWarningBody ) ) {
						return;
					}

					// Collect the loose files plus each folder's top-level
					// files (flat, no recursion) and add them through the
					// shared pre-filtered path; entries that could not be
					// read get an honest failed row.
					void collectTopLevelFiles( entries ).then(
						( { files, unreadable } ) => {
							for ( const name of unreadable ) {
								status.update(
									name,
									name,
									strings.fileUnreadable,
									'failed'
								);
							}
							addFilesToPond(
								mountedPond,
								files.map( ( file ) => ( {
									file,
									relativePath: file.name,
								} ) ),
								status,
								strings
							);
						}
					);
				},
				{ capture: true }
			);
		},
	},
} );

export { state };
