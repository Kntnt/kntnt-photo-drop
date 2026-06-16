<?php
/**
 * The collection descriptor — `collection.json`, the one irreplaceable file.
 *
 * A descriptor is the stored record of a collection's three-rendition settings
 * and its human display name (ADR-0013). The **upload width + quality** are the
 * immutable output contract (the source bytes are discarded once the main image
 * is encoded); the **full** and **thumbnail** width+quality pairs are
 * re-derivable settings; and `pathComponents` is the mutable placement template
 * for Drop Zone uploads (ADR-0014). It is the visible, authoritative file at a
 * collection root; unlike the derived renditions and the per-folder index it is
 * never regenerable, so the rest of the system treats it as ground truth for a
 * collection's shape. This class is both the typed value object and its on-disk
 * codec: it reads and writes `collection.json` as stable, pretty JSON.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Storage;

use Kntnt\Photo_Drop\Collection\Path_Guard;
use Kntnt\Photo_Drop\Collection\Path_Template;
use Kntnt\Photo_Drop\Plugin;

/**
 * An immutable, typed view of a collection's `collection.json`.
 *
 * The nine descriptor fields are exposed as `readonly` promoted properties, so
 * an instance is a faithful in-memory image of the file with no setters and no
 * drift. The external interface is deliberately small: construct one when
 * establishing or rewriting a collection, `read()` one back from disk, and
 * `write()` it out again. The six-rendition defaults that pre-fill a create
 * form live in `Rendition_Defaults`, not here — this class only stores what it
 * is handed — keeping the descriptor a pure value object plus codec.
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
	 * The default Drop Zone placement template (ADR-0014).
	 *
	 * Nests every Drop Zone upload under year/month/day/uploader. Used when a
	 * descriptor omits `pathComponents` (an empty field means the default) and as
	 * the create-form pre-fill. The template is expanded server-side at upload time
	 * by `Path_Template`; the lifecycle surfaces validate it at save through
	 * `normalize_path_components()`.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const DEFAULT_PATH_COMPONENTS = '%year%/%month%/%day%/%uploader%';

	/**
	 * Normalises and validates a raw placement template for storage.
	 *
	 * The save-time gate the admin page and the CLI both call before writing a
	 * descriptor (ADR-0014, "one validator, invoked at two times"). It normalises
	 * the separator structure (an empty result becoming the default template),
	 * enforces the `%`-reservation (any `%` left after the four known placeholders
	 * is rejected, so a mistyped `%moth%` cannot become a literal folder), and runs
	 * the normalised template — expanded with `Path_Template`'s sample values —
	 * through the `Path_Guard` *lexical* checks, so an unsafe template such as
	 * `%year%/../../x` is rejected on submit rather than silently breaking every
	 * later upload. Only the lexical half of the guard is reused (it needs no
	 * existing collection directory); the realpath confinement runs at upload time.
	 * Returns the canonical template to store, or `false` when the template must be
	 * rejected.
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw The raw template value from a lifecycle surface.
	 * @return string|false The canonical template to store, or false when rejected.
	 */
	public static function normalize_path_components( string $raw ): string|false {

		// Canonicalise the separator structure first; an empty field becomes the
		// default template, since an empty field means the default (ADR-0014).
		$normalised = Path_Template::normalise( $raw );

		// Enforce the `%`-reservation: a stray `%` after the four known placeholders
		// is a typo or unknown token and is rejected rather than stored as a literal.
		if ( Path_Template::has_stray_placeholder( $normalised ) ) {
			return false;
		}

		// Run the sample-expanded template through the guard's lexical checks, so a
		// traversal, absolute path, backslash, or NUL in a literal segment is caught
		// at save — the same validator the upload path reuses, never re-implemented.
		if ( ! Path_Guard::is_lexically_safe( Path_Template::sample_expansion( $normalised ) ) ) {
			return false;
		}

		return $normalised;

	}

	/**
	 * Constructs a descriptor from already-resolved field values.
	 *
	 * Callers resolve each value first — the lifecycle surfaces (the admin page
	 * and the CLI) default any omitted rendition field through `Rendition_Defaults`
	 * and normalise the path-components template — then hand the concrete nine
	 * fields here. `upload_width` is nullable (`null` = the source's own
	 * dimensions); every other width and quality is a concrete integer.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name              The human display name.
	 * @param int|null $upload_width      The immutable upload-width ceiling, or null for the source's own dimensions.
	 * @param int      $upload_quality    The immutable upload (main) WebP quality (0–100).
	 * @param int      $full_width        The re-derivable full-image width.
	 * @param int      $full_quality      The re-derivable full-image WebP quality (0–100).
	 * @param int      $thumbnail_width   The re-derivable thumbnail width.
	 * @param int      $thumbnail_quality The re-derivable thumbnail WebP quality (0–100).
	 * @param string   $path_components   The mutable Drop Zone placement template.
	 */
	public function __construct(
		public string $name,
		public ?int $upload_width,
		public int $upload_quality,
		public int $full_width,
		public int $full_quality,
		public int $thumbnail_width,
		public int $thumbnail_quality,
		public string $path_components,
	) {}

	/**
	 * Reads and decodes a `collection.json` from a collection root.
	 *
	 * Returns a typed descriptor, or `null` when the file is missing, unreadable,
	 * or not a JSON object — a degraded state the caller surfaces rather than
	 * crashing on. Fields are coerced defensively so an externally hand-edited
	 * file still yields a sane shape: `uploadWidth` accepts an int or `null` (no
	 * limit); the five remaining width/quality fields coerce to int (a missing or
	 * non-int value reads as `0`, which the doctor and optimiser then surface);
	 * and `pathComponents` falls back to the default template when absent or
	 * non-string, since an empty field means the default (ADR-0014).
	 *
	 * @since 0.1.0
	 *
	 * @param string $collection_path Absolute path to the collection root directory.
	 * @return self|null The decoded descriptor, or null when it cannot be read.
	 */
	public static function read( string $collection_path ): ?self {

		// Read the raw bytes; a missing or unreadable file is a soft failure. The
		// plugin owns this directory tree on disk directly (ADR-0001), so it reads
		// the file rather than routing through the Media Library.
		$file = self::path_for( $collection_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = is_file( $file ) ? file_get_contents( $file ) : false;
		if ( $raw === false ) {
			Plugin::warning( "Cannot read the collection descriptor at {$file}." );
			return null;
		}

		// Decode to an associative array; anything that is not a JSON object is a
		// corrupt descriptor we refuse to interpret.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			Plugin::warning( "The collection descriptor at {$file} is not valid JSON." );
			return null;
		}

		// Coerce each field to its declared type. The upload width is the nullable
		// half of the contract; every other width/quality coerces to int, and the
		// path-components template falls back to the default when absent (ADR-0014).
		$name           = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		$upload_width   = isset( $data['uploadWidth'] ) && is_int( $data['uploadWidth'] ) ? $data['uploadWidth'] : null;
		$upload_quality = self::int_field( $data, 'uploadQuality' );
		$full_width     = self::int_field( $data, 'fullWidth' );
		$full_quality   = self::int_field( $data, 'fullQuality' );
		$thumb_width    = self::int_field( $data, 'thumbnailWidth' );
		$thumb_quality  = self::int_field( $data, 'thumbnailQuality' );
		$stored_path    = $data['pathComponents'] ?? null;
		$path           = is_string( $stored_path ) && $stored_path !== ''
			? $stored_path
			: self::DEFAULT_PATH_COMPONENTS;

		return new self(
			$name,
			$upload_width,
			$upload_quality,
			$full_width,
			$full_quality,
			$thumb_width,
			$thumb_quality,
			$path,
		);

	}

	/**
	 * Writes this descriptor to a collection root as stable, pretty JSON.
	 *
	 * The key order is fixed (`schema`, `name`, the upload/full/thumbnail
	 * width+quality pairs, `pathComponents`) and the output is pretty-printed with
	 * unescaped slashes and unicode, so the file is human-readable and a re-write
	 * with unchanged data produces byte-identical output — keeping diffs and any
	 * content-hash comparison stable. The file is published through `Atomic_Writer`,
	 * so a reader (or a crash) only ever observes the old descriptor or the
	 * complete new one, never a torn or truncated file. Returns whether the write
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
	 * @return array{schema:int,name:string,uploadWidth:int|null,uploadQuality:int,fullWidth:int,fullQuality:int,thumbnailWidth:int,thumbnailQuality:int,pathComponents:string}
	 */
	public function to_array(): array {
		return [
			'schema'           => self::SCHEMA,
			'name'             => $this->name,
			'uploadWidth'      => $this->upload_width,
			'uploadQuality'    => $this->upload_quality,
			'fullWidth'        => $this->full_width,
			'fullQuality'      => $this->full_quality,
			'thumbnailWidth'   => $this->thumbnail_width,
			'thumbnailQuality' => $this->thumbnail_quality,
			'pathComponents'   => $this->path_components,
		];
	}

	/**
	 * Reads an integer field from a decoded descriptor, defaulting to zero.
	 *
	 * A missing or non-integer value reads as `0` rather than aborting the read —
	 * a degenerate width or quality surfaces downstream (the doctor flags it, the
	 * optimiser refuses it) rather than blanking the whole collection on a
	 * hand-edit.
	 *
	 * @since 0.7.0
	 *
	 * @param array<array-key,mixed> $data The decoded descriptor.
	 * @param string                 $key  The integer field name.
	 * @return int The coerced integer value, or 0 when absent or non-integer.
	 */
	private static function int_field( array $data, string $key ): int {
		return isset( $data[ $key ] ) && is_int( $data[ $key ] ) ? $data[ $key ] : 0;
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

}
