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
	 * and normalise the path-components template — then hand the nine fields here.
	 * `upload_width` is nullable (`null` = the source's own dimensions). The three
	 * re-derivable knobs `full_width`, `full_quality`, and `thumbnail_width` are
	 * *also* nullable, where `null` means **unset** — collapse to the tier above it
	 * (ADR-0013, #71): an unset full width makes the main itself the full rendition,
	 * an unset full quality follows the upload quality, and an unset thumbnail width
	 * follows the (effective) full width. `effective_renditions()` resolves those
	 * nulls into the concrete widths/qualities every deriver, doctor, and srcset
	 * reads; the raw fields stay nullable so "unset" persists on disk distinct from
	 * a concrete value. `upload_quality` and `thumbnail_quality` are always concrete.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name              The human display name.
	 * @param int|null $upload_width      The immutable upload-width ceiling, or null for the source's own dimensions.
	 * @param int      $upload_quality    The immutable upload (main) WebP quality (0–100).
	 * @param int|null $full_width        The re-derivable full-image width, or null to collapse (main serves full).
	 * @param int|null $full_quality      The re-derivable full-image quality (0–100), or null to follow upload quality.
	 * @param int|null $thumbnail_width   The re-derivable thumbnail width, or null to follow the effective full width.
	 * @param int      $thumbnail_quality The re-derivable thumbnail WebP quality (0–100).
	 * @param string   $path_components   The mutable Drop Zone placement template.
	 */
	public function __construct(
		public string $name,
		public ?int $upload_width,
		public int $upload_quality,
		public ?int $full_width,
		public ?int $full_quality,
		public ?int $thumbnail_width,
		public int $thumbnail_quality,
		public string $path_components,
	) {}

	/**
	 * Resolves the re-derivable knobs into the concrete renditions to derive from.
	 *
	 * The collapse-to-parent rule (ADR-0013, #71): an unset re-derivable field means
	 * "use the tier above", so this turns the descriptor's possibly-null full/thumbnail
	 * settings into the concrete widths and qualities every deriver, doctor, and srcset
	 * acts on. An unset **full width** resolves to `PHP_INT_MAX` — an unbounded ceiling
	 * no main is ever strictly wider than, so no separate full file is ever written and
	 * the main is itself the full rendition at its own width. An unset **full quality**
	 * follows the upload quality (the main's own quality). An unset **thumbnail width**
	 * follows the effective full width — so it collapses into the full when the full is
	 * set, and inherits the same unbounded ceiling (the main serves every role) when the
	 * full is unset too. The thumbnail quality is always concrete and passes through.
	 *
	 * Keeping this resolution in one deep method means the three consumers read the same
	 * effective values from one authority and can never drift, while the stored fields
	 * stay nullable so "unset" survives a write/read cycle distinct from a concrete value.
	 *
	 * @since 0.14.0
	 *
	 * @return array{full_width:int,full_quality:int,thumbnail_width:int,thumbnail_quality:int} The renditions.
	 */
	public function effective_renditions(): array {

		// An unset full width is an unbounded ceiling (no separate full; the main serves
		// the full role), an unset full quality follows the upload quality, and an unset
		// thumbnail width follows the effective full width.
		$full_width      = $this->full_width ?? PHP_INT_MAX;
		$thumbnail_width = $this->thumbnail_width ?? $full_width;

		return [
			'full_width'        => $full_width,
			'full_quality'      => $this->full_quality ?? $this->upload_quality,
			'thumbnail_width'   => $thumbnail_width,
			'thumbnail_quality' => $this->thumbnail_quality,
		];

	}

	/**
	 * Returns a copy with the re-derivable rendition pairs replaced — the flip.
	 *
	 * The "flip" half of regenerate-then-flip (ADR-0013): once a browser-driven
	 * batch has written every main's full and thumbnail at the new widths, the
	 * descriptor's active widths are switched to those targets so the gallery starts
	 * serving them. Only the four re-derivable values change; the immutable upload
	 * contract (`upload_width` + `upload_quality`), the display name, and the mutable
	 * placement template carry over untouched, because the flip never touches the
	 * irreversible pair. Being a fresh value object rather than an in-place mutation
	 * is what makes an interrupted run safe: the live descriptor on disk is never
	 * altered until the caller chooses to `write()` this copy, so a crash before the
	 * write leaves the old renditions serving and a re-run reconciles.
	 *
	 * @since 0.11.0
	 *
	 * @param int|null $full_width        The new full-image width, or null to collapse (main serves the full role).
	 * @param int|null $full_quality      The new full-image WebP quality (0–100), or null to follow the upload quality.
	 * @param int|null $thumbnail_width   The new thumbnail width, or null to follow the effective full width.
	 * @param int      $thumbnail_quality The new thumbnail WebP quality (0–100).
	 * @return self A new descriptor carrying the new re-derivable settings.
	 */
	public function with_renditions(
		?int $full_width,
		?int $full_quality,
		?int $thumbnail_width,
		int $thumbnail_quality,
	): self {
		return new self(
			$this->name,
			$this->upload_width,
			$this->upload_quality,
			$full_width,
			$full_quality,
			$thumbnail_width,
			$thumbnail_quality,
			$this->path_components,
		);
	}

	/**
	 * Reads and decodes a `collection.json` from a collection root.
	 *
	 * Returns a typed descriptor, or `null` when the file is missing, unreadable,
	 * or not a JSON object — a degraded state the caller surfaces rather than
	 * crashing on. Fields are coerced defensively so an externally hand-edited
	 * file still yields a sane shape: `uploadWidth` accepts an int or `null` (no
	 * limit); the re-derivable `fullWidth`, `fullQuality`, and `thumbnailWidth`
	 * accept an int or `null` (unset — collapse to the tier above, ADR-0013/#71),
	 * with a missing or non-int value reading as `null` (unset) too; the always-
	 * concrete `uploadQuality` and `thumbnailQuality` coerce to int (a missing or
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

		// Coerce each field to its declared type. The upload width and the three
		// re-derivable knobs (full width/quality, thumbnail width) are nullable —
		// `null` means unset (ADR-0013/#71); the always-concrete qualities coerce to
		// int; and the path-components template falls back to the default when absent
		// (ADR-0014).
		$name           = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		$upload_width   = self::nullable_int_field( $data, 'uploadWidth' );
		$upload_quality = self::int_field( $data, 'uploadQuality' );
		$full_width     = self::nullable_int_field( $data, 'fullWidth' );
		$full_quality   = self::nullable_int_field( $data, 'fullQuality' );
		$thumb_width    = self::nullable_int_field( $data, 'thumbnailWidth' );
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
	 * @return array{schema:int,name:string,uploadWidth:int|null,uploadQuality:int,fullWidth:int|null,fullQuality:int|null,thumbnailWidth:int|null,thumbnailQuality:int,pathComponents:string}
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
	 * Reads a nullable integer field from a decoded descriptor.
	 *
	 * The reader for the fields where `null` is a meaningful value: the upload width
	 * (no ceiling) and the re-derivable full width/quality and thumbnail width (unset
	 * — collapse to the tier above, ADR-0013/#71). A JSON `null`, a missing key, or a
	 * non-integer value all read as `null` so "unset" survives a hand-edit rather than
	 * silently coercing to a concrete `0` that would freeze a degenerate value.
	 *
	 * @since 0.14.0
	 *
	 * @param array<array-key,mixed> $data The decoded descriptor.
	 * @param string                 $key  The nullable integer field name.
	 * @return int|null The coerced integer value, or null when absent, null, or non-integer.
	 */
	private static function nullable_int_field( array $data, string $key ): ?int {
		return isset( $data[ $key ] ) && is_int( $data[ $key ] ) ? $data[ $key ] : null;
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
