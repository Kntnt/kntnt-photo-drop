# Plan 006: Exercise the `*_capability` filter override at the integration layer

> **Executor instructions**: Read "Why this matters" before starting — this is
> the **lowest-leverage** plan in the batch and the reviewer may reasonably
> reject it. It requires Docker (`@wordpress/env`). If Docker is unavailable,
> STOP and report that you cannot run integration tests rather than writing a
> test you cannot verify. Follow the steps and run every verification command.
>
> **Drift check (run first)**: `git diff --stat 2cf63ed..HEAD -- tests/Integration/ .wp-env.json classes/Rest/Upload_Controller.php`
> If these changed since this plan was written, re-read the live state first.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (soft: run after plan 001 if both are done, so the new assertion exercises the refactored `Request_Gate::resolve_capability()`)
- **Category**: tests
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

Every write REST endpoint resolves its gating capability through a `kntnt_photo_drop_*_capability` filter, so a site can re-gate any action without touching code (ADR-0015). The **unit** tests already prove each controller reads and hardens its filter (e.g. `tests/Unit/Rest/UploadControllerTest.php` asserts the gate at its default *and* through `kntnt_photo_drop_upload_capability`), and the **integration** tests already prove the live endpoint enforces the default gate (`RestUploadTest.php`: admin 200, no-nonce 401, subscriber 403). What no test proves end-to-end is that a *custom* filter registered in a running WordPress is honored by the live HTTP request — the seam between "controller reads the filter" (unit) and "the endpoint enforces it" (integration) is only inferred. This plan closes that seam for one representative endpoint. **Honest caveat:** the marginal assurance is small and the harness cost (a test-only mu-plugin mounted into wp-env) is real; reviewers who judge the unit coverage sufficient should mark this REJECTED in the index rather than carry the extra harness.

## Current state

- `tests/Integration/RestUploadTest.php` — the upload round-trip integration test (read it in full; mirror its structure). It authenticates via `admin_session()` / `login_session()`, POSTs with `rest_upload(...)`, and asserts status + on-disk effect. Helpers live in `tests/Integration/helpers.php` (`create_collection`, `delete_collection`, `delete_user`, `collection_path`, `rest_upload`, `run_cli`, `unique_slug`, `write_jpeg`, …). `run_cli([...])` runs WP-CLI **inside the container** — use it to set options and create users.
- `Upload_Controller::required_capability()` applies `kntnt_photo_drop_upload_capability` defaulting to `upload_files` (`classes/Rest/Upload_Controller.php:453-459`).
- `.wp-env.json` currently mounts only the plugin (`"plugins": [ "." ]`) with no `mappings` and no mu-plugins. **A filter that affects an HTTP request must run inside the WordPress process**, so a host-side `add_filter` cannot work and a `run_cli(['eval', …])` filter dies with its one CLI process. The mechanism therefore is: mount a tiny **option-gated** mu-plugin into the instance; it is inert unless an integration test sets the option, so it does not affect normal dev, e2e, or other integration tests.

Conventions: `docs/testing.md` says capability-filter behavior is asserted "at its default AND through its filter" and that the write endpoints are integration-first. Integration tests are Pest files in `tests/Integration/` driven against `@wordpress/env`. The mu-plugin file follows the plugin's PHP standard (`declare( strict_types = 1 )`, paragraph comments, PHPDoc) but is test scaffolding, not shipped code.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Boot + run integration suite | `npm run test:integration` | Pest green (boots wp-env itself) |
| Run just this file | `npm run test:integration -- --filter "capability filter"` | the new test green |
| Confirm mu-plugin is inert by default | `npm run test:integration` (full) | all pre-existing integration tests still green |

(All require Docker. If absent, STOP — do not fake a pass.)

## Scope

**In scope** (create/modify only these):
- `tests/Integration/mu-plugins/kntnt-pd-test-capability.php` (create — the option-gated filter)
- `.wp-env.json` (add a `mappings` entry mounting the mu-plugin)
- `tests/Integration/RestUploadTest.php` (add one test) — or a new `tests/Integration/RestCapabilityFilterTest.php` if you prefer isolation; either is acceptable.

**Out of scope** (do NOT touch):
- `classes/` — no production change. The filter already works; this only observes it live.
- The other write endpoints (add-to-media, trash) — one representative endpoint (upload) is enough; do not fan this out to all three (the unit layer already covers each filter individually).
- Any other `.wp-env.json` field beyond adding `mappings`.

## Git workflow

- Branch: `advisor/006-integration-capability-filter-override`
- Commit style: conventional commits (e.g. `test(rest): prove the upload capability filter is honored end-to-end`).
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add the option-gated mu-plugin

Create `tests/Integration/mu-plugins/kntnt-pd-test-capability.php`:

```php
<?php
/**
 * Integration test scaffold: re-gates the upload capability from an option.
 *
 * Mounted into the wp-env instance via `.wp-env.json` `mappings`. It is inert
 * unless an integration test sets the `kntnt_pd_test_upload_capability` option,
 * so it does not affect normal dev, e2e, or other integration tests. When the
 * option holds a non-empty capability string, it overrides the upload gate
 * through the same `kntnt_photo_drop_upload_capability` filter a site would use,
 * proving the live endpoint honors that filter end-to-end.
 *
 * @package Kntnt\Photo_Drop
 */

declare( strict_types = 1 );

// Override the upload capability from a test option when one is set; otherwise
// return the default untouched so the filter is a no-op for every other test.
add_filter(
	'kntnt_photo_drop_upload_capability',
	static function ( $default ) {
		$override = get_option( 'kntnt_pd_test_upload_capability', '' );
		return is_string( $override ) && $override !== '' ? $override : $default;
	}
);
```

### Step 2: Mount it via `.wp-env.json`

Add a `mappings` entry (keep the existing fields):

```json
"mappings": {
	"wp-content/mu-plugins/kntnt-pd-test-capability.php": "tests/Integration/mu-plugins/kntnt-pd-test-capability.php"
}
```

**Verify**: `npx wp-env start` then `npx wp-env run cli wp eval 'echo has_filter("kntnt_photo_drop_upload_capability") ? "wired" : "absent";'` → prints `wired`. (Or just proceed to Step 3 and rely on the test.)

### Step 3: Add the integration test

In `tests/Integration/RestUploadTest.php` (or a new file mirroring its imports/`beforeAll`/`afterAll`), add a test that:

1. Creates an **Author** user via `run_cli([ 'user', 'create', <name>, <email>, '--role=author', '--user_pass=…' ])` (an Author has `upload_files` but not `manage_options`). Reuse `unique_slug()` for a unique name and `delete_user(...)` in a `finally`.
2. Sets the override option to a capability the Author lacks: `run_cli([ 'option', 'update', 'kntnt_pd_test_upload_capability', 'manage_options' ])`.
3. Logs in as the Author (`login_session(...)`), uploads the fixture, and asserts **403** with nothing written — the live endpoint honored the filtered, stricter capability.
4. Deletes the option (`run_cli([ 'option', 'delete', 'kntnt_pd_test_upload_capability' ])`) and uploads again as the same Author, asserting **200** (`outcome` `reencoded`/`stored`) — with the filter inert, the default `upload_files` gate passes. This second half also proves the mu-plugin is genuinely option-gated.
5. Cleans up in `finally`: delete the option (idempotent) and the user.

Name it: `test( 'the upload capability filter is honored end-to-end', ... )`.

**Verify (RED first)**: comment out the `add_filter(...)` body in the mu-plugin (Step 1) and run `npm run test:integration -- --filter "capability filter"` → the 403 assertion FAILS (the Author uploads successfully because the override is ignored). This proves the test exercises the filter. Restore the mu-plugin.

### Step 4: Confirm GREEN and inert-by-default

**Verify (GREEN)**: `npm run test:integration -- --filter "capability filter"` → passes. Then `npm run test:integration` (full) → every pre-existing integration test still green (proves the mu-plugin is inert when the option is unset).

## Test plan

- **New test**: `'the upload capability filter is honored end-to-end'` — Author + `manage_options` override → 403, override cleared → 200.
- **New scaffold**: an option-gated mu-plugin, inert by default.
- **Pattern to mirror**: `RestUploadTest.php`'s subscriber-403 test (`:87`) for the user lifecycle and assertions.
- **Verification**: full integration suite green, including the RED demonstration.

## Done criteria

ALL must hold:

- [ ] `npm run test:integration` exits 0 with the new test passing and all existing integration tests still green
- [ ] The RED was demonstrated (disabling the mu-plugin filter made the 403 assertion fail) — note it in the report
- [ ] The mu-plugin is option-gated and inert when the option is unset (proven by the full suite staying green)
- [ ] `git status` shows only the three in-scope paths changed; no `classes/` file modified
- [ ] `plans/README.md` status row for 006 updated (or REJECTED with rationale if the reviewer judges the unit coverage sufficient)

## STOP conditions

Stop and report (do not improvise) if:

- Docker / `@wordpress/env` is unavailable — you cannot verify an integration test; do not write one blind.
- `RestUploadTest.php` or `.wp-env.json` differs from "Current state" (drift since `2cf63ed`).
- The RED step does not fail with the filter disabled — the test is not reaching the gate; fix once, else STOP.
- Mounting the mu-plugin breaks an existing integration or e2e test (the option-gating should prevent this; if it does not, the mu-plugin is not as inert as intended — STOP and report).
- Making it pass appears to require any change under `classes/`.

## Maintenance notes

- The mu-plugin is **test scaffolding**, never shipped — it lives under `tests/` and is mounted only into the dev/CI wp-env. It must stay option-gated so it cannot affect any test that does not opt in.
- If add-to-media or trash ever need the same end-to-end proof, generalize the mu-plugin to read one option per filter rather than copying it.
- A reviewer should decide whether this end-to-end proof earns the standing mu-plugin + `.wp-env.json` mapping, given the unit tests already assert each controller reads its filter. REJECTED-with-rationale is a legitimate close.
