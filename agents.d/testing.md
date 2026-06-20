# Testing strategy

The detailed, per-area test specification: what is tested, with what tooling, and what is deliberately not. The human-facing orientation (test pyramid, out-of-scope) is in [`CONTRIBUTING.md`](../CONTRIBUTING.md#testing). Bar: [`definition-of-done.md`](definition-of-done.md). Toolchain: [`coding-standards.md`](coding-standards.md). Authoritative specs: [`design.md`](../docs/design.md), ADR-0013/0014/0015.

## Test layer per issue (TDD)

Drive each issue at the **lowest layer that meaningfully constrains its behaviour** (test-first bar: [`definition-of-done.md`](definition-of-done.md)).

| Layer | Issues | Core under test |
|---|---|---|
| Pure unit (Red/Green) | [#42](https://github.com/Kntnt/kntnt-photo-drop/issues/42), [#43](https://github.com/Kntnt/kntnt-photo-drop/issues/43), [#45](https://github.com/Kntnt/kntnt-photo-drop/issues/45), [#48](https://github.com/Kntnt/kntnt-photo-drop/issues/48) | descriptor read/write, tier-skip, `srcset`, doctor reconciliation (#42); pre-order traversal (#43); filter resolution (#45); template expansion, site timezone, stray-`%`/`..` rejection, `Path_Guard` lexical (#48) |
| Pure core + e2e shell | [#44](https://github.com/Kntnt/kntnt-photo-drop/issues/44), [#47](https://github.com/Kntnt/kntnt-photo-drop/issues/47), [#50](https://github.com/Kntnt/kntnt-photo-drop/issues/50), [#51](https://github.com/Kntnt/kntnt-photo-drop/issues/51) | helper Red/Green (bucket accounting; breadcrumb string + hide-count + leading-ellipsis; slug default/suffix; programmatic save); DOM/CSS/editor shell via e2e — never fake a unit assertion over visual output |
| Integration-first | [#49](https://github.com/Kntnt/kntnt-photo-drop/issues/49), [#52](https://github.com/Kntnt/kntnt-photo-drop/issues/52), [#53](https://github.com/Kntnt/kntnt-photo-drop/issues/53) | regenerate-then-flip (#49), add-to-media (#52), trash (#53): real filesystem + REST, so the RED is an integration test against `@wordpress/env` (attachment really created, `Path_Guard` really confines, descriptor flips only on success), with capability/nonce + path confinement unit-checked on top — never mocked into a tautology |
| No automated tests | [#46](https://github.com/Kntnt/kntnt-photo-drop/issues/46) | docs |

## Test pyramid

| Layer | Tooling | Where | What it covers |
|---|---|---|---|
| PHP unit | Pest + Brain Monkey + Mockery | `tests/Unit/` | Pure-ish domain logic: path sanitisation + `realpath` confinement (the `Path_Guard` lexical + realpath checks), the path-components template expansion, the `<original>.webp` naming rule, contract conformance checks, the tier-skip rule, the doctor's reconciliation algorithm, descriptor/index read-write (the six-field rendition shape + `pathComponents`), pre-order tree-traversal ordering, breadcrumb-string assembly, `srcset` assembly. No real WordPress, no real filesystem where a temp dir + Brain Monkey stub will do. |
| PHP integration | WordPress (via `@wordpress/env`, or `@wp-playground/cli` where no browser is needed) + Pest | `tests/Integration/` | The plugin loads, both blocks register, the REST upload endpoint round-trips, the gallery's add-to-media and trash REST endpoints round-trip, the WP-CLI `collection`/`image` commands run, the doctor reconciles a real on-disk collection, the regenerate-then-flip of a full/thumbnail size change, the index self-heals on a real directory `mtime` bump. |
| Block JS unit | Jest via `wp-scripts test-unit-js` | `src/blocks/<slug>/*.test.ts(x)` (co-located) | Pure browser helpers behind the Drop Zone (the Canvas downscale + `canvas.toBlob(…, 'image/webp', q)` encode wrapper, the `webkitRelativePath` → relative-path mapping, the recursive dropped-folder walk, the upload-queue bucket accounting behind the aggregate progress bar and Retry-failed) and the Gallery (justified-row `flex-grow`/`flex-basis` math, the breadcrumb string + hide-count + leading-ellipsis output, the programmatic-download save path, lightbox index/keyboard reducers, the slideshow advance gate and trigger-target resolution, and the slideshow's cycle-boundary resync — fresh-view parsing, wrapper matching, and the keep/replace/end outcomes). |
| Block end-to-end | Playwright + `@wordpress/e2e-test-utils-playwright` | `tests/e2e/` | Insert each block in the editor; the Drop Zone uploads fixtures (loose files and a dragged folder), drives the aggregate progress bar, Cancel, and Retry-failed, and the Gallery renders them; the lightbox opens, navigates, traps focus, and closes; the overlays appear at their positions and visibilities — the download icon saves the main image, add-to-media copies into the Media Library, trash deletes a tile behind its confirm; the slideshow starts from its triggers, advances, picks up images imported mid-playback at the cycle boundary, and ends when the collection is emptied; the no-JS `<a href>` fallback resolves. Run against a `@wordpress/env` instance. |

## The boundary that matters most: ingestion is conforming by construction

The security and correctness spine of this plugin is that **nothing non-conforming can enter a collection through the plugin**, and that an attacker-controlled `relativePath` cannot escape the collection root. These get the heaviest, most adversarial coverage.

### Path traversal and `realpath` confinement (ADR-0006)

The Drop Zone REST endpoint and `image import` both accept a caller-supplied relative path and recreate sub-directories under the collection root. The sanitiser is the trust boundary. Unit-test that, given the collection root, the resolved target path is always inside the root for benign input and is **rejected** (never written, never resolved outside) for hostile input:

- `../`, `..\\`, and deep `../../../../etc/passwd` sequences.
- Absolute paths (`/etc/passwd`, `C:\Windows\…`), UNC paths, `file://` and other schemes.
- Leading-slash, leading-`./`, and mixed-separator inputs.
- Encoded traversal (`%2e%2e%2f`, double-encoded, overlong UTF-8) — decoded before the check, then rejected.
- Embedded NUL bytes and control characters.
- Symlink games: a sanitised path whose `realpath` lands outside the root is rejected (the confinement check is on the *resolved* path, not the lexical one).
- Empty path and `.`/single-segment paths resolve to the root itself, not above it.

Assert both halves: hostile input yields no write **and** a `rejected` outcome, and the realpath is confined for every accepted input.

### Path-components template expansion (ADR-0014)

The collection's mutable `pathComponents` template (default `%year%/%month%/%day%/%uploader%`) is expanded server-side and prefixed before each Drop Zone upload's own relative path. The expansion and its validation are pure-unit territory:

- `%year%`/`%month%`/`%day%` expand to the upload date **in the site's timezone**, not UTC; `%uploader%` expands to the authenticated uploader's `sanitize_title`'d `user_nicename` (with a user-ID fallback), so it can never be client-spoofed.
- At **save**, the template is normalised (split on `/`, strip leading/trailing separators, collapse empty segments; an empty result falls back to the default), the `%`-reservation is enforced (any `%` left after the four known placeholders — e.g. a mistyped `%moth%` — is **rejected**), and the expanded-with-sample-values template is run through the `Path_Guard` **lexical** checks so an unsafe template such as `%year%/../../x` is rejected on submit.
- At **upload**, the full `Path_Guard` (lexical + realpath confinement) runs on the real expanded path — the same boundary as any other relative path.
- The template governs the **Drop Zone path only**: CLI `image import` writes its literal target with no expansion.

### Server-side contract re-enforcement (ADR-0002, ADR-0006)

The client Canvas optimisation is a bandwidth optimisation, **not** the boundary. A file POSTed straight to the REST endpoint (bypassing the browser) must still be made conforming. Test the same code path that `image import` uses:

- An over-ceiling image (wider than the collection's **upload width**) is downscaled to the ceiling and re-encoded to WebP; the stored main's width equals the ceiling.
- A non-WebP input (JPEG, PNG) is converted to WebP; the stored bytes are WebP.
- An already-conforming WebP at or under the ceiling is **accepted as-is** (no re-encode — avoids a second lossy pass). Assert the stored bytes are byte-identical to the input.
- An **empty upload width** (no limit) never upscales: a small image is stored at its own width.
- Upload quality is applied from the descriptor, not from any client-supplied value.
- The server **derives the full image and thumbnail** from the stored main (each tier skipped when the main is no wider), so the right derived files exist after a single upload.
- The per-file response is exactly one of `stored | skipped | reencoded | rejected`, and one failing file never aborts the batch.

### `<original>.webp` naming, including no-double-`.webp` (ADR-0003)

The main image is stored as the original filename with `.webp` appended, except an input that is already `.webp` is not doubled:

- `IMG_2024.jpg` → `IMG_2024.jpg.webp`.
- `panorama.png` → `panorama.png.webp`.
- `sunset.webp` → `sunset.webp` (**not** `sunset.webp.webp`).
- `Photo.WEBP` (uppercase) → not doubled (case-insensitive extension check), stored conventionally.
- Names with dots (`a.b.c.jpg` → `a.b.c.jpg.webp`) and unicode names round-trip.
- The rule is reversible: the stored name maps back to the original for display.

### Nonce + capability gates (ADR-0006, ADR-0015)

Every write REST endpoint defends two different things and needs both — a valid `wp_rest` nonce **and** the gating capability — and **every capability check reads from a `kntnt_photo_drop_*_capability` filter**, so each gate must be asserted both at its default and through its filter:

- **Drop Zone upload** (`POST …/collections/<slug>/images`): no valid nonce → rejected (forgery protection), even for a logged-in user; valid nonce but the user lacks `upload_files` → rejected (authorisation), so a self-registered Subscriber on an open-registration site cannot write files; both present → accepted. Overridable via `kntnt_photo_drop_upload_capability`.
- **Add-to-media** (`POST …/collections/<slug>/media`): same two-factor gate, default `upload_files`, overridable via `kntnt_photo_drop_add_to_media_capability`; the target image's `path` is `Path_Guard`-confined.
- **Trash** (`DELETE …/collections/<slug>/images`): same two-factor gate, default `delete_others_posts`, overridable via `kntnt_photo_drop_delete_capability`; the target image's `path` is `Path_Guard`-confined.
- As defence in depth, the Drop Zone **and** the gallery's action overlays render their UI **and** their nonce only for users who hold the relevant capability — assert the nonce is absent from the markup for an un-capable user.
- The admin lifecycle page is gated by `manage_options` (`kntnt_photo_drop_manage_capability`); the editor-only broken-reference notice by `edit_posts` (`kntnt_photo_drop_editor_notice_capability`); the collection-list REST route by `edit_posts` (`kntnt_photo_drop_list_capability`). CLI runs trusted with no capability check.

## Index self-heal via `dirMtime` (ADR-0003)

The per-folder `index.json` (inside `kntnt-thumbnails/`) is a regenerable cache validated by the content folder's directory `mtime`:

- Stored `dirMtime` matches the folder `mtime` → the index is trusted; no image is re-read for dimensions.
- A file added, removed, renamed, or moved bumps the folder `mtime` → the index is regenerated on the next gallery view (dimensions read once, written back, images stored sorted ascending).
- The upload handler writes only the main image and its derived renditions (full image, thumbnail) and **never touches the index** — a several-hundred-file batch causes no index write contention; the index self-heals once on the next view.
- A move bumps both the source and destination folder `mtime`s, so both indexes regenerate.
- Dimensions (`width`, `height`) stored in the index match the main image and are what feed `aspect-ratio` and `srcset`.

## Doctor reconciliation

`collection doctor` is report-only by default (the report is the dry run); `--repair` acts; `--repair --force` re-derives everything. Drive a real on-disk collection and assert the report and the post-repair state:

- Main present, derived artifact (full image, thumbnail, or index entry) missing → **created** by `--repair`.
- Main missing, derived artifact present → orphan **removed** by `--repair`.
- A main image no wider than a derived tier's width needs no separate file there (the next tier up serves both roles) and is **not** flagged.
- A contract-violating main (over ceiling, wrong format, arrived by out-of-band copy) is **warned about**, never processed in place, never deleted — even with `--repair`.
- `--repair --force` regenerates all derived renditions after a full- or thumbnail-size change in the descriptor.
- Foreign files are warned about, except the built-in OS-junk ignore list (`.DS_Store`, `._*`, `.Spotlight-V100`, `.Trashes`, `.fseventsd`, `Thumbs.db`, `desktop.ini`); a user's own `.thumbnails` is foreign, not ours. `--ignore=<glob>` extends the list; `--show-ignored` reveals what was skipped.
- Doctor never alters main images and never deletes foreign files.

## Gallery rendering: `srcset`, ordering, and overlays (ADR-0005, ADR-0013, ADR-0015)

- `srcset` is `{ thumbnail, full }`, with each candidate's real pixel width; the **full image is the display ceiling**, and the (possibly unbounded) main is download-only, so it is **not** a srcset candidate when it exceeds the full width. When the main is no wider than the full width it *is* the full rendition and stays a candidate, so nothing is upscaled there.
- `sizes`/dimensions come from the stored index, so the markup carries `width`/`height` (or `aspect-ratio`) → zero layout shift.
- Recursive-flatten ordering is a **pre-order tree traversal** (a folder's own images before its subfolders, natural sort within each level); descending reverses the sort within each level while keeping that structure ([ADR-0015](../docs/adr/0015-gallery-overlays-and-rest-write-path.md)).
- The start path is an editor-set attribute validated once against the collection root — there is **no** visitor-controllable path query parameter (the traversal surface is gone by design); assert the renderer ignores any request-time path input.
- A no-collection or broken reference renders nothing for the public and an editor-only notice for a logged-in user; an imageless-but-valid collection renders the `emptyMessage` (or its translated default) to everyone ([ADR-0012](../docs/adr/0012-imageless-gallery-shows-a-public-message.md)).
- **Overlays** format and place correctly ([ADR-0015](../docs/adr/0015-gallery-overlays-and-rest-write-path.md)): each of breadcrumbs / download / add-to-media / trash honours its visibility (Off / Thumbnail / Full / Both, with "Full" requiring the lightbox) and its nine-point position; icons sharing a position cluster in fixed order; breadcrumbs are mutually exclusive with the icon band. The **breadcrumb string** is the humanised folder path with the collection display name as the first crumb, the hide-count drops leading crumbs, and the separator defaults to `›`. The action icons render only when the current user holds the matching capability, and the slideshow carries no overlays.

## Gallery write overlays: add-to-media and trash (ADR-0015)

The two action overlays are real filesystem + REST, so the RED is an **integration test** against `@wordpress/env` (with capability/nonce gating and `Path_Guard` confinement unit-checked on top — never mocked into a tautology):

- **Add-to-media copies, never links.** A confirmed action sideloads the **main image** into the Media Library as an ordinary, independent attachment; assert the attachment really exists (a real post + file, WordPress sub-sizes generated) and that the collection image is untouched — deleting one never affects the other. Each confirmed click adds another copy.
- **Trash is a permanent delete.** A confirmed action removes the main image **and** its derived artifacts (reusing the `image delete` path); assert the main, full, and thumbnail files are gone, the index self-heals on the next render, and there is no recycle bin.
- Both endpoints carry the target image's `Path_Guard`-confined `path`; a hostile `path` is rejected (no sideload, no delete).

## Regenerate-then-flip of a re-derivable size (ADR-0013)

Editing a full or thumbnail width/quality regenerates the affected derived artifacts by **regenerate-then-flip**, which is real-filesystem behaviour (integration-first):

- The new-width derived files are written **while the gallery keeps serving the old ones** — the descriptor's active widths flip only on success, then the old-width files are pruned.
- An **interrupted** run is safe: the descriptor was never flipped, so the gallery keeps serving the old renditions and a re-run reconciles.
- The upload contract (upload width/quality) is never touched by this path.

## Drop Zone progress UI (#44)

The upload-queue accounting behind the progress UI is a pure browser helper (Jest), with the DOM/aggregate-bar behaviour covered by e2e:

- **Bucket accounting:** each per-file terminal outcome (`stored` / `skipped` / `reencoded` → counted as uploaded; `rejected` / transport failure → failed) lands in the right bucket; the aggregate is files-reaching-a-terminal-state ÷ total, shown as "N / total".
- **Cancel** aborts in-flight XHRs and stops the queue while leaving already-uploaded files in place.
- The **three-bucket summary** (Uploaded as a count; Skipped and Failed listed by filename) renders on completion or cancel, and **Retry-failed** re-queues only the failures.
- The upload queue **dedupes by source-relative path** across every flow (loose files, dropped folder, folder picker), so two files sharing a basename in different sub-folders both upload.

## CLI surface (ADR-0004)

Integration-test the grouped commands against a real WP-CLI:

- `collection create <slug>` takes `slug` positionally and the upload/full/thumbnail rendition flags plus `--path-components` and `--name`, each defaulting to the value the admin form pre-fills (`--upload-width` empty, `--upload-quality` 95, `--full-width` 1920, `--full-quality` 85, `--thumbnail-width` 640, `--thumbnail-quality` 75, `--path-components` `%year%/%month%/%day%/%uploader%`, `--name` a humanised slug); it writes a valid `collection.json` with the six-field rendition shape plus `pathComponents`.
- `collection update <slug>` mutates `--name`, `--path-components`, and the re-derivable full/thumbnail flags (regenerating derived renditions when those change), and **rejects** `--upload-width`/`--upload-quality` (the immutable contract).
- `collection delete` and `image delete` prompt unless `--yes`.
- `image import <slug> <source…>` requires an existing collection, carries no contract flags, optimises to the target contract, and is idempotent (skip-if-exists, `--overwrite` to force). It writes to the literal target path with no path-components expansion (that template governs the Drop Zone path only).
- `doctor` and `import` present their per-file results as `format_items()` tables.

## Updater

Mirror gpx-blocks: do **not** hit live GitHub. Stub `wp_remote_get` via Brain Monkey to return canned release JSON and assert the transient is populated only when a newer version with a `application/zip` asset exists, and passed through untouched when the payload is `false` or already current.

## What is deliberately not tested

- **The Canvas API, the Interactivity API runtime, WordPress core, GD/Imagick internals.** We test our wrapper logic and our wiring, not the libraries' own behaviour.
- **Visual rendering and feel.** No screenshot-diff tests; gallery appearance is theme-dependent. The irreducibly subjective — how the overlays *look* at each nine-point position, the justified-row aesthetics, the slideshow's dissolve and pacing, the perceived smoothness of the regenerate-then-flip — is a human-verification item (see [`definition-of-done.md`](definition-of-done.md)); the automation asserts the *logic and wiring* underneath (positions emitted, buckets counted, the descriptor flipped only on success).
- **The Updater against live GitHub** (network flakiness + drift); it is unit-tested with stubbed HTTP.
- **Translation loading** (a WordPress responsibility), though a source-strings test asserts the `__()` strings in code match the `.pot`.

## Running the suite

| Layer | Command |
|---|---|
| PHP unit | `composer test` |
| PHP static analysis | `composer phpstan` |
| PHP code style | `composer phpcs` |
| Block JS lint | `npm run lint:js` |
| Block CSS lint | `npm run lint:css` |
| Block JS unit | `npm run test:js` |
| Integration | `npm run test:integration` (boots `@wordpress/env`) |
| End-to-end | `npm run test:e2e` (Playwright against `@wordpress/env`) |
