# Definition of done

The bar every change clears before a pull request, and again over the merged work before a release. A green per-issue run does not guarantee green over the merged union — run the gates per-change and once more before cutting a release.

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

Touch only PHP → the JS gates need not re-run, and vice versa; but run any gate a change could plausibly affect. When in doubt, run all.

## Test-driven development

New behaviour is built test-first — Red/Green/Refactor ([`coding-standard/general.md`](coding-standard/general.md)). The bar is the **demonstrated RED**: a test seen failing *before* its implementation — committed ahead of the code, or the failing run captured in the PR — and failing for the expected reason (a real assertion, not an import error or typo). Self-service and non-blocking: the agent produces and reports the RED itself and never pauses for a human to run, write, or confirm a test. Automate at the lowest layer that captures the behaviour, escalating to integration/e2e only where a unit test cannot reach. Per-issue layer mapping: [`testing.md`](testing.md).

## Integration and end-to-end — green where relevant

Run integration (`npm run test:integration`, `@wordpress/env`) and e2e (`npm run test:e2e`, Playwright) for any change touching the behaviour they cover:

- **REST upload endpoint** → nonce + `upload_files` gate, server-side contract re-enforcement, path confinement, per-file outcomes.
- **WP-CLI `collection`/`image`** → each subcommand.
- **Doctor** → reconcile a real on-disk collection.
- **Index self-heal** → real directory `mtime` bump.
- **Either block in editor/frontend** → e2e: insertion, upload, gallery render, lightbox.

A change fully covered by unit tests need not add one, but must not break an existing one.

## Standard adherence

- Code obeys [`coding-standard/`](coding-standard/): `declare( strict_types = 1 )`, typed properties, `readonly` where immutable, `match` over `switch`, `[ ... ]` arrays, paragraph-style `//` comments, PHPDoc/TSDoc on every file, class, method, property, and constant.
- Honours the load-bearing invariants in [`AGENTS.md`](../AGENTS.md) and [`design.md`](../docs/design.md); contradicts no ADR. A change needing to contradict a decision is blocked until the ADR is amended — never shipped as a silent deviation.
- Domain terms match [`CONTEXT.md`](../CONTEXT.md) exactly.
- User-facing strings translatable (`__()`, `esc_html__()`, …) against the `kntnt-photo-drop` text domain; output escaped at the point of output; every superglobal sanitised; all SQL via `$wpdb->prepare()`.
- Filters namespaced `kntnt_photo_drop_*`.

## Pre-1.0 discipline

Major version `0`: no backwards-compatibility scaffolding (no `block.json` `deprecated`, no attribute migrations, no old-shape fallbacks). Ship the cleanest end-state. See the pre-1.0 policy in [`AGENTS.md`](../AGENTS.md).

## Human-verification caveat

The gates cannot judge whether the result *looks and feels right*. Kept as small as automation allows: the mechanical half of each item below — a keypress changes the slide, focus is trapped, a dropped folder's hierarchy is preserved — is e2e-covered and excluded; only the irreducibly subjective remains. Never waited on — record and report upward; the outermost agent aggregates the residual into one final list (see *Reporting*).

- **Gallery visual layout** — grid (mode A) and justified-rows (mode B), overlay positions, narrow and wide viewports, zero layout shift on load.
- **Lightbox feel** — open/close, prev/next, keyboard, touch swipe, neighbour preload, focus trap, no-JS fallback.
- **Slideshow feel** — built-in and custom trigger, dissolve pacing, reduced-motion hard cut, exit paths (Escape, native fullscreen exit, close button).
- **Drop Zone UX** — loose-file and whole-folder drag-drop (hierarchy preserved), "Select a folder", large-batch progress, visible upload-size reduction.
- **Admin page UX** — create/update/delete flow, contract-field irreversibility warning, read-only contract display in the Drop Zone inspector.

A PR states which gates were run and their result, which could not run in the environment (and why), and which items above still need a human's visual check.

## Reporting

Report faithfully. A failed gate: say so with the output. A gate not runnable in the environment (e.g. no Docker for `@wordpress/env`): say so explicitly — never imply it passed. "Done" = the gates are green and the human-verification items are listed, not that the code merely compiles. In an agent hierarchy, each implementing agent ends with the structured report from [`autonomy.md`](autonomy.md) — *Automatically tested* / *Remaining for a human* / *Assumptions & blockers* — and the outermost agent consolidates into one end-of-work report: everything implemented and green, then the aggregated human-remaining list and any blockers.
