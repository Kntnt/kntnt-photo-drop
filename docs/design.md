# kntnt-photo-drop — Design

## Status

This plan was written before the code and the plugin has since been built from it; the document is kept current as the settled design. Its open questions were resolved in a `grill-with-docs` session; the decisions with real trade-offs are recorded as ADRs under `docs/adr/` and the domain language is in `CONTEXT.md`. Sections below reflect the settled design and link the ADR that owns each load-bearing decision.

## Purpose

A WordPress plugin with two Gutenberg blocks. A field photographer logs in to a page carrying the **Photo Drop Zone** block and drags in one image, many images, or a folder of images; each is downscaled, converted, and compressed in the browser before upload. Anyone can later visit a page carrying the **Photo Drop Gallery** block and browse the result, with a lightbox. Built block-first and server-rendered, in the spirit of kntnt-gpx-blocks.

## The two blocks

**Photo Drop Zone** — a capability-gated front-end uploader bound to one collection. It is a *consumer*: it selects an existing collection from a dropdown and uploads into it. It cannot create or reconfigure collections. Its inspector is just the collection selector plus a read-only display of that collection's contract (max width, quality, WebP, thumbnail width) — nothing to edit, so nothing can conflict.

**Photo Drop Gallery** — a public, server-rendered gallery of a chosen collection, with a lightbox.

## Storage — see ADR-0001, ADR-0003

The plugin has a **single storage model: collections on disk**, outside the Media Library, under `wp_upload_dir()['basedir']/kntnt-photo-drop/<slug>/` (filter `kntnt_photo_drop_root`; must stay web-served, per-site on multisite). No database rows are created; the filesystem is the source of truth. There is deliberately **no Media-Library-backed mode** — the Media Library cannot enforce the output contract, so it would break "conforming by construction." Images are served **directly by URL**, so collections are **public-by-path** (directory listing disabled so paths can't be enumerated).

A collection's **identity is its directory slug**; a block stores the slug to point at a collection. The human **display name** lives in the descriptor. Renaming a collection is just `mv` on the directory (the filesystem is the truth); blocks that referenced the old slug then dangle, which is expected.

On disk, per content folder:

- main images as `<original-filename>.webp` (original name preserved, `.webp` appended; an already-`.webp` input is not doubled) — collision-free on the case-sensitive Linux server;
- one **visible `collection.json`** at the collection root (the descriptor — the one irreplaceable file);
- one **hidden `.kntnt-thumbnails/`** holding all regenerable artifacts: thumbnails at `.kntnt-thumbnails/<width>/<name>.webp` and that folder's `index.json`.

`collection.json` = `{ schema, name, maxWidth, quality, thumbnailWidths, uploaderFolders }`. `index.json` = `{ schema, dirMtime, subdirs, images: [{ file, width, height }] }`, with images stored sorted ascending.

## Output contract and immutability — see ADR-0002

Each collection carries an immutable **output contract** of exactly two lossy values fixed at establishment: **maximum width** (e.g. 1920, configurable, `null` = no limit) and **compression quality** (e.g. 80). The stored **format is always WebP** — not a choice; inputs in other formats are accepted and converted. Because images are downscaled and re-encoded at ingestion and the original is not kept, the contract is irreversible; raising the maximum later cannot retroactively enlarge already-imported images.

**Thumbnail width is *not* part of the immutable contract** — unlike max-width/quality it is losslessly re-derivable from the main image, so it is a changeable setting: supplied by filter `kntnt_photo_drop_thumbnail_width` (default `640`; may return an array; `[]`/`0` = no thumbnail), recorded in the descriptor, changed by re-running `wp kntnt-photo-drop collection doctor <slug> --repair --force`. There is no UI field and no `create` flag for it. The CLI/admin contract defaults come from `kntnt_photo_drop_default_max_width` (1920) and `kntnt_photo_drop_default_quality` (80), which pre-fill the admin form.

The descriptor also carries **`uploaderFolders`** — a boolean **fixed at establishment** (default `true`), set on the admin create form and the `collection create` CLI alongside the contract, then never changed (flipping it later would split one uploader's images across two layouts on disk with no migration path). When it is on, every Drop Zone upload is placed under a first path segment derived **server-side** from the authenticated uploader's `user_nicename` — an **uploader folder** — ahead of the upload's own relative path; the segment is computed from the user, never the client, so it cannot be spoofed, and it both records uploader provenance (the only ADR-compatible carrier, since the index is a regenerable cache and EXIF is stripped) and eliminates cross-uploader filename collisions. The trade-off is that nicenames then appear in public image URLs, which is mild because WordPress already exposes the same nicename in every author-archive URL. The rule applies to the **Drop Zone REST path only**: CLI `image import` runs in a trusted context with no authenticated uploader and is unaffected, and the doctor stays neutral — an uploader folder is an ordinary content folder and nothing about this option produces a foreign file (ADR-0008).

## Upload / ingestion pipeline — see ADR-0006

The Drop Zone is an **`InnerBlocks` wrapper** whose whole inner-block surface is a **native drag-drop + click-to-browse** zone (no FilePond — the block's own intake, queue, and progress UI), with client-side optimisation via the **Canvas API** (`createImageBitmap` decode → canvas downscale → `canvas.toBlob(…, 'image/webp', quality)`, no extra assets; a file the browser cannot decode falls back to uploading the original bytes, which the server then converts). `render.php` reads the selected collection's descriptor and configures the client optimisation from it. **The client optimisation is a bandwidth optimisation, not the security boundary**: the server re-applies the contract on every upload (same code path as `image import`) — accept as-is if already conforming (avoids double compression), otherwise decode/downscale/re-encode — so a file POSTed directly to the API cannot enter non-conforming. The server side of the boundary is hardened: sources declaring more pixels than the `kntnt_photo_drop_max_input_megapixels` ceiling (default 50) are rejected per-file before any decode (decompression-bomb defence); EXIF orientation is applied to the pixels before scale/encode, so CLI imports and direct POSTs come out upright; an already-conforming WebP is decode-validated before being accepted byte-identical, and its `EXIF`/`XMP` RIFF chunks are stripped losslessly so pass-through files leak no GPS metadata; mains, thumbnails, the descriptor, and the index are all published atomically (temp file + rename), so a reader never sees a torn file.

Folder structure is preserved with **identical semantics for a drop and the "Select folder" picker** (`webkitdirectory`): a dropped folder is traversed recursively and each file's source-relative path is carried per file (exactly as `webkitRelativePath` does for the picker), and the server recreates the sub-directories (path hard-sanitised and `realpath`-confined). Plain drag-and-drop onto the surface also handles loose files, and a click anywhere on the surface opens a hidden loose-file picker. There is no detect-warn-flatten step: dropping a folder and choosing it through the picker land the same tree (ADR-0008).

Each main image gets its **thumbnail(s)** generated at upload. The upload handler writes only the main image and its thumbnail(s) and **never touches the index** — the index self-heals via `dirMtime` on the next gallery view, so a several-hundred-file batch causes no index write contention.

**REST surface (the only write path; the gallery needs no REST):** `POST /wp-json/kntnt-photo-drop/v1/collections/<slug>/images`, one file per request, multipart (file + `relativePath`). Requires a valid `wp_rest` nonce **and** `current_user_can('upload_files')`. Per-file response (`stored | skipped | reencoded | rejected`); a clash with an existing path skips by default; no chunking (files are pre-downscaled).

## Capability model — see ADR-0006

No bespoke capabilities. Viewing a gallery is plain page access; placing the blocks is plain `edit_posts`. Uploading via the Drop Zone (and its endpoint) requires **`upload_files`** (filter `kntnt_photo_drop_upload_capability`); managing collections on the admin page requires **`manage_options`** (filter `kntnt_photo_drop_manage_capability`). As defence in depth the Drop Zone renders its UI and nonce only for users holding the upload capability. CLI runs in a trusted context with no capability check.

## Collection lifecycle and discovery

Collection lifecycle — create (fixing the immutable contract), update (display name only), and delete — lives on a **dedicated admin page** gated by `manage_options`, and on the CLI (a deliberate context). Blocks are select-only consumers; they never create or reconfigure a collection.

Collection discovery is a **directory scan**: a collection is any directory under the uploads root that contains a `collection.json`. The Drop Zone dropdown, the Gallery selector, and the admin page all list collections this way, so a collection copied in from another site appears automatically and a deleted directory disappears — no registry to keep in sync.

## Index design — see ADR-0003

Per-folder `index.json` (hidden inside `.kntnt-thumbnails/`) gives locality and is validated by the folder's directory **mtime** — one `stat` tells whether anything was added, removed, renamed, or moved (a move bumps both folders). If the stored `dirMtime` matches, trust the index; otherwise regenerate (scan, read dimensions once, write back). Because mtime has one-second granularity, a rebuild whose stamped `dirMtime` is still the current second is **not persisted** (the in-memory index is served and the next read rebuilds again) — otherwise an image written later within the same second would be invisible behind a cache hit forever. The index is a regenerable cache; the directory is the truth. Dimensions needed for `srcset` and to avoid layout shift are stored in the index, computed once at build. `subdirs` is listed for completeness (recursive gallery walks the tree); symlinked entries are skipped by the walk, mirroring the delete path's treat-as-leaf stance.

## Doctor

`collection doctor` is **report-only by default** (the report is the dry run). `--repair` acts; `--repair --force` re-derives everything (e.g. after a thumbnail-width change). The main image is the unit of truth:

- main present, derived artifact (thumbnail or index entry) missing → **create** it.
- main missing, derived artifact present → **remove** the orphaned artifact.
- An image smaller than the thumbnail width needs no separate thumbnail (it serves both roles) and is not flagged.
- A main image that violates the contract (over the ceiling, wrong format) — which can only arrive by an out-of-band copy — is **warned** about, never processed in place, never deleted.

`doctor` never alters main images and never deletes foreign files, even with `--repair`. **Foreign files** are warned about, except a short built-in **ignore list** of OS junk (Mac: `.DS_Store`, `._*`, `.Spotlight-V100`, `.Trashes`, `.fseventsd`; Windows: `Thumbs.db`, `desktop.ini`) — and a user's own `.thumbnails` (from other photo tools) is foreign, not ours. `--ignore=<glob>` extends the list; `--show-ignored` reveals what was skipped.

## Command-line surface — see ADR-0004

WP-CLI, grouped by object with verb subcommands:

```
wp kntnt-photo-drop collection create <slug> --name="…" --max-width=1920 --quality=80 [--no-uploader-folders]
wp kntnt-photo-drop collection update <slug> --name="…"      # mutable name only; rejects contract + uploader-folders changes
wp kntnt-photo-drop collection delete <slug> [--yes]
wp kntnt-photo-drop collection doctor <slug> [--repair] [--force] [--ignore=<glob>] [--show-ignored]
wp kntnt-photo-drop image      import <slug> <source…> [--overwrite]
wp kntnt-photo-drop image      delete <slug> <path> [--yes]
```

`create` takes `slug` positionally; `--name` is optional (defaults to a humanised slug); `--max-width` and `--quality` are **required flags** (the contract is irreversible); `--uploader-folders` is optional and defaults to on, fixed at establishment alongside the contract (pass `--no-uploader-folders` to land Drop Zone uploads at the collection root; [ADR-0008](adr/0008-ingestion-placement-hierarchy-and-uploader-folders.md)). `update` mutates only `--name` and rejects `--max-width`, `--quality`, and `--uploader-folders` alike, since all three are fixed at establishment. `import` requires an existing collection, carries no contract flags, and is idempotent (skip-if-exists, `--overwrite` to force). Both deletes prompt unless `--yes`. `doctor` and `import` present their per-file results as `format_items()` tables. Deliberately **not** included: `verify` (subsumed by doctor), `list`/`mv` (the filesystem plus `find` and self-healing indexes cover them), and a standalone in-place `process`.

## Gallery rendering — see ADR-0005, ADR-0007

The Gallery block targets a collection (slug) plus an optional **start path** (default root, both editor-set) and renders **all images under that path recursively as one flattened gallery** — no clickable folder tiles, no in-gallery navigation. A per-block toggle switches to "this folder only." To present folders separately, place multiple blocks and compose with native page-building. The recursive case orders by **full relative path** (natural sort) so each folder's images stay contiguous.

- **Ordering** is a block attribute (not visitor-controllable): natural filename sort, ascending/descending.
- **Captions** are a presentation block setting and are **always an overlay inside the image** (issue #33; there is no under/above position): content = none / filename / path-breadcrumb (humanise toggle; "include collection name" toggle, default off; separator free-text, default `›`); plus a nine-point anchor. The caption's colour and font come from the **Colour** and **Typography** block-support panels, and each image's border and shadow from the **Border** and **Shadow** panels — all declared with `__experimentalSkipSerialization` and projected server-side onto the right sub-element (figcaption / img) through the style engine (`wp_style_engine_get_styles`), the core Image-block pattern, rather than onto the block wrapper. Note: re-encoding strips all EXIF/IPTC, and the server strips `EXIF`/`XMP` chunks losslessly from pass-through WebP, so there is no embedded caption or capture date to draw on — caption *content* is filename/path only.
- **Layout** delegates to core block supports where possible. Mode toggle **A (uniform grid)** vs **B (justified rows)**. A uses core's Grid layout (`minimumColumnWidth` default 320px) + bespoke aspect-ratio and fit; stored dimensions set `aspect-ratio` → zero layout shift. B is bespoke justified rows (per-image `flex-grow`/`flex-basis` from stored dimensions, target row height default 240px, last row left-aligned). The inter-item gap is the **Block spacing** support (`blockGap`, default 12px), read server-side into both layout containers. Each figure is the sizing box and the overlay caption's positioning context, with the link and image filling it absolutely so neither the caption nor the image is clipped to nothing. Gallery width and alignment use core width/alignment supports; the Colour and Typography supports are claimed by the caption via skip-serialisation (see the Captions bullet), projected onto the figcaption rather than the block wrapper.
- **Lightbox** is built with the WordPress **Interactivity API** in TypeScript (open/close, prev/next, keyboard, swipe, neighbour preload, focus trap, `aria`), with a no-JS `<a href="full.webp">` fallback. This supersedes a CSS `:target` lightbox (a11y).
- **Slideshow** ([ADR-0009](adr/0009-slideshow-passive-surface-pluggable-trigger.md)) is a third, optional surface beside the grid and the lightbox: a visitor-started, endlessly looping fullscreen playback of exactly the gallery's view, passive except for exiting (Escape, native fullscreen exit, a close button). Each slide stands for a configurable number of seconds, then dissolves (~1 s; a hard cut under `prefers-reduced-motion`); the next image preloads during display and the playback never advances to an unloaded image. The trigger is a three-state block attribute: off, a quiet built-in button above the gallery, or any designer-placed element carrying `data-kntnt-photo-drop-slideshow` (its value targets a gallery by its HTML anchor; valueless targets the page's first slideshow-enabled gallery). At each cycle boundary the slideshow re-syncs its slide list to the gallery's current view by refetching the page ([ADR-0011](adr/0011-slideshow-cycle-boundary-resync-by-page-refetch.md)): images uploaded or deleted during playback appear or disappear on the next cycle, a single-image gallery cycles (and re-syncs) on its lone slide's visible time, and an emptied view ends the playback; the grid and the lightbox stay frozen at the page-load view.

CSS Grid, native `loading="lazy"`, and `srcset` (thumbnail width(s) + main; the browser picks by rendered size and DPR, and never shows a thumbnail upscaled because the main is always a candidate). No jQuery. Virtualisation is only considered at thousands-of-images scale.

## Distribution and privacy

**Distribution:** mirror kntnt-gpx-blocks — GitHub Releases plus an `Updater` class.

**Privacy:** no third-party request to gate. The Interactivity API is a bundled local asset, the drop surface is the block's own code (no third-party uploader library), WebP encoding is local (Canvas), and images are first-party files served from the site — there is no equivalent of gpx-blocks' map-tile consent. The only external call is the admin-side `Updater` checking GitHub for releases, which is not a visitor-facing embed.

## Settled decisions (recorded as ADRs)

1. **Bespoke plugin**, not NextGEN/Piwigo/Lychee (ADR-0001).
2. **Client-side optimisation via Canvas**, not WebAssembly (jSquash) — Cimo's proven approach, zero extra assets.
3. **Filesystem collections, no database rows, no Media Library backend** (ADR-0001).
4. **Per-folder `index` (hidden, regenerable) validated by directory mtime** (ADR-0003); rejected a central manifest and inode keys.
5. **Immutable WebP output contract (max-width + quality); thumbnail width re-derivable & filter-driven; blocks select-only; lifecycle on the admin page** (ADR-0002).
6. **Slug-as-identity; `<original>.webp` mains; hidden `.kntnt-thumbnails/`** (ADR-0003).
7. **Dropped folders preserve hierarchy (drop ≡ "Select folder" picker); optional immutable per-uploader namespace `uploaderFolders`** (ADR-0008).
8. **CLI = grouped `collection {create,update,delete,doctor}` + `image {import,delete}`; `import` is a pure consumer** (ADR-0004).
9. **Downscaling at ingestion is lossy** — the stored main is the ceiling; no later upscaling (ADR-0002).
10. **Recursive-flatten gallery; no in-gallery folder navigation; compose with multiple blocks** (ADR-0005).
11. **Server re-enforces the contract; REST upload gated by `upload_files` + nonce** (ADR-0006).
12. **Lightbox via the Interactivity API**, superseding CSS `:target` (ADR-0007).
13. **Drop Zone upload controls are builder-authored links wired by an anchor-token href** (`#kntnt-drop-zone-files`/`#kntnt-drop-zone-folder`), not bespoke control chrome (ADR-0010).
14. **The slideshow is a passive fullscreen surface with a pluggable trigger** — off / built-in button / any custom element carrying `data-kntnt-photo-drop-slideshow` (ADR-0009).

## Structure and conventions

Mirror kntnt-gpx-blocks: `build/`, `classes/` (PSR-4), `docs/` (`design.md`, `blocks.md`, `testing.md`, `definition-of-done.md`, `updater.md`, `coding-standards.md`, `adr/`), `src/blocks/`, `tests/`; main file `kntnt-photo-drop.php`; plus `autoloader.php`, `composer.json`, `package.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `tsconfig.json`, `install.php`, `uninstall.php`, `CLAUDE.md`, `AGENTS.md`, `README.md`. Follow the coder skill. Markdown prose is not hard-wrapped; code comments wrap at 80 columns.
