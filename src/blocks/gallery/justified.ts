/**
 * Pure justified-rows flex math for the Gallery editor preview.
 *
 * Mode B lays images out in justified rows of a target height; the frontend math
 * lives in the PHP `Justified_Layout`. The editor preview reproduces it in the
 * browser so the preview's justified rows match the published page. This module
 * is that math as a pure function — no DOM, no React — kept in lockstep with the
 * PHP: an image's natural width at the target height is `aspectRatio × height`,
 * used as the flex basis, with the same aspect ratio as the grow factor; the
 * final, incomplete row is flagged so the preview can left-align it rather than
 * stretch it.
 *
 * @since 0.6.0
 */

/**
 * The stored pixel dimensions of one image.
 *
 * @since 0.6.0
 */
export interface ImageDimensions {
	readonly width: number;
	readonly height: number;
}

/**
 * The computed flex descriptor for one image in a justified row.
 *
 * @since 0.6.0
 */
export interface FlexDescriptor {
	/** The `flex-grow` factor (the aspect ratio). */
	readonly grow: number;
	/** The `flex-basis` in pixels (the natural width at the target height). */
	readonly basis: number;
	/** Whether the image sits in the final, possibly incomplete, row. */
	readonly lastRow: boolean;
}

/**
 * The container width assumed when packing rows, in pixels.
 *
 * @since 0.6.0
 */
export const ASSUMED_CONTAINER_WIDTH = 960;

/**
 * Returns an image's aspect ratio, guarding a non-positive dimension.
 *
 * @since 0.6.0
 *
 * @param image - The image's stored dimensions.
 * @return The aspect ratio, falling back to a square for a corrupt dimension.
 */
function aspectRatio( image: ImageDimensions ): number {
	if ( image.height <= 0 || image.width <= 0 ) {
		return 1;
	}
	return image.width / image.height;
}

/**
 * Computes the flex descriptor for every image, flagging the final row.
 *
 * Greedily packs images into rows until the next image would overflow the
 * assumed container width (accounting for the gap), then begins a new row. Each
 * image gets a grow equal to its aspect ratio and a basis equal to its natural
 * width at the target height; images in the final row are flagged so the caller
 * can pin their grow to zero and left-align the row.
 *
 * @since 0.6.0
 *
 * @param images          - The images' stored dimensions, in order.
 * @param targetRowHeight - The target row height in pixels.
 * @param gap             - The gap between images in pixels.
 * @param containerWidth  - The assumed container width in pixels.
 * @return One flex descriptor per image, in input order.
 */
export function computeJustifiedLayout(
	images: readonly ImageDimensions[],
	targetRowHeight: number,
	gap: number,
	containerWidth: number = ASSUMED_CONTAINER_WIDTH
): FlexDescriptor[] {
	const height = targetRowHeight > 0 ? targetRowHeight : 1;
	const width = containerWidth > 0 ? containerWidth : ASSUMED_CONTAINER_WIDTH;

	// Greedily pack images into rows, tracking each image's row index so a second
	// pass can flag the final row.
	const rowOf: number[] = [];
	const grow: number[] = [];
	const basis: number[] = [];
	let row = 0;
	let rowWidth = 0;
	images.forEach( ( image, index ) => {
		const ratio = aspectRatio( image );
		const natural = ratio * height;
		if ( rowWidth > 0 && rowWidth + gap + natural > width ) {
			row += 1;
			rowWidth = 0;
		}
		rowWidth += ( rowWidth === 0 ? 0 : gap ) + natural;
		rowOf[ index ] = row;
		grow[ index ] = ratio;
		basis[ index ] = natural;
	} );

	// Flag the final row so its images can be left-aligned instead of stretched.
	const lastRowNumber = row;
	return images.map( ( _image, index ) => ( {
		grow: grow[ index ] ?? 1,
		basis: basis[ index ] ?? height,
		lastRow: rowOf[ index ] === lastRowNumber,
	} ) );
}
