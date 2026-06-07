<?php
/**
 * The per-folder index value object — a folder's images, dimensions, subdirs,
 * and the directory mtime it was built against.
 *
 * Each content folder in a collection carries one hidden `index.json` (inside
 * `.kntnt-thumbnails/`). This immutable value object is its in-memory image:
 * the main images present (with the pixel dimensions the gallery needs for
 * `srcset` and `aspect-ratio`), the folder's sub-directories, and the
 * `dirMtime` that stamps which directory state the data reflects. The index is
 * a regenerable cache, never authoritative — the directory is the truth — so
 * the reading, validating, and rebuilding all live in `Index_Store`, which
 * hands back one of these. This class only models the data and its JSON shape.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

/**
 * An immutable `{ schema, dirMtime, subdirs, images }` index for one folder.
 *
 * Produced by `Index_Store::get_or_rebuild()` and consumed by the gallery
 * (issue #10), which reads `images` for dimensions and `subdirs` for a
 * recursive walk. The value object carries no I/O: it is a faithful, setter-free
 * view of the folder's index, with `images` always sorted ascending by filename.
 *
 * @since 0.1.0
 */
final readonly class Index {

	/**
	 * The hidden directory that corrals all regenerable artifacts in a folder.
	 *
	 * Thumbnails live at `<folder>/.kntnt-thumbnails/<width>/<name>.webp` and
	 * the index at `<folder>/.kntnt-thumbnails/index.json`. The name is
	 * dot-hidden but namespaced so a user's own `.thumbnails` is foreign to us
	 * (ADR-0003).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const THUMBNAILS_DIRNAME = '.kntnt-thumbnails';

	/**
	 * The index filename inside the hidden thumbnails directory.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const FILENAME = 'index.json';

	/**
	 * The index schema version recorded in every written file.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const SCHEMA = 1;

	/**
	 * Constructs an index value object from its already-validated parts.
	 *
	 * Callers go through `Index_Store`, which sorts the images and stats the
	 * folder before constructing one of these; the constructor assumes its
	 * inputs are already canonical.
	 *
	 * @since 0.1.0
	 *
	 * @param int                    $dir_mtime The folder mtime the data reflects.
	 * @param array<int,string>      $subdirs   The folder's sub-directory names, sorted ascending.
	 * @param array<int,Image_Entry> $images    The folder's main images, sorted ascending by filename.
	 */
	public function __construct(
		public int $dir_mtime,
		public array $subdirs,
		public array $images,
	) {}

	/**
	 * Returns the index as its on-disk associative array.
	 *
	 * The key order is fixed (`schema`, `dirMtime`, `subdirs`, `images`) so the
	 * written file is deterministic and a re-write with unchanged data is
	 * byte-identical.
	 *
	 * @since 0.1.0
	 *
	 * @return array{schema:int,dirMtime:int,subdirs:array<int,string>,images:array<int,array{file:string,width:int,height:int}>}
	 */
	public function to_array(): array {
		return [
			'schema'   => self::SCHEMA,
			'dirMtime' => $this->dir_mtime,
			'subdirs'  => $this->subdirs,
			'images'   => array_map( static fn ( Image_Entry $entry ): array => $entry->to_array(), $this->images ),
		];
	}

}
