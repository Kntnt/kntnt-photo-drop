# kntnt-photo-drop — agent guide

## Ground rules (authoritative)

Precedence over any conflicting skill, README, or narrative doc unless the user overrides in the moment.

- Authoritative: this file, the files it references, the actual code/state. Specs are `docs/design.md` + the ADRs (`docs/adr/`). Ignore `README*`, `CONTRIBUTING.md`, and other narrative docs as spec unless referenced here.

## What it is

Two Gutenberg blocks: **Photo Drop Zone** (capability-gated front-end bulk uploader; downscales → WebP → compresses in-browser before upload) and **Photo Drop Gallery** (public, server-rendered, Interactivity-API lightbox). Collection images are files under `wp_upload_dir()['basedir']/kntnt-photo-drop/<slug>/`, **outside the Media Library, no DB rows**; discovery is a directory scan for `collection.json`.

Naming: namespace `Kntnt\Photo_Drop`; slug + text domain `kntnt-photo-drop`. Every global identifier carries the prefix — every exposed hook is a filter `kntnt_photo_drop_*`, and **every capability check reads a `kntnt_photo_drop_*_capability` filter** (each gate is overridable).

## Load-bearing invariants — do not violate without an ADR

- **Filesystem is the source of truth.** No DB rows for collection images.
- **A collection owns an immutable output contract** (max width + quality; format always WebP). Blocks are *select-only consumers* — never create or reconfigure a collection. Lifecycle (create / rename / delete) lives on the admin page and the CLI only.
- **Conforming by construction.** All ingestion passes the optimisation boundary (`image import` or the Drop Zone REST endpoint, which re-enforces the contract server-side), so non-conforming files cannot enter through the plugin.
- **Derived artifacts are slaved to the main image.** Thumbnails and index entries regenerate from it and are removed when it is gone.
- **Plugin files are JSON beside the images:** `collection.json` (visible descriptor at a collection root — the one irreplaceable file) and `index.json` (per-folder cache inside `kntnt-thumbnails/`; regenerable, directory-`mtime`-validated, never authoritative — the **index**).

## Decisions, vocabulary, policy

- **Never contradict `design.md` or an ADR. Never redesign.** A change that requires it is *blocked* — surface it as an ADR amendment, never a silent edit. The ADRs (`docs/adr/`) own the decisions with trade-offs; some carry amendment banners.
- **Pre-1.0 (major `0`): ignore backwards-compat** — no `block.json` `deprecated`, no attribute migrations, no old-shape fallbacks. Ship the cleanest end-state. Sunsets when `Version:` (`kntnt-photo-drop.php`) and `"version"` (`package.json`) cross `1.0.0`.
- **Use `CONTEXT.md` vocabulary exactly** — no synonyms.

## References

Load on demand.

- **Coding standard** — full language/style rules, one file per module under [`agents.d/coding-standard/`](agents.d/coding-standard/) (indexed below); the four deliberate WP-CS deviations (don't "fix" toward upstream) live in `wordpress.md`, enforced by `phpcs.xml.dist`. Or invoke the `kntnt-code-skills:coder` skill.
- [`agents.d/testing.md`](agents.d/testing.md) — what to test per area/issue, tooling, the suite + how to run it.
- [`agents.d/definition-of-done.md`](agents.d/definition-of-done.md) — the bar before shipping; the green gates.
- [`agents.d/releasing.md`](agents.d/releasing.md) — tag-triggered CI release; CI owns build + publish.
- [`agents.d/autonomy.md`](agents.d/autonomy.md) — away-from-keyboard agent protocol + 3-bucket report.
- [`agents.d/reference-repo.md`](agents.d/reference-repo.md) — mirror `kntnt-gpx-blocks` when touching build infra.
- [`docs/design.md`](docs/design.md) — big-picture plan + every load-bearing decision; admin CRUD UX in § *Collection lifecycle and discovery*.
- [`docs/adr/`](docs/adr/) — the rationale behind each decision.
- [`docs/updater.md`](docs/updater.md) — how the auto-updater finds releases.
- [`CONTEXT.md`](CONTEXT.md) — domain glossary (authoritative vocabulary).
- `src/blocks/<slug>/block.json` — authoritative block attribute schema.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — human-contributor setup (`composer install`, `npm install`, build, `@wordpress/env`).
- agents.d/coding-standard/general.md — read before writing or changing any code
- agents.d/coding-standard/php.md — read before writing or changing PHP
- agents.d/coding-standard/wordpress.md — read before writing or changing a WordPress plugin or theme
- agents.d/coding-standard/typescript.md — read before writing or changing TypeScript
- agents.d/coding-standard/wordpress-block.md — read before writing or changing Gutenberg blocks
- agents.d/coding-standard/bash.md — read before writing or changing Bash
