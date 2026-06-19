<?php
/**
 * Pure parsing and validation of the collection command's flag inputs.
 *
 * The WP-CLI command is deliberately thin: every decidable rule that does not
 * touch WP-CLI or the filesystem lives here, in a small, dependency-free value
 * helper that can be unit-tested directly. That includes parsing the nullable
 * `--upload-width` flag (with its `none` → `null` "source dimensions" form), the
 * positive-int `--full-width`/`--thumbnail-width` flags, bounding every quality
 * flag to 0–100, defaulting the display name from the slug, and spotting an
 * *immutable* upload-contract flag passed to `update` (ADR-0013). Keeping these
 * off the command also keeps them off WP-CLI's subcommand reflection, so only the
 * real verbs surface as subcommands.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Cli;

/**
 * Stateless parser/validator for the collection lifecycle flags.
 *
 * Every method is pure: it maps raw string flag values to typed results (or to
 * `false` when a value is malformed) without side effects, so the command can
 * translate each result into a `WP_CLI::error()` or proceed. Holding no state,
 * a single instance is safe to reuse.
 *
 * @since 0.2.0
 */
final class Collection_Input {

	/**
	 * The literal `--upload-width` value that maps to "source dimensions" (`null`).
	 *
	 * The upload contract is irreversible, so the ceiling must be stated
	 * explicitly; this keyword is the one explicit way to say "do not cap width"
	 * — store the source's own dimensions — without a silent default freezing it.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const NO_LIMIT_KEYWORD = 'none';

	/**
	 * The smallest sensible rendition width in pixels.
	 *
	 * A width below this value produces a rendered image that is effectively
	 * invisible in any reasonable gallery layout. Both the admin form and the
	 * CLI enforce this floor on every *present* width value; a blank field (which
	 * means "no limit" for upload-width, or "collapse to the tier above" for
	 * full/thumbnail-width) is never coerced and is unaffected by this constant.
	 *
	 * @since 0.15.0
	 * @var int
	 */
	public const MINIMUM_WIDTH = 320;

	/**
	 * The flags fixed at establishment and rejected on `update`.
	 *
	 * Only the upload pair (`upload-width`, `upload-quality`) is the immutable
	 * output contract — the source bytes are discarded once the main is encoded
	 * (ADR-0013). The full/thumbnail and path-components flags are re-derivable or
	 * mutable and are *not* listed here. Probed in this fixed order so the update
	 * error names a stable offender.
	 *
	 * @since 0.2.0
	 * @var array<int,string>
	 */
	private const IMMUTABLE_FLAGS = [ 'upload-width', 'upload-quality' ];

	/**
	 * Parses the `--upload-width` flag into the contract's nullable ceiling.
	 *
	 * Accepts the literal "none" (case-insensitive) as the explicit "source
	 * dimensions" form, mapping it to `null` (unaffected by {@see MINIMUM_WIDTH});
	 * otherwise the value must be a strictly positive integer at or above
	 * {@see MINIMUM_WIDTH}. Returns `false` for any other input so the caller can
	 * report a precise error rather than freezing a contract from a malformed
	 * value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The raw flag value.
	 * @return int|null|false The pixel ceiling (≥ MINIMUM_WIDTH), null for
	 *                        "source dimensions", or false when invalid.
	 */
	public function parse_upload_width( string $value ): int|null|false {

		// The keyword maps to "no limit"; matched case-insensitively so "None" and
		// "NONE" are equally accepted.
		if ( strtolower( $value ) === self::NO_LIMIT_KEYWORD ) {
			return null;
		}

		// Otherwise demand a strictly positive integer: a width is a pixel count,
		// and zero or a negative is not a meaningful ceiling.
		return $this->parse_width( $value );

	}

	/**
	 * Parses a `--full-width`/`--thumbnail-width` flag into a positive integer.
	 *
	 * Unlike the upload width, a derived-rendition width is never unbounded, so
	 * there is no "none" form: the value must be a strictly positive integer at
	 * or above {@see MINIMUM_WIDTH}. Returns `false` for any non-positive,
	 * below-floor, or malformed value.
	 *
	 * @since 0.7.0
	 *
	 * @param string $value The raw flag value.
	 * @return int|false The pixel width (≥ MINIMUM_WIDTH), or false when invalid.
	 */
	public function parse_width( string $value ): int|false {

		// Require a strictly positive integer with no sign, decimal, leading zero,
		// or trailing noise; a width is a plain pixel count.
		if ( preg_match( '/^[1-9][0-9]*$/', $value ) !== 1 ) {
			return false;
		}

		// Reject any value below the floor — a smaller width renders an effectively
		// invisible gallery in any real layout.
		$width = (int) $value;
		return $width >= self::MINIMUM_WIDTH ? $width : false;

	}

	/**
	 * Parses a quality flag into a WebP quality in the range 0–100.
	 *
	 * Returns `false` for any non-integer or out-of-range value so the caller can
	 * report it precisely. Zero is permitted (a degenerate but valid quality);
	 * the ceiling is 100. Shared by every `--*-quality` flag.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The raw flag value.
	 * @return int|false The quality, or false when invalid.
	 */
	public function parse_quality( string $value ): int|false {

		// Require a bare non-negative integer; reject signs, decimals and noise.
		if ( preg_match( '/^[0-9]+$/', $value ) !== 1 ) {
			return false;
		}

		// Bound the value to the WebP quality range.
		$quality = (int) $value;
		if ( $quality > 100 ) {
			return false;
		}

		return $quality;

	}

	/**
	 * Resolves the display name from the optional flag, defaulting from the slug.
	 *
	 * A non-empty `--name` wins as given; otherwise the slug is humanised
	 * (hyphens to spaces, each word capitalised) so a collection always has a
	 * readable display name even when none was supplied.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $name The caller-supplied display name, or null.
	 * @param string      $slug The collection slug to humanise as a fallback.
	 * @return string The resolved display name.
	 */
	public function resolve_name( ?string $name, string $slug ): string {

		// A supplied, non-empty name is taken verbatim.
		if ( $name !== null && $name !== '' ) {
			return $name;
		}

		return $this->humanise_slug( $slug );

	}

	/**
	 * Humanises a slug into a display name: hyphens to spaces, words capitalised.
	 *
	 * `spring-2024-trip` becomes `Spring 2024 Trip`. Purely presentational; the
	 * slug remains the identity.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug The collection slug.
	 * @return string The humanised display name.
	 */
	public function humanise_slug( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Returns the first immutable upload-contract flag present, if any.
	 *
	 * Only the upload pair (`upload-width`, `upload-quality`) is fixed at
	 * establishment — the source bytes are discarded once the main is encoded, so
	 * the ceiling and quality cannot be raised retroactively (ADR-0013). Either of
	 * them appearing on `update` is an attempt to change a frozen, irreversible
	 * value; the full/thumbnail and path-components flags are re-derivable or
	 * mutable and never match here. Returns the offending flag name so the caller
	 * can name it in the error, or `null` when none is present.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string,string|bool> $assoc_args The associative arguments to inspect.
	 * @return string|null The first immutable flag found, or null.
	 */
	public function find_immutable_flag( array $assoc_args ): ?string {

		// Probe the immutable flags in a fixed order so the error is stable.
		foreach ( self::IMMUTABLE_FLAGS as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				return $flag;
			}
		}

		return null;

	}

}
