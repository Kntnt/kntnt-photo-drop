/**
 * Photo Drop Zone edit component.
 *
 * The Drop Zone's *editable appearance* is its inner blocks, and the block's own
 * wrapper is the layout container that holds them — a Group-equivalent. The block
 * carries the Group-equivalent supports (layout, colour, typography, border,
 * spacing, min-height, shadow, align), so the dashed box a builder sees, the drop
 * target, and the drag-over highlight are all the **one** wrapper element; there
 * is no inner `core/group`. The default template seeds the wrapper directly with a
 * level-4 heading, a paragraph naming the target collection through the
 * `{kntnt-drop-zone-collection}` placeholder, a smaller note, and a `core/buttons`
 * whose two buttons are the visible upload controls — each wired to the uploader by
 * an anchor-token href the view module recognises (`#kntnt-drop-zone-files` opens the
 * loose-file picker, `#kntnt-drop-zone-folder` the folder picker; ADR-0010). The
 * wrapper's default dashed-box look (dashed `#808080` border, `#fafaff` background,
 * `2rem` padding) comes from the block's stylesheet, not a `style` attribute default
 * — a block-attribute default is never serialised, so the dynamic wrapper never
 * received it on the front end; the border/colour/spacing supports still override it.
 * The template is **not** locked, so a site builder can relabel, restyle, reposition,
 * or remove the controls (or any of the surface); render.php replaces the placeholder
 * with the collection's display
 * name at frontend render and turns the whole wrapper into the native drop-and-browse
 * uploader for a capable visitor (ADR-0006).
 *
 * The Drop Zone remains a *select-only consumer* of collections: its only
 * inspector controls are a collection selector and a strictly read-only display
 * of the selected collection's renditions (the immutable upload width/quality
 * contract, the re-derivable full and thumbnail renditions, and the always-WebP
 * format). Nothing about the renditions is editable here — that is what keeps the
 * block unable to conflict with the immutable contract (ADR-0002, ADR-0013).
 *
 * The collection list and contracts come from the editor-only REST endpoint
 * `kntnt-photo-drop/v1/collections` (gated by `edit_posts`), fetched once per
 * mounted block. An empty or dangling `collection` shows an inline inspector
 * notice prompting selection or noting the collection is gone.
 *
 * @since 0.5.0
 */

import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Notice,
	Spinner,
	ExternalLink,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';
import type { JSX } from '@wordpress/element';

/**
 * Persisted attributes for the Photo Drop Zone block.
 *
 * The slug is the only durable reference; everything about the contract is read
 * live from the descriptor, never stored on the block (docs/blocks.md).
 *
 * @since 0.5.0
 */
interface DropZoneAttributes {
	/** The slug of the collection to upload into; `''` until one is chosen. */
	collection: string;
	[ key: string ]: unknown;
}

/**
 * One collection as returned by the editor list endpoint.
 *
 * Mirrors the payload `Rest\Collections_Controller::list_collections()` emits:
 * the slug and display name drive the selector, and the six rendition fields the
 * read-only display.
 *
 * @since 0.5.0
 */
interface CollectionSummary {
	readonly slug: string;
	readonly name: string;
	/** The immutable upload-width ceiling in pixels, or `null` for the source's own dimensions. */
	readonly uploadWidth: number | null;
	readonly uploadQuality: number;
	/** The full-image width, or `null` when unset (the main image serves the full role). */
	readonly fullWidth: number | null;
	/** The full-image quality, or `null` when unset (it follows the upload quality). */
	readonly fullQuality: number | null;
	/** The thumbnail width, or `null` when unset (it follows the effective full width). */
	readonly thumbnailWidth: number | null;
	readonly thumbnailQuality: number;
}

/**
 * The fetch state of the shared collection list.
 *
 * A discriminated union so the component renders one of three clear states —
 * loading, error, or loaded — rather than juggling nullable flags.
 *
 * @since 0.5.0
 */
type CollectionsState =
	| { readonly status: 'loading' }
	| { readonly status: 'error' }
	| {
			readonly status: 'loaded';
			readonly collections: readonly CollectionSummary[];
	  };

/**
 * The editor REST path the list is fetched from.
 *
 * @since 0.5.0
 */
const LIST_PATH = '/kntnt-photo-drop/v1/collections';

/**
 * The placeholder render.php replaces with the collection's display name.
 *
 * Authored literally into the default template's target paragraph; a builder who
 * deletes or edits it simply forgoes the substitution (a removed token is never
 * replaced). Kept in sync with `Render_Drop_Zone::COLLECTION_PLACEHOLDER`.
 *
 * @since 0.4.0
 */
const COLLECTION_PLACEHOLDER = '{kntnt-drop-zone-collection}';

/**
 * The anchor-token href that wires a link to the loose-file picker.
 *
 * Any link inside the Drop Zone whose target is this fragment becomes the
 * "Add photos" trigger — the view module finds it by href and opens the hidden
 * loose-file input on click (ADR-0010). A `#fragment` (not a `{…}` token) because
 * the editor's link control and `esc_url()` strip the braces a brace token would
 * need; a fragment is a valid URL that survives the round-trip and does nothing
 * without JavaScript. Kept in sync with `view.ts`'s `FILES_TOKEN`.
 *
 * @since 0.6.0
 */
const FILES_TOKEN = '#kntnt-drop-zone-files';

/**
 * The anchor-token href that wires a link to the `webkitdirectory` folder picker.
 *
 * The folder counterpart of {@link FILES_TOKEN}; a link targeting this fragment
 * opens the hidden folder input on click (ADR-0010). Kept in sync with `view.ts`'s
 * `FOLDER_TOKEN`.
 *
 * @since 0.6.0
 */
const FOLDER_TOKEN = '#kntnt-drop-zone-folder';

/**
 * A WordPress inner-block template entry: name, optional attributes, optional children.
 *
 * The recursive tuple `register_block_type`/`useInnerBlocksProps` accept for a
 * seeded template, declared locally so the seeded `core/buttons` can carry its two
 * `core/button` children.
 *
 * @since 0.6.0
 */
type BlockTemplate = [ string, Record< string, unknown >?, BlockTemplate[]? ];

/**
 * The default inner-block template seeded into a freshly inserted Drop Zone.
 *
 * A level-4 heading, a paragraph naming the target collection through the
 * placeholder render.php substitutes, a smaller note explaining that the live
 * uploader appears on the published page, and a `core/buttons` holding the two
 * visible upload controls — an "Add photos" button and a quieter "Select a folder"
 * button — each wired to the uploader by an anchor-token href ({@link FILES_TOKEN},
 * {@link FOLDER_TOKEN}; ADR-0010). Seeded **directly** into the block's wrapper,
 * with no inner `core/group`, because the wrapper is itself the layout container
 * (its dashed-box look comes from the block's stylesheet and its block centring from
 * the `constrained` layout support). The template is intentionally
 * not locked, so a builder can relabel, restyle, reposition, or remove any of it —
 * including turning the folder button back into a plain text link; the placeholder
 * and the tokens are defaults and conventions, not a contract.
 *
 * @since 0.5.0
 * @since 0.6.0 Seeds the two upload controls as tokened buttons (ADR-0010).
 */
const DEFAULT_TEMPLATE: readonly BlockTemplate[] = [
	[
		'core/heading',
		{
			level: 4,
			// Centre the heading via the typography support — how current core/heading
			// (WordPress 7.0+, the plugin floor) represents text alignment. The legacy
			// top-level `textAlign` attribute it used before is gone, so seeding it would
			// be silently stripped.
			style: { typography: { textAlign: 'center' } },
			content: __( 'Photo Drop Zone', 'kntnt-photo-drop' ),
		},
	],
	[
		'core/paragraph',
		{
			align: 'center',
			content: sprintf(
				/* translators: %s: the placeholder replaced at render with the collection's display name. */
				__(
					'Uploads go into the “%s” collection.',
					'kntnt-photo-drop'
				),
				COLLECTION_PLACEHOLDER
			),
		},
	],
	[
		'core/paragraph',
		{
			align: 'center',
			fontSize: 'small',
			content: __(
				'The live uploader appears on the published page for users who can upload files.',
				'kntnt-photo-drop'
			),
		},
	],
	[
		'core/buttons',
		{ layout: { type: 'flex', justifyContent: 'center' } },
		[
			[
				'core/button',
				{
					url: FILES_TOKEN,
					text: __( 'Add photos', 'kntnt-photo-drop' ),
				},
			],
			[
				'core/button',
				{
					url: FOLDER_TOKEN,
					text: __( 'Select a folder', 'kntnt-photo-drop' ),
					className: 'is-style-outline',
				},
			],
		],
	],
];

/**
 * Formats the upload-width contract value for the read-only display.
 *
 * A `null` ceiling is the "source's own dimensions" contract; any other value is
 * shown as a pixel width. Kept tiny and local because it is presentation only.
 *
 * @since 0.5.0
 *
 * @param uploadWidth - The immutable upload ceiling, or `null` for the source's own dimensions.
 * @return The display string.
 */
function formatUploadWidth( uploadWidth: number | null ): string {
	if ( uploadWidth === null ) {
		return __( 'Original dimensions', 'kntnt-photo-drop' );
	}
	return sprintf(
		/* translators: %d: the maximum upload width in pixels. */
		__( '%d px', 'kntnt-photo-drop' ),
		uploadWidth
	);
}

/**
 * Formats a rendition's width and quality as one read-only "W px, quality Q" cell.
 *
 * Used for the re-derivable full and thumbnail renditions. Both the width and the
 * quality are nullable, where `null` means the field is unset and collapses to the
 * tier above (#71): an unset width shows as the translatable "Auto" and an unset
 * quality as "auto", mirroring the admin list so the inspector reflects the
 * collapse rather than a misleading "0 px". Kept tiny and local because it is
 * presentation only.
 *
 * @since 0.7.0
 *
 * @param width   - The rendition width in pixels, or `null` when unset.
 * @param quality - The rendition WebP quality (0–100), or `null` when unset.
 * @return The display string.
 */
function formatRendition(
	width: number | null,
	quality: number | null
): string {
	const widthLabel =
		width === null
			? __( 'Auto', 'kntnt-photo-drop' )
			: /* translators: %d: the rendition width in pixels. */
			  sprintf( __( '%d px', 'kntnt-photo-drop' ), width );
	const qualityLabel =
		quality === null ? __( 'auto', 'kntnt-photo-drop' ) : String( quality );
	return sprintf(
		/* translators: 1: rendition width (a pixel count or "Auto"); 2: WebP quality (0–100 or "auto"). */
		__( '%1$s, quality %2$s', 'kntnt-photo-drop' ),
		widthLabel,
		qualityLabel
	);
}

/**
 * Renders the read-only rendition display for the selected collection.
 *
 * A plain definition list of the collection's three renditions — the immutable
 * upload contract (width + quality), the re-derivable full and thumbnail
 * renditions, and the always-WebP format. Nothing here is editable; the panel
 * exists so a site builder can confirm what the chosen collection will do to
 * uploaded images. The "Manage collections" admin link is rendered separately and
 * always by the inspector panel (issue #41), not here, so it shows even when no
 * collection is selected.
 *
 * @since 0.5.0
 *
 * @param props            - Component props.
 * @param props.collection - The selected collection's summary.
 * @return The rendition display.
 */
function ContractDisplay( {
	collection,
}: {
	collection: CollectionSummary;
} ): JSX.Element {
	return (
		<div className="kntnt-photo-drop-drop-zone__contract">
			<p className="kntnt-photo-drop-drop-zone__contract-hint">
				{ __(
					'Renditions (read-only — set when the collection was created):',
					'kntnt-photo-drop'
				) }
			</p>
			<dl>
				<dt>{ __( 'Upload width', 'kntnt-photo-drop' ) }</dt>
				<dd>{ formatUploadWidth( collection.uploadWidth ) }</dd>
				<dt>{ __( 'Upload quality', 'kntnt-photo-drop' ) }</dt>
				<dd>{ collection.uploadQuality }</dd>
				<dt>{ __( 'Full image', 'kntnt-photo-drop' ) }</dt>
				<dd>
					{ formatRendition(
						collection.fullWidth,
						collection.fullQuality
					) }
				</dd>
				<dt>{ __( 'Thumbnail', 'kntnt-photo-drop' ) }</dt>
				<dd>
					{ formatRendition(
						collection.thumbnailWidth,
						collection.thumbnailQuality
					) }
				</dd>
				<dt>{ __( 'Format', 'kntnt-photo-drop' ) }</dt>
				<dd>{ __( 'WebP', 'kntnt-photo-drop' ) }</dd>
			</dl>
		</div>
	);
}

/**
 * Edit component for the Photo Drop Zone block.
 *
 * Renders the inner-block region (seeded with the default template on insertion)
 * and drives the inspector's collection selector and read-only contract display.
 * The inner blocks are the block's editable appearance and the wrapper is itself
 * their layout container — the Group-equivalent — so the seeded heading,
 * paragraphs, and upload-control buttons are its direct children and the supports
 * (layout, colour, typography, border, spacing, min-height, shadow, align) style
 * that same wrapper. An empty or dangling `collection` surfaces an inline notice
 * inside the inspector (the slug is no longer among the discovered collections,
 * e.g. after an `mv` rename).
 *
 * @since 0.5.0
 *
 * @param props               - Standard block edit props.
 * @param props.attributes    - Current block attributes.
 * @param props.setAttributes - Attribute setter.
 * @return The block's editor markup.
 */
export function DropZoneEdit( {
	attributes,
	setAttributes,
}: BlockEditProps< DropZoneAttributes > ): JSX.Element {
	const { collection } = attributes;
	const blockProps = useBlockProps( {
		className: 'kntnt-photo-drop-drop-zone',
	} );

	// The wrapper is itself the inner blocks' layout container, so the inner-block
	// props are derived from the block props: the layout/colour/border/spacing
	// supports land on the same element the inner blocks render directly into. Seed
	// the default template on insertion but leave it unlocked so a builder can
	// rewrite the surface freely.
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: DEFAULT_TEMPLATE,
		templateLock: false,
	} );

	// Fetch the editor-only collection list once per mounted block. The endpoint
	// is cheap (a directory scan) and the list reflects the filesystem at fetch
	// time, so a collection added or removed out of band shows up on the next
	// editor load rather than from a stale cache.
	const [ state, setState ] = useState< CollectionsState >( {
		status: 'loading',
	} );
	useEffect( () => {
		let active = true;
		apiFetch< CollectionSummary[] >( { path: LIST_PATH } )
			.then( ( collections ) => {
				if ( active ) {
					setState( { status: 'loaded', collections } );
				}
			} )
			.catch( () => {
				if ( active ) {
					setState( { status: 'error' } );
				}
			} );
		return () => {
			active = false;
		};
	}, [] );

	// Resolve the selected collection's summary from the loaded list. A non-empty
	// slug that is absent from the list is a dangling reference (the directory was
	// renamed or removed) — surfaced as a notice rather than silently cleared.
	const collections = state.status === 'loaded' ? state.collections : [];
	const selected =
		collections.find( ( item ) => item.slug === collection ) ?? null;
	const isDangling =
		state.status === 'loaded' && collection !== '' && selected === null;

	// Build the selector options, prepending an empty "choose" prompt and, for a
	// dangling slug, a placeholder labelled with the slug so the editor reflects
	// the persisted state without rewriting it.
	const options = [
		{
			value: '',
			label: __( '— Select a collection —', 'kntnt-photo-drop' ),
		},
		...collections.map( ( item ) => ( {
			value: item.slug,
			label: item.name,
		} ) ),
	];
	if ( isDangling ) {
		options.push( {
			value: collection,
			label: sprintf(
				/* translators: %s: the missing collection slug. */
				__( '%s (missing)', 'kntnt-photo-drop' ),
				collection
			),
		} );
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Collection', 'kntnt-photo-drop' ) }>
					{ state.status === 'loading' && (
						<p>
							<Spinner />
							{ __( 'Loading collections…', 'kntnt-photo-drop' ) }
						</p>
					) }
					{ state.status === 'error' && (
						<Notice status="error" isDismissible={ false }>
							{ __(
								'The collection list could not be loaded.',
								'kntnt-photo-drop'
							) }
						</Notice>
					) }
					{ state.status === 'loaded' && (
						<>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Collection', 'kntnt-photo-drop' ) }
								value={ collection }
								options={ options }
								onChange={ ( value: string ) =>
									setAttributes( { collection: value } )
								}
								help={ __(
									'Choose which collection uploaded photos go into.',
									'kntnt-photo-drop'
								) }
							/>
							{ collection === '' && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'Select a collection to enable uploads on the published page.',
										'kntnt-photo-drop'
									) }
								</Notice>
							) }
							{ isDangling && (
								<Notice status="error" isDismissible={ false }>
									{ sprintf(
										/* translators: %s: the missing collection slug. */
										__(
											'The collection “%s” no longer exists. Choose another.',
											'kntnt-photo-drop'
										),
										collection
									) }
								</Notice>
							) }
							{ selected !== null && (
								<ContractDisplay collection={ selected } />
							) }
						</>
					) }
					<p className="kntnt-photo-drop-drop-zone__contract-note">
						<ExternalLink href="admin.php?page=kntnt-photo-drop">
							{ __( 'Manage collections', 'kntnt-photo-drop' ) }
						</ExternalLink>
					</p>
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
