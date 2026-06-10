/**
 * Photo Drop Zone edit component.
 *
 * The Drop Zone's *editable appearance* is its inner blocks: the editor renders
 * an `InnerBlocks` region seeded, on insertion, with a default template — a
 * centred dashed group holding a heading, a paragraph naming the target
 * collection through the `{kntnt-drop-zone-collection}` placeholder, and a
 * smaller note. The template is **not** locked, so a site builder can rewrite the
 * surface freely; render.php replaces the placeholder with the collection's
 * display name at frontend render and wraps the whole surface in the native
 * drop-and-browse uploader for a capable visitor (ADR-0006).
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
 * The default inner-block template seeded into a freshly inserted Drop Zone.
 *
 * A centred dashed group (border `#808080`, background `#fafaff`) holding a
 * level-4 heading, a paragraph naming the target collection through the
 * placeholder render.php substitutes, and a smaller note explaining that the live
 * uploader appears on the published page. The template is intentionally not
 * locked, so a builder can edit any of it; the placeholder is just a default, not
 * a contract.
 *
 * @since 0.4.0
 */
const DEFAULT_TEMPLATE: readonly [ string, Record< string, unknown > ][] = [
	[
		'core/group',
		{
			layout: { type: 'constrained' },
			style: {
				border: {
					color: '#808080',
					style: 'dashed',
					width: '2px',
					radius: '4px',
				},
				color: { background: '#fafaff' },
				spacing: { padding: '2rem' },
			},
		},
		[
			[
				'core/heading',
				{
					level: 4,
					textAlign: 'center',
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
 * The inner blocks are the block's editable appearance, so the block wrapper is
 * the `InnerBlocks` container; an empty or dangling `collection` surfaces an
 * inline notice inside the inspector (the slug is no longer among the discovered
 * collections, e.g. after an `mv` rename).
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

	// The inner blocks are the editable drop surface; seed the default template
	// on insertion but leave it unlocked so a builder can rewrite it freely.
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
