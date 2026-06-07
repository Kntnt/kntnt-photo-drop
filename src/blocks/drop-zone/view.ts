/**
 * Photo Drop Zone frontend view module — the FilePond uploader.
 *
 * Registered as the block's viewScriptModule and mounted by the Interactivity API
 * `init` callback against the capability-gated markup `Render_Drop_Zone` emits.
 * The heavy lifting lives in three pure, separately-tested helpers — the
 * Canvas→WebP encode (`canvas-webp.ts`), the `webkitRelativePath` mapping
 * (`relative-path.ts`), and the dragged-folder detector (`folder-detect.ts`) — and
 * this module is the thin DOM/FilePond wiring around them.
 *
 * It initialises FilePond on the drop area with the image-resize plugin
 * (downscale to the collection's max width) and a custom `server.process` that
 * re-encodes each resized image to WebP via `canvas.toBlob(…, 'image/webp', q)`
 * and POSTs it one request at a time to the REST endpoint, carrying the file, its
 * `relativePath`, and the `X-WP-Nonce`. It adds a native "Select folder" control
 * (`webkitdirectory`), warns when a folder is dragged onto the zone (offering to
 * continue flat), and surfaces each file's per-file outcome.
 *
 * The client optimisation is a bandwidth optimisation only; the server re-enforces
 * the contract on every file (ADR-0006), so a browser that cannot encode WebP
 * simply uploads the original bytes and the server converts them.
 *
 * FilePond's CSS is imported here so @wordpress/scripts bundles it into the
 * view-side asset declared as `viewStyle` in block.json.
 *
 * @since 0.5.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import { create, registerPlugin } from 'filepond';
import type { FilePond } from 'filepond';
import FilePondPluginImageResize from 'filepond-plugin-image-resize';
import 'filepond/dist/filepond.css';
import './view.scss';
import { encodeCanvasToWebp } from './canvas-webp';
import { relativePathForFile } from './relative-path';
import { hasDroppedFolder } from './folder-detect';

// Register the image-resize plugin once for the module so every pond downscales
// the source image before the WebP encode runs.
registerPlugin( FilePondPluginImageResize );

/**
 * The pre-translated UI strings the module surfaces.
 *
 * View-script modules cannot import `@wordpress/i18n`, so `Render_Drop_Zone`
 * translates every runtime string server-side and passes them through the
 * Interactivity context. The keys mirror `Render_Drop_Zone::translations()`.
 *
 * @since 0.5.0
 */
interface DropZoneStrings {
	readonly folderWarningBody: string;
	readonly folderWarningTitle: string;
	readonly outcomeStored: string;
	readonly outcomeReencoded: string;
	readonly outcomeSkipped: string;
	readonly outcomeRejected: string;
	readonly uploadFailed: string;
	readonly [ key: string ]: string;
}

/**
 * The per-block Interactivity context emitted by `Render_Drop_Zone`.
 *
 * Carries the collection slug, the contract (`maxWidth`/`quality`) that configures
 * the downscale and the WebP encode, the absolute REST `uploadUrl`, the `wp_rest`
 * `nonce`, the collection display name, and the pre-translated strings.
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
	readonly collection: string;
	readonly i18n: DropZoneStrings;
}

/**
 * The per-file outcome shape the REST endpoint returns.
 *
 * Mirrors `Upload_Controller::respond()`: the backed `outcome` plus the display
 * `name`. Only `outcome` is read at runtime to label the status line.
 *
 * @since 0.5.0
 */
interface UploadResponse {
	readonly outcome: 'stored' | 'skipped' | 'reencoded' | 'rejected';
	readonly name: string | null;
}

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
 * Draws an image onto a canvas, downscaled to the contract ceiling.
 *
 * Computes the target size from the source dimensions and the contract `maxWidth`
 * — never upscaling (a `null` ceiling or a source already within it keeps the
 * source size) — then paints the bitmap onto a fresh canvas at that size. The
 * canvas is handed to `encodeCanvasToWebp`; keeping the draw here makes the encode
 * helper independently testable with a plain canvas.
 *
 * @since 0.5.0
 *
 * @param bitmap   - The decoded source image.
 * @param maxWidth - The contract ceiling in pixels, or `null` for no limit.
 * @return The canvas holding the downscaled pixels.
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

	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	const renderingContext = canvas.getContext( '2d' );
	if ( renderingContext ) {
		renderingContext.drawImage( bitmap, 0, 0, width, height );
	}

	return canvas;
}

/**
 * Optimises one source file to a WebP blob per the collection contract.
 *
 * Decodes the file to a bitmap, downscales it to the contract ceiling, and
 * re-encodes to WebP at the contract quality. On any failure — an undecodable
 * file or a browser that cannot encode WebP — it resolves to the original file
 * unchanged, because the client optimisation is a bandwidth optimisation only and
 * the server re-enforces the contract on every upload (ADR-0006).
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
		const bitmap = await createImageBitmap( file );
		const canvas = drawDownscaled( bitmap, maxWidth );
		bitmap.close();
		return await encodeCanvasToWebp( canvas, quality );
	} catch {
		// Fall back to the original bytes; the server downscales and converts.
		return file;
	}
}

/**
 * Maps a per-file outcome to its pre-translated status label.
 *
 * @since 0.5.0
 *
 * @param outcome - The backed outcome from the REST response.
 * @param strings - The pre-translated string map.
 * @return The label to show for that outcome.
 */
function labelForOutcome(
	outcome: UploadResponse[ 'outcome' ],
	strings: DropZoneStrings
): string {
	switch ( outcome ) {
		case 'stored':
			return strings.outcomeStored;
		case 'reencoded':
			return strings.outcomeReencoded;
		case 'skipped':
			return strings.outcomeSkipped;
		case 'rejected':
			return strings.outcomeRejected;
		default:
			return strings.uploadFailed;
	}
}

/**
 * Appends one per-file status line to the status list.
 *
 * Inserts text only via `textContent`, never `innerHTML`, so a hostile filename
 * cannot inject markup. Returns nothing — the line is appended in place.
 *
 * @since 0.5.0
 *
 * @param statusList - The `<ul>` the status lines live in.
 * @param fileName   - The display name of the file.
 * @param label      - The outcome label to show.
 */
function appendStatus(
	statusList: HTMLElement,
	fileName: string,
	label: string
): void {
	const item = document.createElement( 'li' );
	item.className = 'kntnt-photo-drop-drop-zone__status-item';
	item.textContent = `${ fileName }: ${ label }`;
	statusList.appendChild( item );
}

/**
 * Builds FilePond's custom `server.process` for one collection.
 *
 * Returns the process function FilePond calls per file: it optimises the file to
 * WebP, then POSTs the file plus its `relativePath` (from the
 * `webkitRelativePath` mapping carried as item metadata) to the REST endpoint with
 * the `X-WP-Nonce` header, one request per file. It reports progress, surfaces the
 * per-file outcome, and translates a non-2xx response or a transport error into a
 * FilePond error so the item shows as failed without aborting the rest of the
 * batch.
 *
 * @since 0.5.0
 *
 * @param context    - The per-block context with the contract, URL, and nonce.
 * @param statusList - The status list per-file outcomes are appended to.
 * @return The FilePond `server.process` function.
 */
function makeProcess( context: DropZoneContext, statusList: HTMLElement ) {
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
		const controller = new AbortController();

		// Resolve the relative path from the metadata the folder picker / drop
		// handler attached, falling back to the file's own name for a loose file.
		const relativePath =
			typeof metadata.relativePath === 'string' &&
			metadata.relativePath !== ''
				? metadata.relativePath
				: relativePathForFile( file );

		// Optimise then upload. A network or server failure is surfaced per file
		// via `error()` so one bad file never aborts the batch (ADR-0006).
		optimiseToWebp( file, context.maxWidth, context.quality )
			.then( ( blob ) => {
				const body = new FormData();
				body.append( 'file', blob, file.name );
				body.append( 'relativePath', relativePath );
				progress( false, 0, blob.size );

				return fetch( context.uploadUrl, {
					method: 'POST',
					headers: { 'X-WP-Nonce': context.nonce },
					body,
					signal: controller.signal,
				} );
			} )
			.then( async ( response ) => {
				const payload = ( await response
					.json()
					.catch( () => null ) ) as UploadResponse | null;

				// A non-2xx response (e.g. a 422 rejection) is a per-file failure:
				// surface its label and report the error to FilePond.
				if ( ! response.ok || payload === null ) {
					const label = payload
						? labelForOutcome( payload.outcome, context.i18n )
						: context.i18n.uploadFailed;
					appendStatus( statusList, file.name, label );
					error( label );
					return;
				}

				// Mark the file done and surface its per-file outcome. The
				// response carries no byte count, so a completed bar is reported
				// with a unit total.
				progress( true, 1, 1 );
				appendStatus(
					statusList,
					payload.name ?? file.name,
					labelForOutcome( payload.outcome, context.i18n )
				);
				load( payload.name ?? file.name );
			} )
			.catch( () => {
				// A transport error (network drop, abort) fails this one file.
				appendStatus(
					statusList,
					file.name,
					context.i18n.uploadFailed
				);
				error( context.i18n.uploadFailed );
			} );

		// Hand FilePond an abort hook that cancels the in-flight request.
		return {
			abort: () => {
				controller.abort();
				abort();
			},
		};
	};
}

/**
 * Adds files from the native "Select folder" input to the pond.
 *
 * Each `File` from a `webkitdirectory` selection carries its
 * `webkitRelativePath`; the path is mapped through the shared helper and attached
 * as FilePond item metadata so `server.process` sends it as `relativePath`,
 * preserving the nested structure server-side.
 *
 * @since 0.5.0
 *
 * @param pond  - The mounted FilePond instance.
 * @param files - The files chosen via the folder input.
 */
function addFolderFiles( pond: FilePond, files: FileList ): void {
	for ( const file of Array.from( files ) ) {
		void pond.addFile( file, {
			// Carry the per-file relative path so the structure is recreated
			// server-side (the server hard-sanitises and realpath-confines it).
			metadata: { relativePath: relativePathForFile( file ) },
		} );
	}
}

const { state } = store( 'kntnt-photo-drop/drop-zone', {
	state: {},
	callbacks: {
		/**
		 * Initialise one Drop Zone block.
		 *
		 * Reads the per-block context, mounts FilePond on the drop area with the
		 * custom WebP-encoding upload process, wires the "Select folder" input,
		 * and installs the dragged-folder warning. Idempotent via `mountedZones`
		 * so a re-run never double-mounts.
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

			// Locate the drop area and the status list the render handler emitted;
			// without them there is nothing to mount, so bail before reading any
			// further context rather than half-initialise.
			const pondEl = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__pond'
			);
			const statusList = ref.querySelector< HTMLElement >(
				'.kntnt-photo-drop-drop-zone__status'
			);
			if ( ! pondEl || ! statusList ) {
				return;
			}

			mountedZones.add( ref );

			// Read the per-block context and the optional folder picker only past
			// the guard, so a missing drop area never leaves them computed unused.
			const context = getContext< DropZoneContext >();
			const folderInput = ref.querySelector< HTMLInputElement >(
				'.kntnt-photo-drop-drop-zone__folder-input'
			);

			// Mount FilePond: image-resize downscales to the contract ceiling, the
			// custom process re-encodes to WebP and uploads one file per request.
			const pond = create( pondEl, {
				allowMultiple: true,
				allowProcess: true,
				instantUpload: true,
				imageResizeTargetWidth:
					context.maxWidth !== null ? context.maxWidth : undefined,
				imageResizeMode: 'contain',
				imageResizeUpscale: false,
				server: {
					process: makeProcess( context, statusList ),
				},
			} );

			// Wire the native folder picker: each selected file's
			// webkitRelativePath rides along as metadata so the server recreates
			// its sub-directories.
			folderInput?.addEventListener(
				'change',
				() => {
					if ( folderInput.files ) {
						addFolderFiles( pond, folderInput.files );
					}
				},
				{ passive: true }
			);

			// Warn when a folder is dragged onto the zone (no recursion): a
			// dropped folder yields only its top-level files, so offer to continue
			// flat rather than silently dropping the sub-folders.
			pondEl.addEventListener( 'drop', ( event: DragEvent ) => {
				const items = event.dataTransfer?.items;
				if ( items && hasDroppedFolder( Array.from( items ) ) ) {
					// eslint-disable-next-line no-alert
					const proceed = window.confirm(
						context.i18n.folderWarningBody
					);
					if ( ! proceed ) {
						event.preventDefault();
						event.stopPropagation();
					}
				}
			} );
		},
	},
} );

export { state };
