# kntnt-photo-drop — Design

## Status

This plan is written before the code. Its open questions were resolved in a `grill-with-docs` session; the decisions with real trade-offs are recorded as ADRs under `docs/adr/` and the domain language is in `CONTEXT.md`. Sections below reflect the settled design and link the ADR that owns each load-bearing decision.

## Purpose

A WordPress plugin with two Gutenberg blocks. A field photographer logs in to a page carrying the **Photo Drop Zone** block and drags in one image, many images, or a folder of images; each is downscaled, converted, and compressed in the browser before upload. Anyone can later visit a page carrying the **Photo Gallery** block and browse the result, with a lightbox. Built block-first and server-rendered, in the spirit of kntnt-gpx-blocks.

## The two blocks

**Photo Drop Zone** — a capability-gated front-end uploader bound to one collection. It is a *consumer*: it selects an existing collection from a dropdown and uploads into it. It cannot create or reconfigure collections. Its inspector is just the collection selector plus a read-only display of that collection's contract (max width, quality, WebP, thumbnail width) — nothing to edit, so nothing can conflict.

**Photo Gallery** — a public, server-rendered gallery of a chosen collection, with a lightbox.

## Storage — see ADR-0001, ADR-0003

The plugin has a **single storage model: collections on disk**, outside the Media Library, under `wp_upload_dir()['basedir']/kntnt-photo-drop/<slug>/` (filter `kntnt_photo_drop_root`; must stay web-served, per-site on multisite). No database rows are created; the filesystem is the source of truth. There is deliberately **no Media-Library-backed mode** — the Media Library cannot enforce the output contract, so it would break "conforming by construction." Images are served **directly by URL**, so collections are **public-by-path** (directory listing disabled so paths can't be enumerated).

A collection's **identity is its directory slug**; a block stores the slug to point at a collection. The human **display name** lives in the descriptor. Renaming a collection is just `mv` on the directory (the filesystem is the truth); blocks that referenced the old slug then dangle, which is expected.

On disk, per content folder:

- main images as `<original-filename>.webp` (original name preserved, `.webp` appended; an already-`.webp` input is not doubled) — collision-free on the case-sensitive Linux server;
- one **visible `collection.json`** at the collection root (the descriptor — the one irreplaceable file);
- one **hidden `.kntnt-thumbnails/`** holding all regenerable artifacts: thumbnails at `.kntnt-thumbnails/<width>/<name>.webp` and that folder's `index.json`.

`collection.json` = `{ schema, name, maxWidth, quality, thumbnailWidths }`. `index.json` = `{ schema, dirMtime, subdirs, images: [{ file, width, height }] }`, with images stored sorted ascending.

## Output contract and immutability — see ADR-0002

Each collection carries an immutable **output contract** of exactly two lossy values fixed at establishment: **maximum width** (e.g. 1920, configurable, `null` = no limit) and **compression quality** (e.g. 80). The stored **format is always WebP** — not a choice; inputs in other formats are accepted and converted. Because images are downscaled and re-encoded at ingestion and the original is not kept, the contract is irreversible; raising the maximum later cannot retroactively enlarge already-imported images.

**Thumbnail width is *not* part of the immutable contract** — unlike max-width/quality it is losslessly re-derivable from the main image, so it is a changeable setting: supplied by filter `kntnt_photo_drop_thumbnail_width` (default `640`; may return an array; `[]`/`0` = no thumbnail), recorded in the descriptor, changed by re-running `wp kntnt-photo-drop collection doctor <slug> --repair --force`. There is no UI field and no `create` flag for it. The CLI/admin contract defaults come from `kntnt_photo_drop_default_max_width` (1920) and `kntnt_photo_drop_default_quality` (80), which pre-fill the admin form.

## Upload / ingestion pipeline — see ADR-0006

The Drop Zone uses **FilePond** with client-side optimisation via the **Canvas API** (FilePond `image-resize` plus a WebP encode hook calling `canvas.toBlob(…, 'image/webp', quality)`, the Cimo technique, no extra assets). `render.php` reads the selected collection's descriptor and configures FilePond from it. **The client optimisation is a bandwidth optimisation, not the security boundary**: the server re-applies the contract on every upload (same code path as `image import`) — accept as-is if already conforming (avoids double compression), otherwise decode/downscale/re-encode — so a file POSTed directly to the API cannot enter non-conforming.

Folder structure is preserved through a **"Select folder"** control (`webkitdirectory`): each file's `webkitRelativePath` is carried as FilePond item metadata and sent per file; the server recreates the sub-directories (path hard-sanitised and `realpath`-confined). Plain drag-and-drop handles loose files. A *folder* dragged onto the drop zone is detected (`webkitGetAsEntry().isDirectory`, no recursion) and **warned** about, offering to continue flat; recursive drag traversal is out of scope.

Each main image gets its **thumbnail(s)** generated at upload. The upload handler writes only the main image and its thumbnail(s) and **never touches the index** — the index self-heals via `dirMtime` on the next gallery view, so a several-hundred-file batch causes no index write contention.

**REST surface (the only write path; the gallery needs no REST):** `POST /wp-json/kntnt-photo-drop/v1/collections/<slug>/images`, one file per request, multipart (file + `relativePath`). Requires a valid `wp_rest` nonce **and** `current_user_can('upload_files')`. Per-file response (`stored | skipped | reencoded | rejected`); a clash with an existing path skips by default; no chunking (files are pre-downscaled).

## Capability model — see ADR-0006

No bespoke capabilities. Viewing a gallery is plain page access; placing the blocks is plain `edit_posts`. Uploading via the Drop Zone (and its endpoint) requires **`upload_files`** (filter `kntnt_photo_drop_upload_capability`); managing collections on the admin page requires **`manage_options`** (filter `kntnt_photo_drop_manage_capability`). As defence in depth the Drop Zone renders its UI and nonce only for users holding the upload capability. CLI runs in a trusted context with no capability check.

## Collection lifecycle and discovery

Collection lifecycle — create (fixing the immutable contract), update (display name only), and delete — lives on a **dedicated admin page** gated by `manage_options`, and on the CLI (a deliberate context). Blocks are select-only consumers; they never create or reconfigure a collection.

Collection discovery is a **directory scan**: a collection is any directory under the uploads root that contains a `collection.json`. The Drop Zone dropdown, the Gallery selector, and the admin page all list collections this way, so a collection copied in from another site appears automatically and a deleted directory disappears — no registry to keep in sync.

## Index design — see ADR-0003

Per-folder `index.json` (hidden inside `.kntnt-thumbnails/`) gives locality and is validated by the folder's directory **mtime** — one `stat` tells whether anything was added, removed, renamed, or moved (a move bumps both folders). If the stored `dirMtime` matches, trust the index; otherwise regenerate (scan, read dimensions once, write back). The index is a regenerable cache; the directory is the truth. Dimensions needed for `srcset` and to avoid layout shift are stored in the index, computed once at build. `subdirs` is listed for completeness (recursive gallery walks the tree).

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
wp kntnt-photo-drop collection create <slug> --name="…" --max-width=1920 --quality=80
wp kntnt-photo-drop collection update <slug> --name="…"      # mutable name only; rejects contract changes
wp kntnt-photo-drop collection delete <slug> [--yes]
wp kntnt-photo-drop collection doctor <slug> [--repair] [--force] [--ignore=<glob>] [--show-ignored]
wp kntnt-photo-drop image      import <slug> <source…> [--overwrite]
wp kntnt-photo-drop image      delete <slug> <path> [--yes]
```

`create` takes `slug` positionally; `--name` is optional (defaults to a humanised slug); `--max-width` and `--quality` are **required flags** (the contract is irreversible). `import` requires an existing collection, carries no contract flags, and is idempotent (skip-if-exists, `--overwrite` to force). Both deletes prompt unless `--yes`. Read output uses `format_items()` (`table`, `csv`, `json`, `yaml`, `ids`, `count`). Deliberately **not** included: `verify` (subsumed by doctor), `list`/`mv` (the filesystem plus `find` and self-healing indexes cover them), and a standalone in-place `process`.

## Gallery rendering — see ADR-0005, ADR-0007

The Gallery block targets a collection (slug) plus an optional **start path** (default root, both editor-set) and renders **all images under that path recursively as one flattened gallery** — no clickable folder tiles, no in-gallery navigation. A per-block toggle switches to "this folder only." To present folders separately, place multiple blocks and compose with native page-building. The recursive case orders by **full relative path** (natural sort) so each folder's images stay contiguous.

- **Ordering** is a block attribute (not visitor-controllable): natural filename sort, ascending/descending.
- **Captions** are a presentation block setting: content = none / filename / path-breadcrumb (humanise toggle; "include collection name" toggle, default off; separator free-text, default `›`); plus layout = position (under / above / overlay), overlay anchor (9 positions), background (none / colour with alpha), text colour. Note: Canvas re-encoding strips all EXIF/IPTC, so there is no embedded caption or capture date to draw on.
- **Layout** delegates to core block supports where possible. Mode toggle **A (uniform grid)** vs **B (justified rows)**. A uses core's Grid layout (`minimumColumnWidth` default 320px, `blockGap` default 12px) + bespoke aspect-ratio and fit; stored dimensions set `aspect-ratio` → zero layout shift. B is bespoke justified rows (per-image `flex-grow`/`flex-basis` from stored dimensions, target row height default 240px, last row left-aligned), reusing `blockGap`. Gallery width uses core width/alignment; colour and typography use core supports.
- **Lightbox** is built with the WordPress **Interactivity API** in TypeScript (open/close, prev/next, keyboard, swipe, neighbour preload, focus trap, `aria`), with a no-JS `<a href="full.webp">` fallback. This supersedes a CSS `:target` lightbox (a11y).

CSS Grid, native `loading="lazy"`, and `srcset` (thumbnail width(s) + main; the browser picks by rendered size and DPR, and never shows a thumbnail upscaled because the main is always a candidate). No jQuery. Virtualisation is only considered at thousands-of-images scale.

## Distribution and privacy

**Distribution:** mirror kntnt-gpx-blocks — GitHub Releases plus an `Updater` class.

**Privacy:** no third-party request to gate. FilePond and the Interactivity API are bundled local assets, WebP encoding is local (Canvas), and images are first-party files served from the site — there is no equivalent of gpx-blocks' map-tile consent. The only external call is the admin-side `Updater` checking GitHub for releases, which is not a visitor-facing embed.

## Settled decisions (recorded as ADRs)

1. **Bespoke plugin**, not NextGEN/Piwigo/Lychee (ADR-0001).
2. **Client-side optimisation via Canvas**, not WebAssembly (jSquash) — Cimo's proven approach, zero extra assets.
3. **Filesystem collections, no database rows, no Media Library backend** (ADR-0001).
4. **Per-folder `index` (hidden, regenerable) validated by directory mtime** (ADR-0003); rejected a central manifest and inode keys.
5. **Immutable WebP output contract (max-width + quality); thumbnail width re-derivable & filter-driven; blocks select-only; lifecycle on the admin page** (ADR-0002).
6. **Slug-as-identity; `<original>.webp` mains; hidden `.kntnt-thumbnails/`** (ADR-0003).
7. **Folder structure via `webkitdirectory` only; drag-drop flattens with a warning.**
8. **CLI = grouped `collection {create,update,delete,doctor}` + `image {import,delete}`; `import` is a pure consumer** (ADR-0004).
9. **Downscaling at ingestion is lossy** — the stored main is the ceiling; no later upscaling (ADR-0002).
10. **Recursive-flatten gallery; no in-gallery folder navigation; compose with multiple blocks** (ADR-0005).
11. **Server re-enforces the contract; REST upload gated by `upload_files` + nonce** (ADR-0006).
12. **Lightbox via the Interactivity API**, superseding CSS `:target` (ADR-0007).

## Structure and conventions

Mirror kntnt-gpx-blocks: `build/`, `classes/` (PSR-4), `docs/` (`design.md`, `architecture.md`, `blocks.md`, `security.md`, `adr/`), `src/blocks/`, `tests/`; main file `kntnt-photo-drop.php`; plus `autoloader.php`, `composer.json`, `package.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `tsconfig.json`, `install.php`, `uninstall.php`, `CLAUDE.md`, `AGENTS.md`, `README.md`. Follow the coder skill. Markdown prose is not hard-wrapped; code comments wrap at 80 columns.
