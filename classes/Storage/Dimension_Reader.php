<?php
/**
 * The seam through which the index learns a main image's pixel dimensions.
 *
 * Reading a file's width and height is the one expensive, side-effecting step
 * the index performs, and the entire point of the `dirMtime` self-heal is to
 * avoid it on the cache-hit path. Pulling it behind this interface lets a test
 * inject a counting or stubbed reader and assert that a trusted index reads
 * *no* dimensions, while production binds the GD/`getimagesize`-backed reader.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * Resolves the pixel dimensions of an image file on disk.
 *
 * A single-method port. Implementations return `[ $width, $height ]` for a
 * readable image, or `null` when the file is missing or undecodable — the index
 * skips an entry it cannot measure rather than recording a zero-sized image.
 *
 * @since 0.1.0
 */
interface Dimension_Reader {

	/**
	 * Returns the pixel dimensions of an image file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @return array{0:int,1:int}|null `[ $width, $height ]`, or null when unreadable.
	 */
	public function dimensions( string $file_path ): ?array;

}
