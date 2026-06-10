# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository. Read this file first; it is the bootstrap playbook.

## Coding standards

@docs/coding-standards.md

## What this plugin is

`kntnt-photo-drop` is a WordPress plugin that registers two Gutenberg blocks: **Photo Drop Zone** (a capability-gated front-end bulk uploader that downscales, converts to WebP, and compresses images in the browser before upload) and **Photo Gallery** (a public, server-rendered gallery of a chosen collection, with an Interactivity-API lightbox). A field photographer drags hundreds of images into the Drop Zone at once; anyone can later browse them in a Gallery. The plugin stores images as files on disk under `wp_upload_dir()['basedir']/kntnt-photo-drop/<slug>/`, **outside the Media Library, with no database rows** — the filesystem is the source of truth.

The ubiquitous language is in [`CONTEXT.md`](CONTEXT.md); use those terms (collection, output contract, descriptor, slug, main image, thumbnail, derived artifact, index, conforming, foreign file, doctor) exactly. Do not invent synonyms.

It is **GPL-2.0-or-later**, PHP 8.4+, WordPress 6.6+ (6.6 introduced the `react-jsx-runtime` script handle the compiled blocks depend on; on older WordPress the editor scripts are silently skipped). Every WordPress hook the plugin exposes is a filter namespaced **`kntnt_photo_drop_*`** (e.g. `kntnt_photo_drop_root`, `kntnt_photo_drop_thumbnail_width`, `kntnt_photo_drop_default_max_width`, `kntnt_photo_drop_default_quality`, `kntnt_photo_drop_upload_capability`, `kntnt_photo_drop_manage_capability`, `kntnt_photo_drop_list_capability`, `kntnt_photo_drop_max_input_megapixels`).

## First move: clone the reference and mirror it

This plugin **mirrors the repository structure, build chain, and conventions of [`kntnt-gpx-blocks`](https://github.com/Kntnt/kntnt-gpx-blocks)**. Before writing any code, clone it next to this repo and read it as the template:

```bash
git clone --depth 1 https://github.com/Kntnt/kntnt-gpx-blocks.git /tmp/kntnt-gpx-blocks
```

Mirror, in particular: the `Plugin` singleton (component wiring + the four-level `error`/`warning`/`info`/`debug` logging API gated by a `KNTNT_PHOTO_DROP_LOG_LEVEL` constant), the `Updater` class (GitHub-Releases auto-update by ZIP `content_type`), `autoloader.php`, `composer.json` / `package.json` / `phpcs.xml.dist` / `phpstan.neon.dist` / `tsconfig.json`, the `build-release-zip.sh` script, the Pest + Brain Monkey test harness (`tests/Unit/TestCase.php`, `tests/Pest.php`), and the dynamic-block layout under `src/blocks/<slug>/` compiling into `build/blocks/<slug>/`. Where gpx-blocks and this plugin's specs disagree (e.g. gpx is consent-gated for map tiles; we have no third-party embed to gate), the specs in `docs/` win.

## Second move: invoke the coder skill

Every code-shaped task in this repo runs **through the coder skill** (`kntnt-code-skills:coder`, <https://github.com/Kntnt/kntnt-code-skills/blob/main/skills/coder/SKILL.md>). It loads the language modules (`general`, `php`, `wordpress`, `typescript`, `wordpress-block`) whose concatenation is checked in as [`docs/coding-standards.md`](docs/coding-standards.md). The standard is the contract: tabs, `$snake_case`, `Pascal_Snake_Case` classes, PSR-4 in `classes/`, `[ ... ]` arrays, `declare( strict_types = 1 )`, paragraph-style comments, PHPDoc/TSDoc on every symbol, the four deliberate WP-CS deviations. Block JS/TS stays on the `@wordpress/scripts` happy path (its bundled ESLint/Prettier/Stylelint/Jest), not Biome/Bun.

## Where the specs live

The plugin is built from a settled design. Load only what the task needs.

| Task | Read |
|---|---|
| Big-picture plan, every load-bearing decision | [`design.md`](docs/design.md) |
| The rationale behind a decision | the linked ADR under [`docs/adr/`](docs/adr/) |
| Domain vocabulary | [`CONTEXT.md`](CONTEXT.md) |
| Block attributes + admin-page CRUD UX | [`docs/blocks.md`](docs/blocks.md) |
| What to test, with what tooling | [`docs/testing.md`](docs/testing.md) |
| The bar a change must clear before it ships | [`docs/definition-of-done.md`](docs/definition-of-done.md) |
| Language/style rules | [`docs/coding-standards.md`](docs/coding-standards.md) |

The seven ADRs (`docs/adr/0001`–`0007`) own the decisions with real trade-offs: filesystem collections with no Media Library (0001), the immutable WebP output contract (0002), the on-disk layout and mtime-validated index (0003), the grouped CLI with consumer `import` (0004), the recursive-flatten gallery (0005), the server-enforced contract behind a nonce + `upload_files` REST upload (0006), and the Interactivity-API lightbox (0007). **Never contradict design.md or an ADR. Never redesign.** If a task seems to require contradicting a decision, stop and surface it — change is an ADR, not a silent edit.

## Load-bearing invariants (do not violate without an ADR)

- **The filesystem is the source of truth.** No database rows for collection images. Discovery is a directory scan for `collection.json`.
- **A collection owns an immutable output contract** (max width + quality; format is always WebP). Blocks are *select-only consumers*; they never create or reconfigure a collection. Lifecycle (create/update-name/delete) lives on the admin page and the CLI only.
- **Everything inside a collection is conforming by construction** — all ingestion passes through the optimisation boundary (`image import` or the Drop Zone REST endpoint, which re-enforces the contract server-side), so non-conforming files cannot enter through the plugin.
- **Derived artifacts are slaved to the main image.** The main image is the unit of truth; thumbnails and index entries are regenerated from it and removed when it is gone.
- **Plugin files live with the images as JSON** — `collection.json` (the visible descriptor, at a collection root; the one irreplaceable file) and `index.json` (a per-folder cache, hidden inside `.kntnt-thumbnails/`). The index is regenerable and validated by directory mtime, never authoritative. (Earlier drafts called the index "manifest"; ADR-0003 renamed it. Use **index**.)

## Pre-1.0 policy — no backwards compatibility

This plugin is in **pre-1.0 development**. There are no users, no installations in the wild, no production data anywhere except the maintainer's machine. **As long as the major version is `0`, no decision factors in backwards compatibility** — no `block.json` `deprecated` entries, no attribute migrations, no fallback paths for old shapes, no concern for existing `post_content`. Pick the cleanest end-state and ship the breaking change. This rule sunsets the moment the `Version:` header in `kntnt-photo-drop.php` and `"version"` in `package.json` cross `1.0.0`.

## Toolchain commands

PHP 8.4+ and WordPress 6.6+ are the runtime floor. Install both toolchains once:

```bash
composer install        # PHP deps + PSR-4 autoload (Pest, PHPStan, PHPCS, WP stubs)
npm install             # block toolchain (@wordpress/scripts, @wordpress/interactivity, FilePond)
```

Build and watch the blocks:

```bash
npm run build           # compile src/blocks/** → build/blocks/** (committed to git)
npm run start           # watch build
```

Quality gates — all must be green (see [`docs/definition-of-done.md`](docs/definition-of-done.md)):

```bash
composer test           # Pest unit tests (Brain Monkey + Mockery)
composer phpstan        # PHPStan level max (szepeviktor/phpstan-wordpress)
composer phpcs          # PHP_CodeSniffer, WordPress standard (phpcs.xml.dist)
npm run lint:js         # ESLint via wp-scripts
npm run lint:css        # Stylelint via wp-scripts
npm run test:js         # Jest block-JS unit tests via wp-scripts
```

Integration and end-to-end layers run against a real WordPress through **`@wordpress/env`** (Docker): `npm run test:integration` (Pest, `tests/Integration/`) and `npm run test:e2e` (Playwright + `@wordpress/e2e-test-utils-playwright`, `tests/e2e/`) both boot the instance themselves; `npx wp-env start` boots it manually, and the WP-CLI surface is reachable via `npx wp-env run cli wp kntnt-photo-drop …`. Both suites also run in CI. See [`docs/testing.md`](docs/testing.md) for the full pyramid and per-component test targets.

## Local dev environment

There is no live WordPress on the maintainer's machine. For interactive verification, use `@wordpress/env` (the `.wp-env.json` at the repo root mounts the plugin and sets PHP 8.4 / WP 6.6+). `npx wp-env start` then `http://localhost:8888` (admin `admin` / `password`). REST, CLI, and the admin page are all exercised there. WordPress Playground via `@wp-playground/cli` is the lighter alternative for PHP-only integration checks that need no browser.

## Cutting a release

Mirror gpx-blocks: bump the `Version:` header in `kntnt-photo-drop.php` **and** `"version"` in `package.json` (must match), run every gate over the merged work, commit, tag `vX.Y.Z`, `./build-release-zip.sh` to produce `kntnt-photo-drop.zip` (runtime artefacts only, single top-level folder), push the commit and tag, then `gh release create vX.Y.Z ./kntnt-photo-drop.zip`. The `Updater` finds the asset by `content_type === "application/zip"`, so the stable filename is intentional. Skipping the ZIP means the auto-updater sees no new version.

## Conventions for these docs

`CONTEXT.md` is a **glossary only** — no implementation details, no decisions, no spec. Decisions with trade-offs go in `docs/adr/`. Markdown prose is **not hard-wrapped** (write each paragraph as one continuous line; soft-wrap in the editor). Code comments wrap at 80 columns per the coder skill.
