<?php
/**
 * One image row inside a per-folder index: filename plus stored dimensions.
 *
 * The index records, for each main image in a folder, the stored filename and
 * the pixel `width`/`height` measured once at build time. Those dimensions are
 * what let the gallery emit `srcset` candidates and set an `aspect-ratio` (so
 * there is zero layout shift) without re-opening a single image file at render
 * time. This immutable value object is that row.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * An immutable `{ file, width, height }` entry within an index.
 *
 * The `file` is the stored main-image filename relative to its folder (the
 * `<original>.webp` form), never a path; `width` and `height` are its pixel
 * dimensions. Instances are produced by the `Index` rebuild and consumed by the
 * gallery; they are value objects with no behaviour beyond JSON shape mapping.
 *
 * @since 0.1.0
 */
final readonly class Image_Entry {

	/**
	 * Constructs an entry from a filename and its measured dimensions.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file   The stored main-image filename, relative to its folder.
	 * @param int    $width  The image width in pixels.
	 * @param int    $height The image height in pixels.
	 */
	public function __construct(
		public string $file,
		public int $width,
		public int $height,
	) {}

	/**
	 * Returns the entry as its on-disk associative array.
	 *
	 * @since 0.1.0
	 *
	 * @return array{file:string,width:int,height:int}
	 */
	public function to_array(): array {
		return [
			'file'   => $this->file,
			'width'  => $this->width,
			'height' => $this->height,
		];
	}

}
