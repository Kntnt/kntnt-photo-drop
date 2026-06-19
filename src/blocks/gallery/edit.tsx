/**
 * Photo Gallery edit component.
 *
 * The Gallery is a *select-only consumer* of collections (ADR-0002): the editor
 * chooses a collection and a start path, and tunes presentation, but never
 * creates or reconfigures a collection. This component owns the inspector — a
 * Collection panel (selector from the editor REST list, start-path control, and a
 * "this folder only" toggle), an Ordering panel, a Layout panel whose revealed
 * controls depend on the mode, a Lightbox panel, an Overlays panel (one control
 * group per overlay — breadcrumbs, download, add-to-media, trash — each a
 * visibility and a nine-point position, with the breadcrumb's hide-count and
 * separator and one shared icon size, and the mutual band-exclusion between the
 * breadcrumb and the icon positions; ADR-0015), and a Slideshow panel — plus an
 * in-canvas `ServerSideRender` preview, so the canvas shows the same markup
 * `Render_Gallery` emits on the frontend. The preview runs in editor-preview
 * mode: it sends the render-time-only `isEditorPreview` flag so the server caps
 * the figures and suppresses the lightbox (clicks stay inert; a collection of
 * thousands never floods the canvas). When there is nothing to render — no
 * collection chosen, a dangling slug, an empty collection, or while the preview
 * loads — a grid of grey placeholders stands in for the gallery rather than a
 * bare notice.
 *
 * The collection list comes from the editor-only endpoint
 * `kntnt-photo-drop/v1/collections` (gated by `edit_posts`), the same list the
 * Drop Zone uses; it is fetched once per mounted block.
 *
 * @since 0.6.0
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
	RangeControl,
	// The package exports UnitControl and NumberControl only under their
	// experimental names; these aliased imports are what core blocks themselves
	// do.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalNumberControl as NumberControl,
	Disabled,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';
import type { JSX } from '@wordpress/element';

import type {
	GalleryAttributes,
	GalleryLayout,
	OverlayPosition,
	OverlayVisibility,
	SlideshowTrigger,
} from './attributes';
import { bandOf, disabledPositions, type OverlayBand } from './overlay-bands';

/**
 * One collection as returned by the editor list endpoint.
 *
 * Mirrors the payload `Rest\Collections_Controller::list_collections()` emits;
 * the Gallery reads only the slug and display name to drive its selector.
 *
 * @since 0.6.0
 */
interface CollectionSummary {
	readonly slug: string;
	readonly name: string;
}

/**
 * The fetch state of the shared collection list.
 *
 * @since 0.6.0
 */
type CollectionsState =
	| { readonly status: 'loading' }
	| { readonly status: 'error' }
	| {
			readonly status: 'loaded';
			readonly collections: readonly CollectionSummary[];
	  };

/**
 * The editor REST path the collection list is fetched from.
 *
 * @since 0.6.0
 */
const LIST_PATH = '/kntnt-photo-drop/v1/collections';

/**
 * How many grey placeholders the empty/loading preview shows.
 *
 * Matches the server's editor-preview figure cap so a populated preview and an
 * empty one occupy the same footprint in the canvas.
 *
 * @since 0.4.0
 */
const PREVIEW_PLACEHOLDER_COUNT = 6;

/**
 * A calm grid of grey placeholder tiles for the editor preview.
 *
 * Shown when there is nothing to render — no collection chosen, a dangling slug,
 * an empty collection, or while the server preview loads — so the canvas reads as
 * a gallery-in-waiting rather than a notice or a blank frame. It is purely
 * decorative: aria-hidden, no images, no interactivity.
 *
 * @since 0.4.0
 *
 * @return The placeholder grid markup.
 */
function PreviewPlaceholders(): JSX.Element {
	return (
		<div
			className="kntnt-photo-drop-gallery-editor__placeholders"
			aria-hidden="true"
		>
			{ Array.from( { length: PREVIEW_PLACEHOLDER_COUNT } ).map(
				( _, index ) => (
					<div
						key={ index }
						className="kntnt-photo-drop-gallery-editor__placeholder"
					/>
				)
			) }
		</div>
	);
}

/**
 * The four overlay visibility options, paired with their translated labels.
 *
 * @since 0.15.0
 *
 * @return The visibility select options.
 */
function visibilityOptions(): { value: OverlayVisibility; label: string }[] {
	return [
		{ value: 'off', label: __( 'Off', 'kntnt-photo-drop' ) },
		{ value: 'thumbnail', label: __( 'Thumbnail', 'kntnt-photo-drop' ) },
		{ value: 'full', label: __( 'Lightbox', 'kntnt-photo-drop' ) },
		{ value: 'both', label: __( 'Both', 'kntnt-photo-drop' ) },
	];
}

/**
 * The nine position options, paired with their translated labels.
 *
 * @since 0.15.0
 *
 * @return The position select options.
 */
function positionOptions(): { value: OverlayPosition; label: string }[] {
	return [
		{ value: 'top-left', label: __( 'Top left', 'kntnt-photo-drop' ) },
		{ value: 'top-center', label: __( 'Top centre', 'kntnt-photo-drop' ) },
		{ value: 'top-right', label: __( 'Top right', 'kntnt-photo-drop' ) },
		{
			value: 'middle-left',
			label: __( 'Middle left', 'kntnt-photo-drop' ),
		},
		{
			value: 'middle-center',
			label: __( 'Middle centre', 'kntnt-photo-drop' ),
		},
		{
			value: 'middle-right',
			label: __( 'Middle right', 'kntnt-photo-drop' ),
		},
		{
			value: 'bottom-left',
			label: __( 'Bottom left', 'kntnt-photo-drop' ),
		},
		{
			value: 'bottom-center',
			label: __( 'Bottom centre', 'kntnt-photo-drop' ),
		},
		{
			value: 'bottom-right',
			label: __( 'Bottom right', 'kntnt-photo-drop' ),
		},
	];
}

/**
 * The props of one overlay's visibility + position control group.
 *
 * @since 0.15.0
 */
interface OverlayControlProps {
	/** The control group's translated heading. */
	readonly label: string;
	/** The overlay's current visibility. */
	readonly visibility: OverlayVisibility;
	/** The overlay's current position. */
	readonly position: OverlayPosition;
	/** The positions disabled because the other overlays occupy their band. */
	readonly disabled: readonly OverlayPosition[];
	/** Commits a new visibility. */
	readonly onVisibility: ( value: OverlayVisibility ) => void;
	/** Commits a new position. */
	readonly onPosition: ( value: OverlayPosition ) => void;
	/** Optional extra controls (the breadcrumb's hide-count and separator). */
	readonly children?: JSX.Element;
}

/**
 * One overlay's inspector control group: a visibility select and a position
 * select, the position options of any band-excluded position disabled.
 *
 * The position select is shown only when the overlay is not off (an off overlay
 * has nowhere to sit), and a disabled position carries a note so the builder
 * sees why a band is unavailable — the mutual band-exclusion between the
 * breadcrumb and the icons (ADR-0015).
 *
 * @since 0.15.0
 *
 * @param props - The control group props.
 * @return The control group markup.
 */
function OverlayControl( props: OverlayControlProps ): JSX.Element {
	const { label, visibility, position, disabled, onVisibility, onPosition } =
		props;
	const isOff = visibility === 'off';
	const options = positionOptions().map( ( option ) => ( {
		...option,
		disabled: disabled.includes( option.value ),
		label: disabled.includes( option.value )
			? /* translators: %s: a nine-point position label. */
			  __( '%s (used by another overlay)', 'kntnt-photo-drop' ).replace(
					'%s',
					option.label
			  )
			: option.label,
	} ) );

	return (
		<div className="kntnt-photo-drop-gallery-editor__overlay">
			<p className="kntnt-photo-drop-gallery-editor__overlay-label">
				{ label }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Show', 'kntnt-photo-drop' ) }
				value={ visibility }
				options={ visibilityOptions() }
				onChange={ ( value: string ) =>
					onVisibility( value as OverlayVisibility )
				}
			/>
			{ ! isOff && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Position', 'kntnt-photo-drop' ) }
					value={ position }
					options={ options }
					onChange={ ( value: string ) =>
						onPosition( value as OverlayPosition )
					}
				/>
			) }
			{ ! isOff && props.children }
		</div>
	);
}

/**
 * Edit component for the Photo Gallery block.
 *
 * Fetches the collection list, drives every inspector panel, and renders the
 * server-side preview plus the empty/dangling notice. Attribute setters are
 * thin pass-throughs so the wiring stays declarative.
 *
 * @since 0.6.0
 *
 * @param props               - Standard block edit props.
 * @param props.attributes    - Current block attributes.
 * @param props.setAttributes - Attribute setter.
 * @return The block's editor markup.
 */
export function GalleryEdit( {
	attributes,
	setAttributes,
}: BlockEditProps< GalleryAttributes > ): JSX.Element {
	const {
		collection,
		startPath,
		recursive,
		order,
		emptyMessage,
		layout,
		minimumColumnWidth,
		imageFit,
		aspectRatio,
		targetRowHeight,
		lightbox,
		slideshow,
		slideshowButtonLabel,
		slideshowSeconds,
		anchor,
		breadcrumbsVisibility,
		breadcrumbsPosition,
		breadcrumbsHideCount,
		breadcrumbsSeparator,
		downloadVisibility,
		downloadPosition,
		addToMediaVisibility,
		addToMediaPosition,
		trashVisibility,
		trashPosition,
		iconSize,
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'kntnt-photo-drop-gallery-editor',
	} );

	// Fetch the editor-only collection list once per mounted block; the endpoint
	// is a cheap directory scan and reflects the filesystem at fetch time.
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

	// Resolve the selected collection's summary; a non-empty slug absent from the
	// loaded list is a dangling reference surfaced as a notice, never auto-cleared.
	const collections = state.status === 'loaded' ? state.collections : [];
	const selected =
		collections.find( ( item ) => item.slug === collection ) ?? null;
	const isDangling =
		state.status === 'loaded' && collection !== '' && selected === null;

	// Build the selector options, prepending an empty prompt and, for a dangling
	// slug, a placeholder labelled with the slug so the persisted state shows.
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
			/* translators: %s: the missing collection slug. */
			label: __( '%s (missing)', 'kntnt-photo-drop' ).replace(
				'%s',
				collection
			),
		} );
	}

	const isGrid = layout === 'grid';

	// Resolve the mutual band-exclusion (ADR-0015). The breadcrumb occupies a whole
	// band, so the bands the visible icon overlays occupy are excluded from the
	// breadcrumb's position picker, and the band the visible breadcrumb occupies is
	// excluded from each icon picker. An overlay turned off frees its band.
	const iconBands: OverlayBand[] = [];
	if ( downloadVisibility !== 'off' ) {
		iconBands.push( bandOf( downloadPosition ) );
	}
	if ( addToMediaVisibility !== 'off' ) {
		iconBands.push( bandOf( addToMediaPosition ) );
	}
	if ( trashVisibility !== 'off' ) {
		iconBands.push( bandOf( trashPosition ) );
	}
	const breadcrumbDisabled = disabledPositions( iconBands );
	const iconDisabled =
		breadcrumbsVisibility !== 'off'
			? disabledPositions( [ bandOf( breadcrumbsPosition ) ] )
			: [];

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
									'Choose which collection this gallery shows.',
									'kntnt-photo-drop'
								) }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Start path', 'kntnt-photo-drop' ) }
								value={ startPath }
								onChange={ ( value: string ) =>
									setAttributes( { startPath: value } )
								}
								help={ __(
									'A sub-folder of the collection to start from. Leave empty for the whole collection.',
									'kntnt-photo-drop'
								) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'This folder only',
									'kntnt-photo-drop'
								) }
								checked={ ! recursive }
								onChange={ ( value: boolean ) =>
									setAttributes( { recursive: ! value } )
								}
								help={ __(
									'When on, sub-folders are not included; only images directly in the start path show.',
									'kntnt-photo-drop'
								) }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Empty-gallery message',
									'kntnt-photo-drop'
								) }
								value={ emptyMessage }
								placeholder={ __(
									'There are currently no images in the gallery. Please try again later.',
									'kntnt-photo-drop'
								) }
								onChange={ ( value: string ) =>
									setAttributes( { emptyMessage: value } )
								}
								help={ __(
									'Shown to every visitor when the chosen collection has no images yet. Leave empty for the default.',
									'kntnt-photo-drop'
								) }
							/>
						</>
					) }
				</PanelBody>

				<PanelBody
					title={ __( 'Ordering', 'kntnt-photo-drop' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'kntnt-photo-drop' ) }
						value={ order }
						options={ [
							{
								value: 'asc',
								label: __( 'Ascending', 'kntnt-photo-drop' ),
							},
							{
								value: 'desc',
								label: __( 'Descending', 'kntnt-photo-drop' ),
							},
						] }
						onChange={ ( value: string ) =>
							setAttributes( {
								order: value === 'desc' ? 'desc' : 'asc',
							} )
						}
						help={ __(
							'A pre-order tree traversal: a folder’s own images come before its sub-folders. Descending reverses within each level.',
							'kntnt-photo-drop'
						) }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Layout', 'kntnt-photo-drop' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout mode', 'kntnt-photo-drop' ) }
						value={ layout }
						options={ [
							{
								value: 'grid',
								label: __( 'Uniform grid', 'kntnt-photo-drop' ),
							},
							{
								value: 'justified',
								label: __(
									'Justified rows',
									'kntnt-photo-drop'
								),
							},
						] }
						onChange={ ( value: string ) =>
							setAttributes( {
								layout: ( value === 'justified'
									? 'justified'
									: 'grid' ) as GalleryLayout,
							} )
						}
					/>
					{ isGrid && (
						<>
							<UnitControl
								__next40pxDefaultSize
								label={ __(
									'Minimum column width',
									'kntnt-photo-drop'
								) }
								value={ minimumColumnWidth }
								onChange={ ( value: string | undefined ) =>
									setAttributes( {
										minimumColumnWidth: value ?? '320px',
									} )
								}
							/>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Image fit', 'kntnt-photo-drop' ) }
								value={ imageFit }
								options={ [
									{
										value: 'cover',
										label: __(
											'Cover (crop to fill)',
											'kntnt-photo-drop'
										),
									},
									{
										value: 'contain',
										label: __(
											'Contain (fit whole image)',
											'kntnt-photo-drop'
										),
									},
								] }
								onChange={ ( value: string ) =>
									setAttributes( {
										imageFit:
											value === 'contain'
												? 'contain'
												: 'cover',
									} )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Aspect ratio',
									'kntnt-photo-drop'
								) }
								value={ aspectRatio }
								onChange={ ( value: string ) =>
									setAttributes( { aspectRatio: value } )
								}
								help={ __(
									'A CSS ratio such as 1, 4/3, or 16/9. Leave empty to use each image’s own ratio.',
									'kntnt-photo-drop'
								) }
							/>
						</>
					) }
					{ ! isGrid && (
						<RangeControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Target row height',
								'kntnt-photo-drop'
							) }
							value={ targetRowHeight }
							min={ 80 }
							max={ 600 }
							onChange={ ( value: number | undefined ) =>
								setAttributes( {
									targetRowHeight: value ?? 240,
								} )
							}
						/>
					) }
					<p className="kntnt-photo-drop-gallery-editor__hint">
						{ __(
							'The gap between items is set under Dimensions → Block spacing.',
							'kntnt-photo-drop'
						) }
					</p>
				</PanelBody>

				<PanelBody
					title={ __( 'Lightbox', 'kntnt-photo-drop' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Lightbox', 'kntnt-photo-drop' ) }
						checked={ lightbox }
						onChange={ ( value: boolean ) =>
							setAttributes( { lightbox: value } )
						}
						help={ __(
							'When on, clicking an image opens it in a modal viewer and gates the “Lightbox” overlay surface. A no-JS link to the full image is always present.',
							'kntnt-photo-drop'
						) }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Overlays', 'kntnt-photo-drop' ) }
					initialOpen={ false }
				>
					<OverlayControl
						label={ __( 'Breadcrumbs', 'kntnt-photo-drop' ) }
						visibility={ breadcrumbsVisibility }
						position={ breadcrumbsPosition }
						disabled={ breadcrumbDisabled }
						onVisibility={ ( value ) =>
							setAttributes( { breadcrumbsVisibility: value } )
						}
						onPosition={ ( value ) =>
							setAttributes( { breadcrumbsPosition: value } )
						}
					>
						<>
							<NumberControl
								__next40pxDefaultSize
								label={ __(
									'Hide first N crumbs',
									'kntnt-photo-drop'
								) }
								value={ breadcrumbsHideCount }
								min={ 0 }
								step={ 1 }
								onChange={ (
									value: string | number | undefined
								) => {
									const parsed =
										typeof value === 'number'
											? value
											: parseInt( value ?? '', 10 );
									setAttributes( {
										breadcrumbsHideCount:
											Number.isFinite( parsed ) &&
											parsed >= 0
												? Math.trunc( parsed )
												: 0,
									} );
								} }
								help={ __(
									'0 shows all, starting with the collection name; 1 hides the collection name; 2 hides it and the next crumb.',
									'kntnt-photo-drop'
								) }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Separator', 'kntnt-photo-drop' ) }
								value={ breadcrumbsSeparator }
								onChange={ ( value: string ) =>
									setAttributes( {
										breadcrumbsSeparator: value,
									} )
								}
							/>
						</>
					</OverlayControl>

					<OverlayControl
						label={ __( 'Download', 'kntnt-photo-drop' ) }
						visibility={ downloadVisibility }
						position={ downloadPosition }
						disabled={ iconDisabled }
						onVisibility={ ( value ) =>
							setAttributes( { downloadVisibility: value } )
						}
						onPosition={ ( value ) =>
							setAttributes( { downloadPosition: value } )
						}
					/>

					<OverlayControl
						label={ __(
							'Add to Media Library',
							'kntnt-photo-drop'
						) }
						visibility={ addToMediaVisibility }
						position={ addToMediaPosition }
						disabled={ iconDisabled }
						onVisibility={ ( value ) =>
							setAttributes( { addToMediaVisibility: value } )
						}
						onPosition={ ( value ) =>
							setAttributes( { addToMediaPosition: value } )
						}
					/>

					<OverlayControl
						label={ __( 'Trash', 'kntnt-photo-drop' ) }
						visibility={ trashVisibility }
						position={ trashPosition }
						disabled={ iconDisabled }
						onVisibility={ ( value ) =>
							setAttributes( { trashVisibility: value } )
						}
						onPosition={ ( value ) =>
							setAttributes( { trashPosition: value } )
						}
					/>

					<UnitControl
						__next40pxDefaultSize
						label={ __( 'Icon size', 'kntnt-photo-drop' ) }
						value={ iconSize }
						onChange={ ( value: string | undefined ) =>
							setAttributes( { iconSize: value ?? '2rem' } )
						}
					/>
					<p className="kntnt-photo-drop-gallery-editor__hint">
						{ __(
							'The overlays share one foreground and background from the Color panel and the breadcrumb font from Typography; per-image borders and shadow come from Border. Add to Media and Trash appear on the published page only for users who can use them.',
							'kntnt-photo-drop'
						) }
					</p>
				</PanelBody>

				<PanelBody
					title={ __( 'Slideshow', 'kntnt-photo-drop' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Slideshow', 'kntnt-photo-drop' ) }
						value={ slideshow }
						options={ [
							{
								value: 'off',
								label: __( 'Off', 'kntnt-photo-drop' ),
							},
							{
								value: 'button',
								label: __(
									'Built-in button',
									'kntnt-photo-drop'
								),
							},
							{
								value: 'custom',
								label: __(
									'Custom element',
									'kntnt-photo-drop'
								),
							},
						] }
						onChange={ ( value: string ) =>
							setAttributes( {
								slideshow: ( value === 'button' ||
								value === 'custom'
									? value
									: 'off' ) as SlideshowTrigger,
							} )
						}
						help={ __(
							'A fullscreen, endlessly looping playback of this gallery. Exit with Escape or the close button.',
							'kntnt-photo-drop'
						) }
					/>
					{ slideshow === 'button' && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Button label', 'kntnt-photo-drop' ) }
							value={ slideshowButtonLabel }
							placeholder={ __(
								'Slideshow',
								'kntnt-photo-drop'
							) }
							onChange={ ( value: string ) =>
								setAttributes( {
									slideshowButtonLabel: value,
								} )
							}
							help={ __(
								'Shown on the quiet button above the gallery. Leave empty for the default.',
								'kntnt-photo-drop'
							) }
						/>
					) }
					{ slideshow === 'custom' && (
						<p className="kntnt-photo-drop-gallery-editor__hint">
							{ __(
								'Any link or button anywhere on the page starts this slideshow when it carries this attribute:',
								'kntnt-photo-drop'
							) }
							<br />
							<code>
								{ anchor
									? `data-kntnt-photo-drop-slideshow="${ anchor }"`
									: 'data-kntnt-photo-drop-slideshow' }
							</code>
							<br />
							{ __(
								'Without a value it targets the page’s first slideshow-enabled gallery. Set an HTML anchor under Advanced to target this gallery explicitly.',
								'kntnt-photo-drop'
							) }
						</p>
					) }
					{ slideshow !== 'off' && (
						<NumberControl
							__next40pxDefaultSize
							label={ __(
								'Seconds per image',
								'kntnt-photo-drop'
							) }
							value={ slideshowSeconds }
							min={ 1 }
							step={ 1 }
							onChange={ (
								value: string | number | undefined
							) => {
								const parsed =
									typeof value === 'number'
										? value
										: parseInt( value ?? '', 10 );
								setAttributes( {
									slideshowSeconds:
										Number.isFinite( parsed ) && parsed >= 1
											? Math.trunc( parsed )
											: 5,
								} );
							} }
							help={ __(
								'How long each image stands fully visible; the dissolve comes on top.',
								'kntnt-photo-drop'
							) }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div className="kntnt-photo-drop-gallery-editor__preview">
				{ selected === null ? (
					<PreviewPlaceholders />
				) : (
					// Wrap the server-rendered preview in Disabled (core's own pattern
					// for SSR blocks, e.g. latest-posts) so a click on a preview
					// thumbnail never navigates the editor canvas — clicking an image
					// in the editor does nothing. The placeholders are already
					// interactivity-free.
					<Disabled>
						<ServerSideRender
							block="kntnt-photo-drop/gallery"
							attributes={ {
								...attributes,
								isEditorPreview: true,
							} }
							EmptyResponsePlaceholder={ PreviewPlaceholders }
							LoadingResponsePlaceholder={ PreviewPlaceholders }
							ErrorResponsePlaceholder={ PreviewPlaceholders }
						/>
					</Disabled>
				) }
			</div>
		</div>
	);
}
