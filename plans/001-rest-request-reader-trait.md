# Plan 001: Deduplicate REST request-readers and capability resolution into a shared trait

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 2cf63ed..HEAD -- classes/Rest/`
> If any file under `classes/Rest/` changed since this plan was written,
> compare the "Current state" excerpts against the live code before
> proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

Four of the five REST controllers (`Upload_Controller`, `Images_Controller`, `Media_Controller`, `Regenerate_Controller`) each carry **byte-for-byte identical** private helpers for reading the `wp_rest` nonce and the collection slug, plus a near-identical "apply the capability filter and harden its return" block. The hardening line is the security-sensitive one: *a buggy filter returning a non-string or empty value must fall back to the default rather than silently open the gate.* Today that rule is written out five times (the four controllers plus an inline copy in `Collections_Controller`). A future change — say, also rejecting whitespace-only capability strings, or logging a misused filter — must be made in five places, and the first one missed is a silent gate regression. Collapsing the leaf readers into one trait gives the rule a single home and shrinks each controller, with the existing unit and integration suites as the safety net.

## Current state

The five REST controllers live in `classes/Rest/`, all in namespace `Kntnt\Photo_Drop\Rest`, all WordPress-flavoured (`Pascal_Snake_Case` classes, tab indent, `snake_case` methods). There is **no trait anywhere in `classes/` yet** — this introduces the first one.

The duplicated members (verified identical bodies):

**`read_nonce()` — identical in `Upload_Controller.php:474`, `Images_Controller.php:372`, `Media_Controller.php:396`, `Regenerate_Controller.php:598`:**

```php
private function read_nonce( \WP_REST_Request $request ): string {

	// Take the header value first, then the parameter fallback; sanitise either
	// way so only a clean token string reaches the verifier.
	$header = $request->get_header( 'X-WP-Nonce' );
	$raw    = is_string( $header ) && $header !== '' ? $header : $request->get_param( '_wpnonce' );
	return is_string( $raw ) ? sanitize_text_field( $raw ) : '';

}
```

**`read_slug()` — identical in `Upload_Controller.php:496`, `Images_Controller.php:394`, `Media_Controller.php:418`, `Regenerate_Controller.php:621`:**

```php
private function read_slug( \WP_REST_Request $request ): string {
	$raw = $request->get_param( 'slug' );
	return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
}
```

**The capability harden block — same body, different filter name + `DEFAULT_CAPABILITY` per controller.** Two variants exist: a `private function required_capability()` (in `Upload_Controller.php:453` and `Regenerate_Controller.php:577`) and a `public static function required_capability()` (in `Images_Controller.php:351` and `Media_Controller.php:375` — public+static because `Render_Gallery` resolves the same value to gate its overlay UI). The shared shape is always:

```php
$filtered = apply_filters( '<filter name>', self::DEFAULT_CAPABILITY );
return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;
```

`Collections_Controller.php:129-130` inlines the same two lines inside `check_permission()`.

Per-controller filter names / defaults: upload → `kntnt_photo_drop_upload_capability` / `upload_files`; add-to-media → `kntnt_photo_drop_add_to_media_capability` / `upload_files`; delete → `kntnt_photo_drop_delete_capability` / `delete_others_posts`; manage (regenerate) → `kntnt_photo_drop_manage_capability` / `manage_options`; list → `kntnt_photo_drop_list_capability` / `edit_posts`.

The relative-path reader is **deliberately not** fully shared: `Upload_Controller::read_relative_path()` (`:517`) reads `self::RELATIVE_PATH_PARAM`, while `Images_Controller::read_path()` (`:413`) and `Media_Controller::read_path()` (`:437`) read `self::PATH_PARAM`. The bodies are otherwise identical (`return is_string( $raw ) ? $raw : ''`). Leave these out of scope — see Scope — because their differing param constant and differing PHPDoc make a shared version add an argument for little gain, and they are not the security-sensitive duplication.

Tests that protect this behavior (run them as the safety net, do not weaken them):
- `tests/Unit/Rest/UploadControllerTest.php`, `ImagesControllerTest.php`, `MediaControllerTest.php`, `RegenerateControllerTest.php`, `CollectionsControllerTest.php` — assert the nonce/capability gates at default and through each `kntnt_photo_drop_*_capability` filter.
- `tests/Integration/RestUploadTest.php`, `RestAddToMediaTest.php`, `RestTrashTest.php` — round-trip the live endpoints.

Coding-standard constraints to honor (from `docs/coding-standards.md`): WordPress flavour — tabs, `$snake_case`, `snake_case` methods, `Pascal_Snake_Case` type names; `[ ... ]` arrays; `declare( strict_types = 1 )` at the top of the new file; PHPDoc on the trait and every method explaining the *why/contract*; paragraph-style `//` comments preserved verbatim when moving the bodies. PSR-4: the trait file is `classes/Rest/Request_Gate.php` mapping to `\Kntnt\Photo_Drop\Rest\Request_Gate`. Use `@since 0.15.0` on the new symbols (matches the current unreleased cycle — see plan 004).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Static analysis | `composer phpstan` | exit 0, no errors |
| Code style | `composer phpcs` | exit 0, no violations |
| Unit tests | `composer test` | Pest green |
| Targeted unit tests | `vendor/bin/pest --testsuite Unit --filter Controller` | the 5 controller test files green |

(Integration tests need Docker via `@wordpress/env`; run `npm run test:integration` only if the environment is available. If it is not, say so in the report — do not claim it passed.)

## Scope

**In scope** (modify only these):
- `classes/Rest/Request_Gate.php` (create — the trait)
- `classes/Rest/Upload_Controller.php`
- `classes/Rest/Images_Controller.php`
- `classes/Rest/Media_Controller.php`
- `classes/Rest/Regenerate_Controller.php`
- `classes/Rest/Collections_Controller.php`

**Out of scope** (do NOT touch):
- `Upload_Controller::read_relative_path()` and the two `read_path()` methods — differing param constants; leave them per-controller.
- The `check_permission()` method *bodies* beyond swapping the capability-resolution expression for a trait call — do not unify `check_permission()` itself (Collections returns `bool`; the others return `bool|\WP_Error` with different wiring; that divergence is intentional).
- Any test file — this plan changes no behavior, so no test should need editing. If a test breaks, that is a STOP condition, not a license to edit the test.
- The public-vs-private/static split of `required_capability()` — keep each controller's existing signature; only its *body* delegates to the trait.

## Git workflow

- Branch: `advisor/001-rest-request-reader-trait`
- Commit style: conventional commits, matching `git log` (e.g. `refactor(rest): extract shared request readers into Request_Gate trait`).
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Create the `Request_Gate` trait

Create `classes/Rest/Request_Gate.php`:

```php
<?php
/**
 * Shared request-reading and capability-resolution helpers for REST controllers.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.15.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

/**
 * Leaf helpers shared by every collection REST controller.
 *
 * Each write controller reads the same two request fields the same way — the
 * `wp_rest` nonce (header first, parameter fallback) and the collection slug —
 * and resolves its gating capability through a filter with the identical
 * hardening rule. Centralising them here keeps that rule in one place: a filter
 * that returns a non-string or empty value is a misuse and falls back to the
 * default rather than silently disabling the gate. The trait holds no state; it
 * only factors out duplicated leaf logic, so the controllers' deep external
 * interface (their two callbacks) is unchanged.
 *
 * @since 0.15.0
 */
trait Request_Gate {

	/**
	 * Reads the `wp_rest` nonce from the request, header first.
	 *
	 * Prefers the canonical `X-WP-Nonce` header that `wp.apiFetch` and the
	 * plugin's fetch paths send, falling back to a `_wpnonce` parameter. The
	 * value is sanitised before it reaches `wp_verify_nonce()`.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The nonce string, or '' when none was supplied.
	 */
	private function read_nonce( \WP_REST_Request $request ): string {

		// Take the header value first, then the parameter fallback; sanitise
		// either way so only a clean token string reaches the verifier.
		$header = $request->get_header( 'X-WP-Nonce' );
		$raw    = is_string( $header ) && $header !== '' ? $header : $request->get_param( '_wpnonce' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';

	}

	/**
	 * Reads and sanitises the addressed collection slug.
	 *
	 * The slug comes from the matched route segment; it is sanitised here as
	 * defence in depth, though the `Repository` re-validates it strictly before
	 * any filesystem access.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The sanitised slug, or '' when absent.
	 */
	private function read_slug( \WP_REST_Request $request ): string {
		$raw = $request->get_param( 'slug' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Resolves a gating capability through its filter, hardening the result.
	 *
	 * Applies the named `kntnt_photo_drop_*_capability` filter to the default
	 * and rejects any non-string or empty return back to the default, so a buggy
	 * filter can never open the gate.
	 *
	 * @since 0.15.0
	 *
	 * @param string $filter  The capability filter name.
	 * @param string $default The default capability when the filter is unused or misused.
	 * @return string The capability string to check.
	 */
	private static function resolve_capability( string $filter, string $default ): string {

		// Apply the filter and harden its return: a non-string or empty result
		// is rejected back to the default, so a buggy filter can never open the
		// gate.
		$filtered = apply_filters( $filter, $default );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : $default;

	}

}
```

Note `resolve_capability()` is `private static` so it works for both the `private` callers (Upload, Regenerate, Collections) and the `public static` `required_capability()` callers (Images, Media) — a static method can be called from a static or instance context.

**Verify**: `composer phpcs -- classes/Rest/Request_Gate.php` → exit 0. `composer phpstan` → exit 0.

### Step 2: Wire the trait into all five controllers and delete the duplicated readers

For each of `Upload_Controller`, `Images_Controller`, `Media_Controller`, `Regenerate_Controller`, `Collections_Controller`:

1. Add `use Request_Gate;` as the first statement inside the class body (immediately after the opening `{`, as its own paragraph with a `//` topic line such as `// Shared request-reading and capability-resolution helpers.`).
2. Delete that class's now-duplicated `read_nonce()` and `read_slug()` methods (Collections has no `read_nonce`/`read_slug` of its own — skip what is absent).
3. Replace the body of each `required_capability()` with a single delegating line, keeping the method's existing signature (`private` for Upload/Regenerate, `public static` for Images/Media):

   ```php
   public static function required_capability(): string {
   	return self::resolve_capability( 'kntnt_photo_drop_delete_capability', self::DEFAULT_CAPABILITY );
   }
   ```

   (Substitute each controller's own filter name; `DEFAULT_CAPABILITY` is already the right per-controller constant.)
4. In `Collections_Controller::check_permission()`, replace the inlined two-line resolve+harden (`:129-130`) with `$capability = self::resolve_capability( 'kntnt_photo_drop_list_capability', self::DEFAULT_CAPABILITY );`.

Leave every `check_permission()` call site otherwise untouched — they already call `$this->read_nonce(...)`, `$this->read_slug(...)`, and `$this->required_capability()` / `static::required_capability()`, which now resolve through the trait.

**Verify**: `composer phpstan` → exit 0. `composer phpcs` → exit 0.

### Step 3: Confirm no behavior changed

Run the controller unit tests and the full unit suite.

**Verify**: `vendor/bin/pest --testsuite Unit --filter Controller` → all green. `composer test` → Pest green, zero failures, zero new skips.

## Test plan

- **No new tests.** This is a behavior-preserving refactor; the existing controller unit tests (which assert each gate at its default *and* through its `kntnt_photo_drop_*_capability` filter) are the regression guard. Their continued passing is the proof.
- After the change, `grep -rn "X-WP-Nonce" classes/Rest/` should return exactly one match (inside `Request_Gate.php`), down from four.
- If the environment has Docker, run `npm run test:integration` and confirm `RestUploadTest`, `RestAddToMediaTest`, `RestTrashTest` stay green. If not, report integration as "not run (no Docker)".

## Done criteria

ALL must hold:

- [ ] `composer phpstan` exits 0
- [ ] `composer phpcs` exits 0
- [ ] `composer test` exits 0; no test files were modified (`git status` shows only the six in-scope `classes/Rest/` files changed)
- [ ] `grep -rn "X-WP-Nonce" classes/Rest/` returns exactly one match (in `Request_Gate.php`)
- [ ] `grep -rcn "function read_nonce" classes/Rest/*.php` shows the method only in `Request_Gate.php`
- [ ] `plans/README.md` status row for 001 updated

## STOP conditions

Stop and report (do not improvise) if:

- Any file under `classes/Rest/` differs from the "Current state" excerpts (drift since `2cf63ed`).
- Any controller unit test fails after the change — that means the refactor altered behavior; do **not** edit the test to make it pass.
- PHPStan reports a new error about `apply_filters()` return types or trait method visibility that you cannot resolve without changing a controller's public signature.
- You find a sixth controller, or a non-controller caller of `read_nonce`/`read_slug` that would also need the trait.

## Maintenance notes

- The capability-hardening rule now lives only in `Request_Gate::resolve_capability()`. Any future tightening (e.g. `trim()`-then-recheck, or logging a misused filter) goes there once and covers all six gates.
- `Render_Gallery` and the admin/Drop-Zone renderers call `Images_Controller::required_capability()` / `Media_Controller::required_capability()` to gate their UI; those signatures are unchanged, so those call sites keep working.
- A reviewer should confirm: each `required_capability()` kept its original visibility, the `use Request_Gate;` line sits at the top of each class body, and no `read_path`/`read_relative_path` method was folded in (deliberately out of scope).
- Deferred: the `read_path`/`read_relative_path` near-duplication is left alone on purpose; if a fourth path-reading controller appears, reconsider adding a `read_path( string $param )` helper to the trait then.
