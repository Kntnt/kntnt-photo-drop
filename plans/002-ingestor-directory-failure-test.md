# Plan 002: Cover the untested directory-creation failure branch in the Ingestor

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. The
> test-first order matters: write the test, watch it FAIL for the right reason
> (RED), then confirm it passes against the unchanged production code (it
> already handles this path — this plan adds the missing *coverage*, not new
> behavior). If anything in "STOP conditions" occurs, stop and report.
>
> **Drift check (run first)**: `git diff --stat 2cf63ed..HEAD -- classes/Ingestion/Ingestor.php tests/Unit/Ingestion/IngestorTest.php`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

The plugin's batch-upload guarantee is that *one bad file is a per-file rejection, never a batch abort* (ADR-0006). One real-world way a single file fails mid-batch is a directory-creation error — disk full or permissions revoked while recreating the confined sub-directory tree for that file's target. `Ingestor::ingest()` handles it correctly: a failed `write_main()` returns `Ingest_Result::rejected()` and writes nothing. But `IngestorTest.php` aliases `wp_mkdir_p()` to a real recursive `mkdir()` in every test, so the `wp_mkdir_p() === false` branch — and the rejection it produces — is **never exercised**. This plan adds the one test that pins that contract, so a future refactor of `write_main()`/`ensure_dir()` that accidentally turned a mkdir failure into a phantom "stored" outcome would be caught.

## Current state

`classes/Ingestion/Ingestor.php` — the shared ingestion deep module. The relevant path (verbatim):

```php
// ingest(), classes/Ingestion/Ingestor.php:148-152
// Recreate the confined sub-directory tree and write the conforming main; a
// write failure is a rejection so the caller never reports a phantom store.
if ( ! $this->write_main( $target, $optimized->bytes ) ) {
	return Ingest_Result::rejected( $relative_path );
}
```

```php
// ensure_dir(), classes/Ingestion/Ingestor.php:246-256
private function ensure_dir( string $directory ): bool {

	// An existing directory needs nothing further.
	if ( is_dir( $directory ) ) {
		return true;
	}

	// Prefer the WordPress helper; fall back to a recursive mkdir for tests.
	if ( function_exists( 'wp_mkdir_p' ) ) {
		return wp_mkdir_p( $directory );
	}
	...
```

So: `wp_mkdir_p()` returning `false` → `ensure_dir()` false → `write_main()` false (after logging via `Plugin::error()`) → `ingest()` returns `Ingest_Result::rejected( $relative_path )` and nothing is written under the collection root. **The target must require a not-yet-existing sub-directory**, otherwise `ensure_dir()` short-circuits on `is_dir()` at line 249 and never calls `wp_mkdir_p()`.

`tests/Unit/Ingestion/IngestorTest.php` is the test file. Its `beforeEach` aliases `wp_mkdir_p` to real mkdir (line 39):

```php
Functions\when( 'wp_mkdir_p' )->alias(
	static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0700, true )
);
```

Existing rejection tests to mirror for the "nothing written" assertions — `IngestorTest.php:231` (`'an undecodable source is rejected with nothing written'`) and `:284` (`'a hostile traversal path is rejected and writes nothing outside the root'`). The sub-directory recreation test at `:269` (`'a relative path recreates its sub-directories confined inside the root'`) shows how to construct a deep relative path and where the root temp dir comes from. Read those three tests before writing the new one — reuse their helpers (`fresh_collection_root()`-style temp-dir helper, the image-bytes fixture, and the `Ingestor` construction) rather than inventing new ones.

Conventions (from `agents.d/coding-standards.md` + the existing file): Pest `test( 'imperative behavior statement', function (): void { ... } )`; Arrange-Act-Assert; tab indent; `expect(...)->toBe(...)`; Brain Monkey `Functions\when(...)` for WP stubs. The test name states the expected behavior. Per the testing standard (`agents.d/testing.md`), this is pure-unit territory and the RED must be demonstrated.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Run this test file | `vendor/bin/pest tests/Unit/Ingestion/IngestorTest.php` | green, including the new test |
| Full unit suite | `composer test` | Pest green |
| Static analysis | `composer phpstan` | exit 0 (no production code changes, should be unaffected) |
| Code style | `composer phpcs -- tests/Unit/Ingestion/IngestorTest.php` | exit 0 |

## Scope

**In scope** (modify only this):
- `tests/Unit/Ingestion/IngestorTest.php` (add one test)

**Out of scope** (do NOT touch):
- `classes/Ingestion/Ingestor.php` — the production code already handles this path correctly; this plan adds coverage only. If you believe the production code is wrong, that is a STOP condition, not an edit.
- Any other test file.

## Git workflow

- Branch: `advisor/002-ingestor-directory-failure-test`
- Commit style: conventional commits (e.g. `test(ingestion): cover the directory-creation failure rejection`).
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add the failing test (RED)

Append one test to `tests/Unit/Ingestion/IngestorTest.php`, modeled on the existing `'a relative path recreates its sub-directories confined inside the root'` test (`:269`) for setup and the `'an undecodable source is rejected with nothing written'` test (`:231`) for the rejection/no-write assertions. The test must:

1. Build a collection root the same way the neighbouring tests do.
2. Override the `beforeEach` `wp_mkdir_p` alias **inside this test** so it returns `false`:
   ```php
   Functions\when( 'wp_mkdir_p' )->justReturn( false );
   ```
   (A `when()` in the test body replaces the `beforeEach` alias for this test only; Brain Monkey tears it down afterward.)
3. Ingest a valid, decodable image fixture (reuse the same bytes the passing ingest tests use) at a relative path containing a **new sub-directory** that does not yet exist under the root — e.g. `new-sub/photo.jpg` — so `ensure_dir()` reaches `wp_mkdir_p()` rather than short-circuiting on `is_dir()`.
4. Assert:
   - the returned `Ingest_Result` outcome is `rejected` (match how `:231` reads the outcome — likely `->outcome` / an `Ingest_Outcome` enum case; copy that test's exact assertion style),
   - no file exists at the would-be target,
   - the new sub-directory was not left behind under the root (mirror `:284`'s "root holds only …" assertion style).

Name it: `test( 'a directory-creation failure is a per-file rejection with nothing written', ... )`.

**Verify (RED)**: temporarily comment out the `Functions\when( 'wp_mkdir_p' )->justReturn( false );` line and run `vendor/bin/pest tests/Unit/Ingestion/IngestorTest.php --filter "directory-creation failure"` → the test FAILS (the image ingests successfully, outcome is not `rejected`). This proves the test actually exercises the branch. Restore the line.

### Step 2: Confirm GREEN

With the `justReturn( false )` line in place, run the test.

**Verify (GREEN)**: `vendor/bin/pest tests/Unit/Ingestion/IngestorTest.php --filter "directory-creation failure"` → passes. Then `composer test` → whole unit suite green.

## Test plan

- **New test**: `tests/Unit/Ingestion/IngestorTest.php` — `'a directory-creation failure is a per-file rejection with nothing written'`. Covers: `wp_mkdir_p()` returns false → `ingest()` returns a `rejected` outcome, no main file written, no orphan sub-directory left behind.
- **Pattern to mirror**: setup from `:269`, rejection/no-write assertions from `:231` and `:284`.
- **Verification**: `composer test` → green, one new test, no skips.

## Done criteria

ALL must hold:

- [ ] `composer test` exits 0; the new test exists and passes
- [ ] The RED was demonstrated (the test was seen to fail with the `wp_mkdir_p` override removed) — note this in the report
- [ ] `composer phpcs -- tests/Unit/Ingestion/IngestorTest.php` exits 0
- [ ] `git status` shows only `tests/Unit/Ingestion/IngestorTest.php` changed
- [ ] `plans/README.md` status row for 002 updated

## STOP conditions

Stop and report (do not improvise) if:

- `Ingestor.php` differs from the "Current state" excerpts (drift since `2cf63ed`).
- The RED step does **not** fail when the `wp_mkdir_p` override is removed — that means the test is not reaching the branch (likely the relative path didn't require a new sub-directory); fix the path once, and if it still won't go RED, stop and report.
- The test passes RED but the production code returns something other than a `rejected` outcome — that is a production bug; report it, do not change the assertion to match.
- Making the test pass appears to require editing `Ingestor.php`.

## Maintenance notes

- This test guards the ADR-0006 per-file-rejection guarantee for the disk-full/permissions case. If `write_main()` or `ensure_dir()` is ever refactored, this test must keep passing.
- A reviewer should confirm the test goes RED without the `wp_mkdir_p` override (otherwise it proves nothing) and that the relative path genuinely forces a new sub-directory.
- Deferred and recorded as rejected (see `plans/README.md`): the analogous `Atomic_Writer` short-write (partial disk-full) branch is *not* added here — its `=== false` consequence is already covered by `AtomicWriterTest.php:59,73`, and the distinct short-positive-count branch cannot be exercised without mocking the internal `file_put_contents`, which Brain Monkey does not reliably do in this harness and which would mean adding a new mocking dependency for a branch whose effect is already tested.
