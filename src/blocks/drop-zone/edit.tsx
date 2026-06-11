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
 * wrapper's seeded `style` (dashed `#808080` border, `#fafaff` background, `2rem`
 * padding) reproduces today's default look. The template is **not** locked, so a
 * site builder can relabel, restyle, reposition, or remove the controls (or any of
 * the surface); render.php replaces the placeholder with the collection's display
 * name at frontend render and turns the whole wrapper into the native drop-and-browse
 * uploader for a capable visitor (ADR-0006).
 *
 * The Drop Zone remains a *select-only consumer* of collections: its only
 * inspector controls are a collection selector and a strictly read-only display
 * of the selected collection's output contract (max width, quality, format,
 * thumbnail width). Nothing about the contract is editable here — that is what
 * keeps the block unable to conflict with the immutable contract (ADR-0002).
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
 * the slug and display name drive the selector, and the three contract fields the
 * read-only display.
 *
 * @since 0.5.0
 */
interface CollectionSummary {
	readonly slug: string;
	readonly name: string;
	/** The contract ceiling in pixels, or `null` for no limit. */
	readonly maxWidth: number | null;
	readonly quality: number;
	readonly thumbnailWidths: readonly number[];
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
 * (its dashed-box look comes from the block's seeded `style` attribute and its
 * centring from the `constrained` layout support). The template is intentionally
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
			// Centre the heading across the whole supported WordPress range. Current
			// core/heading reads the typography support (`style.typography.textAlign`);
			// WordPress 6.6's core/heading reads the legacy top-level `textAlign`
			// attribute and ignores the former. Seeding both lets each version honour the
			// one it recognises — and `createBlock` strips the attribute that is not in
			// the running version's schema, so a modern editor keeps only
			// `style.typography.textAlign` and the markup stays clean.
			textAlign: 'center',
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
 * Formats the max-width contract value for the read-only display.
 *
 * A `null` ceiling is the "no limit" contract; any other value is shown as a pixel
 * width. Kept tiny and local because it is presentation only.
 *
 * @since 0.5.0
 *
 * @param maxWidth - The contract ceiling, or `null` for no limit.
 * @return The display string.
 */
function formatMaxWidth( maxWidth: number | null ): string {
	if ( maxWidth === null ) {
		return __( 'No limit', 'kntnt-photo-drop' );
	}
	return sprintf(
		/* translators: %d: the maximum image width in pixels. */
		__( '%d px', 'kntnt-photo-drop' ),
		maxWidth
	);
}

/**
 * Formats the thumbnail-width list for the read-only display.
 *
 * An empty list is the "no thumbnail" contract; otherwise the widths are joined
 * as a comma-separated pixel list.
 *
 * @since 0.5.0
 *
 * @param widths - The canonical thumbnail widths.
 * @return The display string.
 */
function formatThumbnailWidths( widths: readonly number[] ): string {
	if ( widths.length === 0 ) {
		return __( 'None', 'kntnt-photo-drop' );
	}
	return widths
		.map( ( width ) =>
			sprintf(
				/* translators: %d: a thumbnail width in pixels. */
				__( '%d px', 'kntnt-photo-drop' ),
				width
			)
		)
		.join( ', ' );
}

/**
 * Renders the read-only output-contract display for the selected collection.
 *
 * A plain definition list of the four contract facets — max width, quality, format
 * (always WebP), and thumbnail width(s) — with a hint linking to the admin page
 * where the lifecycle (and thus the contract) is managed. Nothing here is
 * editable; the panel exists so a site builder can confirm what the chosen
 * collection will do to uploaded images. The admin-page link is spaced clear of
 * the definition list so it does not read as another contract row.
 *
 * @since 0.5.0
 *
 * @param props            - Component props.
 * @param props.collection - The selected collection's summary.
 * @return The contract display.
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
					'Output contract (read-only — set when the collection was created):',
					'kntnt-photo-drop'
				) }
			</p>
			<dl>
				<dt>{ __( 'Maximum width', 'kntnt-photo-drop' ) }</dt>
				<dd>{ formatMaxWidth( collection.maxWidth ) }</dd>
				<dt>{ __( 'Quality', 'kntnt-photo-drop' ) }</dt>
				<dd>{ collection.quality }</dd>
				<dt>{ __( 'Format', 'kntnt-photo-drop' ) }</dt>
				<dd>{ __( 'WebP', 'kntnt-photo-drop' ) }</dd>
				<dt>{ __( 'Thumbnail width', 'kntnt-photo-drop' ) }</dt>
				<dd>{ formatThumbnailWidths( collection.thumbnailWidths ) }</dd>
			</dl>
			<p className="kntnt-photo-drop-drop-zone__contract-note">
				<ExternalLink href="admin.php?page=kntnt-photo-drop">
					{ __( 'Manage collections', 'kntnt-photo-drop' ) }
				</ExternalLink>
			</p>
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
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
