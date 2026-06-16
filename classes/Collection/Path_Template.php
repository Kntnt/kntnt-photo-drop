<?php
/**
 * The Drop Zone placement template — RED stub.
 *
 * Deliberately wrong shells so the Path_Template tests fail on real assertions
 * before the behaviour is implemented (ADR-0014). The real mechanics replace
 * these for GREEN.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.7.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

/**
 * RED stub for the placement template helper.
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
	 * @since 0.7.0
	 * @var string
	 */
	public const SAMPLE_UPLOADER = 'jane-doe';

	/**
	 * Normalises a raw template's separator structure (RED stub).
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw The raw template value.
	 * @return string The normalised template.
	 */
	public static function normalise( string $raw ): string {
		return $raw;
	}

	/**
	 * Reports whether a stray `%` survives the four known placeholders (RED stub).
	 *
	 * @since 0.7.0
	 *
	 * @param string $template The template to inspect.
	 * @return bool True when an unrecognised percent remains.
	 */
	public static function has_stray_placeholder( string $template ): bool {
		return false;
	}

	/**
	 * Expands the four placeholders at upload time (RED stub).
	 *
	 * @since 0.7.0
	 *
	 * @param string             $template The normalised template.
	 * @param \DateTimeImmutable $date     The upload date in the site timezone.
	 * @param string             $uploader The uploader's nicename.
	 * @return string The expanded sub-path.
	 */
	public static function expand( string $template, \DateTimeImmutable $date, string $uploader ): string {
		return $template;
	}

	/**
	 * Substitutes the placeholders with fixed sample values for preview (RED stub).
	 *
	 * @since 0.7.0
	 *
	 * @param string $template The template to preview.
	 * @return string The sample-expanded preview path.
	 */
	public static function sample_expansion( string $template ): string {
		return $template;
	}

}
