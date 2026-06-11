<?php
/**
 * Pure parsing and validation of the collection command's flag inputs.
 *
 * The WP-CLI command above is deliberately thin: every decidable rule that does
 * not touch WP-CLI or the filesystem lives here, in a small, dependency-free
 * value helper that can be unit-tested directly. That includes parsing the
 * `--max-width` flag (with its `none` → `null` "no limit" form), bounding
 * `--quality` to 0–100, defaulting the display name from the slug, and spotting
 * an immutable-contract flag passed to `update`. Keeping these off the command
 * also keeps them off WP-CLI's subcommand reflection, so only the real verbs
 * (`create`, `update`, `delete`) surface as subcommands.
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
	 * The literal `--max-width` value that maps to "no limit" (`null`).
	 *
	 * The contract is irreversible, so a max width must be stated explicitly;
	 * this keyword is the one explicit way to say "do not cap width" without a
	 * silent default freezing the contract.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const NO_LIMIT_KEYWORD = 'none';

	/**
	 * The flags fixed at establishment and rejected on `update`.
	 *
	 * The two output-contract flags (`max-width`, `quality`) plus the placement
	 * rule `uploader-folders` are all set once when the collection is created and
	 * have no update path (ADR-0002, ADR-0008). Probed in this fixed order so the
	 * update error names a stable offender.
	 *
	 * @since 0.2.0
	 * @var array<int,string>
	 */
	private const IMMUTABLE_FLAGS = [ 'max-width', 'quality', 'uploader-folders' ];

	/**
	 * Parses the `--max-width` flag into the contract's nullable ceiling.
	 *
	 * Accepts the literal "none" (case-insensitive) as the explicit "no limit"
	 * form, mapping it to `null`; otherwise the value must be a strictly positive
	 * integer. Returns `false` for any other input so the caller can report a
	 * precise error rather than freezing a contract from a malformed value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The raw flag value.
	 * @return int|null|false The pixel ceiling, null for "no limit", or false when invalid.
	 */
	public function parse_max_width( string $value ): int|null|false {

		// The keyword maps to "no limit"; matched case-insensitively so "None" and
		// "NONE" are equally accepted.
		if ( strtolower( $value ) === self::NO_LIMIT_KEYWORD ) {
			return null;
		}

		// Otherwise demand a strictly positive integer: a width is a pixel count,
		// and zero or a negative is not a meaningful ceiling.
		if ( preg_match( '/^[1-9][0-9]*$/', $value ) !== 1 ) {
			return false;
		}

		return (int) $value;

	}

	/**
	 * Parses the `--quality` flag into a WebP quality in the range 0–100.
	 *
	 * Returns `false` for any non-integer or out-of-range value so the caller can
	 * report it precisely. Zero is permitted (a degenerate but valid quality);
	 * the ceiling is 100.
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
	 * Parses the `--uploader-folders` flag into the placement-rule boolean.
	 *
	 * The placement rule is fixed at establishment (ADR-0008) and defaults to on,
	 * so an absent flag (`null`) resolves to `true`. The command folds WP-CLI's
	 * argument shapes to a string first: a bare `--uploader-folders` and
	 * `--no-uploader-folders` reach this as `"true"`/`"false"`, an explicit
	 * `--uploader-folders=<value>` as that value. This accepts the common truthy
	 * and falsy spellings and returns the parsed boolean; an unrecognised value
	 * yields `null`, distinct from a valid `false`, so the caller can report a
	 * precise error rather than freezing the placement rule from a typo.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The raw flag value, or null when the flag is absent.
	 * @return bool|null The placement-rule boolean, or null when a present value is undecidable.
	 */
	public function parse_uploader_folders( ?string $value ): ?bool {

		// An absent flag keeps the default-on placement rule (ADR-0008).
		if ( $value === null ) {
			return true;
		}

		// Decide on the common truthy and falsy spellings; an unrecognised value
		// is undecidable (null), kept distinct from a valid false.
		return match ( strtolower( $value ) ) {
			'1', 'true', 'yes', 'on' => true,
			'0', 'false', 'no', 'off', '' => false,
			default => null,
		};

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
	 * Returns the first establishment-fixed flag present in the arguments, if any.
	 *
	 * The output contract (`max-width`, `quality`) and the placement rule
	 * (`uploader-folders`) are all fixed when the collection is created; any of
	 * them appearing on `update` is an attempt to change a frozen, irreversible
	 * value (ADR-0002, ADR-0008). Returns the offending flag name so the caller
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
