# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository. Read this file first; it is the bootstrap playbook.

## Coding standards

@docs/coding-standards.md

## What this plugin is

`kntnt-photo-drop` is a WordPress plugin that registers two Gutenberg blocks: **Photo Drop Zone** (a capability-gated front-end bulk uploader that downscales, converts to WebP, and compresses images in the browser before upload) and **Photo Drop Gallery** (a public, server-rendered gallery of a chosen collection, with an Interactivity-API lightbox). A field photographer drags hundreds of images into the Drop Zone at once; anyone can later browse them in a Gallery. The plugin stores images as files on disk under `wp_upload_dir()['basedir']/kntnt-photo-drop/<slug>/`, **outside the Media Library, with no database rows** — the filesystem is the source of truth.

The ubiquitous language is in [`CONTEXT.md`](CONTEXT.md); use those terms (collection, output contract, descriptor, slug, main image, thumbnail, derived artifact, index, conforming, foreign file, doctor) exactly. Do not invent synonyms.

It is **GPL-2.0-or-later**, PHP 8.4+, WordPress 7.0+ (the floor tracks current WordPress; the blocks rely on current block-editor APIs — the `react-jsx-runtime` script handle and the stabilised block-support keys — and no support for older WordPress is carried). Every WordPress hook the plugin exposes is a filter namespaced **`kntnt_photo_drop_*`** (e.g. `kntnt_photo_drop_root`; the per-field rendition defaults `kntnt_photo_drop_default_{upload,full,thumbnail}_{width,quality}`; **every capability check** via `kntnt_photo_drop_{upload,add_to_media,delete,editor_notice,list,manage}_capability`; and `kntnt_photo_drop_max_input_megapixels`).

## First move: clone the reference and mirror it

This plugin **mirrors the repository structure, build chain, and conventions of [`kntnt-gpx-blocks`](https://github.com/Kntnt/kntnt-gpx-blocks)**. Before writing any code, clone it next to this repo and read it as the template:

```bash
git clone --depth 1 https://github.com/Kntnt/kntnt-gpx-blocks.git /tmp/kntnt-gpx-blocks
```

Mirror, in particular: the `Plugin` singleton (component wiring + the four-level `error`/`warning`/`info`/`debug` logging API gated by a `KNTNT_PHOTO_DROP_LOG_LEVEL` constant), the `Updater` class (GitHub-Releases auto-update by ZIP `content_type`), `autoloader.php`, `composer.json` / `package.json` / `phpcs.xml.dist` / `phpstan.neon.dist` / `tsconfig.json`, the `build-zip.sh` script, the Pest + Brain Monkey test harness (`tests/Unit/TestCase.php`, `tests/Pest.php`), and the dynamic-block layout under `src/blocks/<slug>/` compiling into `build/blocks/<slug>/`. Where gpx-blocks and this plugin's specs disagree (e.g. gpx is consent-gated for map tiles; we have no third-party embed to gate), the specs in `docs/` win.

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
| The release mechanics and the auto-updater | [`docs/updater.md`](docs/updater.md) |
| Language/style rules | [`docs/coding-standards.md`](docs/coding-standards.md) |

The fifteen ADRs (`docs/adr/0001`–`0015`) own the decisions with real trade-offs: filesystem collections with no Media Library (0001), the immutable WebP output contract (0002), the on-disk layout and mtime-validated index (0003), the grouped CLI with consumer `import` (0004), the recursive-flatten gallery (0005), the server-enforced contract behind a nonce + `upload_files` REST upload (0006), the Interactivity-API lightbox (0007), hierarchy-preserving folder drop and immutable per-uploader folders (0008), the passive fullscreen slideshow with a pluggable trigger (0009), the token-wired Drop Zone upload controls (0010), the slideshow's cycle-boundary resync to the gallery's current view by page refetch (0011), the imageless-gallery public message (0012), the three-rendition model — main / full / thumbnail, with only the upload pair immutable (0013), the mutable path-components placement template (0014), and the unified gallery overlays over a gated REST write-path (0015). Note that 0002/0003/0005/0008 carry amendment banners pointing to 0013–0015. **Never contradict design.md or an ADR. Never redesign.** If a task seems to require contradicting a decision, stop and surface it — change is an ADR, not a silent edit.

## Load-bearing invariants (do not violate without an ADR)

- **The filesystem is the source of truth.** No database rows for collection images. Discovery is a directory scan for `collection.json`.
- **A collection owns an immutable output contract** (max width + quality; format is always WebP). Blocks are *select-only consumers*; they never create or reconfigure a collection. Lifecycle (create/update-name/delete) lives on the admin page and the CLI only.
- **Everything inside a collection is conforming by construction** — all ingestion passes through the optimisation boundary (`image import` or the Drop Zone REST endpoint, which re-enforces the contract server-side), so non-conforming files cannot enter through the plugin.
- **Derived artifacts are slaved to the main image.** The main image is the unit of truth; thumbnails and index entries are regenerated from it and removed when it is gone.
- **Plugin files live with the images as JSON** — `collection.json` (the visible descriptor, at a collection root; the one irreplaceable file) and `index.json` (a per-folder cache, hidden inside `.kntnt-thumbnails/`). The index is regenerable and validated by directory mtime, never authoritative. (Earlier drafts called the index "manifest"; ADR-0003 renamed it. Use **index**.)

## Pre-1.0 policy — no backwards compatibility

This plugin is in **pre-1.0 development**. There are no users, no installations in the wild, no production data anywhere except the maintainer's machine. **As long as the major version is `0`, no decision factors in backwards compatibility** — no `block.json` `deprecated` entries, no attribute migrations, no fallback paths for old shapes, no concern for existing `post_content`. Pick the cleanest end-state and ship the breaking change. This rule sunsets the moment the `Version:` header in `kntnt-photo-drop.php` and `"version"` in `package.json` cross `1.0.0`.

## How autonomous agents work — autonomy, blockers, reporting

When these issues are implemented away from the keyboard, agents operate autonomously and **never block on the maintainer**: no agent stops to ask for input, to have a test run for it, or to wait on a decision. Genuine ambiguity is resolved by the most reasonable assumption — recorded and reported, never a silent guess that hides the choice and never a pause.

**The one exception is a true design blocker** — a task that cannot proceed without contradicting an ADR, `design.md`, or a load-bearing invariant. There the rule above (*change is an ADR, not a silent edit*) still wins: the agent neither guesses past the decision nor pauses-and-waits. It stops *that unit only*, records the blocker, and proceeds with everything else it can do; the blocker surfaces in the final report for the maintainer to resolve as an ADR amendment.

**Every implementing agent ends with a structured report to its caller**, in three buckets:

- **Automatically tested** — what was covered, and at which layer (unit / integration / e2e).
- **Remaining for a human** — the irreducibly subjective checks the automation cannot meaningfully make (the human-verification caveat in [`docs/definition-of-done.md`](docs/definition-of-done.md)).
- **Assumptions & blockers** — every assumption made to avoid pausing, and any true design blocker that stopped a unit.

**The outermost agent aggregates.** It concatenates and de-duplicates every sub-agent's three buckets into one end-of-work report to the maintainer: everything implemented, tested and green, then the consolidated *remaining-for-a-human* list and any *blockers*. That single report is the only thing that travels back up — nothing waits mid-flight.

## Toolchain commands

PHP 8.4+ and WordPress 7.0+ are the runtime floor. Install both toolchains once:

```bash
composer install        # PHP deps + PSR-4 autoload (Pest, PHPStan, PHPCS, WP stubs)
npm install             # block toolchain (@wordpress/scripts, @wordpress/interactivity)
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

There is no live WordPress on the maintainer's machine. For interactive verification, use `@wordpress/env` (the `.wp-env.json` at the repo root mounts the plugin, sets PHP 8.4, and tracks current WordPress via `"core": null`). `npx wp-env start` then `http://localhost:8888` (admin `admin` / `password`). REST, CLI, and the admin page are all exercised there. WordPress Playground via `@wp-playground/cli` is the lighter alternative for PHP-only integration checks that need no browser.

## Cutting a release

Releasing is **tag-triggered and runs on CI** — it is never an upload performed from a local script. Bump the `Version:` header in `kntnt-photo-drop.php` **and** `"version"` in `package.json` (must match), commit, and push; then push the tag `vX.Y.Z`. The `release` job in `.github/workflows/ci.yml` runs only on a `v*` tag and only after every gate job (PHP, Node, integration/e2e) is green, then runs `./build-zip.sh` and attaches the resulting `dist/kntnt-photo-drop.zip` to a **draft** GitHub Release. Review the draft and **publish it from GitHub** — that publish is the one deliberate "go live to users" step, and the `Updater` (which reads only the *latest published* release) offers nothing until then. The `Updater` finds the asset by `content_type === "application/zip"`, so the version-less filename is intentional; a release published without this ZIP offers no installable package. `./build-zip.sh` is a build-only step (it never uploads) — run it locally only to inspect or test the package.

## Conventions for these docs

`CONTEXT.md` is a **glossary only** — no implementation details, no decisions, no spec. Decisions with trade-offs go in `docs/adr/`. Markdown prose is **not hard-wrapped** (write each paragraph as one continuous line; soft-wrap in the editor). Code comments wrap at 80 columns per the coder skill.
