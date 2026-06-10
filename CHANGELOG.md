# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- The collection-lifecycle admin page lists each collection with always-visible **Edit** and **Delete** buttons at the right-hand end of the row, replacing the hover-revealed row actions, and the list table is now visibly separated from the page header. Delete still routes through the existing confirmation step, and a collection whose descriptor cannot be read still lists by slug and remains deletable. (#30)
- The **Photo Drop Zone** block is now an `InnerBlocks` wrapper: its appearance is editable inner blocks (a centred dashed group by default, seeded on insertion and not locked), and the whole inner-block surface is the upload zone. The token `{kntnt-drop-zone-collection}` in the inner blocks is replaced with the selected collection's display name on the published page. The block also gains the `cloud-upload` icon in the inserter and list view, and the inspector spaces the "Manage collections" link clear of the read-only contract display. The capability gate and `wp_rest`-nonce privacy are unchanged: nothing renders and no nonce is emitted for a visitor who cannot upload (ADR-0006). (#31)

### Removed

- The Drop Zone's FilePond uploader is replaced by a native drag-drop + click-to-browse surface covering the whole inner-block area; the `filepond` dependency and its stylesheet are dropped from the bundle. Folder drag-and-drop (with the top-level-only warning), the "Select folder" picker, the per-file status list and live summary, real upload progress, and the one-shot nonce refresh on expiry are all preserved. (#31)

## [0.3.0] - 2026-06-10

### Added

- **Automated integration-test suite** (`tests/Integration/`, Pest against a booted `@wordpress/env` WordPress, `npm run test:integration`): plugin and block registration, the CLI collection/image lifecycle, the import and REST-upload round-trips (including nonce, capability, and path-traversal negatives), doctor reconciliation on a real on-disk collection, and the index's mtime self-heal.
- **Automated end-to-end suite** (`tests/e2e/`, Playwright + `@wordpress/e2e-test-utils-playwright` in real Chromium, `npm run test:e2e`): inserting both blocks in the editor — including opening the gallery's Layout panel and waiting for the ServerSideRender preview, the regression test for both editor bugs — plus a real in-browser Drop Zone upload round-trip (Canvas WebP conversion included), the lightbox's keyboard/focus/scroll behaviour as an anonymous visitor, and the no-JavaScript `<a href>` fallback.
- A CI job that runs both new suites against `@wordpress/env` on every push.

### Changed

- **The WordPress floor is raised from 6.5 to 6.6.** The compiled blocks depend on the `react-jsx-runtime` script that WordPress ships from 6.6; on 6.5, WordPress silently skips enqueueing the editor scripts, so the blocks never appeared in the editor at all (a bug present since 0.1.0 that only a real browser could reveal — now covered by the e2e suite). Rather than shipping a hand-rolled JSX-runtime polyfill, the minimum version is now one where the editor actually works.

## [0.2.0] - 2026-06-10

### Added

- New filter `kntnt_photo_drop_max_input_megapixels` (default 50): sources declaring more pixels are rejected per-file **before** any decode, so a decompression bomb or an oversized original can no longer exhaust PHP's memory and kill the upload request or CLI batch.
- **Drop Zone session recovery** — when the page has been open long enough for the WordPress session nonce to expire, the uploader now fetches a fresh nonce automatically and retries the upload once; failing that, it shows the server's actionable message instead of a generic "Upload failed".
- **Real upload progress** — uploads now report true per-file transfer progress, and a 60-second inactivity watchdog aborts a stalled connection with a retryable error instead of freezing the upload queue indefinitely.
- The Drop Zone warns before the page is closed or navigated away while files are still queued or uploading.
- The Drop Zone pre-filters camera-folder noise: RAW files (CR2/CR3/NEF/ARW/DNG/RAF/ORF/RW2/SRW/PEF) and videos are rejected locally with a clear status line instead of uploading hundreds of megabytes only to be rejected by the server.
- The Drop Zone status list now keeps **one row per file** that updates through its states (converting, uploading, uploaded, skipped, failed) plus a live summary count, instead of an append-only log with contradictory lines.
- The Photo Gallery block's **editor preview now uses `ServerSideRender`**, so the editor shows the real rendered gallery instead of placeholder tiles.
- The lightbox shows loading and error states for the enlarged image, locks the page scroll while open, and feeds the enlarged image a responsive `srcset`.
- An admin notice warns when the host's PHP can encode WebP with neither GD nor Imagick — previously every upload failed with an opaque error and no explanation anywhere.
- The plugin row's "View version details" modal now shows the GitHub release notes instead of a "plugin not found" error.
- Translations now load from the plugin's `languages/` directory, and the FilePond interface strings (drag-and-drop prompt, per-file states) are translatable.

### Changed

- WP-CLI exit codes are now scriptable: `image import` exits non-zero when **every** source was rejected (a partial failure still succeeds with a warning and counts), and a report-only `collection doctor` exits non-zero when actionable findings exist.
- `collection doctor --repair --force` now also **prunes** thumbnail directories left behind by a thumbnail-width change, instead of letting de-configured widths accumulate on disk forever.
- The auto-updater caches the GitHub release lookup in a site transient (6 h, with a short-lived failure marker), sends a proper API `Accept` header and timeout, and logs failed lookups — previously it hit the GitHub API uncached on every admin load and went silently blind when rate-limited. The update row now reports `tested`, `requires`, and `requires_php` correctly.
- Gallery `sizes` hints are layout-aware (derived from the column width or the justified row height) instead of `100vw`, so browsers no longer download the full-size main image for every thumbnail-sized tile.
- The unused FilePond image-resize plugin was removed from the bundle; the Canvas pipeline has always done the actual downscaling.

### Fixed

- **Folder drag-and-drop no longer loses photos silently.** The folder warning never fired (its listener was attached to an element FilePond removes), and FilePond recursively traversed dropped folders while flattening every path — so same-named files from different camera folders (`100CANON/IMG_0001.JPG`, `101CANON/IMG_0001.JPG`) silently overwrote or skipped each other while the UI claimed success. A dropped folder is now intercepted, warned about, and on confirmation uploads its top-level images flat, exactly as designed.
- **The Photo Gallery block no longer crashes the editor** when the Layout panel opens (`UnitControl` is not a stable `@wordpress/components` export; the experimental export is now used, as core blocks do).
- **A blank image can no longer be uploaded as if it were the photo**: when the browser cannot provide a canvas context (memory pressure) or the image exceeds the safe canvas area on iOS, the original file is uploaded instead and the server performs the conversion.
- Uploading an image with an extreme aspect ratio (wide panoramas) crashed the request mid-batch with an uncaught `ValueError` from GD; the scaled height is now clamped to one pixel and any codec failure becomes a clean per-file rejection.
- Portrait photos imported via `wp kntnt-photo-drop image import` or POSTed directly to the REST API rendered sideways: the server now applies EXIF orientation to the pixels before scaling and encoding, in both the GD and Imagick codecs.
- Palette-based PNG/GIF images (screenshots, logos) within the size ceiling were wrongly rejected; they are now promoted to truecolor (transparency preserved) and convert correctly.
- A truncated or corrupt WebP could pass the header-only check and be stored as a permanently broken image; already-conforming WebP files are now decode-validated before being accepted byte-identical.
- `collection.json` — the one irreplaceable file — could be destroyed by a crash or full disk mid-write; the descriptor, the index, every main image, and every thumbnail are now published atomically (temp file + rename) with short writes detected, so a reader only ever sees the old or the complete new file.
- An image uploaded within the same second as an index rebuild could stay invisible in the gallery indefinitely (mtime has one-second granularity); an index stamped in the current second is no longer persisted, closing the race.
- A photographer's `--ignore` glob that happened to match stored images de-classified them and deleted their thumbnails under `--repair`; ignore globs now apply only to files that are not main images.
- The doctor flagged the plugin's own directory-listing guard (`index.php`) as a foreign file in every collection.
- The REST route's parameter sanitization silently mangled legitimate filenames containing `%`-sequences or doubled spaces, and bypassed the path guard's documented decoding defence; the raw value now reaches the guard, which is the real sanitizer.
- A collection containing an unreadable subdirectory white-screened the whole admin page, including the delete action; the image count now degrades to an em-dash with a logged warning.
- Lightbox keyboard handling went dead after clicking the enlarged image (Escape/arrows stopped working and Tab escaped the dialog); keys are now handled at the document level while the lightbox is open.
- Pinch-zoom gestures over the lightbox no longer trigger spurious image changes, Cmd/Ctrl/Shift-clicking a thumbnail opens it in a new tab as expected, holding an arrow key no longer floods the network with uncancelled full-resolution preloads, and Alt/Cmd+Arrow (browser back/forward) is no longer hijacked.
- The justified layout's last row is now corrected client-side against the real container width, so mid-gallery rows no longer render ragged on themes narrower or wider than the assumed width.
- A failed collection scan (`glob()` error) and a failed block registration are now logged instead of silently rendering "no collections" or removing the blocks from the inserter.
- `build-release-zip.sh` restores the development dependencies even when a build step fails, and excludes macOS junk files from the release archive.
- Uninstalling now removes the plugin's transients (release cache, admin notices).
- Documentation: the thumbnail-regeneration examples in the README, the design notes, and ADR-0002 now show the complete, runnable WP-CLI command (`wp kntnt-photo-drop collection doctor <slug> --repair --force`) instead of a bare `collection doctor --repair --force` fragment that omitted the `wp kntnt-photo-drop` prefix.

### Security

- **`collection doctor --repair` can no longer be tricked into deleting or writing files outside the collection** — every doctor walk now skips symbolic links and refuses to unlink or write through them, matching the confinement the rest of the plugin already enforced.
- The gallery's index rebuild also skips symbolic links, so a planted link can no longer send the recursive gallery walk into an infinite loop (denial of service) or expose files outside the collection.
- GPS positions and other EXIF/XMP metadata are now stripped server-side — losslessly — from already-conforming WebP files that are stored byte-identical, closing a privacy leak where a directly-POSTed WebP published the photographer's location. Re-encoded uploads were already stripped.
- Every newly created collection directory is seeded with a directory-listing guard (`index.php`), so a server with autoindex enabled cannot enumerate collection contents.

## [0.1.0] - 2026-06-08

### Added

- **Photo Drop Zone** block — a capability-gated front-end bulk uploader that downscales, converts to WebP, and compresses images in the browser (FilePond + the Canvas API) before uploading them one at a time, with a "Select folder" control that preserves folder structure and a warning when a folder is dragged onto the zone.
- **Photo Gallery** block — a public, server-rendered gallery of a chosen collection with responsive `srcset`, two layout modes (uniform grid and justified rows), configurable captions (filename or path breadcrumb, with overlay positioning), and an accessible lightbox built on the WordPress Interactivity API with a no-JavaScript fallback.
- **Collections** stored on disk outside the Media Library, under the uploads directory, with the filesystem as the single source of truth — no database rows. Collections are discovered by scanning for a `collection.json` descriptor.
- An immutable per-collection **output contract** (maximum width and quality; the stored format is always WebP), re-enforced on the server for every upload so every image inside a collection is conforming by construction.
- A **collection-lifecycle admin page** (under *Media*) to create, rename, and delete collections, with an explicit warning that a collection's contract is irreversible.
- A **REST upload endpoint** (`/wp-json/kntnt-photo-drop/v1/...`) gated by both a nonce and the `upload_files` capability, which re-applies the output contract server-side.
- **WP-CLI** commands `wp kntnt-photo-drop collection {create,update,delete,doctor}` and `wp kntnt-photo-drop image {import,delete}`.
- A **doctor** command that reconciles thumbnails and per-folder indexes to the main images, reports contract-violating and foreign files, and never alters main images or deletes foreign files.
- Per-folder thumbnail indexes that self-heal from the directory's modification time, so a large upload batch causes no write contention.
- A **GitHub-Releases auto-updater** that installs new versions from the published release ZIP.
- Public filters: `kntnt_photo_drop_root`, `kntnt_photo_drop_thumbnail_width`, `kntnt_photo_drop_default_max_width`, `kntnt_photo_drop_default_quality`, `kntnt_photo_drop_upload_capability`, `kntnt_photo_drop_manage_capability`, and `kntnt_photo_drop_list_capability`.

[Unreleased]: https://github.com/Kntnt/kntnt-photo-drop/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.3.0
[0.2.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.2.0
[0.1.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.1.0
