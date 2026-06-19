# Plan 007: Insert add-to-media images at full resolution (no `-scaled`)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 2cf63ed..HEAD -- classes/Rest/Media_Controller.php tests/Integration/RestAddToMediaTest.php tests/Integration/helpers.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

The gallery's **add-to-media** overlay (ADR-0015) copies a collection's *main image* into the Media Library by calling `media_handle_sideload()`. WordPress's "big image" feature downscales any uploaded image wider or taller than `big_image_size_threshold` (default **2560 px**) into a separate `…-scaled.webp` master and attaches *that* instead of the original — so a collection whose main images exceed 2560 px (a field photographer's collection routinely will) ends up in the Media Library at reduced resolution with a `-scaled` suffix. The maintainer wants the main inserted at its **real, contract-bounded resolution**, exactly as the collection stores it — the main is already the source of truth, so WordPress re-scaling it is pure loss. This change disables the big-image threshold for this one sideload only, leaving ordinary Media Library uploads untouched.

## Current state

- `classes/Rest/Media_Controller.php` — the gallery's add-to-media REST write path. The `protected sideload()` method (a unit-test seam) does the actual Media Library import. The `-scaled` behaviour originates entirely inside `media_handle_sideload()`; nothing in this file currently controls it.

  Current `sideload()` body (lines ~322–359), the relevant tail:

  ```php
  		// Hand the temp copy to the Library as an independent attachment with its own
  		// sub-sizes; pass the original basename so the stored filename reads naturally.
  		$file = [
  			'name'     => wp_basename( $main_path ),
  			'tmp_name' => $temp,
  		];
  		$attachment_id = media_handle_sideload( $file, 0 );

  		// media_handle_sideload removes the temp file on success; clean it up
  		// ourselves on failure so a failed import leaves no orphan behind.
  		if ( $attachment_id instanceof \WP_Error && file_exists( $temp ) ) {
  			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up the throwaway temp copy after a failed import, outside the Media Library.
  			unlink( $temp );
  		}

  		return $attachment_id;
  ```

- `tests/Integration/RestAddToMediaTest.php` — the real-WordPress integration suite for this endpoint (drives JSON POSTs against the live wp-env instance, asserts the attachment is really created with sub-sizes). This is the **only** layer that can observe `-scaled`, because the unit suite (`tests/Unit/Rest/MediaControllerTest.php`) overrides `sideload()` with a recording stub and never touches a real Media Library. The file already seeds one collection (`create_collection($slug, '1200', 70)`) and imports a 1600×900 source — that main is **below** 2560 px, so it would never exhibit `-scaled`; the new test needs a collection whose main exceeds 2560 px.

- `tests/Integration/helpers.php` — host-side helpers driving wp-env via WP-CLI. Relevant existing helpers:
  - `create_collection(string $slug, string $max_width = '1200', int $quality = 70, ?string $name = null)` — `$max_width` is passed as `--upload-width`, which is the **main (upload) rendition** width ceiling. A value of `'none'` means the source's own dimensions.
  - `import_images(string $slug, array $sources): array` — imports real files through the CLI so they are conforming mains on disk.
  - `rest_add_to_media(string $slug, string $path, ?string $jar, ?string $nonce): array` — POSTs the add-to-media request; returns the decoded response (including the created attachment `id`).
  - `attachment_subsize_count(int $attachment_id): int` (around line 1461) — reads attachment metadata via WP-CLI. **Use this as the structural model** for the new helper in Step 1: copy its WP-CLI invocation shape (`run_cli([...])`, JSON-decode of `wp post meta get` / `wp post get`).
  - `admin_session(): array`, `unique_slug()`, `make_fixture_dir()`, `write_jpeg()`, `to_container_path()`, `delete_collection()`, `remove_tree()`, `attachment_count()` — already imported at the top of `RestAddToMediaTest.php`.

- **Conventions to follow**: tabs; `declare( strict_types = 1 )`; paragraph-style `//` comments with a topic sentence above each block; PHPDoc on every new function with `@since`, `@param`, `@return`. New symbols this cycle are stamped **`@since 0.15.0`** (see commit `fd80bfc` "normalize @since to 0.15.0 for this cycle's additions"; confirm by checking a recently-added symbol nearby). Integration test cases use Pest `test( '…', function () … )` and end with `media`/`remove_tree` cleanup — match the existing cases in `RestAddToMediaTest.php`.

- **ADR constraint to honour**: ADR-0015 (`docs/adr/0015-gallery-overlays-and-rest-write-path.md`) says add-to-media "sideloads the **main image** into the Media Library as an ordinary, independent attachment (WordPress generates its own sub-sizes)." Inserting at full resolution is *consistent* with this — sub-sizes still generate; only the lossy `-scaled` master replacement is suppressed. Step 4 adds a one-line clarifying note to the ADR so the behaviour is documented, not silently changed.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHP unit tests | `composer test` | Pest green, exit 0 |
| PHP static analysis | `composer phpstan` | level max, zero errors, exit 0 |
| PHP code style | `composer phpcs` | zero violations, exit 0 |
| Integration tests | `npm run test:integration` | Pest green; boots wp-env (Docker) itself |

**Note on the integration layer**: `npm run test:integration` needs Docker + `@wordpress/env`. If Docker is unavailable in your environment, you cannot run the RED→GREEN cycle for the new integration test here. In that case: still **write** the test (Step 2), commit it, and report in your final summary that the integration test was authored but **not executed** for lack of Docker (per `docs/definition-of-done.md`, state plainly what could not be run — never imply it passed).

## Scope

**In scope** (the only files you should modify):
- `classes/Rest/Media_Controller.php` — disable the threshold around the sideload.
- `tests/Integration/helpers.php` — add one helper that reads an attachment's stored file path.
- `tests/Integration/RestAddToMediaTest.php` — add the full-resolution test case.
- `docs/adr/0015-gallery-overlays-and-rest-write-path.md` — one-line clarifying note.
- `CHANGELOG.md` — one entry under `## [Unreleased]`.

**Out of scope** (do NOT touch, even though they look related):
- The duplicate-detection / overwrite / single-click work — that is **Plan 008**. Do not add dedup, an `overwrite` param, source meta, or any client-side change here. This plan changes resolution only.
- `tests/Unit/Rest/MediaControllerTest.php` — the `-scaled` behaviour is invisible to the unit seam; do not try to assert it there.
- Any global `add_filter( 'big_image_size_threshold', … )` outside `sideload()` — the suppression must be scoped to this one import so normal uploads keep WordPress's default scaling.

## Git workflow

- Branch: `advisor/007-add-to-media-full-resolution`.
- Commit per logical unit; the repo uses Conventional Commits — e.g. `fix: insert add-to-media images at full resolution (no -scaled)`. Recent example from `git log`: `fix: serve gallery renditions from a non-hidden directory (ADR-0003 amendment)`.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add an integration helper that reads an attachment's stored file path

In `tests/Integration/helpers.php`, add a helper that returns the value of an attachment's `_wp_attached_file` meta (the stored file path relative to the uploads dir). When WordPress scales a big image, this value points at the `…-scaled.<ext>` master; with scaling suppressed it points at the original. Model the WP-CLI invocation on the existing `attachment_subsize_count()` (around line 1461).

Target shape:

```php
/**
 * Reads an attachment's stored file path (`_wp_attached_file`) over WP-CLI.
 *
 * When WordPress's big-image threshold downscales an upload, this value names
 * the generated `…-scaled.<ext>` master; with scaling suppressed it names the
 * original file. The add-to-media full-resolution test asserts the absence of
 * the `-scaled` master here.
 *
 * @since 0.15.0
 *
 * @param int $attachment_id The attachment to inspect.
 * @return string The stored file path relative to the uploads basedir.
 */
function attachment_file( int $attachment_id ): string {

	// Read the meta over WP-CLI; an absent value yields the empty string.
	$result = run_cli( [ 'post', 'meta', 'get', (string) $attachment_id, '_wp_attached_file' ] );
	return trim( $result['output'] );

}
```

Confirm the exact `run_cli` argument convention by reading `attachment_subsize_count()` — if it prefixes commands differently (e.g. omits/keeps the leading `post`), match it exactly.

**Verify**: `composer phpcs -- tests/Integration/helpers.php` → exit 0 (no style violations in the new function). (PHPStan/Pest run after the next steps.)

### Step 2: Add the failing integration test (RED)

In `tests/Integration/RestAddToMediaTest.php`, add a test that seeds a collection whose **main width exceeds 2560 px**, imports a source at least that wide, copies it into the Media Library, and asserts the stored file is **not** a `-scaled` master. Seed a *dedicated* collection inside the test (do not reuse the file-level 1200-px `$slug`, whose main is below the threshold).

Target shape (adapt cleanup/imports to match the file's existing cases):

```php
test( 'add-to-media inserts the main at full resolution without a -scaled master', function (): void {

	// Seed a collection whose main width (3000) exceeds WordPress's 2560px
	// big-image threshold, then import a source wide enough to keep the main
	// above it. Without the threshold suppression the sideload would store a
	// `…-scaled.webp` master at 2560px instead of the real main.
	$slug     = unique_slug();
	$fixtures = make_fixture_dir();
	create_collection( $slug, '3000', 70 );
	write_jpeg( "{$fixtures}/big.jpg", 3200, 1800 );
	$import = import_images( $slug, [ to_container_path( "{$fixtures}/big.jpg" ) ] );
	expect( $import['exit_code'] )->toBe( 0 );

	// Copy the main into the Media Library through the real REST write-path.
	$session  = admin_session();
	$response = rest_add_to_media( $slug, 'big.jpg.webp', $session['jar'], $session['nonce'] );
	expect( $response['status'] )->toBe( 201 );
	$attachment_id = $response['body']['id'];

	// The stored file must be the original main, never a `-scaled` master.
	expect( attachment_file( $attachment_id ) )->not->toContain( '-scaled' );

	delete_collection( $slug );
	remove_tree( $fixtures );
} );
```

Adjust field access (`$response['status']` / `$response['body']['id']`) to match how the existing `rest_add_to_media` cases in this file read the response — read one existing case and mirror it exactly. The collection-relative path of an imported `big.jpg` is `big.jpg.webp` (the importer appends `.webp`); confirm against how the file-level `$main_path = 'photo.jpg.webp'` is derived.

**Verify (RED)**: `npm run test:integration` → this new test **fails** on the `not->toContain('-scaled')` assertion (the stored file *is* `…-scaled.webp`), proving the bug exists and the test catches it. If Docker is unavailable, skip running and note it (see Commands note); proceed to Step 3.

If a 3000-px `--upload-width` is rejected by the contract (e.g. an input-megapixel ceiling rejects a 3200×1800 source), that is a STOP condition — see STOP conditions for the fallback.

### Step 3: Suppress the big-image threshold for the sideload only (GREEN)

In `classes/Rest/Media_Controller.php`, wrap the `media_handle_sideload()` call in `sideload()` with a scoped `big_image_size_threshold` filter that disables scaling, restoring it immediately after so ordinary uploads are unaffected.

Replace the `$attachment_id = media_handle_sideload( $file, 0 );` line with:

```php
		// Insert the collection main at its real, contract-bounded resolution:
		// WordPress otherwise downscales any image past big_image_size_threshold
		// (2560px) into a `…-scaled` master and attaches that. The main is already
		// the source of truth, so disable the threshold for this one import only,
		// then restore it so ordinary Media Library uploads keep WordPress's
		// default scaling. Sub-sizes still generate (ADR-0015).
		add_filter( 'big_image_size_threshold', '__return_false' );
		$attachment_id = media_handle_sideload( $file, 0 );
		remove_filter( 'big_image_size_threshold', '__return_false' );
```

**Verify (GREEN)**:
- `npm run test:integration` → the Step 2 test now passes (Docker permitting; otherwise note it).
- `composer phpstan` → exit 0.
- `composer phpcs` → exit 0.

### Step 4: Document the behaviour in ADR-0015

In `docs/adr/0015-gallery-overlays-and-rest-write-path.md`, in the bullet that begins "**Add-to-media copies; it does not link.**" (in the "## The REST write-path, and add-to-media as a copy" section), append one sentence so the full-resolution behaviour is documented rather than silently introduced:

> The main is inserted at its real, contract-bounded resolution: WordPress's `big_image_size_threshold` scaling is disabled for this import so the Media Library copy is not a downscaled `-scaled` master (sub-sizes still generate).

Do not restructure the ADR; this is one appended sentence.

**Verify**: `git diff docs/adr/0015-gallery-overlays-and-rest-write-path.md` → shows only the appended sentence.

### Step 5: Add a CHANGELOG entry

In `CHANGELOG.md`, under `## [Unreleased]` → `### Changed`, add one bullet matching the surrounding terse, ADR-referencing style:

```markdown
- **Add-to-media inserts at full resolution.** Copying a collection image into the Media Library no longer produces a downscaled `…-scaled` master for mains wider than WordPress's 2560px big-image threshold; the contract-bounded main is inserted as-is (sub-sizes still generate). (ADR-0015)
```

**Verify**: `git diff CHANGELOG.md` → shows only the added bullet under `### Changed`.

## Test plan

- **New integration test** in `tests/Integration/RestAddToMediaTest.php`: "add-to-media inserts the main at full resolution without a -scaled master" — seeds a >2560 px main, copies it, asserts `_wp_attached_file` contains no `-scaled`. This is the RED→GREEN proof (the only layer that can observe big-image scaling).
- **New helper** `attachment_file()` in `tests/Integration/helpers.php`, modelled on `attachment_subsize_count()`.
- No unit test (the behaviour lives inside `media_handle_sideload`, below the unit seam).
- Verification: `npm run test:integration` → all pass including the 1 new test (Docker permitting).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer phpstan` exits 0
- [ ] `composer phpcs` exits 0
- [ ] `composer test` exits 0 (unchanged; no unit test added)
- [ ] `npm run test:integration` exits 0 with the new full-resolution test passing — **or**, if Docker is unavailable, the test is committed and the inability to run it is reported explicitly
- [ ] `grep -n "big_image_size_threshold" classes/Rest/Media_Controller.php` shows the `add_filter`/`remove_filter` pair inside `sideload()` and nowhere else in the codebase outside this method
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row for 007 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code in `sideload()` no longer matches the "Current state" excerpt (drift since this plan was written).
- The collection contract **rejects** a `--upload-width` above 2560 px, or an input-megapixel ceiling rejects the 3200×1800 fixture. Then the bug cannot be reproduced via `--upload-width`; report this and try the fallback: seed with `create_collection($slug, 'none', 70)` (original dimensions) and a fixture wider than 2560 px (e.g. 3000×2000) so the main keeps the source's full width. If *that* is also rejected, STOP and report — the threshold may already be unreachable, making this plan moot.
- `npm run test:integration` fails for a reason unrelated to this change (a pre-existing red suite), or the GREEN step does not flip the new test, after one reasonable fix attempt.
- The fix appears to require touching any file outside the in-scope list.

## Maintenance notes

- **Reviewer focus**: confirm the `big_image_size_threshold` filter is added *and removed* around exactly the `media_handle_sideload` call — a leaked `add_filter` without the matching `remove_filter` would silently disable big-image scaling for every subsequent upload in the same request lifecycle.
- **Interaction with Plan 008**: Plan 008 adds a "replace the attachment file in place" overwrite path. That path also regenerates attachment metadata/sub-sizes and **must apply the same threshold suppression**, or an overwrite would re-introduce the `-scaled` master this plan removes. Plan 008's steps reference this. If Plan 008 lands first or this technique is later refactored into a shared helper, keep both call sites covered.
- This is intentionally narrow: it does not change *which* file is copied (still the main), only its stored resolution.
