/**
 * Photo Drop Zone edit component.
 *
 * Renders the block inside the editor. The Drop Zone is a *select-only consumer*
 * of collections: its only inspector control is a collection selector, and beside
 * it a strictly read-only display of the selected collection's output contract
 * (max width, quality, format, thumbnail width). Nothing about the contract is
 * editable here — that is what keeps the block unable to conflict with the
 * immutable contract (ADR-0002). The block canvas shows a static representation of
 * the drop area; no live upload happens in the editor.
 *
 * The collection list and contracts come from the editor-only REST endpoint
 * `kntnt-photo-drop/v1/collections` (gated by `edit_posts`), fetched once and
 * shared across every Drop Zone block on the page. An empty or dangling
 * `collection` shows an inline notice prompting selection or noting the collection
 * is gone.
 *
 * @since 0.5.0
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
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
 * collection will do to uploaded images.
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
 * Fetches the shared collection list, drives the inspector's selector and
 * read-only contract display, and renders the static editor canvas (a non-live
 * representation of the drop area). The canvas surfaces an inline notice when the
 * collection is unset (prompt to choose) or dangling (the saved slug is no longer
 * among the discovered collections, e.g. after an `mv` rename).
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
		className:
			'kntnt-photo-drop-drop-zone kntnt-photo-drop-drop-zone--editor',
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
		<div { ...blockProps }>
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
							{ selected !== null && (
								<ContractDisplay collection={ selected } />
							) }
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<div className="kntnt-photo-drop-drop-zone__preview">
				<p className="kntnt-photo-drop-drop-zone__preview-title">
					{ __( 'Photo Drop Zone', 'kntnt-photo-drop' ) }
				</p>
				{ collection === '' && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Select a collection in the block settings to enable uploads.',
							'kntnt-photo-drop'
						) }
					</Notice>
				) }
				{ isDangling && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: the missing collection slug. */
							__(
								'The collection “%s” no longer exists. Choose another in the block settings.',
								'kntnt-photo-drop'
							),
							collection
						) }
					</Notice>
				) }
				{ selected !== null && (
					<p className="kntnt-photo-drop-drop-zone__preview-target">
						{ sprintf(
							/* translators: %s: the collection's display name. */
							__(
								'Uploads go into the “%s” collection.',
								'kntnt-photo-drop'
							),
							selected.name
						) }
					</p>
				) }
				<p className="kntnt-photo-drop-drop-zone__preview-note">
					{ __(
						'The live uploader appears on the published page for users who can upload files.',
						'kntnt-photo-drop'
					) }
				</p>
			</div>
		</div>
	);
}
