<?php
/**
 * One resolved image in a gallery walk — its relative path and dimensions.
 *
 * The gallery flattens a collection's tree into a single ordered list of images
 * (ADR-0005). Each entry the walk yields is one of these: the main image's
 * stored filename, the folder-relative path the image sits at (so the walk can
 * order by full relative path and so a path-breadcrumb caption can be derived),
 * and the pixel dimensions the index measured once. The dimensions are what let
 * the renderer emit a `srcset` and an `aspect-ratio` without re-opening a single
 * file at render time.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

/**
 * An immutable resolved gallery image: relative directory, filename, dimensions.
 *
 * Produced by `Gallery_Walker` and consumed by `Render_Gallery` and the pure
 * caption / srcset / justified-layout helpers. The `relative_dir` is the
 * collection-root-relative directory the image lives in (`''` at the root, never
 * with a leading or trailing slash); `file` is the stored `<original>.webp`
 * filename. The full relative path the walk orders by is `relative_dir` plus
 * `file`, which `relative_path()` assembles.
 *
 * @since 0.6.0
 */
final readonly class Gallery_Item {

	/**
	 * Constructs a resolved gallery image from its parts.
	 *
	 * @since 0.6.0
	 *
	 * @param string $relative_dir The collection-root-relative directory; `''` at the root.
	 * @param string $file         The stored `<original>.webp` filename.
	 * @param int    $width        The main image width in pixels.
	 * @param int    $height       The main image height in pixels.
	 */
	public function __construct(
		public string $relative_dir,
		public string $file,
		public int $width,
		public int $height,
	) {}

	/**
	 * Returns the image's full path relative to the collection root.
	 *
	 * This is the key the gallery orders by (natural sort), so each folder's
	 * images stay contiguous in the flattened list. A root-level image has no
	 * directory prefix, so the path is just the filename.
	 *
	 * @since 0.6.0
	 *
	 * @return string The collection-root-relative path, e.g. `morning/sunrise.jpg.webp`.
	 */
	public function relative_path(): string {
		return $this->relative_dir === '' ? $this->file : $this->relative_dir . '/' . $this->file;
	}

}
