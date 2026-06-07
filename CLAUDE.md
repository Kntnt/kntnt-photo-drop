# kntnt-photo-drop — Claude working notes

WordPress plugin providing two Gutenberg blocks — **Photo Drop Zone** (a front-end bulk uploader that optimises images in the browser before upload) and **Photo Gallery** (a public, server-rendered gallery with a lightbox). It lets a field photographer drag in hundreds of images at once, and lets anyone browse them later.

## Coding standards

@docs/coding-standards.md

## Status

Greenfield — no code yet, this repo is in the design phase. The plan to be implemented lives in `docs/design.md`; the domain glossary in `CONTEXT.md`; architectural decisions will be recorded under `docs/adr/`. The intended next step is a `grill-with-docs` session to resolve the open questions in `docs/design.md` before any code is written.

## How to build it

Mirror the repository structure and conventions of **kntnt-gpx-blocks** (https://github.com/Kntnt/kntnt-gpx-blocks) and follow the **coder skill** (https://github.com/Kntnt/kntnt-code-skills/blob/main/skills/coder/SKILL.md). In short: PHP 8.4+, WordPress 6.5+, GPL v2+, PSR-4 autoloading, `@wordpress/scripts` building into `build/`, dynamic (server-rendered) blocks, the WordPress Interactivity API for client behaviour, PHPStan (max) + PHPCS (WordPress) + ESLint + Stylelint, distributed via GitHub Releases with an `Updater` class. Filters are namespaced `kntnt_photo_drop_*`.

## Load-bearing invariants (do not violate without an ADR)

- **The filesystem is the source of truth** for out-of-library collections. No database rows are created for collection images.
- **A collection owns an immutable output contract** (max width, format, quality, thumbnail width). Blocks are *select-only consumers* of collections; they never create or reconfigure one. Collection lifecycle (create / configure / delete) lives on a dedicated admin page.
- **Everything inside a collection is conforming by construction** — all ingestion passes through the optimisation boundary (`import` or the Drop Zone), so non-conforming files cannot enter through the plugin.
- **Derived artifacts are slaved to the main image.** The main image is the unit of truth; thumbnails and manifest entries are regenerated from it and removed when it is gone.
- **Plugin files live with the images as visible JSON** — `collection.json` (the descriptor, at a collection root) and `manifest.json` (a per-folder index). Manifests are regenerable caches validated by directory mtime, never authoritative.

## Conventions for these docs

`CONTEXT.md` is a **glossary only** — no implementation details, no decisions, no spec. Decisions with trade-offs go in `docs/adr/`. Markdown prose is **not hard-wrapped** (write each paragraph as one continuous line; soft-wrap in the editor). Code comments wrap at 80 columns per the coder skill.
