# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.10.0] - 2026-06-12

### Added

- The **Photo Drop Gallery** now has a configurable **empty-gallery message** — the text every visitor sees when the chosen collection has no images yet (for example, before a photographer has uploaded). It defaults to *"There are currently no images in the gallery. Please try again later."* and is editable in the block's **Collection** inspector panel; leave it empty to keep the default. (ADR-0012)

### Changed

- The gallery's empty-state messaging now splits by cause. A block pointing at **no collection — or a deleted or otherwise broken one** — still shows visitors nothing and a logged-in editor the notice *"This gallery has no collection selected. Choose a collection in the block settings."*, so a deletion never leaks to the public. A **real collection that simply holds no images yet** is now treated as a legitimate visitor-facing state and shows the configurable empty-gallery message **to everyone** — where previously it showed visitors nothing and editors a single combined notice. (ADR-0012)

## [0.9.0] - 2026-06-12

### Added

- The **Photo Drop Gallery** slideshow now **re-syncs with the gallery at the start of every loop**: images uploaded while the slideshow plays join the rotation on the next pass, deleted images leave it, and caption changes follow — no page reload needed. The slideshow simply refetches the page it is on, so each loop plays exactly what a reload would show (including any full-page cache the site runs). A single-image gallery cycles too — previously its slideshow stood still forever — so a photo frame started at the beginning of an event keeps up as the photographer's uploads stream in; and when the gallery has been emptied or its collection deleted, the playback ends within one loop, so removed images never linger on screen. A network hiccup never interrupts playback: the slideshow keeps its current images and tries again on the next loop. (ADR-0011)
- End-to-end coverage of the resync — an image imported mid-playback joins at the loop boundary, a single-image slideshow grows, an emptied collection ends the playback — and a Jest suite for the resync's fresh-view parsing, wrapper matching, and keep/replace/end decisions.

### Fixed

- `npm run lint:js` no longer crawls for minutes through Playwright's generated HTML report when run after the e2e suite: the project ESLint configuration (the `@wordpress/scripts` bundled config, unchanged) now ignores the gitignored test-artifact directories.

## [0.8.0] - 2026-06-12

### Added

- The **Photo Drop Zone** block's inspector now **always** shows the **Manage collections** link to the admin page, instead of only once a collection has been selected. (#41)

### Changed

- **The minimum WordPress version is now 7.0** (was 6.6). The plugin tracks current WordPress and carries no compatibility code for older releases — the seeded heading uses only the current typography text-alignment mechanism, and the end-to-end suite runs against current WordPress.

### Fixed

- The **Photo Drop Zone**'s default appearance — the dashed box with its light background and padding — now shows on the **published page**, not only in the editor. The default was stored as a block-attribute default, which WordPress never serialises into `post_content`, so the dynamic block rendered its wrapper without it on the front end; the default now lives in the block's stylesheet (which loads on both the editor and the front end), and the block's border, colour, and spacing controls still override it.

## [0.7.1] - 2026-06-12

### Fixed

- The **Photo Drop Zone**'s seeded heading is centred again on current WordPress. WordPress 7.0 moved `core/heading`'s text alignment onto the typography support (`style.typography.textAlign`) and dropped the legacy top-level `textAlign` attribute the seeded template set, so a freshly inserted block's heading came out left-aligned there (it stayed centred on the WordPress 6.6 floor — the only version the test suite ran against, which is why it slipped through). The template now seeds both alignment mechanisms, so the heading is centred from WordPress 6.6 through 7.0, and each version keeps only the attribute it recognises so the saved markup stays clean. The end-to-end suite now also runs against current WordPress rather than only the 6.6 floor, so this class of version-specific regression is caught.
- The README has been trued up against the shipped plugin: a dropped folder is no longer described as warned-about-and-flattened (it has uploaded recursively with its hierarchy preserved since 0.5.0), the uploader-folders choice now appears in the admin create form walkthrough and the `collection create` CLI synopsis, the editable token-wired upload controls and the admin list's always-visible Edit/Delete buttons are described as they actually behave, the gallery block is called by its real name — **Photo Drop Gallery** — throughout, and a `--format` flag the CLI never had is no longer documented (`doctor` and `import` print plain WP-CLI tables).
- The contributor and agent documentation caught up with the same drift: `AGENTS.md` counts all ten ADRs (0008–0010 were missing) and points at `docs/updater.md`, `docs/design.md` records the slideshow decision and the actual `docs/` layout, `docs/blocks.md` mirrors the real `block.json` files, `docs/testing.md` and `docs/definition-of-done.md` drop the retired dragged-folder warning and cover the slideshow and download surfaces, and `CONTRIBUTING.md` no longer mentions the retired FilePond dependency or the old WordPress 6.5 floor.

## [0.7.0] - 2026-06-12

### Added

- **Slideshow** for the **Photo Drop Gallery**: a visitor-started, endlessly looping fullscreen playback of exactly the images the gallery shows, in the gallery's order. A three-state block setting picks the trigger — off (the default), a quiet built-in button above the gallery with an editable label, or any element the page designer places anywhere on the page carrying the documented `data-kntnt-photo-drop-slideshow` attribute (its value targets a gallery by its HTML anchor; without a value it targets the page's first slideshow-enabled gallery). Each image stands fully visible for a configurable number of seconds (default 5) and dissolves (~1 s) to the next; visitors who prefer reduced motion get a hard cut instead. Playback is deliberately passive — Escape, the browser's own fullscreen exit, or the always-visible close button end it and return to the gallery — and it never advances to an image that has not finished loading, holds a screen wake lock while playing, uses the Fullscreen API where available with a viewport-filling fallback (notably iPhone Safari), and mirrors the gallery's caption overlay on each slide. (ADR-0009)
- End-to-end coverage of both slideshow trigger modes (the built-in button's reveal/start/auto-advance/Escape round trip, and a designer-supplied element targeting a gallery by anchor), Jest suites for the slideshow's advance gate and trigger resolution, and Pest coverage of the server-emitted slideshow markup.

## [0.6.0] - 2026-06-11

### Changed

- The **Photo Drop Zone**'s visible upload controls — the "Add photos" button and the folder picker — are now ordinary, fully editable blocks you author inside the block rather than fixed chrome. Each is a normal core button (or any link) wired to the uploader by an anchor-token link target (`#kntnt-drop-zone-files` opens the file picker, `#kntnt-drop-zone-folder` the folder picker). A freshly inserted block seeds both as styled buttons under the centred heading, and you can relabel, restyle, reposition, or remove either — or turn the folder one into a plain text link — like any other block. A Drop Zone saved before this change has no controls until you re-insert it or add a tokened button. (ADR-0010)

### Fixed

- The **Photo Drop Gallery**'s per-image **Shadow** setting now actually shows. A custom shadow value was silently dropped (WordPress's style engine returns a raw `box-shadow` only under `declarations`, never folded into its `css` string, so reading `css` alone lost it), and a preset shadow reached the image but was clipped by the tile's `overflow: hidden`; both are fixed, so a shadow chosen in the inspector paints around the image.

## [0.5.0] - 2026-06-11

### Added

- Dropping a folder onto the **Photo Drop Zone** now uploads every image at every nesting level with its source-relative path preserved on disk — identical placement to choosing the same folder through the "Select folder" picker. Previously a dropped folder was warned about and, on consent, contributed only its top-level files, flat. (#37)
- **Uploader folders:** a collection can namespace every Drop Zone upload under a first folder named after the contributor, derived server-side from the authenticated WordPress user's nicename. This records who uploaded each image — the only place such provenance can live now that the filesystem is the source of truth — and stops two photographers' identically named files from overwriting each other. The choice is made once at establishment — a checkbox, checked by default, on the admin **Create collection** form, and a `--uploader-folders` flag (default on) on `wp kntnt-photo-drop collection create` — and is immutable afterwards; with it off, uploads land at the collection root as before. (#36, #38, #39)
- The **Photo Drop Zone** block's inspector gains the full layout-container control set — layout (constrained by default) with block spacing, background and text colour, typography, border, margin and padding, minimum height, shadow, and alignment — applied to the block's own wrapper. (#35)
- End-to-end coverage of both download-on cells of the click matrix (a thumbnail/enlarged-image click does nothing; an icon click saves the file without navigating or opening a tab), a Jest suite for the programmatic download helper, and a regression assertion that a freshly inserted Drop Zone seeds its heading centred.

### Changed

- The **Photo Drop Zone** block's outermost wrapper is now itself the visible, stylable layout container, instead of delegating its appearance to a seeded inner `core/group`. One DOM level disappears, and the styled box, the drag-drop target, and the drag-over highlight become the same element. A freshly inserted block keeps the familiar centred dashed box; a pointer click anywhere on it (outside interactive children) opens the file picker, while keyboard and assistive-technology users reach a real, visible "Add photos" button. (#35)
- The Drop Zone's prominent "Select folder" button is demoted to a quiet inline "or select a folder" link, while staying fully focusable, labelled, and keyboard-operable — it remains the route to a preserved hierarchy for keyboard and touch users now that plain drag-and-drop preserves one too. (#40)
- The **Photo Drop Gallery**'s download trigger is now the download icon alone: a click on the icon saves the full main image, and a click on the image outside the icon never downloads — it does nothing with the lightbox off, and in the lightbox the enlarged image is no longer a download anchor. The icon itself is now an `<a download>` anchor with a translated accessible label and a visible keyboard-focus ring.
- The download is performed programmatically (the image is fetched into a Blob and saved through a temporary same-document object-URL anchor), so the save can no longer be turned into navigation or a new browser tab by a link-rewriting theme/plugin or a cross-origin media host; the icon anchor's own `download` attribute remains the no-JS fallback, and a failed fetch falls back to a plain same-tab navigation.

### Removed

- The dragged-folder warning is gone. The Drop Zone no longer detects a dropped folder and warns that it will be flattened (offering to continue flat); a dropped folder now keeps its hierarchy, so the consent dialog and its messages are removed. (#37)

### Security

- The transitive development dependency `uuid` (pulled in via `@wordpress/scripts` → `webpack-dev-server` → `sockjs`) is forced to ≥ 11.1.1 through an npm override, resolving a medium-severity Dependabot alert (missing buffer bounds check in v3/v5/v6 when a `buf` argument is provided). Build-chain only; nothing shipped to the browser changes.

## [0.4.0] - 2026-06-10

### Changed

- The collection-lifecycle admin page lists each collection with always-visible **Edit** and **Delete** buttons at the right-hand end of the row, replacing the hover-revealed row actions, and the list table is now visibly separated from the page header. Delete still routes through the existing confirmation step, and a collection whose descriptor cannot be read still lists by slug and remains deletable. (#30)
- The **Photo Drop Zone** block is now an `InnerBlocks` wrapper: its appearance is editable inner blocks (a centred dashed group by default, seeded on insertion and not locked), and the whole inner-block surface is the upload zone. The token `{kntnt-drop-zone-collection}` in the inner blocks is replaced with the selected collection's display name on the published page. The block also gains the `cloud-upload` icon in the inserter and list view, and the inspector spaces the "Manage collections" link clear of the read-only contract display. The capability gate and `wp_rest`-nonce privacy are unchanged: nothing renders and no nonce is emitted for a visitor who cannot upload (ADR-0006). The surface is keyboard-operable (focusable, with Enter or Space opening the file picker), and each file's status row shows the live upload percentage while it transfers. (#31)
- The Photo Gallery block is retitled **Photo Drop Gallery** (the `kntnt-photo-drop/gallery` slug is unchanged) and gains the `format-gallery` icon in the inserter and list view. Its editor preview now renders in a capped **editor-preview mode**: at most 6 figures and no lightbox markup (clicks are inert in the canvas), so a collection of thousands never floods the editor. When there is nothing to render — no collection chosen, a dangling slug, an empty collection, or while the preview loads — the editor shows 6 grey placeholders instead of only a notice, and the editor-only "Photo Gallery" preview label is gone (the canvas shows only images). The preview signal is a render-time-only `isEditorPreview` attribute that defaults to `false` and is never written into `post_content`, so the frontend render is unchanged: no cap, lightbox as configured, full walk. (#32)
- The **Photo Drop Gallery** caption is now always an overlay inside the image, governed by one shared **Caption** panel: content (none / filename / path breadcrumb), humanise, include-collection-name and separator (for the breadcrumb), and a nine-point **Anchor** (always shown for any non-"none" content). The caption's text colour, overlay background, and typography come from the core **Colour** and **Typography** block-support panels; each image's border and shadow come from the core **Border** and **Shadow** panels; and the inter-item gap comes from the core **Block spacing** (`blockGap`) support. All are declared with `__experimentalSkipSerialization` and projected server-side onto the right sub-element — the `<figcaption>` (colour, typography) or each `<img>` (border, shadow) — through the WordPress style engine, the same pattern core's Image block uses, never onto the block wrapper. The same Caption settings will drive the lightbox caption once the lightbox consumes them (#34). (#33)
- The gallery figure layout is rebuilt so the always-overlay caption and the image render correctly in both the uniform grid (mode A) and the justified rows (mode B): each figure is the sizing box and the caption's positioning context, with the link and image filling it absolutely. This fixes the two clipping bugs the old `overflow:hidden`-over-aspect-ratio figure caused — captions that showed nothing and justified rows that showed only alt-text — and justified rows now render real images at small target row heights. (#33)
- The **Photo Drop Gallery**'s single *Lightbox* panel is replaced by a **Click behaviour** panel of two toggles — **Lightbox** (default on) and **Download** (default off) — that together fix what a thumbnail click does: both off → the click does nothing; lightbox on → the click opens the lightbox; download on, lightbox off → a download icon overlays each image and the click saves the full main image; both on → the thumbnail has no icon and the click opens the lightbox, with the download icon appearing only inside the lightbox and the enlarged image downloading on click. The download always targets the full-resolution main image (same-origin `download` anchor), modified clicks stay with the browser, and the no-JS `<a href>` fallback still navigates to the main image. The download icon is an anchored overlay with bespoke size (`2rem`), background (`#00000080`), foreground (`#ffffff`), and nine-point anchor (`top-left`) controls — bespoke because the block-support Colour panel is claimed by the caption. When the lightbox is on and the caption content is not "none", the enlarged image carries the same overlay caption (content, anchor, and colour/typography projection) as the gallery. The editor preview stays click-inert but shows the download icon where the frontend would. (#34)

### Removed

- The Drop Zone's FilePond uploader is replaced by a native drag-drop + click-to-browse surface covering the whole inner-block area; the `filepond` dependency and its stylesheet are dropped from the bundle. Folder drag-and-drop (with the top-level-only warning), the "Select folder" picker, the per-file status list and live summary, real upload progress, and the one-shot nonce refresh on expiry are all preserved. (#31)
- The gallery's bespoke caption-styling and gap attributes are gone (pre-1.0, no migration): `captionPosition` (captions are always an overlay now), `captionOverlayAnchor` (renamed to `captionAnchor`), `captionBackground` and `captionTextColor` (replaced by the Colour/Typography block-support panels), and the custom `blockGap` attribute (replaced by the `blockGap` spacing support). The Position and overlay-colour inspector controls and the bespoke Gap control are removed with them. (#33)
- The gallery's `enableLightbox` attribute is gone (pre-1.0, no migration), split into `lightbox` (default on) and `download` (default off) plus the four download-icon controls (`downloadIconSize`, `downloadIconBackground`, `downloadIconForeground`, `downloadIconAnchor`). (#34)

### Fixed

- The justified layout's server-side row packing now understands `rem`/`em` gap values instead of silently packing every non-pixel gap as 12 px, so rows break where the rendered gap says they should.

### Security

- Free-text styling values that reach inline `style` attributes — the download-icon size and colours, the minimum column width, the block-spacing gap, and the aspect ratio — are now validated against strict shape rules (length, colour, ratio) and fall back to their defaults when they do not conform. Previously a user without `unfiltered_html` could store CSS declarations through a block attribute and have them emitted on the public page (the aspect-ratio and column-width vectors date back to 0.1.0).

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

[Unreleased]: https://github.com/Kntnt/kntnt-photo-drop/compare/v0.10.0...HEAD
[0.10.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.10.0
[0.9.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.9.0
[0.8.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.8.0
[0.7.1]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.7.1
[0.7.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.7.0
[0.6.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.6.0
[0.5.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.5.0
[0.4.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.4.0
[0.3.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.3.0
[0.2.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.2.0
[0.1.0]: https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.1.0
