# Definition of done

The bar every change clears before it is considered finished — before a pull request is opened, and again over the merged work before a release. A green run on an individual issue does not guarantee a green run on the union of merged work; the gates below are run both per-change and once more before cutting a release.

## Automated gates — all must be green

| Gate | Command | Bar |
|---|---|---|
| PHP static analysis | `composer phpstan` | **PHPStan level `max`, zero errors.** No baseline, no `@phpstan-ignore` without a one-line justification comment. The `szepeviktor/phpstan-wordpress` extension is loaded; WordPress and WP-CLI stubs are on the path. |
| PHP code style | `composer phpcs` | **PHPCS WordPress standard, zero violations**, per `phpcs.xml.dist` (which encodes the four deliberate WP-CS deviations: short-array syntax, PSR-4 filenames, namespaces over global prefixes, no required Yoda). Do not silence a real violation; fix it or, if it is a genuine standard deviation, document it in `phpcs.xml.dist`. |
| PHP unit tests | `composer test` | **Pest green**, no skipped tests masking failures. New domain logic ships with tests (see [`testing.md`](testing.md)). |
| Block JS lint | `npm run lint:js` | **ESLint (via `@wordpress/scripts`) clean.** No disable comments without justification. |
| Block CSS lint | `npm run lint:css` | **Stylelint (via `@wordpress/scripts`) clean.** |
| Block JS unit tests | `npm run test:js` | **Jest green** for the block helpers under test. |
| Block build | `npm run build` | **Compiles with no errors or type errors** (`wp-scripts build` type-checks the TypeScript). `build/` is committed and matches the source. |

A change that touches only PHP need not re-run the JS gates and vice versa — but anything that could plausibly affect a gate runs it. When in doubt, run all of them.

## Integration and end-to-end — green where relevant

The integration (`npm run test:integration`, WordPress via `@wordpress/env`) and end-to-end (`npm run test:e2e`, Playwright) layers run for any change that touches the behaviour they cover:

- **REST upload endpoint** → integration tests for the nonce + `upload_files` gate, server-side contract re-enforcement, path confinement, and per-file outcomes.
- **WP-CLI `collection`/`image`** → integration tests for each subcommand.
- **Doctor** → integration test reconciling a real on-disk collection.
- **Index self-heal** → integration test on a real directory `mtime` bump.
- **Either block in the editor / on the frontend** → e2e for insertion, upload, gallery render, and the lightbox.

A change whose behaviour is fully covered by unit tests need not add an integration/e2e test, but must not break an existing one.

## Standard adherence

- The code obeys [`coding-standards.md`](coding-standards.md): `declare( strict_types = 1 )`, typed properties, `readonly` where immutable, `match` over `switch`, `[ ... ]` arrays, paragraph-style `//` comments with a topic sentence, PHPDoc/TSDoc on every file, class, method, property, and constant.
- It honours the load-bearing invariants in [`AGENTS.md`](../AGENTS.md) and [`design.md`](design.md), and contradicts no ADR. A change that needs to contradict a decision is blocked until the ADR is amended — it is never shipped as a silent deviation.
- Domain terms match [`CONTEXT.md`](../CONTEXT.md) exactly.
- All user-facing strings are translatable (`__()`, `esc_html__()`, …) against the `kntnt-photo-drop` text domain; all output is escaped at the point of output; every superglobal is sanitised; all SQL (if any) goes through `$wpdb->prepare()`.
- Filters are namespaced `kntnt_photo_drop_*`.

## Pre-1.0 discipline

While the major version is `0`, no change adds backwards-compatibility scaffolding (no `block.json` `deprecated`, no attribute migrations, no old-shape fallbacks). The cleanest end-state ships. See the pre-1.0 policy in [`AGENTS.md`](../AGENTS.md).

## Human-verification caveat

The automated gates cannot judge whether the result *looks and feels right*. The following require a human's eyes and are called out explicitly in the PR rather than claimed as done:

- **Visual layout** of the Gallery — uniform-grid (mode A) and justified-rows (mode B), caption positions and overlays, behaviour at narrow and wide viewports, zero layout shift on load.
- **Lightbox feel** — open/close animation, prev/next, keyboard, swipe on touch, neighbour preload, focus trap, and that the no-JS fallback degrades gracefully.
- **Slideshow feel** — starting from the built-in button and a custom trigger, the dissolve pacing, the reduced-motion hard cut, and the exit paths (Escape, native fullscreen exit, the close button).
- **Drop Zone UX** — drag-and-drop of loose files and whole folders (hierarchy preserved), the "Select a folder" control, progress feedback across a large batch, and that browser-side optimisation visibly reduces upload size.
- **Admin page UX** — the create/update/delete flow, the irreversibility warning on the contract fields, and the read-only contract display in the Drop Zone inspector.

A PR states plainly which automated gates were run and their result, which could not be run in the current environment (and why), and which items above still need a human's visual check.

## Reporting

Report outcomes faithfully. If a gate failed, say so with the output. If a gate could not be run in the environment (e.g. no Docker for `@wordpress/env`), say that explicitly rather than implying it passed. "Done" means the automated gates above are green and the human-verification items are listed for review — not that the code merely compiles.
