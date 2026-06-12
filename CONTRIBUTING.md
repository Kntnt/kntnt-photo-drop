# Contributing to Kntnt Photo Drop

Thank you for considering a contribution. This plugin gives a field photographer a front-end drop zone to bulk-upload hundreds of images at once — optimised in the browser before upload — and gives anyone a public, server-rendered gallery to browse them later. Contributions of every size help, from a typo fix to a new feature.

## Ways to contribute

- **Report a bug or request a feature.** [Open an issue](https://github.com/Kntnt/kntnt-photo-drop/issues), and search the existing issues first to avoid duplicates.
- **Ask a question or float an idea.** Use [Discussions](https://github.com/Kntnt/kntnt-photo-drop/discussions) rather than the issue tracker.
- **Submit a pull request.** Fix a bug, improve the documentation, add a translation, or implement a feature.

For anything larger than a small fix, open an issue or a discussion first so the approach can be agreed before you invest the work.

## Development setup

```bash
git clone https://github.com/Kntnt/kntnt-photo-drop.git
cd kntnt-photo-drop
composer install   # PHP toolchain: Pest, PHPStan, PHPCS, WordPress stubs
npm install        # block toolchain: @wordpress/scripts, @wordpress/interactivity
```

The plugin requires **PHP 8.4+** and **WordPress 7.0+**. The blocks are built with `@wordpress/scripts`; `npm run build` compiles the TypeScript and SCSS under `src/blocks/` into the committed `build/` directory. For interactive testing against a real WordPress, use [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) — `npx wp-env start` boots WordPress with the plugin mounted (see [`AGENTS.md`](AGENTS.md) and [`docs/testing.md`](docs/testing.md)).

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

`vendor/bin/phpcbf` fixes most coding-standard violations automatically. The integration and end-to-end layers described in [`docs/testing.md`](docs/testing.md) run against a real WordPress through `@wordpress/env`; if a behaviour genuinely cannot be exercised by the unit suite, raise it on the issue tracker rather than weakening a gate.

## Coding and writing standards

- **Code** follows [`docs/coding-standards.md`](docs/coding-standards.md). Note the four deliberate deviations from the WordPress Coding Standards — `[ ]` arrays, PSR-4 filenames, namespaces over global function prefixes, and no required Yoda conditions — which are enforced in `phpcs.xml.dist` and must not be "corrected" toward upstream WP-CS. Block JS/TS stays on the `@wordpress/scripts` happy path (its bundled ESLint, Prettier, Stylelint, and Jest).
- **Naming** follows the conventions in [`AGENTS.md`](AGENTS.md): namespace `Kntnt\Photo_Drop`, slug and text domain `kntnt-photo-drop`, and the `kntnt_photo_drop_` prefix for filters and other global identifiers.
- **Domain vocabulary** uses the terms in [`CONTEXT.md`](CONTEXT.md) exactly (collection, output contract, descriptor, main image, thumbnail, index, conforming, foreign file, doctor). Never contradict [`docs/design.md`](docs/design.md) or an architecture decision record under [`docs/adr/`](docs/adr/) — a decision changes by amending its ADR, not by a silent edit.
- **Documentation** is written in British English following the `kntnt-text-skills:writing-rules en_GB` standard — spaced en-dashes ( – ), `-ise`/`-isation` spellings, and no Oxford comma.

## Pre-1.0 policy

While the major version is `0`, the project makes **no backwards-compatibility commitments**. There are no installations in the wild, so pick the cleanest end state and ship the breaking change rather than carrying migrations or deprecations — no `block.json` `deprecated` entries, no attribute migrations, no fallback paths for old shapes. This policy sunsets automatically when the version crosses `1.0.0`.

## Pull-request process

1. Branch from `main` and keep each pull request focused on a single concern.
2. Make sure the quality gates above pass locally.
3. Open the pull request against `main`. CI runs PHPStan, PHPCS, Pest, the block build, the JS lint/unit gates, and the integration and end-to-end suites against `@wordpress/env`; all must be green.
4. Describe what changed and why, and link any related issue.

## Licence

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE) licence that covers the project.
