<?php
/**
 * One typed observation the doctor made about a collection.
 *
 * A finding pairs a `Finding_Kind` with the collection-relative path it concerns
 * and a short human detail (the width of a missing thumbnail, the reason a main
 * violates the contract). The doctor computes a flat list of these first — a
 * pure, testable diagnosis — and only then prints them or, under `--repair`,
 * acts on them. Modelling each observation as a value object keeps that act vs
 * report split clean: the same finding list drives both.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Doctor;

/**
 * An immutable `{ kind, path, detail }` doctor finding.
 *
 * The `path` is always relative to the collection root (POSIX separators, e.g.
 * `sub/IMG.jpg.webp` or `.kntnt-thumbnails/320/IMG.jpg.webp`), so the report
 * reads cleanly regardless of where the collection lives on disk and a test can
 * assert against stable strings. The `detail` is presentational only — it never
 * carries machine state the repairer needs, which travels in the kind and path.
 *
 * @since 0.4.0
 */
final readonly class Finding {

	/**
	 * Constructs a finding from its kind, relative path, and human detail.
	 *
	 * @since 0.4.0
	 *
	 * @param Finding_Kind $kind   The closed-set classification of the observation.
	 * @param string       $path   The path relative to the collection root, POSIX-separated.
	 * @param string       $detail A short human-readable explanation, possibly empty.
	 */
	public function __construct(
		public Finding_Kind $kind,
		public string $path,
		public string $detail = '',
	) {}

	/**
	 * Returns the finding as a flat row for the report table.
	 *
	 * The column order (`kind`, `path`, `detail`) matches what the command feeds
	 * `WP_CLI\Utils\format_items()`, so the tabular summary is assembled in one
	 * place and stays consistent across every finding kind.
	 *
	 * @since 0.4.0
	 *
	 * @return array{kind:string,path:string,detail:string} The report row.
	 */
	public function to_row(): array {
		return [
			'kind'   => $this->kind->value,
			'path'   => $this->path,
			'detail' => $this->detail,
		];
	}

}
