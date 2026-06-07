<?php
/**
 * Reconciles a collection's derived artifacts to its main images.
 *
 * The doctor is the diagnostic the design calls for (`collection doctor`): it
 * walks a collection and, treating **the main image as the unit of truth**,
 * finds every place a derived artifact has drifted from the mains. A main with a
 * missing thumbnail or index entry is a *missing-derived* finding; a thumbnail
 * or index entry whose main is gone is an *orphan-derived* finding; a main that
 * violates the immutable contract (over the width ceiling, or not WebP — only
 * arrivable by an out-of-band copy) is a *contract-violation* warning; and any
 * file that is none of main/thumbnail/index/descriptor is a *foreign* warning,
 * minus a built-in OS-junk ignore list the caller can extend.
 *
 * The service computes that flat, typed diagnosis first — pure and testable —
 * and only then prints it (report-only, the default) or applies it (`--repair`).
 * `--repair --force` re-derives *every* thumbnail and rebuilds *every* index,
 * the path taken after a `kntnt_photo_drop_thumbnail_width` change. The doctor
 * never alters a main image and never deletes a foreign file, even with
 * `--repair`.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Doctor;

use Kntnt\Photo_Drop\Collection\Image_Name;
use Kntnt\Photo_Drop\Imaging\Gd_Webp_Codec;
use Kntnt\Photo_Drop\Imaging\Thumbnailer;
use Kntnt\Photo_Drop\Imaging\Webp_Codec;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Storage\Descriptor;
use Kntnt\Photo_Drop\Storage\Index;
use Kntnt\Photo_Drop\Storage\Index_Store;

/**
 * Diagnoses and (optionally) repairs one collection against its mains.
 *
 * Constructed once per collection with its root and descriptor, then driven by a
 * single deep method, `run()`, that returns a `Doctor_Report`. The codec (for
 * the cheap header probe that detects a contract violation), the thumbnailer
 * (which already owns the `.kntnt-thumbnails/<width>/<name>.webp` convention and
 * the at-or-below-width skip rule), and the index store (whose `rebuild()`
 * refreshes a folder's index) are all injected so production binds the GD-backed
 * production engine while a test drives the real codec end to end. The diagnosis
 * is recomputed inside `run()`, so a repair acts on the same finding list the
 * report would have printed.
 *
 * @since 0.4.0
 */
final class Doctor {

	/**
	 * The codec used to probe each main's width and format without a full decode.
	 *
	 * @since 0.4.0
	 * @var Webp_Codec
	 */
	private readonly Webp_Codec $codec;

	/**
	 * The deriver that writes a missing thumbnail and owns the path convention.
	 *
	 * @since 0.4.0
	 * @var Thumbnailer
	 */
	private readonly Thumbnailer $thumbnailer;

	/**
	 * The store whose `rebuild()` refreshes a folder's per-folder index.
	 *
	 * @since 0.4.0
	 * @var Index_Store
	 */
	private readonly Index_Store $index_store;

	/**
	 * The matcher deciding which foreign files are silently ignored.
	 *
	 * @since 0.4.0
	 * @var Ignore_Matcher
	 */
	private readonly Ignore_Matcher $ignore;

	/**
	 * Constructs a doctor anchored at one collection.
	 *
	 * The root and descriptor fix what "conforming" and "every configured width"
	 * mean for this run. The three engine collaborators default to the production
	 * GD-backed pair and the default index store, so the CLI constructs a doctor
	 * with just the root, descriptor, and ignore matcher; tests inject their own
	 * to exercise or fake the pixel work and the index rebuild.
	 *
	 * @since 0.4.0
	 *
	 * @param string           $root        Absolute path to the collection root directory.
	 * @param Descriptor       $descriptor  The collection's output contract and thumbnail widths.
	 * @param Ignore_Matcher   $ignore      The foreign-file ignore matcher for this run.
	 * @param Webp_Codec|null  $codec       The codec to probe mains with, or null for GD.
	 * @param Thumbnailer|null $thumbnailer The thumbnailer to derive with, or null for the default.
	 * @param Index_Store|null $index_store The index store to rebuild with, or null for the default.
	 */
	public function __construct(
		private readonly string $root,
		private readonly Descriptor $descriptor,
		Ignore_Matcher $ignore,
		?Webp_Codec $codec = null,
		?Thumbnailer $thumbnailer = null,
		?Index_Store $index_store = null,
	) {
		$this->ignore      = $ignore;
		$this->codec       = $codec ?? new Gd_Webp_Codec();
		$this->thumbnailer = $thumbnailer ?? new Thumbnailer( $this->codec );
		$this->index_store = $index_store ?? new Index_Store();
	}

	/**
	 * Diagnoses the collection and, when asked, repairs it.
	 *
	 * Always computes the full typed diagnosis first. When `$repair` is false the
	 * findings are returned untouched — the report is the dry run, and nothing on
	 * disk changes. When `$repair` is true the findings are acted on: every
	 * missing thumbnail is derived, every orphan thumbnail is removed, and every
	 * folder that gained or lost a derived artifact has its index rebuilt;
	 * `$force` additionally re-derives *all* thumbnails and rebuilds *all* indexes
	 * regardless of the findings (the path after a thumbnail-width change). A
	 * contract-violating main and a foreign file are reported in both modes but
	 * never acted on — the main is never altered, the foreign file never deleted.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $repair Whether to act on the findings (true) or only report (false).
	 * @param bool $force  Whether to re-derive every thumbnail and rebuild every index.
	 * @return Doctor_Report The diagnosis plus, when repairing, the created/removed tallies.
	 */
	public function run( bool $repair, bool $force ): Doctor_Report {

		// Gather the collection's content folders once; both diagnosis and a forced
		// re-derive walk the same set, so the tree is enumerated a single time.
		$folders  = $this->content_folders();
		$findings = $this->diagnose( $folders );

		// Report-only mode stops at the diagnosis — the report is the dry run, so no
		// byte on disk is touched.
		if ( ! $repair ) {
			return new Doctor_Report( $findings );
		}

		// Acting mode applies the diagnosis: derive what is missing, remove what is
		// orphaned, then rebuild the indexes of every affected folder (or, under
		// --force, of every folder).
		return $this->repair( $folders, $findings, $force );

	}

	/**
	 * Computes the full typed diagnosis for the given content folders.
	 *
	 * Pure relative to disk reads: it walks each folder's mains, thumbnails, and
	 * loose files and emits a flat finding list without writing anything. The
	 * per-folder logic is delegated so this method reads as the shape of the whole
	 * diagnosis — one folder's findings after another.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string> $folders Absolute paths of the collection's content folders.
	 * @return array<int,Finding> The flat, ordered finding list.
	 */
	private function diagnose( array $folders ): array {

		// Accumulate each folder's findings in directory order so the report is
		// stable and a reader can scan the collection top-down.
		$findings = [];
		foreach ( $folders as $folder ) {
			$findings = [ ...$findings, ...$this->diagnose_folder( $folder ) ];
		}

		return $findings;

	}

	/**
	 * Diagnoses one content folder against its mains.
	 *
	 * Partitions the folder's entries into mains, sub-directories, and loose files,
	 * then reconciles in three passes: each main against its required thumbnails
	 * and index entry, the folder's thumbnail tree against the surviving mains
	 * (orphans), and every loose file as foreign-or-ignored. The descriptor lives
	 * only at the collection root, so it is recognised and excluded there.
	 *
	 * @since 0.4.0
	 *
	 * @param string $folder Absolute path to a content folder within the collection.
	 * @return array<int,Finding> The findings for this folder.
	 */
	private function diagnose_folder( string $folder ): array {

		// Scan the folder once, classifying each entry. Mains drive reconciliation;
		// loose files become foreign or ignored findings; the hidden artifacts dir
		// and the root descriptor are ours and are never foreign.
		$mains = [];
		$loose = [];
		$entries = scandir( $folder );
		foreach ( $entries === false ? [] : $entries as $entry ) {

			// Skip the self/parent links and our own hidden artifacts directory — the
			// latter is reconciled separately, not treated as content.
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}

			// A sub-directory is a content folder of its own (walked separately) and
			// the root descriptor is ours, so neither is content here.
			$path = $folder . '/' . $entry;
			if ( is_dir( $path ) || $this->is_descriptor( $folder, $entry ) ) {
				continue;
			}

			// The ignore list wins before the main test, so OS junk that happens to
			// end in `.webp` (an AppleDouble `._photo.jpg.webp`) is skipped rather than
			// mistaken for a main; only a non-ignored `*.webp` becomes a main.
			$relative = $this->relative( $path );
			if ( $this->ignore->matches( $relative ) ) {
				$loose[] = $relative;
			} elseif ( $this->is_main( $entry ) ) {
				$mains[] = $entry;
			} else {
				$loose[] = $relative;
			}
		}

		// Reconcile in three independent passes so each kind of finding has one
		// authoritative source, then concatenate them in a stable order.
		return [
			...$this->reconcile_mains( $folder, $mains ),
			...$this->reconcile_orphans( $folder, $mains ),
			...$this->classify_foreign( $loose ),
		];

	}

	/**
	 * Reconciles each main against its required thumbnails and index entry.
	 *
	 * A main that violates the contract (over the ceiling, or not WebP) yields a
	 * single contract-violation finding and is then left entirely alone — it is
	 * never processed in place, so no derived artifact is demanded of it. A
	 * conforming main yields a missing-derived finding for each configured width
	 * strictly below its own width whose thumbnail is absent, plus one for a
	 * missing index entry.
	 *
	 * @since 0.4.0
	 *
	 * @param string            $folder Absolute path to the content folder.
	 * @param array<int,string> $mains  The stored main filenames in the folder.
	 * @return array<int,Finding> The mains' missing-derived and contract findings.
	 */
	private function reconcile_mains( string $folder, array $mains ): array {

		// Read the folder's index once so an absent entry can be detected without
		// re-reading it per main. A missing or stale index simply yields no entries,
		// which surfaces as missing-derived findings the rebuild then heals.
		$indexed = $this->indexed_names( $folder );

		// Walk each main, probing it once. A contract violation short-circuits the
		// derived checks for that main; a conforming main is checked for every
		// missing thumbnail and its index entry.
		$findings = [];
		foreach ( $mains as $main ) {
			$main_path = $folder . '/' . $main;
			$probe     = $this->codec->probe( $this->read( $main_path ) ?? '' );

			// An unreadable or undecodable main, or one whose format/width breaks the
			// contract, is warned about and never reconciled further. The null-probe
			// case is checked first so the conforming path has a measured width.
			if ( $probe === null ) {
				$findings[] = new Finding(
					Finding_Kind::Contract_Violation,
					$this->relative( $main_path ),
					'unreadable or not a decodable image',
				);
				continue;
			}
			$violation = $this->contract_violation( $probe );
			if ( $violation !== null ) {
				$findings[] = new Finding(
					Finding_Kind::Contract_Violation,
					$this->relative( $main_path ),
					$violation,
				);
				continue;
			}

			// A conforming main: every configured width below its own needs a
			// thumbnail, and the folder index needs its entry.
			$findings = [ ...$findings, ...$this->missing_thumbnails( $folder, $main, $probe[0] ) ];
			if ( ! in_array( $main, $indexed, true ) ) {
				$findings[] = new Finding(
					Finding_Kind::Missing_Derived,
					$this->relative( $main_path ),
					'index entry',
				);
			}
		}

		return $findings;

	}

	/**
	 * Finds the missing thumbnails a conforming main should have.
	 *
	 * Only widths strictly below the main's own width apply — a width at or above
	 * is served by the main itself in the gallery's `srcset`, so it is never
	 * flagged. For each applicable width whose thumbnail file is absent, a
	 * missing-derived finding naming the width is produced.
	 *
	 * @since 0.4.0
	 *
	 * @param string $folder     Absolute path to the content folder.
	 * @param string $main       The stored main filename.
	 * @param int    $main_width The main's own pixel width.
	 * @return array<int,Finding> One finding per missing applicable thumbnail.
	 */
	private function missing_thumbnails( string $folder, string $main, int $main_width ): array {

		// Check each configured width below the main width for its thumbnail file,
		// recording a finding only when the file is absent.
		$findings = [];
		foreach ( $this->descriptor->thumbnail_widths as $width ) {
			if ( $width >= $main_width ) {
				continue;
			}
			$thumb = Thumbnailer::thumbnail_path( $folder, $main, $width );
			if ( ! is_file( $thumb ) ) {
				$findings[] = new Finding(
					Finding_Kind::Missing_Derived,
					$this->relative( $thumb ),
					"thumbnail {$width}px",
				);
			}
		}

		return $findings;

	}

	/**
	 * Finds orphan thumbnails — those whose main no longer exists.
	 *
	 * Walks every `<width>/` sub-directory under the folder's `.kntnt-thumbnails/`
	 * and flags each thumbnail whose stored name is not among the folder's surviving
	 * mains. A stale index entry (a name in the index with no main) is healed by the
	 * rebuild rather than flagged here, so this pass concerns thumbnail files only.
	 *
	 * @since 0.4.0
	 *
	 * @param string            $folder Absolute path to the content folder.
	 * @param array<int,string> $mains  The stored main filenames surviving in the folder.
	 * @return array<int,Finding> One finding per orphan thumbnail.
	 */
	private function reconcile_orphans( string $folder, array $mains ): array {

		// No thumbnails directory means there can be no orphan thumbnails.
		$thumbs_root = $folder . '/' . Index::THUMBNAILS_DIRNAME;
		if ( ! is_dir( $thumbs_root ) ) {
			return [];
		}

		// Visit each width sub-directory and, within it, each thumbnail file. A
		// thumbnail whose stored name has no surviving main is an orphan.
		$findings = [];
		foreach ( $this->width_dirs( $thumbs_root ) as $width_dir ) {
			$names = scandir( $width_dir );
			foreach ( $names === false ? [] : $names as $name ) {
				if ( $name === '.' || $name === '..' ) {
					continue;
				}
				$thumb = $width_dir . '/' . $name;
				if ( is_file( $thumb ) && ! in_array( $name, $mains, true ) ) {
					$findings[] = new Finding(
						Finding_Kind::Orphan_Derived,
						$this->relative( $thumb ),
						'orphan thumbnail',
					);
				}
			}
		}

		return $findings;

	}

	/**
	 * Classifies each loose file as foreign or silently ignored.
	 *
	 * A path on the OS-junk list or matched by a caller `--ignore` glob becomes an
	 * ignored finding (surfaced only with `--show-ignored`); everything else is a
	 * foreign warning.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string> $foreign The collection-relative loose-file paths.
	 * @return array<int,Finding> One finding per loose file.
	 */
	private function classify_foreign( array $foreign ): array {
		return array_map(
			fn ( string $relative ): Finding => $this->ignore->matches( $relative )
				? new Finding( Finding_Kind::Ignored, $relative )
				: new Finding( Finding_Kind::Foreign, $relative ),
			$foreign,
		);
	}

	/**
	 * Applies the diagnosis: derive what is missing, remove orphans, rebuild indexes.
	 *
	 * Missing thumbnails are derived from their (conforming) mains, orphan
	 * thumbnails are unlinked, and every folder that changed has its index rebuilt
	 * so a stale or missing index entry self-heals. Under `$force` the derive and
	 * rebuild run for *every* folder regardless of the findings, which is how a
	 * thumbnail-width change re-derives the whole collection. Contract violations
	 * and foreign files are never acted on. Returns the diagnosis with the
	 * created/removed tallies and the repaired flag set.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string>  $folders  The collection's content folders.
	 * @param array<int,Finding> $findings The diagnosis to act on.
	 * @param bool               $force    Whether to re-derive and rebuild everything.
	 * @return Doctor_Report The diagnosis plus the repair tallies.
	 */
	private function repair( array $folders, array $findings, bool $force ): Doctor_Report {

		// Remove every orphan thumbnail first; the count feeds the summary and the
		// removed files must be gone before the indexes are rebuilt.
		$removed = $this->remove_orphans( $findings );

		// Derive thumbnails: under --force regenerate all of them (the post-width-
		// change path), otherwise create only the ones the diagnosis flagged missing.
		$created = $force
			? $this->regenerate_all_thumbnails( $folders )
			: $this->create_missing_thumbnails( $findings );

		// Rebuild the index of every affected folder so its entries match the mains
		// on disk; under --force rebuild every folder unconditionally.
		$this->rebuild_indexes( $folders, $findings, $force );

		return new Doctor_Report( $findings, $created, $removed, true );

	}

	/**
	 * Removes every orphan thumbnail named by the diagnosis, returning the count.
	 *
	 * Only orphan-derived findings are acted on, and only their thumbnail files are
	 * unlinked — never a main, never a foreign file. A path that cannot be unlinked
	 * is left in place and not counted, so the tally reflects real removals.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,Finding> $findings The diagnosis.
	 * @return int The number of orphan thumbnails removed.
	 */
	private function remove_orphans( array $findings ): int {

		// Unlink each orphan thumbnail by reconstructing its absolute path from the
		// relative one the finding carries; count only the removals that succeed.
		$removed = 0;
		foreach ( $findings as $finding ) {
			if ( $finding->kind !== Finding_Kind::Orphan_Derived ) {
				continue;
			}
			if ( $this->unlink( $this->absolute( $finding->path ) ) ) {
				++$removed;
			}
		}

		return $removed;

	}

	/**
	 * Derives every thumbnail the diagnosis flagged missing, returning the count.
	 *
	 * Each missing-thumbnail finding names one width of one main; the mains are
	 * grouped so a main is decoded once and all its missing widths are written in a
	 * single thumbnailer pass. A flagged *index* entry is not a thumbnail and is
	 * healed by the rebuild, so it is skipped here.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,Finding> $findings The diagnosis.
	 * @return int The number of thumbnail files created.
	 */
	private function create_missing_thumbnails( array $findings ): int {

		// Collect, per main, the set of widths whose thumbnails are missing, so the
		// thumbnailer decodes each main once for all of its widths.
		$wanted = [];
		foreach ( $findings as $finding ) {
			$width = $this->thumbnail_width_of( $finding );
			if ( $width === null ) {
				continue;
			}
			$main = $this->main_path_for_thumbnail( $finding->path );
			$wanted[ $main ][] = $width;
		}

		// Derive each main's missing widths and tally what was actually written.
		$created = 0;
		foreach ( $wanted as $key => $widths ) {
			$main_path = (string) $key;
			$written = $this->thumbnailer->generate(
				$main_path,
				basename( $main_path ),
				$widths,
				$this->descriptor->quality,
			);
			$created += count( $written );
		}

		return $created;

	}

	/**
	 * Regenerates every thumbnail for every conforming main in the collection.
	 *
	 * The `--repair --force` path: it ignores the diagnosis and re-derives each
	 * main's full set of configured thumbnails, which is how a change to the
	 * `kntnt_photo_drop_thumbnail_width` filter is rolled out across an existing
	 * collection. A contract-violating main is skipped — it is never processed in
	 * place — so a forced regenerate still never touches a non-conforming main.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string> $folders The collection's content folders.
	 * @return int The number of thumbnail files written.
	 */
	private function regenerate_all_thumbnails( array $folders ): int {

		// Walk every folder's mains and re-derive each conforming main's thumbnails
		// from scratch, summing what was written across the whole collection.
		$created = 0;
		foreach ( $folders as $folder ) {
			foreach ( $this->folder_mains( $folder ) as $main ) {
				$main_path = $folder . '/' . $main;
				if ( $this->contract_violation( $this->codec->probe( $this->read( $main_path ) ?? '' ) ) !== null ) {
					continue;
				}
				$created += count(
					$this->thumbnailer->generate(
						$main_path,
						$main,
						$this->descriptor->thumbnail_widths,
						$this->descriptor->quality,
					),
				);
			}
		}

		return $created;

	}

	/**
	 * Rebuilds the per-folder index of every folder that needs it.
	 *
	 * Under `$force` every folder is rebuilt unconditionally (paired with the
	 * forced thumbnail regenerate). Otherwise only folders that gained or lost a
	 * derived artifact — i.e. that carry a missing-derived or orphan-derived
	 * finding — are rebuilt, so an untouched folder pays no rebuild cost. The
	 * rebuild reads each main once and writes the index, healing a missing or
	 * stale entry.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,string>  $folders  The collection's content folders.
	 * @param array<int,Finding> $findings The diagnosis.
	 * @param bool               $force    Whether to rebuild every folder.
	 */
	private function rebuild_indexes( array $folders, array $findings, bool $force ): void {

		// Decide which folders to rebuild: all of them under --force, otherwise just
		// the ones whose derived artifacts changed.
		$targets = $force ? $folders : $this->folders_with_derived_changes( $findings );

		// Rebuild each target so its index entries match the mains now on disk.
		foreach ( $targets as $folder ) {
			$this->index_store->rebuild( $folder );
		}

	}

	/**
	 * Returns the absolute paths of folders whose derived artifacts changed.
	 *
	 * A folder is included when it holds a missing-derived or orphan-derived
	 * finding, since those are exactly the changes a rebuild must reflect in the
	 * index. Each folder appears once, in first-seen order.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,Finding> $findings The diagnosis.
	 * @return array<int,string> The absolute folder paths to rebuild.
	 */
	private function folders_with_derived_changes( array $findings ): array {

		// Map each derived-change finding back to the content folder that owns it,
		// de-duplicating so a folder with many changes is rebuilt once.
		$folders = [];
		foreach ( $findings as $finding ) {
			if ( $finding->kind !== Finding_Kind::Missing_Derived && $finding->kind !== Finding_Kind::Orphan_Derived ) {
				continue;
			}
			$folder = $this->content_folder_of( $finding->path );
			$folders[ $folder ] = $folder;
		}

		return array_values( $folders );

	}

	/**
	 * Maps a finding's relative path back to the content folder that owns it.
	 *
	 * A thumbnail or index path lives under `<folder>/.kntnt-thumbnails/...`, so the
	 * content folder is the part before that segment; a plain main path's folder is
	 * just its directory. The result is the absolute content-folder path.
	 *
	 * @since 0.4.0
	 *
	 * @param string $relative_path The finding's collection-relative path.
	 * @return string The absolute content-folder path.
	 */
	private function content_folder_of( string $relative_path ): string {

		// Strip everything from the hidden artifacts segment onward so a thumbnail or
		// index path resolves to its owning content folder, not the width directory.
		$marker = Index::THUMBNAILS_DIRNAME . '/';
		$position = strpos( $relative_path, $marker );
		$folder_relative = $position === false
			? \dirname( $relative_path )
			: rtrim( substr( $relative_path, 0, $position ), '/' );

		// A top-level path has '.' as its dirname, which maps back to the root.
		return $folder_relative === '' || $folder_relative === '.'
			? $this->root
			: $this->absolute( $folder_relative );

	}

	/**
	 * Returns the absolute paths of every content folder in the collection.
	 *
	 * The root counts as a content folder; the walk descends into real
	 * sub-directories but never into our hidden `.kntnt-thumbnails/`, which holds
	 * artifacts, not content. The list is depth-first and stable, so diagnosis and
	 * repair visit folders in the same order.
	 *
	 * @since 0.4.0
	 *
	 * @return array<int,string> The absolute content-folder paths, root first.
	 */
	private function content_folders(): array {

		// Seed the walk at the root and accumulate every content sub-directory.
		$folders = [ $this->root ];
		$this->collect_subfolders( $this->root, $folders );

		return $folders;

	}

	/**
	 * Appends every content sub-directory of a folder, recursing depth-first.
	 *
	 * Our hidden artifacts directory is skipped so the walk stays on content; every
	 * other sub-directory is recorded and descended into.
	 *
	 * @since 0.4.0
	 *
	 * @param string            $folder  The folder to scan.
	 * @param array<int,string> &$folders The accumulator the sub-folders are appended to.
	 */
	private function collect_subfolders( string $folder, array &$folders ): void {

		// Record each content sub-directory, then recurse into it, so the collection
		// tree is walked depth-first while the hidden artifacts dir is left alone.
		$entries = scandir( $folder );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}
			$path = $folder . '/' . $entry;
			if ( is_dir( $path ) ) {
				$folders[] = $path;
				$this->collect_subfolders( $path, $folders );
			}
		}

	}

	/**
	 * Returns the stored main filenames present directly in a folder.
	 *
	 * @since 0.4.0
	 *
	 * @param string $folder Absolute path to the content folder.
	 * @return array<int,string> The folder's main `*.webp` filenames.
	 */
	private function folder_mains( string $folder ): array {

		// Keep only top-level `*.webp` entries; thumbnails live under the hidden dir,
		// so the extension alone classifies a main here.
		$mains = [];
		$entries = scandir( $folder );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === Index::THUMBNAILS_DIRNAME ) {
				continue;
			}
			if ( ! is_dir( $folder . '/' . $entry ) && $this->is_main( $entry ) ) {
				$mains[] = $entry;
			}
		}

		return $mains;

	}

	/**
	 * Returns the main filenames recorded in a folder's index, or an empty list.
	 *
	 * Reads the index without measuring any image (the cache-hit reader). A missing
	 * or unreadable index yields no names, so every main reads as un-indexed and is
	 * flagged — which the rebuild then heals.
	 *
	 * @since 0.4.0
	 *
	 * @param string $folder Absolute path to the content folder.
	 * @return array<int,string> The indexed main filenames.
	 */
	private function indexed_names( string $folder ): array {

		// A null read means no trustworthy index; treat it as recording no mains.
		$index = $this->index_store->read( $folder );
		if ( $index === null ) {
			return [];
		}

		return array_map( static fn ( $entry ): string => $entry->file, $index->images );

	}

	/**
	 * Returns the contract-violation reason for a probe, or null when conforming.
	 *
	 * An unreadable or undecodable main, a non-WebP main, or one over the width
	 * ceiling each yields a short reason phrase for the finding's detail; a
	 * conforming main yields null. A `null` ceiling means no limit, so width never
	 * violates it.
	 *
	 * @since 0.4.0
	 *
	 * @param array{0:int,1:bool}|null $probe The codec probe `[ width, is_webp ]`, or null.
	 * @return string|null The violation reason, or null when the main conforms.
	 */
	private function contract_violation( ?array $probe ): ?string {

		// A main the codec cannot even probe is unusable; report it rather than
		// silently skipping a file that may be a corrupt or non-image copy.
		if ( $probe === null ) {
			return 'unreadable or not a decodable image';
		}
		[ $width, $is_webp ] = $probe;

		// The stored format is always WebP; anything else arrived out of band.
		if ( ! $is_webp ) {
			return 'not WebP';
		}

		// A finite ceiling the main exceeds is the width violation; a null ceiling
		// imposes no limit.
		if ( $this->descriptor->max_width !== null && $width > $this->descriptor->max_width ) {
			return "over the {$this->descriptor->max_width}px ceiling ({$width}px)";
		}

		return null;

	}

	/**
	 * Returns the thumbnail width a missing-derived finding names, or null.
	 *
	 * A missing-derived finding is either a thumbnail (path
	 * `…/.kntnt-thumbnails/<width>/<name>.webp`) or an index entry (a plain main
	 * path with no artifacts segment). The width is read from the path's
	 * `<width>/` directory segment — the authoritative source — so it never
	 * depends on the human detail wording; a finding with no artifacts segment is
	 * an index entry and yields null.
	 *
	 * @since 0.4.0
	 *
	 * @param Finding $finding The finding to inspect.
	 * @return int|null The thumbnail width, or null when the finding is not a thumbnail.
	 */
	private function thumbnail_width_of( Finding $finding ): ?int {

		// Only a missing-derived finding under the hidden artifacts directory names a
		// thumbnail width; everything else (notably an index entry) yields null.
		$marker = '/' . Index::THUMBNAILS_DIRNAME . '/';
		$prefix = Index::THUMBNAILS_DIRNAME . '/';
		if ( $finding->kind !== Finding_Kind::Missing_Derived ) {
			return null;
		}

		// The width is the directory segment immediately after the artifacts marker;
		// handle both a root-level path (`<dir>/<width>/…`) and a nested one
		// (`sub/<dir>/<width>/…`).
		$position = strpos( $finding->path, $marker );
		$tail = $position !== false
			? substr( $finding->path, $position + strlen( $marker ) )
			: ( str_starts_with( $finding->path, $prefix ) ? substr( $finding->path, strlen( $prefix ) ) : null );
		if ( $tail === null ) {
			return null;
		}

		// The first segment of the tail is the width; a non-numeric segment is not a
		// width directory and yields null.
		$segment = explode( '/', $tail )[0];
		return ctype_digit( $segment ) ? (int) $segment : null;

	}

	/**
	 * Maps a thumbnail's relative path to its main's absolute path.
	 *
	 * A thumbnail lives at `<folder>/.kntnt-thumbnails/<width>/<name>.webp`, and the
	 * main it derives from is `<folder>/<name>.webp`, so the content folder and the
	 * thumbnail's own basename together name the main.
	 *
	 * @since 0.4.0
	 *
	 * @param string $thumbnail_relative The thumbnail's collection-relative path.
	 * @return string The absolute main path.
	 */
	private function main_path_for_thumbnail( string $thumbnail_relative ): string {
		return $this->content_folder_of( $thumbnail_relative ) . '/' . basename( $thumbnail_relative );
	}

	/**
	 * Returns the absolute `<width>/` sub-directories under a thumbnails root.
	 *
	 * @since 0.4.0
	 *
	 * @param string $thumbs_root Absolute path to a folder's `.kntnt-thumbnails/`.
	 * @return array<int,string> The absolute width sub-directory paths.
	 */
	private function width_dirs( string $thumbs_root ): array {

		// Each immediate sub-directory of the thumbnails root is a width bucket; the
		// index file beside them is not a directory and is skipped.
		$dirs = [];
		$entries = scandir( $thumbs_root );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $thumbs_root . '/' . $entry;
			if ( is_dir( $path ) ) {
				$dirs[] = $path;
			}
		}

		return $dirs;

	}

	/**
	 * Reports whether a folder entry is the collection's root descriptor.
	 *
	 * The descriptor (`collection.json`) lives only at the collection root, so it is
	 * recognised as ours there and excluded from foreign files. A same-named file in
	 * a sub-folder is not the descriptor and stays foreign.
	 *
	 * @since 0.4.0
	 *
	 * @param string $folder The folder the entry was found in.
	 * @param string $entry  The entry filename.
	 * @return bool True when the entry is the root descriptor.
	 */
	private function is_descriptor( string $folder, string $entry ): bool {
		return $folder === $this->root && $entry === Descriptor::FILENAME;
	}

	/**
	 * Reports whether a filename is a stored main image.
	 *
	 * A main is a `*.webp` file, matched case-insensitively on the extension. Loose
	 * files of any other kind are foreign; thumbnails never live in a content folder.
	 *
	 * @since 0.4.0
	 *
	 * @param string $filename The directory entry name.
	 * @return bool True when the entry is a main image.
	 */
	private function is_main( string $filename ): bool {
		return Image_Name::to_stored( $filename ) === $filename && str_ends_with( strtolower( $filename ), '.webp' );
	}

	/**
	 * Returns a path relative to the collection root, POSIX-separated.
	 *
	 * @since 0.4.0
	 *
	 * @param string $absolute_path An absolute path inside the collection.
	 * @return string The path relative to the root.
	 */
	private function relative( string $absolute_path ): string {
		return ltrim( substr( $absolute_path, strlen( rtrim( $this->root, '/' ) ) ), '/' );
	}

	/**
	 * Returns the absolute path for a collection-relative one.
	 *
	 * @since 0.4.0
	 *
	 * @param string $relative_path A path relative to the collection root.
	 * @return string The absolute path.
	 */
	private function absolute( string $relative_path ): string {
		return rtrim( $this->root, '/' ) . '/' . $relative_path;
	}

	/**
	 * Reads a file's bytes, or null when it cannot be read.
	 *
	 * @since 0.4.0
	 *
	 * @param string $path Absolute path to the file.
	 * @return string|null The file bytes, or null when missing or unreadable.
	 */
	private function read( string $path ): ?string {

		// The plugin owns this directory tree on disk directly (ADR-0001), so it
		// reads the file rather than routing through the Media Library.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = is_file( $path ) ? file_get_contents( $path ) : false;

		return $bytes === false ? null : $bytes;

	}

	/**
	 * Unlinks a single file, returning whether it was removed.
	 *
	 * @since 0.4.0
	 *
	 * @param string $path Absolute path to the file to remove.
	 * @return bool True when the file was removed.
	 */
	private function unlink( string $path ): bool {

		// Only an existing file is unlinked; the plugin owns this tree directly
		// (ADR-0001), so it unlinks rather than routing through wp_delete_file.
		if ( ! is_file( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin owns this directory tree on disk directly (ADR-0001); wp_delete_file is for Media-Library attachments, not files written outside it.
		$removed = unlink( $path );
		if ( ! $removed ) {
			Plugin::warning( "Failed to remove an orphan thumbnail at {$path}." );
		}

		return $removed;

	}

}
