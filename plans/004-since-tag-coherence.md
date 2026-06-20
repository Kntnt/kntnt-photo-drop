# Plan 004: Make the unreleased `@since` tags coherent

> **Executor instructions**: This plan has a **decision gate** in Step 0 that a
> human must answer before any file is changed — because the alternative is
> rewriting ~150 history markers on a guess. Do not run the sweep until the
> target version is confirmed. Follow the rest step by step and run every
> verification command.
>
> **Drift check (run first)**: `git log --oneline 2cf63ed..HEAD` and
> `grep -rn "@since 0.1[1-5]" classes/ src/ | wc -l`. If the version headers or
> the `@since` distribution differ materially from "Current state", re-read the
> live state before proceeding.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

The plugin version is `0.10.1` (`kntnt-photo-drop.php` header and `package.json`), but the code carries `@since` tags for five *unreleased* versions — 0.11.0, 0.12.0, 0.13.0, 0.14.0, 0.15.0 — all of which sit under a single `## [Unreleased]` block in `CHANGELOG.md` and none of which has ever shipped to a user. Commit `fd80bfc` ("docs: normalize @since to 0.15.0 for this cycle's additions") began aligning these to `0.15.0` but touched only two files, leaving the bulk staggered. The result is an incoherent record: symbols that will all first appear to users in the *same* next release advertise five different "since" versions. Under the pre-1.0 policy ("the cleanest end-state ships", no back-compat, no users in the wild) the right end-state is a single `@since` naming the version these symbols actually debut in. This plan makes that true — but the *target number* is a maintainer call, hence the gate.

## Current state

- `kntnt-photo-drop.php` header: `Version: 0.10.1`; `package.json`: `"version": "0.10.1"`.
- `CHANGELOG.md`: the entire redesign (#42–#54) is under `## [Unreleased]`; there is no released `0.11.0`–`0.15.0` heading.
- `@since` distribution in `classes/` (approximate counts at `2cf63ed`): `0.11.0` ×86, `0.12.0` ×24, `0.13.0` ×29, `0.14.0` ×12, `0.15.0` ×2. The two `0.15.0` tags are `classes/Cli/Collection_Input.php:56` and (in tests) `src/admin/width-clamp-dom.test.ts`.
- Commit `fd80bfc` changed exactly `classes/Cli/Collection_Input.php` and `src/admin/width-clamp-dom.test.ts` to `0.15.0` — evidence the maintainer intends `0.15.0` as the unifying version but did not finish.

Conventions (`agents.d/coding-standard/`): `@since` is required on every file/class/method/property/constant and "Include `@since` from the first release." Pre-1.0 policy (`AGENTS.md`): while major version is `0`, no back-compat concerns; pick the cleanest end-state.

## Decision gate (Step 0 — resolve before editing)

The three options, for the maintainer to pick:

1. **(Recommended) Normalize every unreleased `@since` to the next release version.** Replace all `@since 0.11.0 / 0.12.0 / 0.13.0 / 0.14.0` with the chosen next-release number (default `0.15.0`, matching commit `fd80bfc`'s stated intent). Cleanest end-state; one coherent "since" for the whole redesign. Does **not** touch the `Version:`/`package.json` headers (those bump at release time, not here).
2. **Leave the staggered tags as deliberate development history.** Reject this plan. Choose this only if the per-iteration `@since` values are meant to record the development sequence rather than the user-facing debut.
3. **Bump the live version to match the highest tag and split the changelog.** Larger change, release-adjacent; out of scope for a docs-coherence plan — handle via the project's release process, not here.

**The executor must obtain the chosen option and, for option 1, the target version string, before Step 1.** If no human is available to decide, default to **option 1 with target `0.15.0`** (the most recent explicit signal in the git history) and record the assumption in the report. If the maintainer indicates the next release is a different number, substitute that number everywhere in Step 1.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Inventory before | `grep -rn "@since 0.1[1-4]\." classes/ src/` | the staggered tags to change |
| Inventory after | `grep -rln "@since 0.1[1-4]\." classes/ src/` | (option 1) no matches |
| Style | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |
| Block lint/build | `npm run lint:js && npm run build` | exit 0, compiles |
| PHP tests | `composer test` | green |

## Scope (option 1)

**In scope**: every `.php` under `classes/` and every `.ts`/`.tsx` under `src/` whose PHPDoc/TSDoc carries `@since 0.11.0`–`0.14.0` (and the `0.15.0` ones already there stay).

**Out of scope** (do NOT touch):
- The `Version:` header in `kntnt-photo-drop.php` and `"version"` in `package.json` — bumping the live version is a release action, not part of this docs sweep.
- `CHANGELOG.md` — its `## [Unreleased]` block is already correct; restructuring it belongs to option 3 / the release process.
- `@since` tags at `0.1.0`–`0.10.0` — those name genuinely-released versions; leave them.
- Any code logic. This plan changes only PHPDoc/TSDoc `@since` lines.

## Git workflow

- Branch: `advisor/004-since-tag-coherence`
- Commit style: conventional commits (e.g. `docs: normalize unreleased @since tags to 0.15.0`).
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Apply the chosen normalization (option 1)

With the confirmed target version `X` (default `0.15.0`), replace `@since 0.11.0`, `@since 0.12.0`, `@since 0.13.0`, and `@since 0.14.0` with `@since X` across `classes/` and `src/`. Preserve any trailing prose on the tag line (e.g. `Index.php`'s `@since 0.15.0 Renamed from …`). Do **not** alter `@since 0.1.0`–`0.10.0`.

Match only the `@since ` form to avoid touching unrelated version-like strings. Verify each touched file still parses (no PHPDoc block accidentally broken).

**Verify**: `grep -rn "@since 0.1[1-4]\." classes/ src/` → no matches. `composer phpcs` → exit 0. `composer phpstan` → exit 0.

### Step 2: Confirm nothing else moved

**Verify**: `npm run build` compiles with no type errors; `npm run lint:js` exit 0; `composer test` green. `git diff` shows only `@since` lines changed (no logic, no `Version:` header, no `package.json`).

## Done criteria

ALL must hold (for option 1):

- [ ] `grep -rn "@since 0.1[1-4]\." classes/ src/` returns no matches
- [ ] `composer phpcs`, `composer phpstan`, `composer test` all exit 0
- [ ] `npm run lint:js` and `npm run build` exit 0
- [ ] `git diff` touches only `@since` lines (verify: `git diff -G '@since' --stat` lists the files; `git diff | grep '^[+-]' | grep -v '@since' | grep -vE '^(\+\+\+|---)'` returns nothing)
- [ ] `kntnt-photo-drop.php` and `package.json` version headers are unchanged
- [ ] `plans/README.md` status row for 004 updated

## STOP conditions

Stop and report (do not improvise) if:

- The decision gate cannot be resolved and you are unsure whether option 1 is wanted — report the inconsistency and the three options rather than sweeping on a guess.
- The grep/diff guard shows a non-`@since` line changed — you matched too broadly; revert and narrow.
- A `composer phpstan`/`phpcs` error appears after the sweep (a PHPDoc block was malformed).

## Maintenance notes

- After this lands, every redesign symbol reads `@since X`. When the release is actually cut, confirm `X` matches the version being tagged; if the maintainer later picks a different next-version number, these tags must be re-swept to match (the release process should check this).
- A reviewer should confirm the diff is `@since`-only and that no released-version tag (`0.1.0`–`0.10.0`) or version header was touched.
- This is the lowest-value finding in the audit batch; it is bookkeeping, not correctness. It is worth doing only as part of tidying up before the next release.
