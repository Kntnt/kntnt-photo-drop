<?php
/**
 * The Drop Zone placement template — normalise, validate, expand, preview.
 *
 * The descriptor's mutable `pathComponents` string (ADR-0014) determines the
 * sub-path under which Drop Zone uploads are placed: a `/`-separated template
 * with four placeholders — `%year%`, `%month%`, `%day%` (the upload date) and
 * `%uploader%` (the uploader's nicename). This class is the pure home of that
 * template's mechanics, with no WordPress and no filesystem dependency:
 *
 * - `normalise()` canonicalises the separator structure (split on `/`, strip
 *   leading/trailing separators, collapse empty segments), an empty result
 *   falling back to the default template;
 * - `has_stray_placeholder()` enforces the `%`-reservation, flagging any `%`
 *   left after the four known placeholders so a mistyped `%moth%` is rejected
 *   at save rather than becoming a literal folder;
 * - `expand()` performs the server-side substitution at upload time, the caller
 *   supplying the site-timezone upload date and the server-derived nicename so
 *   neither can be spoofed;
 * - `sample_expansion()` is the presentational preview substitution with fixed
 *   sample values — no safety logic, only a visualisation of the resulting path.
 *
 * The lexical safety of a normalised, sample-expanded template is the
 * `Path_Guard`'s job, reused at save time; this class only shapes and expands
 * the string.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Normalises, validates, expands, and previews a placement template.
 *
 * Every method is pure and static: the template is a value, not state, and the
 * four placeholder names are the only knowledge the class carries. The
 * placeholder set is defined once in `PLACEHOLDER_PATTERN` so the stray-`%`
 * check and the expansion never drift, and the sample tokens used by the preview
 * are exposed as constants so the admin form and its tests reference the same
 * values.
 *
 * @since 0.7.0
 */
final class Path_Template {

	/**
	 * The sample year token used in the presentational preview.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const SAMPLE_YEAR = '2024';

	/**
	 * The sample month token used in the presentational preview.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const SAMPLE_MONTH = '06';

	/**
	 * The sample day token used in the presentational preview.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const SAMPLE_DAY = '16';

	/**
	 * The sample uploader token used in the presentational preview.
	 *
	 * A recognisable nicename-shaped sample, so the preview shows the shape of a
	 * real `%uploader%` segment.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const SAMPLE_UPLOADER = 'jane-doe';

	/**
	 * The regex matching exactly the four recognised placeholders.
	 *
	 * The single source of truth for which `%…%` tokens are known. The stray-`%`
	 * check strips these and asks whether any `%` survives; expansion and preview
	 * substitute these and nothing else. Case-sensitive: `%YEAR%` is *not* a known
	 * placeholder, so an uppercase variant is a stray token, never a silent match.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const PLACEHOLDER_PATTERN = '/%(?:year|month|day|uploader)%/';

	/**
	 * Normalises a raw template's separator structure.
	 *
	 * Splits on `/`, drops empty segments (so leading, trailing, and repeated
	 * separators all collapse) and segments that are only whitespace, then rejoins
	 * with a single `/`. An empty result — an empty field, or a value that is
	 * nothing but separators or whitespace — falls back to the default template,
	 * since an empty field means the default and there is no flat-at-root placement
	 * (ADR-0014). This shapes the separator structure only; it neither validates
	 * the `%`-reservation nor checks lexical safety.
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw The raw template value (e.g. from a form field or flag).
	 * @return string The normalised template, or the default when the result is empty.
	 */
	public static function normalise( string $raw ): string {

		// Split on the single separator and keep only the segments that carry a
		// non-whitespace value, collapsing every run of separators and any blank
		// segment in one pass.
		$segments = [];
		foreach ( explode( '/', $raw ) as $segment ) {
			$segment = trim( $segment );
			if ( $segment !== '' ) {
				$segments[] = $segment;
			}
		}

		// An empty result means the default template; otherwise rejoin with one
		// separator so the stored form is canonical regardless of how it was typed.
		return $segments === [] ? Descriptor::DEFAULT_PATH_COMPONENTS : implode( '/', $segments );

	}

	/**
	 * Reports whether any unrecognised `%` survives the four known placeholders.
	 *
	 * Strips every known placeholder and asks whether a `%` remains: a leftover
	 * percent is a typo (`%moth%`), an unknown token (`%hour%`), an unbalanced
	 * marker (`%year`), or a stray literal — none of which may pass, because `%` is
	 * reserved and a stray one must be rejected at save rather than written as a
	 * literal folder (ADR-0014).
	 *
	 * @since 0.7.0
	 *
	 * @param string $template The template to inspect (normalised or raw).
	 * @return bool True when an unrecognised percent remains.
	 */
	public static function has_stray_placeholder( string $template ): bool {

		// Remove the known placeholders, then treat any surviving `%` as a reserved
		// character used illegitimately.
		$without_known = preg_replace( self::PLACEHOLDER_PATTERN, '', $template ) ?? $template;
		return str_contains( $without_known, '%' );

	}

	/**
	 * Expands the four placeholders into a concrete sub-path at upload time.
	 *
	 * `%year%`/`%month%`/`%day%` are taken from the supplied upload date — the
	 * caller passes a `DateTimeImmutable` already in the site timezone, since the
	 * folder is human-facing (ADR-0014) — with month and day zero-padded to two
	 * digits so the folders sort lexically. `%uploader%` is the supplied nicename,
	 * which the caller derives server-side (never client-named) so it cannot be
	 * spoofed. Every occurrence of each placeholder is substituted; literal
	 * segments are left untouched. Any unrecognised `%…%` is left verbatim — the
	 * save-time `%`-reservation is what rejects those, so a stored template only
	 * ever carries the four known tokens.
	 *
	 * @since 0.7.0
	 *
	 * @param string             $template The normalised placement template.
	 * @param \DateTimeImmutable $date     The upload date in the site timezone.
	 * @param string             $uploader The server-derived uploader nicename.
	 * @return string The expanded sub-path (still to be confined by Path_Guard).
	 */
	public static function expand( string $template, \DateTimeImmutable $date, string $uploader ): string {

		// Build the substitution map from the upload date and the nicename, padding
		// month and day to two digits so the resulting folders sort lexically.
		$replacements = [
			'%year%'     => $date->format( 'Y' ),
			'%month%'    => $date->format( 'm' ),
			'%day%'      => $date->format( 'd' ),
			'%uploader%' => $uploader,
		];

		// Substitute every occurrence of each known placeholder, leaving literals
		// and any unrecognised token untouched.
		return strtr( $template, $replacements );

	}

	/**
	 * Substitutes the placeholders with fixed sample values for the live preview.
	 *
	 * Purely presentational: it replaces each of the four placeholders with a
	 * stable, recognisable sample so a builder sees the shape of the resulting path
	 * as they type, and leaves literals and unknown tokens verbatim. It carries no
	 * safety logic and no real date — the save-time stray-`%` and lexical checks are
	 * what actually accept or reject a template (ADR-0014).
	 *
	 * @since 0.7.0
	 *
	 * @param string $template The template to preview (raw or normalised).
	 * @return string The sample-expanded preview path.
	 */
	public static function sample_expansion( string $template ): string {

		// Mirror expand()'s substitution with fixed sample tokens, so the preview
		// reads exactly like a real expanded path would.
		$samples = [
			'%year%'     => self::SAMPLE_YEAR,
			'%month%'    => self::SAMPLE_MONTH,
			'%day%'      => self::SAMPLE_DAY,
			'%uploader%' => self::SAMPLE_UPLOADER,
		];

		return strtr( $template, $samples );

	}

}
