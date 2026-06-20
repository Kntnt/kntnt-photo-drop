# Contributing to Kntnt Photo Drop

Thanks for considering a contribution – everything from a typo fix to a new feature is welcome. This guide covers the setup, the quality bar and how to open a pull request.

## Ways to contribute

- **Report a bug or request a feature.** [Open an issue](https://github.com/Kntnt/kntnt-photo-drop/issues), and search the existing issues first to avoid duplicates.
- **Ask a question or float an idea.** Use [Discussions](https://github.com/Kntnt/kntnt-photo-drop/discussions) rather than the issue tracker.
- **Submit a pull request.** Fix a bug, improve the documentation, add a translation or implement a feature.

For anything larger than a small fix, open an issue or a discussion first so we can agree the approach before you invest the work.

## Development setup

```bash
git clone https://github.com/Kntnt/kntnt-photo-drop.git
cd kntnt-photo-drop
composer install   # PHP toolchain: Pest, PHPStan, PHPCS, WordPress stubs
npm install        # block toolchain: @wordpress/scripts, @wordpress/interactivity
```

The plugin requires **PHP 8.4+** and **WordPress 7.0+**. The blocks are built with `@wordpress/scripts`; `npm run build` compiles the TypeScript and SCSS under `src/blocks/` into the committed `build/` directory. For interactive testing against a real WordPress, use [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) – `npx wp-env start` boots WordPress with the plugin mounted (see [`AGENTS.md`](AGENTS.md) and [`agents.d/testing.md`](agents.d/testing.md)).

## Quality gates

Every change must pass the same gates CI enforces. Run them locally before opening a pull request:

```bash
composer phpstan   # PHPStan at level max
composer phpcs     # WordPress Coding Standards (with the documented deviations)
composer test      # Pest unit suite
npm run build      # compile the blocks (also type-checks the TypeScript)
npm run lint:js    # ESLint via wp-scripts
npm run lint:css   # Stylelint via wp-scripts
npm run test:js    # Jest block-JS unit suite via wp-scripts
```

`vendor/bin/phpcbf` fixes most coding-standard violations automatically. The integration and end-to-end layers described in [`agents.d/testing.md`](agents.d/testing.md) run against a real WordPress through `@wordpress/env`; if a behaviour genuinely cannot be exercised by the unit suite, raise it on the issue tracker rather than weakening a gate.

## Testing

Tests are written first rather than bolted on afterwards (see *Before opening a pull request*), and each one goes at the **lowest layer that meaningfully covers the behaviour** – a unit test where one will do, escalating to integration or end-to-end only where a lower layer cannot reach. The plugin's spine – that nothing non-conforming can enter a collection, and that a caller-supplied path can never escape the collection root – gets the most adversarial coverage.

The suite has four layers:

| Layer | Tooling | Where | What it covers |
|---|---|---|---|
| PHP unit | Pest + Brain Monkey + Mockery | `tests/Unit/` | Pure domain logic – path confinement, the output-contract and `<original>.webp` naming rules, the path-components template, tree-traversal ordering, descriptor and index read/write, `srcset` assembly. No real WordPress. |
| PHP integration | `@wordpress/env` (or `@wp-playground/cli`) + Pest | `tests/Integration/` | The plugin against a real WordPress – both blocks register, the REST upload, add-to-media and trash endpoints round-trip, the WP-CLI commands run, the doctor reconciles a real collection and the index self-heals. |
| Block JS unit | Jest via `wp-scripts test-unit-js` | co-located `*.test.ts(x)` | Pure browser helpers – the Canvas downscale and WebP encode wrapper, the upload-queue accounting, the breadcrumb string and the lightbox and slideshow reducers. |
| Block end-to-end | Playwright + `@wordpress/e2e-test-utils-playwright` | `tests/e2e/` | Each block in a real editor and on the front end – upload of loose files and a dropped folder, gallery render, the lightbox, the overlays, the slideshow and the no-JS fallback. |

A few things are deliberately out of scope – the Canvas and Interactivity API runtimes, WordPress core and the image libraries themselves, visual rendering and feel, plus the Updater against live GitHub. [`agents.d/testing.md`](agents.d/testing.md) is the detailed, per-area specification behind this overview: the exact path-traversal cases, the naming rules, the doctor reconciliation matrix, the capability and nonce gates and the rest.

## Coding and writing standards

- **Code** follows [`agents.d/coding-standards.md`](agents.d/coding-standards.md). Note the four deliberate deviations from the WordPress Coding Standards – `[ ]` arrays, PSR-4 filenames, namespaces over global function prefixes and no required Yoda conditions – which are enforced in `phpcs.xml.dist` and must not be ‘corrected’ toward upstream WP-CS. Block JS/TS stays on the `@wordpress/scripts` happy path (its bundled ESLint, Prettier, Stylelint and Jest).
- **Naming** follows the conventions in [`AGENTS.md`](AGENTS.md): namespace `Kntnt\Photo_Drop`, slug and text domain `kntnt-photo-drop`, and the `kntnt_photo_drop_` prefix for filters and other global identifiers.
- **Domain vocabulary** uses the terms in [`CONTEXT.md`](CONTEXT.md) exactly (collection, output contract, descriptor, main image, thumbnail, index, conforming, foreign file, doctor). Never contradict [`docs/design.md`](docs/design.md) or an architecture decision record under [`docs/adr/`](docs/adr/) – a decision changes by amending its ADR, not by a silent edit.
- **Documentation** is written in British English following the `kntnt-text-skills:writing-rules en_GB` standard – spaced en-dashes ( – ), `-ise`/`-isation` spellings and no Oxford comma.

## Pre-1.0 policy

While the major version is `0`, the project makes **no backwards-compatibility commitments**. There are no installations in the wild, so pick the cleanest end state and ship the breaking change rather than carrying migrations or deprecations – no `block.json` `deprecated` entries, no attribute migrations, no fallback paths for old shapes. This policy sunsets automatically when the version crosses `1.0.0`.

## Before opening a pull request

A change is ready for review when the automated gates are green, new behaviour is covered by tests and you have noted anything the automation cannot check. The full bar is recorded in [`agents.d/definition-of-done.md`](agents.d/definition-of-done.md); this is the human-sized version of it.

- **Run every relevant gate, and make it green.** The commands under *Quality gates* above must all pass. CI runs the same set plus the integration and end-to-end suites, so a gate that is red on your machine is a red build. A change that touches only PHP need not re-run the JavaScript gates and vice versa – but when in doubt, run all of them.
- **New behaviour ships with tests, written test-first.** Add the test at the lowest layer that meaningfully covers the behaviour – a unit test where one will do, escalating to integration or end-to-end only where a unit test cannot reach. Write it before the code and watch it fail for the right reason first: a test you have never seen fail proves nothing. [`agents.d/testing.md`](agents.d/testing.md) maps each kind of change to its layer.
- **Exercise the integration and end-to-end layers when your change reaches them.** If you touch the REST upload endpoint, the WP-CLI `collection`/`image` commands, the doctor, the index self-heal or either block in the editor or on the front end, run the matching `npm run test:integration` or `npm run test:e2e` suite against `@wordpress/env`. A change already covered by unit tests need not add one, but it must not break an existing one.
- **Honour the standards and the settled design.** Beyond the coding and writing standards above, keep to the load-bearing invariants and never contradict [`docs/design.md`](docs/design.md) or an architecture decision record – a decision changes by amending its ADR, not by a silent edit. Keep user-facing strings translatable and escaped at the point of output, and namespace every filter `kntnt_photo_drop_*`.
- **Check what the automation cannot judge.** The gates cannot tell whether the result looks and feels right. Where your change affects them, open the plugin in a real browser through `@wordpress/env` and look: the gallery's grid and justified-row layouts at narrow and wide viewports with no layout shift, the lightbox's open/close, keyboard and swipe feel, the slideshow's dissolve pacing and its exit paths, drag-and-drop of loose files and whole folders into the Drop Zone and the admin create/update/delete flow with its irreversibility warnings.
- **Say plainly what you did.** In the pull request, state which gates you ran and their result, which you could not run in your environment and why – for example, no Docker for `@wordpress/env` – and which visual items above still need a reviewer's eyes. ‘Done’ means the gates are green and the visual checks are listed, not that the code merely compiles.

## Pull-request process

1. Branch from `main` and keep each pull request focused on a single concern.
2. Make sure the quality gates above pass locally.
3. Open the pull request against `main`. CI runs PHPStan, PHPCS, Pest, the block build, the JS lint/unit gates and the integration and end-to-end suites against `@wordpress/env`; all must be green.
4. Describe what changed and why, and link any related issue.

## Licence

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE) licence that covers the project.
