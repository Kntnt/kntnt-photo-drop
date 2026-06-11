# Testing strategy

What is tested, with what tooling, and what is deliberately not. Read this when adding tests, wiring the build pipeline, or deciding whether a change needs new coverage. For the bar a change must clear, see [`definition-of-done.md`](definition-of-done.md); for the toolchain, see [`coding-standards.md`](coding-standards.md).

## Test pyramid

| Layer | Tooling | Where | What it covers |
|---|---|---|---|
| PHP unit | Pest + Brain Monkey + Mockery | `tests/Unit/` | Pure-ish domain logic: path sanitisation + `realpath` confinement, the `<original>.webp` naming rule, contract conformance checks, the doctor's reconciliation algorithm, descriptor/index read-write, natural-sort ordering, caption formatting, `srcset` assembly. No real WordPress, no real filesystem where a temp dir + Brain Monkey stub will do. |
| PHP integration | WordPress (via `@wordpress/env`, or `@wp-playground/cli` where no browser is needed) + Pest | `tests/Integration/` | The plugin loads, both blocks register, the REST upload endpoint round-trips, the WP-CLI `collection`/`image` commands run, the doctor reconciles a real on-disk collection, the index self-heals on a real directory `mtime` bump. |
| Block JS unit | Jest via `wp-scripts test-unit-js` | `src/blocks/<slug>/*.test.ts(x)` (co-located) | Pure browser helpers behind the Drop Zone (the Canvas downscale + `canvas.toBlob(…, 'image/webp', q)` encode wrapper, the `webkitRelativePath` → relative-path mapping, the recursive dropped-folder walk) and the Gallery (justified-row `flex-grow`/`flex-basis` math, caption-string assembly, lightbox index/keyboard reducers, the slideshow advance gate and trigger-target resolution). |
| Block end-to-end | Playwright + `@wordpress/e2e-test-utils-playwright` | `tests/e2e/` | Insert each block in the editor; the Drop Zone uploads fixtures (loose files and a dragged folder) and the Gallery renders them; the lightbox opens, navigates, traps focus, and closes; the download icon saves the image; the slideshow starts from its triggers and advances; the no-JS `<a href>` fallback resolves. Run against a `@wordpress/env` instance. |

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

### Server-side contract re-enforcement (ADR-0002, ADR-0006)

The client Canvas optimisation is a bandwidth optimisation, **not** the boundary. A file POSTed straight to the REST endpoint (bypassing the browser) must still be made conforming. Test the same code path that `image import` uses:

- An over-ceiling image (wider than the collection's `maxWidth`) is downscaled to the ceiling and re-encoded to WebP; the stored main's width equals the ceiling.
- A non-WebP input (JPEG, PNG) is converted to WebP; the stored bytes are WebP.
- An already-conforming WebP at or under the ceiling is **accepted as-is** (no re-encode — avoids a second lossy pass). Assert the stored bytes are byte-identical to the input.
- `maxWidth = null` (no limit) never upscales: a small image is stored at its own width.
- Quality is applied from the descriptor, not from any client-supplied value.
- The per-file response is exactly one of `stored | skipped | reencoded | rejected`, and one failing file never aborts the batch.

### `<original>.webp` naming, including no-double-`.webp` (ADR-0003)

The main image is stored as the original filename with `.webp` appended, except an input that is already `.webp` is not doubled:

- `IMG_2024.jpg` → `IMG_2024.jpg.webp`.
- `panorama.png` → `panorama.png.webp`.
- `sunset.webp` → `sunset.webp` (**not** `sunset.webp.webp`).
- `Photo.WEBP` (uppercase) → not doubled (case-insensitive extension check), stored conventionally.
- Names with dots (`a.b.c.jpg` → `a.b.c.jpg.webp`) and unicode names round-trip.
- The rule is reversible: the stored name maps back to the original for display.

### Upload nonce + capability gate (ADR-0006)

The REST endpoint defends two different things and needs both:

- No valid `wp_rest` nonce → request rejected (forgery protection), even for a logged-in user.
- Valid nonce but the user lacks `upload_files` → request rejected (authorisation), so a self-registered Subscriber on an open-registration site cannot write files.
- Both present → accepted. The capability is overridable via `kntnt_photo_drop_upload_capability`.
- As defence in depth, the Drop Zone block renders its UI **and** its nonce only for users who hold the capability — assert the nonce is absent from the markup for an un-capable user.
- The admin lifecycle page is gated by `manage_options` (filter `kntnt_photo_drop_manage_capability`); CLI runs trusted with no capability check.

## Index self-heal via `dirMtime` (ADR-0003)

The per-folder `index.json` (inside `.kntnt-thumbnails/`) is a regenerable cache validated by the content folder's directory `mtime`:

- Stored `dirMtime` matches the folder `mtime` → the index is trusted; no image is re-read for dimensions.
- A file added, removed, renamed, or moved bumps the folder `mtime` → the index is regenerated on the next gallery view (dimensions read once, written back, images stored sorted ascending).
- The upload handler writes only the main image and its thumbnail(s) and **never touches the index** — a several-hundred-file batch causes no index write contention; the index self-heals once on the next view.
- A move bumps both the source and destination folder `mtime`s, so both indexes regenerate.
- Dimensions (`width`, `height`) stored in the index match the main image and are what feed `aspect-ratio` and `srcset`.

## Doctor reconciliation

`collection doctor` is report-only by default (the report is the dry run); `--repair` acts; `--repair --force` re-derives everything. Drive a real on-disk collection and assert the report and the post-repair state:

- Main present, thumbnail or index entry missing → **created** by `--repair`.
- Main missing, derived artifact present → orphan **removed** by `--repair`.
- A main image smaller than the thumbnail width needs no separate thumbnail and is **not** flagged.
- A contract-violating main (over ceiling, wrong format, arrived by out-of-band copy) is **warned about**, never processed in place, never deleted — even with `--repair`.
- `--repair --force` regenerates all thumbnails after a `kntnt_photo_drop_thumbnail_width` change (e.g. new width array).
- Foreign files are warned about, except the built-in OS-junk ignore list (`.DS_Store`, `._*`, `.Spotlight-V100`, `.Trashes`, `.fseventsd`, `Thumbs.db`, `desktop.ini`); a user's own `.thumbnails` is foreign, not ours. `--ignore=<glob>` extends the list; `--show-ignored` reveals what was skipped.
- Doctor never alters main images and never deletes foreign files.

## Gallery rendering: `srcset` and ordering (ADR-0005)

- `srcset` lists every thumbnail width plus the main, with each candidate's real pixel width; the main is always a candidate, so the browser never upscales a thumbnail.
- `sizes`/dimensions come from the stored index, so the markup carries `width`/`height` (or `aspect-ratio`) → zero layout shift.
- Recursive-flatten ordering is by full relative path (natural sort, ascending/descending per the block attribute) so each folder's images stay contiguous.
- The start path is an editor-set attribute validated once against the collection root — there is **no** visitor-controllable path query parameter (the traversal surface is gone by design); assert the renderer ignores any request-time path input.
- A dangling collection reference renders nothing for the public and an editor-only notice for a logged-in user.
- Captions (none / filename / path-breadcrumb) format correctly, including the humanise toggle, the include-collection-name toggle (default off), and the separator (default `›`).

## CLI surface (ADR-0004)

Integration-test the grouped commands against a real WP-CLI:

- `collection create <slug>` requires `--max-width` and `--quality` (the contract is irreversible); `--name` defaults to a humanised slug; it writes a valid `collection.json`.
- `collection update <slug> --name=…` changes the display name only and **rejects** any attempt to change the immutable contract.
- `collection delete` and `image delete` prompt unless `--yes`.
- `image import <slug> <source…>` requires an existing collection, carries no contract flags, optimises to the target contract, and is idempotent (skip-if-exists, `--overwrite` to force).
- `doctor` and `import` present their per-file results as `format_items()` tables.

## Updater

Mirror gpx-blocks: do **not** hit live GitHub. Stub `wp_remote_get` via Brain Monkey to return canned release JSON and assert the transient is populated only when a newer version with a `application/zip` asset exists, and passed through untouched when the payload is `false` or already current.

## What is deliberately not tested

- **The Canvas API, the Interactivity API runtime, WordPress core, GD/Imagick internals.** We test our wrapper logic and our wiring, not the libraries' own behaviour.
- **Visual rendering.** No screenshot-diff tests; gallery appearance is theme-dependent. Visual/UX correctness is a human-verification item (see [`definition-of-done.md`](definition-of-done.md)).
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
