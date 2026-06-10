<?php
/**
 * The collection descriptor — `collection.json`, the one irreplaceable file.
 *
 * A descriptor is the stored record of a collection's immutable output contract
 * (`maxWidth`, `quality`), its human display `name`, and the `thumbnailWidths`
 * currently in use. It is the visible, authoritative file at a collection root;
 * unlike thumbnails and the per-folder index it is never regenerable, so it is
 * the file the rest of the system treats as ground truth for a collection's
 * shape. This class is both the typed value object and its on-disk codec: it
 * reads and writes `collection.json` as stable, pretty JSON.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

use Kntnt\Photo_Drop\Plugin;

/**
 * An immutable, typed view of a collection's `collection.json`.
 *
 * The five descriptor fields are exposed as `readonly` promoted properties, so
 * an instance is a faithful in-memory image of the file with no setters and no
 * drift. The external interface is deliberately small: construct one from the
 * filter (`from_filter()`) when establishing a collection, `read()` one back
 * from disk, and `write()` it out again. `thumbnailWidths` is always normalised
 * to a sorted, de-duplicated, positive-int list — `[]` meaning "no thumbnail" —
 * so every consumer (the gallery's `srcset`, the doctor's reconciliation) sees
 * one canonical shape regardless of what the filter returned.
 *
 * @since 0.1.0
 */
final readonly class Descriptor {

	/**
	 * The descriptor filename at a collection root.
	 *
	 * Mirrors the discovery contract: a directory is a collection if, and only
	 * if, it holds a file by this name (see ADR-0003 and the Repository).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const FILENAME = 'collection.json';

	/**
	 * The descriptor schema version recorded in every written file.
	 *
	 * Bumped only when the on-disk shape changes incompatibly. Carried so a
	 * future reader can recognise and migrate an older record rather than
	 * silently misreading it.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const SCHEMA = 1;

	/**
	 * The filter that supplies the thumbnail width(s) for a new collection.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const THUMBNAIL_WIDTH_FILTER = 'kntnt_photo_drop_thumbnail_width';

	/**
	 * The default thumbnail width when the filter is unset (see ADR-0002).
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const DEFAULT_THUMBNAIL_WIDTH = 640;

	/**
	 * Constructs a descriptor from already-validated field values.
	 *
	 * Callers normally do not invoke this directly: they go through
	 * `from_filter()` (establishing a collection) or `read()` (loading one),
	 * both of which normalise their inputs first. The constructor itself
	 * assumes `thumbnail_widths` is already canonical (sorted, unique, positive).
	 *
	 * @since 0.1.0
	 *
	 * @param string         $name             The human display name.
	 * @param int|null       $max_width        The contract ceiling in pixels, or null for no limit.
	 * @param int            $quality          The WebP compression quality (0–100).
	 * @param array<int,int> $thumbnail_widths Canonical thumbnail widths; `[]` means no thumbnail.
	 */
	public function __construct(
		public string $name,
		public ?int $max_width,
		public int $quality,
		public array $thumbnail_widths,
	) {}

	/**
	 * Builds a descriptor for a freshly established collection.
	 *
	 * The display name and the two immutable contract values come from the
	 * caller (the admin page or the CLI's `collection create`); the thumbnail
	 * widths are resolved here from the `kntnt_photo_drop_thumbnail_width`
	 * filter and normalised, because thumbnail width is a setting derived
	 * outside the contract (ADR-0002), not something the caller fixes by hand.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name      The human display name.
	 * @param int|null $max_width The contract ceiling in pixels, or null for no limit.
	 * @param int      $quality   The WebP compression quality (0–100).
	 * @return self
	 */
	public static function from_filter( string $name, ?int $max_width, int $quality ): self {

		// Resolve the thumbnail width(s) from the filter and canonicalise. The
		// filter may return a single int, a list of ints, or `[]`/`0` for "no
		// thumbnail"; normalisation collapses all of those to one sorted list.
		$filtered = apply_filters( self::THUMBNAIL_WIDTH_FILTER, self::DEFAULT_THUMBNAIL_WIDTH );
		$widths   = self::normalise_widths( $filtered );

		return new self( $name, $max_width, $quality, $widths );

	}

	/**
	 * Reads and decodes a `collection.json` from a collection root.
	 *
	 * Returns a typed descriptor, or `null` when the file is missing, unreadable,
	 * or not valid JSON of the expected shape — a degraded state the caller
	 * surfaces rather than crashing on. Every field is coerced defensively:
	 * `maxWidth` accepts an int or `null`; `thumbnailWidths` is re-normalised on
	 * read so an externally hand-edited file still yields the canonical shape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $collection_path Absolute path to the collection root directory.
	 * @return self|null The decoded descriptor, or null when it cannot be read.
	 */
	public static function read( string $collection_path ): ?self {

		// Read the raw bytes; a missing or unreadable file is a soft failure.
		// The plugin owns this directory tree on disk directly (ADR-0001), so it
		// reads the file rather than routing through the Media Library.
		$file = self::path_for( $collection_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = is_file( $file ) ? file_get_contents( $file ) : false;
		if ( $raw === false ) {
			Plugin::warning( "Cannot read the collection descriptor at {$file}." );
			return null;
		}

		// Decode to an associative array; anything that is not a JSON object is
		// a corrupt descriptor we refuse to interpret.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Plugin::warning( "The collection descriptor at {$file} is not valid JSON." );
			return null;
		}

		// Coerce each field to its declared type, re-normalising the thumbnail
		// widths so a hand-edited file still lands in the canonical shape.
		$name      = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		$max_width = isset( $data['maxWidth'] ) && is_int( $data['maxWidth'] ) ? $data['maxWidth'] : null;
		$quality   = isset( $data['quality'] ) && is_int( $data['quality'] ) ? $data['quality'] : 0;
		$widths    = self::normalise_widths( $data['thumbnailWidths'] ?? [] );

		return new self( $name, $max_width, $quality, $widths );

	}

	/**
	 * Writes this descriptor to a collection root as stable, pretty JSON.
	 *
	 * The key order is fixed (`schema`, `name`, `maxWidth`, `quality`,
	 * `thumbnailWidths`) and the output is pretty-printed with unescaped slashes
	 * and unicode, so the file is human-readable and a re-write with unchanged
	 * data produces byte-identical output — keeping diffs and any content-hash
	 * comparison stable. The file is published through `Atomic_Writer`, so a
	 * reader (or a crash) only ever observes the old descriptor or the complete
	 * new one, never a torn or truncated file. Returns whether the write
	 * succeeded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $collection_path Absolute path to the collection root directory.
	 * @return bool True when the file was written, false on failure.
	 */
	public function write( string $collection_path ): bool {

		// Encode in a fixed key order so the output is deterministic. A failed
		// encode (non-UTF-8 in the name, say) is logged and reported, never
		// written as a half-formed file.
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json  = wp_json_encode( $this->to_array(), $flags );
		if ( $json === false ) {
			Plugin::error( "Failed to encode the collection descriptor for {$collection_path}." );
			return false;
		}

		// Publish the bytes atomically. The descriptor is the one irreplaceable
		// file, so a crash or full disk mid-write must never replace the live
		// `collection.json` with a truncated copy — the writer stages a temp
		// file, verifies the full length landed, and renames it into place.
		$file = self::path_for( $collection_path );
		if ( ! Atomic_Writer::write( $file, $json . "\n" ) ) {
			Plugin::error( "Failed to write the collection descriptor at {$file}." );
			return false;
		}

		return true;

	}

	/**
	 * Returns the descriptor as its on-disk associative array.
	 *
	 * Exposed for callers that need the raw shape (an API response, a test
	 * assertion) without re-reading the file. The key order matches what
	 * `write()` emits.
	 *
	 * @since 0.1.0
	 *
	 * @return array{schema:int,name:string,maxWidth:int|null,quality:int,thumbnailWidths:array<int,int>}
	 */
	public function to_array(): array {
		return [
			'schema'          => self::SCHEMA,
			'name'            => $this->name,
			'maxWidth'        => $this->max_width,
			'quality'         => $this->quality,
			'thumbnailWidths' => $this->thumbnail_widths,
		];
	}

	/**
	 * Returns the absolute path of the descriptor inside a collection root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $collection_path Absolute path to the collection root directory.
	 * @return string The absolute `collection.json` path.
	 */
	private static function path_for( string $collection_path ): string {
		return rtrim( $collection_path, '/' ) . '/' . self::FILENAME;
	}

	/**
	 * Canonicalises a filter- or file-supplied thumbnail-width value.
	 *
	 * Accepts a single int, a list of ints, or an empty value, and returns a
	 * sorted, de-duplicated list of positive ints. A `0`, a negative number, or
	 * a non-numeric entry is dropped; `[]`/`0`/`null` therefore collapse to the
	 * empty list, which is the canonical "no thumbnail" marker. Reducing every
	 * input to one shape means downstream code never branches on int-vs-array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The raw thumbnail-width value from the filter or file.
	 * @return array<int,int> The sorted, unique, positive widths; `[]` means no thumbnail.
	 */
	private static function normalise_widths( mixed $value ): array {

		// Treat a scalar as a one-element list so the same loop handles both the
		// single-width and the multi-width filter return.
		$candidates = is_array( $value ) ? $value : [ $value ];

		// Keep only strictly-positive integer widths; anything else (0, negative,
		// non-numeric) is not a usable thumbnail width and is discarded.
		$widths = [];
		foreach ( $candidates as $candidate ) {
			if ( is_int( $candidate ) && $candidate > 0 ) {
				$widths[ $candidate ] = $candidate;
			}
		}

		// Sort ascending, which re-indexes to a clean zero-based array, so the
		// stored order is deterministic.
		sort( $widths );

		return $widths;

	}

}
