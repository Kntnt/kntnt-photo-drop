# Plan 008: Single-click add-to-media with de-dup and replace-in-place overwrite

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 2cf63ed..HEAD -- classes/Rest/Media_Controller.php classes/Rendering/Render_Gallery.php src/blocks/gallery/add-to-media.ts src/blocks/gallery/add-to-media-view.ts src/blocks/gallery/add-to-media.test.ts tests/Unit/Rest/MediaControllerTest.php tests/Integration/RestAddToMediaTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: MED
- **Depends on**: plans/007-add-to-media-full-resolution.md (soft — see note)
- **Category**: bug + direction (UX behaviour change)
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

The gallery's **add-to-media** overlay (ADR-0015) has three rough edges the maintainer wants fixed, all in one coherent behaviour change:

1. **It takes two clicks.** A deliberate two-click *confirm gate* (`confirmGate` in `add-to-media.ts`) arms the icon on the first click (a ring that reads like a focus ring) and only fires on the second. The maintainer wants **one click to add**.
2. **Every click adds another copy.** ADR-0015 explicitly chose "no dedup; each confirmed click adds another copy." The maintainer wants the plugin to **detect** that an image is already in the Media Library and **not silently duplicate** it.
3. **There is no overwrite path.** When the image already exists, the maintainer wants an **inline confirm** (like the trash overlay's Delete/Cancel popover) saying it already exists and offering to overwrite; on confirm, **replace the existing attachment's file in place** (same attachment ID, so any post already using it keeps working).

These three are coupled: making the add single-click is only safe *because* the dedup-confirm catches re-adds — without dedup, single-click would silently pile up duplicates, which is worse than today. So the two-click gate is **replaced** by a smarter, duplicate-only confirm: first add is one click; a re-add raises the overwrite popover.

Both #1 and #2 overturn ADR-0015 contract. Per `agents.d/definition-of-done.md` ("A change that needs to contradict a decision is blocked until the ADR is amended — it is never shipped as a silent deviation"), this plan **amends ADR-0015** (Step 9). The maintainer is authorising the change.

**Maintainer decisions baked into this plan** (do not second-guess them):
- Duplicate detection = stamp the **source identity (collection slug + collection-relative path)** as attachment post-meta at add time, and query that meta.
- Overwrite = **replace the file in place**, keeping the same attachment ID.

## Current state

### Server — `classes/Rest/Media_Controller.php`

The add-to-media write path. Deliberately not `final`: `protected sideload()` is a unit-test seam (the unit suite overrides it with a recording stub; the real `media_handle_sideload` is exercised by integration). This plan adds two more seams (`find_existing()`, `replace()`) following the same pattern, so the new branching logic is unit-testable without a real Media Library.

`add_to_media()` today (the tail, after path confinement and the main-image check at lines ~248–265):

```php
		if ( ! $this->is_main_image( $main_path ) ) {
			$message = __( 'The requested image is not a gallery image.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_not_a_main_image', $message, [ 'status' => 404 ] );
		}

		// Sideload the confined main into the Media Library as an independent copy;
		// a WP_Error means the Library import itself failed, which is a 500 rather
		// than a 201 over a copy that did not happen.
		$attachment_id = $this->sideload( $main_path );
		if ( $attachment_id instanceof \WP_Error ) {
			$reason = $attachment_id->get_error_message();
			Plugin::error( "Add-to-media sideload failed for collection {$slug}: {$reason}" );
			$message = __( 'The image could not be added to the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_sideload_failed', $message, [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( [ 'id' => $attachment_id ], 201 );
```

`sideload()` (lines ~322–359) copies the main to a temp file and calls `media_handle_sideload`. Relevant constants/methods already present: `PATH_PARAM = 'path'`, `read_slug()`, `read_path()`, `is_main_image()`. The route's `args` registers `slug` and `path` only (lines ~130–146).

> **If Plan 007 has landed**, `sideload()` already wraps `media_handle_sideload` in an `add_filter('big_image_size_threshold','__return_false') … remove_filter(...)` pair. The new `replace()` method in this plan must apply the **same** suppression around its metadata regeneration. If Plan 007 has *not* landed, add that suppression inline in `replace()` too (and note it), so an overwrite never re-introduces a `-scaled` master.

### Server — `classes/Rendering/Render_Gallery.php`

Mirrors the view-script's runtime strings onto the wrapper as `data-*` attributes (a view module cannot translate at runtime). The trash popover copy is mirrored at lines ~702–710 inside `if ( ! $is_preview && $delete_visible ) { … }`. The add-to-media block is the adjacent `if ( ! $is_preview && $add_to_media_visible ) { … }` at lines ~693–697, which currently emits only the media URL:

```php
		if ( ! $is_preview && $add_to_media_visible ) {
			$wrapper_attrs['data-kntnt-photo-drop-media-url'] = rest_url(
				sprintf( 'kntnt-photo-drop/v1/collections/%s/media', $slug ),
			);
		}
```

The trash copy pattern to mirror (lines ~706–710):

```php
			$wrapper_attrs['data-kntnt-photo-drop-delete-prompt']  =
				__( 'Delete this image permanently? This cannot be undone.', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-confirm'] = __( 'Delete', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-cancel']  = __( 'Cancel', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-delete-label']   = __( 'Confirm deletion', 'kntnt-photo-drop' );
```

### Client — `src/blocks/gallery/add-to-media.ts`

Pure of the DOM. Exports `addToMedia()` (the POST) and `confirmGate()` (the two-click state). The result type today:

```ts
export type AddToMediaResult =
	| { readonly ok: true; readonly id: number }
	| { readonly ok: false; readonly status: number };
```

`addToMedia(url, path, nonce, fetchImpl = fetch)` POSTs `{ path }` and maps 2xx-with-numeric-id → `{ ok: true, id }`, any other status → `{ ok: false, status }`, a thrown fetch → `{ ok: false, status: 0 }`. `confirmGate()` returns `{ armed, activate(), cancel() }` — the two-click state this plan removes.

### Client — `src/blocks/gallery/add-to-media-view.ts`

Thin DOM wiring. One delegated click listener per wrapper. Today: a first click on an icon arms it (`confirmGate`, `ARMED_CLASS`), a second fires `runCopy()`; a click elsewhere disarms. `runCopy()` dims the icon (`BUSY_CLASS`), awaits `addToMedia`, and on success flashes `DONE_CLASS` for `DONE_DURATION` (1500 ms). Reads the credential from `wrapper.dataset.kntntPhotoDropNonce` and `…MediaUrl`; bails (icons inert) when either is missing. The path is read off the clicked icon's `data-kntnt-photo-drop-path`.

### Client — the popover model: `src/blocks/gallery/trash-view.ts`

The overwrite popover in this plan is **modelled on** `trash-view.ts`'s `openConfirm()` (lines ~205–269) — an inline `role="dialog"` popover anchored to the icon, with a message `<p>` and two `<button>`s, Escape/Cancel to dismiss, focus moved to the safe button. **Reuse the existing CSS classes** so no new stylesheet rules are needed for layout: `kntnt-photo-drop-gallery__confirm` (popover), `…__confirm__message`, `…__confirm__button`, `…__confirm__button--cancel`. For the affirmative (Overwrite) button, use the base `…__confirm__button` class (not `--delete`, which is the destructive red). Read the `.kntnt-photo-drop-gallery__confirm` rules in `src/blocks/gallery/style.scss` first; if the base `__button` has no standalone visible style, add a minimal `…__confirm__button--overwrite` modifier there modelled on `…__button--cancel`.

> **Why inline and not a shared module**: extracting a shared `confirm-popover.ts` from `trash-view.ts` is the DRY-correct end state but widens this plan to `trash-view.ts` + its two test files, raising risk. It is deliberately deferred (see `plans/README.md` "considered and rejected" and Maintenance notes). Build the overwrite popover inline in `add-to-media-view.ts`.

### Tests

- `tests/Unit/Rest/MediaControllerTest.php` — adversarial Pest unit suite. `wire_media_stubs()` (lines ~51–93) stubs WP seams (`wp_verify_nonce`, `current_user_can`, `apply_filters`, `sanitize_text_field`, `__`, uploads helpers). `recording_media_controller()` (lines ~109–134) subclasses the controller, overriding `sideload()` to record the path into `$GLOBALS['kntnt_sideloaded_path']` and return a fixed id. `media_request()` builds a request. **The new dedup/overwrite logic must be unit-tested here** by extending the harness to stub the new seams.
- `tests/Integration/RestAddToMediaTest.php` — real-WordPress suite. **Contains a test asserting the OLD behaviour**: "A repeated confirm adds another copy (no dedup)" (described in the file header, around the `attachment_count` assertions). That test **must be updated** to assert the new behaviour (a repeat without overwrite is a 409 and adds nothing; an overwrite replaces in place).
- `src/blocks/gallery/add-to-media.test.ts` — Jest. Pins `addToMedia` request shape + outcomes and the `confirmGate` two-click contract. **Must be rewritten** for the new result union and the removal of `confirmGate`.

### Conventions

PHP: tabs, `declare( strict_types = 1 )`, `match` over `switch`, `[ … ]` arrays, paragraph `//` comments, PHPDoc on every symbol with `@since 0.15.0` for new ones (confirm against a recently-added neighbour; see commit `fd80bfc`). TS: `@wordpress/scripts` happy path, 2-space indent (the block toolchain's Prettier), TSDoc on every exported symbol, discriminated unions over boolean flags where it aids clarity, options object when params exceed three. All user-facing strings translatable via `__()` (PHP) / mirrored from the server (view scripts cannot translate at runtime). Filters namespaced `kntnt_photo_drop_*`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHP unit tests | `composer test` | Pest green, exit 0 |
| PHP static analysis | `composer phpstan` | level max, zero errors |
| PHP code style | `composer phpcs` | zero violations |
| Block JS unit tests | `npm run test:js` | Jest green |
| Block JS lint | `npm run lint:js` | clean |
| Block CSS lint | `npm run lint:css` | clean |
| Block build (type-checks + emits `build/`) | `npm run build` | compiles, no type errors; `build/` updated |
| Integration tests | `npm run test:integration` | Pest green; boots wp-env (Docker) |

**Note**: `npm run test:integration` needs Docker + `@wordpress/env`. If unavailable, write/commit the integration changes and report they were not executed (per `agents.d/definition-of-done.md`). `npm run build` is required for any change under `src/blocks/` because `build/` is committed.

## Scope

**In scope**:
- `classes/Rest/Media_Controller.php` — dedup branching, `overwrite` param, source-meta stamping, `find_existing()` + `replace()` seams, 409/200 responses.
- `classes/Rendering/Render_Gallery.php` — mirror the overwrite-confirm copy onto the wrapper.
- `src/blocks/gallery/add-to-media.ts` — new result union, `overwrite` option, remove `confirmGate`.
- `src/blocks/gallery/add-to-media-view.ts` — single-click flow, overwrite popover.
- `src/blocks/gallery/style.scss` — only if the affirmative button needs a non-destructive modifier (see Current state).
- `tests/Unit/Rest/MediaControllerTest.php` — harness extension + dedup/overwrite/stamp tests.
- `tests/Integration/RestAddToMediaTest.php` — update the no-dedup test; add overwrite test.
- `src/blocks/gallery/add-to-media.test.ts` — rewrite for the new contract.
- `docs/adr/0015-gallery-overlays-and-rest-write-path.md` — amendment section.
- `CHANGELOG.md` — entries under `## [Unreleased]`.
- `build/` — regenerated by `npm run build` (commit it; never hand-edit).

**Out of scope** (do NOT touch):
- `src/blocks/gallery/trash-view.ts`, `trash-view.test.ts`, `confirm-message-align.test.ts`, `delete-image.ts` — the trash path is unrelated; do not refactor a shared popover module here (deferred).
- The `Path_Guard`, `is_main_image`, nonce/capability gates — the security spine is correct and unchanged; dedup/overwrite happens *after* those gates pass.
- The `-scaled` resolution fix itself — that is Plan 007 (this plan only *reuses* its threshold-suppression in the new `replace()` path).
- Any new capability or filter for overwrite — overwrite reuses the existing `add_to_media` capability (a user who can add may overwrite their own add).

## Git workflow

- Branch: `advisor/008-add-to-media-single-click-dedup-overwrite`.
- Conventional Commits; commit per logical unit (e.g. `feat: detect duplicate add-to-media copies and offer overwrite`, `feat: make add-to-media a single click`). Build the codebase so it is never broken between commits where possible (server seams + tests first, then client).
- Do NOT push or open a PR unless instructed.

## Steps

Order: server first (test-first), then client, then docs/build. The server is independently testable and the client depends on the server's new responses.

### Step 1: Define the source-meta keys and a request `overwrite` flag (server)

In `classes/Rest/Media_Controller.php`, add two private constants for the meta keys and one for the body param, near `PATH_PARAM`:

```php
	/** Attachment post-meta key recording the source collection slug. @since 0.15.0 */
	private const META_COLLECTION = '_kntnt_photo_drop_collection';

	/** Attachment post-meta key recording the source collection-relative path. @since 0.15.0 */
	private const META_PATH = '_kntnt_photo_drop_path';

	/** The JSON body flag asking to overwrite an existing copy in place. @since 0.15.0 */
	private const OVERWRITE_PARAM = 'overwrite';
```

Register the `overwrite` arg in `register_routes()` `args` (alongside `slug`/`path`), as an optional boolean:

```php
					self::OVERWRITE_PARAM => [
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => static fn ( mixed $value ): bool => (bool) $value,
					],
```

Add a reader mirroring `read_path()`:

```php
	/**
	 * Reads the optional overwrite flag from the request.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool True when the caller asked to overwrite an existing copy.
	 */
	private function read_overwrite( \WP_REST_Request $request ): bool {
		return (bool) $request->get_param( self::OVERWRITE_PARAM );
	}
```

**Verify**: `composer phpstan` + `composer phpcs` → exit 0 (no behaviour change yet).

### Step 2: Add the `find_existing()` and `replace()` seams (server)

Add two `protected` methods so the new branching is unit-testable without a Media Library, mirroring the existing `sideload()` seam philosophy.

`find_existing()` — looks up an attachment previously stamped with this collection + path:

```php
	/**
	 * Finds an attachment previously copied from this exact collection image.
	 *
	 * Duplicate detection (ADR-0015 amendment): each add-to-media copy is stamped
	 * with its source collection slug and collection-relative path as post-meta, so
	 * a later add of the same image can be recognised. Returns the existing
	 * attachment id, or null when this image is not yet in the Library. A protected
	 * seam so a unit test can control the verdict without a real Media Library.
	 *
	 * @since 0.15.0
	 *
	 * @param string $slug     The collection slug.
	 * @param string $relative The collection-relative path of the main image.
	 * @return int|null The existing attachment id, or null when none.
	 */
	protected function find_existing( string $slug, string $relative ): ?int {

		// Query attachments stamped with this exact source identity; the newest
		// match (there should be at most one) is the existing copy.
		$matches = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => [
					'relation' => 'AND',
					[ 'key' => self::META_COLLECTION, 'value' => $slug ],
					[ 'key' => self::META_PATH, 'value' => $relative ],
				],
			]
		);
		return isset( $matches[0] ) ? (int) $matches[0] : null;

	}
```

`replace()` — overwrites an existing attachment's file in place, keeping its id:

```php
	/**
	 * Replaces an existing attachment's file in place from a collection main.
	 *
	 * Overwrite semantics (ADR-0015 amendment): the attachment id is preserved so
	 * any post already embedding it keeps working. The current attached file is
	 * overwritten with the confined main's bytes, stale sub-sizes are dropped, and
	 * metadata is regenerated. Big-image scaling is disabled for the regeneration
	 * so the replacement is full-resolution, not a `-scaled` master (see Plan 007).
	 * A protected seam so a unit test can record the call without a real Library.
	 *
	 * @since 0.15.0
	 *
	 * @param int    $attachment_id The existing attachment to overwrite.
	 * @param string $main_path     The absolute path to the confined main image.
	 * @return int|\WP_Error The same attachment id on success, or a WP_Error.
	 */
	protected function replace( int $attachment_id, string $main_path ): int|\WP_Error {

		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Resolve the attachment's current file; without it there is nothing to
		// replace in place.
		$current = get_attached_file( $attachment_id );
		if ( $current === false || $current === '' ) {
			return new \WP_Error( 'kntnt_photo_drop_replace_no_file', 'The attachment has no file to replace.' );
		}

		// Drop the existing generated sub-sizes so regeneration does not leave
		// orphaned intermediate files behind, then overwrite the master bytes.
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		$this->delete_subsizes( $current, is_array( $old_meta ) ? $old_meta : [] );
		if ( ! copy( $main_path, $current ) ) {
			return new \WP_Error( 'kntnt_photo_drop_replace_copy', 'Could not overwrite the attachment file.' );
		}

		// Regenerate metadata + sub-sizes for the new bytes at full resolution
		// (big-image scaling disabled, matching the fresh-add path).
		add_filter( 'big_image_size_threshold', '__return_false' );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $current );
		remove_filter( 'big_image_size_threshold', '__return_false' );
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		return $attachment_id;

	}
```

Add the small private helper `delete_subsizes()` that unlinks intermediate size files next to the master (model the unlink/`phpcs:ignore` comment on the existing temp-cleanup in `sideload()`):

```php
	/**
	 * Removes an attachment's generated sub-size files beside its master.
	 *
	 * @since 0.15.0
	 *
	 * @param string               $master_path The attachment's master file path.
	 * @param array<string, mixed>  $meta        The attachment metadata (may be empty).
	 * @return void
	 */
	private function delete_subsizes( string $master_path, array $meta ): void {

		// Each sub-size lives beside the master; unlink any that still exist so a
		// regeneration cannot leave a stale intermediate behind.
		$dir   = dirname( $master_path );
		$sizes = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : [];
		foreach ( $sizes as $size ) {
			$file = is_array( $size ) ? ( $size['file'] ?? '' ) : '';
			if ( is_string( $file ) && $file !== '' && is_file( "{$dir}/{$file}" ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a stale sub-size beside the master before regeneration.
				unlink( "{$dir}/{$file}" );
			}
		}

	}
```

**Verify**: `composer phpstan` + `composer phpcs` → exit 0. (`get_posts`, `get_attached_file`, `wp_get_attachment_metadata`, `wp_generate_attachment_metadata`, `wp_update_attachment_metadata` are in the WordPress stubs PHPStan loads.)

### Step 3: Wire dedup + overwrite + stamping into `add_to_media()` (server, test-first)

First write the failing unit tests (Step 4 details the harness changes), then implement. Replace the sideload tail of `add_to_media()` (the excerpt in Current state) with the branching:

```php
		// Compute the canonical source identity (collection slug + collection-relative
		// path) used both to detect a prior copy and to stamp a fresh one.
		$relative = ltrim( substr( $main_path, strlen( $collection_path ) ), '/' );
		$existing = $this->find_existing( $slug, $relative );

		// A prior copy exists and the caller has not asked to overwrite: report 409
		// with the existing id so the gallery can raise its overwrite confirm. Nothing
		// is copied.
		if ( $existing !== null && ! $this->read_overwrite( $request ) ) {
			$message = __( 'This image is already in the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error(
				'kntnt_photo_drop_already_in_media',
				$message,
				[ 'status' => 409, 'id' => $existing ]
			);
		}

		// A prior copy exists and the caller confirmed overwrite: replace its file in
		// place (same id, so embeds keep working). A WP_Error is a 500.
		if ( $existing !== null ) {
			$replaced = $this->replace( $existing, $main_path );
			if ( $replaced instanceof \WP_Error ) {
				$reason = $replaced->get_error_message();
				Plugin::error( "Add-to-media overwrite failed for collection {$slug}: {$reason}" );
				$message = __( 'The image could not be overwritten in the Media Library.', 'kntnt-photo-drop' );
				return new \WP_Error( 'kntnt_photo_drop_overwrite_failed', $message, [ 'status' => 500 ] );
			}
			return new \WP_REST_Response( [ 'id' => $replaced ], 200 );
		}

		// No prior copy: sideload a fresh independent attachment, then stamp it with
		// its source identity so a future add recognises it. A failed import is a 500.
		$attachment_id = $this->sideload( $main_path );
		if ( $attachment_id instanceof \WP_Error ) {
			$reason = $attachment_id->get_error_message();
			Plugin::error( "Add-to-media sideload failed for collection {$slug}: {$reason}" );
			$message = __( 'The image could not be added to the Media Library.', 'kntnt-photo-drop' );
			return new \WP_Error( 'kntnt_photo_drop_sideload_failed', $message, [ 'status' => 500 ] );
		}
		update_post_meta( $attachment_id, self::META_COLLECTION, $slug );
		update_post_meta( $attachment_id, self::META_PATH, $relative );

		return new \WP_REST_Response( [ 'id' => $attachment_id ], 201 );
```

Note the response shapes the client (Step 6) depends on: **201 `{ id }`** (created), **200 `{ id }`** (replaced), **409** carrying the existing id in the error data (`[ 'status' => 409, 'id' => $existing ]`). Confirm how `WP_Error` data surfaces in the REST JSON body in this codebase — WordPress serialises `WP_Error` data under `data` (so the client reads `body.data.id` for the 409). Verify against the integration helper's response shape in Step 8 and align the client read in Step 6 to match.

**Verify**: `composer test` → the new unit tests from Step 4 pass; `composer phpstan` + `composer phpcs` → exit 0.

### Step 4: Extend the unit harness and add dedup/overwrite/stamp tests (server)

In `tests/Unit/Rest/MediaControllerTest.php`:

1. In `wire_media_stubs()`, add stubs for the new WordPress functions so the 201 path (which now stamps meta) and the lookup path work:
   - `Functions\when( 'update_post_meta' )` — record into a global, e.g. `$GLOBALS['kntnt_stamped_meta'][$key] = $value;`, return true.
   - `get_posts` is called only by the *real* `find_existing()`, which unit tests override via the recording controller (below) — so it need not be stubbed globally, but add a safe `Functions\when('get_posts')->justReturn([])` default so any unmocked call is harmless.

2. Extend `recording_media_controller()` (or add a sibling factory) so the anonymous subclass also overrides `find_existing()` and `replace()`:
   - `find_existing()` returns a configurable id-or-null (default null) so a test can simulate "no duplicate" vs "duplicate exists".
   - `replace()` records `$GLOBALS['kntnt_replaced'] = [ $attachment_id, $main_path ];` and returns the id.

3. Add tests (each follows the existing Arrange-Act-Assert + `media_remove_tree` cleanup style):
   - **fresh add stamps source meta and returns 201**: `find_existing` → null; assert 201, `$GLOBALS['kntnt_sideloaded_path']` is the main, and `update_post_meta` recorded the slug under `_kntnt_photo_drop_collection` and the relative path under `_kntnt_photo_drop_path`.
   - **a duplicate without overwrite is a 409 carrying the existing id, nothing sideloaded**: `find_existing` → e.g. 99; request without `overwrite`; assert `WP_Error` status 409, error data `id` === 99, `$GLOBALS['kntnt_sideloaded_path']` is null and `kntnt_replaced` is null.
   - **a duplicate with overwrite replaces in place and returns 200 with the same id**: `find_existing` → 99; request with `overwrite` true; assert 200, body `id` === 99, `kntnt_replaced` === `[99, <main path>]`, `kntnt_sideloaded_path` null.
   - **a failed overwrite is a 500**: override `replace()` to return a `WP_Error` (a second anonymous subclass like the existing failed-sideload test at lines ~511–522); assert 500.

Use `media_request()` for the no-overwrite cases; build an overwrite request by calling `$request->set_param( 'overwrite', true )` on a `media_request(...)` (or add a `$overwrite` param to a small request builder).

**Verify (RED then GREEN)**: run `composer test` after writing the tests but before Step 3's implementation to see them fail for the right reason (assertion failures, not fatals); then after Step 3 they pass. If you implement Step 3 first by accident, at least confirm the tests fail when you temporarily revert the branching.

### Step 5: Rewrite the client request module `add-to-media.ts`

Replace the result type with a discriminated union on a single `outcome` field, add an `overwrite` option, and **remove `confirmGate` and `ConfirmGate`** entirely.

Target shapes:

```ts
/**
 * The outcome of an add-to-media request.
 *
 * `created` — a new attachment (201). `replaced` — an existing attachment's file
 * was overwritten in place (200). `exists` — the image is already in the Library
 * and overwrite was not requested (409); carries the existing id so the view can
 * raise its overwrite confirm. `error` — any other failure (forgery, capability,
 * confinement, server, or a transport drop), carrying the HTTP status (0 for a
 * thrown fetch).
 */
export type AddToMediaResult =
	| { readonly outcome: 'created'; readonly id: number }
	| { readonly outcome: 'replaced'; readonly id: number }
	| { readonly outcome: 'exists'; readonly id: number }
	| { readonly outcome: 'error'; readonly status: number };

/** Options for {@link addToMedia}. */
export interface AddToMediaOptions {
	/** Ask the server to overwrite an existing copy in place. */
	readonly overwrite?: boolean;
	/** The fetch implementation; defaults to the global, injectable for tests. */
	readonly fetchImpl?: typeof fetch;
}
```

`addToMedia(url, path, nonce, options = {})`:
- Body: `JSON.stringify({ path, ...(options.overwrite ? { overwrite: true } : {}) })`.
- Map: `response.status === 201` → `{ outcome: 'created', id }`; `=== 200` → `{ outcome: 'replaced', id }`; `=== 409` → `{ outcome: 'exists', id: <existing> }`; otherwise → `{ outcome: 'error', status }`; thrown → `{ outcome: 'error', status: 0 }`.
- For `created`/`replaced`, read the numeric `id` from the JSON body (`data.id`), treating a missing/non-numeric id as `{ outcome: 'error', status }` (same defensiveness as today).
- For `exists` (409), read the existing id from the body where Step 3 puts it. **Match the exact JSON shape WordPress emits for `WP_Error` data** (typically `body.data.id`) — confirm by logging the 409 body once in the integration run, or by reading how an existing error-body is asserted. If the id is absent/non-numeric, fall back to `{ outcome: 'error', status: 409 }` so a malformed 409 cannot crash the popover.

**Verify**: covered by the rewritten Jest tests in Step 7 and `npm run build` type-check.

### Step 6: Make the view single-click with an overwrite popover (`add-to-media-view.ts`)

Rewrite `wireAddToMedia()` and `runCopy()`:

- **Remove the confirm-gate machinery**: no `gates` WeakMap, no `confirmGate` import, no `ARMED_CLASS` arming on first click. A plain primary click on an add-to-media icon now calls `runCopy(icon, mediaUrl, nonce, { overwrite: false })` directly. Keep: the delegated single listener, `shouldInterceptClick`, `event.preventDefault()`, the `BUSY_CLASS` in-flight guard, and the `DONE_CLASS`/`DONE_DURATION` success flash.
- Read the overwrite-confirm copy from the wrapper dataset (mirrored in Step 8), with neutral English fallbacks, exactly like `trash-view.ts` reads its `ConfirmCopy`:
  - `data-kntnt-photo-drop-overwrite-prompt` → "This image is already in the Media Library. Overwrite it?"
  - `data-kntnt-photo-drop-overwrite-confirm` → "Overwrite"
  - `data-kntnt-photo-drop-overwrite-cancel` → "Cancel"
  - `data-kntnt-photo-drop-overwrite-label` → "Confirm overwrite"
- **`runCopy()` handles the outcome**:
  - `created` / `replaced` → flash `DONE_CLASS` for `DONE_DURATION` (existing behaviour).
  - `exists` → open an inline overwrite popover anchored to the icon (a local `openOverwriteConfirm()` modelled on `trash-view.ts`'s `openConfirm`, reusing the `kntnt-photo-drop-gallery__confirm` classes; affirmative button uses the base `__button` class, Cancel uses `__button--cancel`; focus the Cancel button; Escape/Cancel/click-away dismiss). On the **Overwrite** button: dismiss the popover and call `runCopy(icon, mediaUrl, nonce, { overwrite: true })`.
  - `error` → silently return the icon to plain (existing behaviour) so the visitor can retry.
- Track a single open overwrite popover per wrapper (like `trash-view.ts`'s `open`), so a click elsewhere or a new one dismisses the prior. A click inside the open popover is the popover's own business (its buttons own it).

Keep the module's doc comment honest: update the file-level TSDoc that currently describes the "two-click confirmGate" to describe the new single-click-with-duplicate-confirm flow (and update `@since` with a new line, e.g. `@since 0.15.0 Single click adds; a duplicate raises an overwrite confirm.`).

**Verify**: `npm run lint:js` clean; `npm run build` type-checks; behaviour proven by e2e is human-verified (see Test plan / human-verification).

### Step 7: Rewrite the Jest tests `add-to-media.test.ts`

Rewrite for the new contract:
- Remove the entire `confirmGate` describe block (the function no longer exists).
- Keep/adapt the request-shape test: assert the POST body is `{ path }` (no overwrite) and `{ path, overwrite: true }` when `addToMedia(..., { overwrite: true })`.
- Outcome tests using the `fakeFetch(status, body)` helper already in the file:
  - 201 `{ id: 42 }` → `{ outcome: 'created', id: 42 }`.
  - 200 `{ id: 42 }` → `{ outcome: 'replaced', id: 42 }`.
  - 409 with the existing-id body shape → `{ outcome: 'exists', id: <that id> }`.
  - 403 → `{ outcome: 'error', status: 403 }`.
  - thrown fetch → `{ outcome: 'error', status: 0 }`.
  - malformed success body (201, no numeric id) → `{ outcome: 'error', status: 201 }` (the defensive branch).
- Update the file-level test-doc comment to describe the new contract.

**Verify (RED then GREEN)**: run `npm run test:js` after rewriting tests but before Step 5's implementation lands to see them fail; then after Step 5 they pass. (If you implement Step 5 first, confirm the suite is green and that the removed `confirmGate` tests are gone.)

### Step 8: Mirror the overwrite-confirm copy in `Render_Gallery.php`; update the integration suite

In `classes/Rendering/Render_Gallery.php`, extend the `if ( ! $is_preview && $add_to_media_visible ) { … }` block (after the media-url line) to mirror the overwrite popover copy, exactly like the trash block does:

```php
			// Mirror the overwrite confirm-popover copy onto the wrapper: a view-script
			// module cannot translate at runtime, so the duplicate prompt and its
			// Overwrite/Cancel labels are translated here and read by the add-to-media
			// view module (the same pattern the trash popover uses).
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-prompt']  =
				__( 'This image is already in the Media Library. Overwrite it?', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-confirm'] = __( 'Overwrite', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-cancel']  = __( 'Cancel', 'kntnt-photo-drop' );
			$wrapper_attrs['data-kntnt-photo-drop-overwrite-label']   = __( 'Confirm overwrite', 'kntnt-photo-drop' );
```

In `tests/Integration/RestAddToMediaTest.php`:
- **Update the existing "repeated confirm adds another copy (no dedup)" test** to the new behaviour: after a first 201, a second add of the same path **without** overwrite returns **409** and `attachment_count()` is unchanged (no new attachment).
- **Add an overwrite test**: after the first 201 (id N), an add of the same path **with** `overwrite: true` returns **200** with the same id N, and `attachment_count()` is unchanged (replaced in place, not added). If the integration helper `rest_add_to_media()` cannot send an `overwrite` body field, extend it (and its signature) minimally to accept one — check its definition in `tests/Integration/helpers.php` (around line 1330) and match its style.
- Update the file header comment that currently states "A repeated confirm adds another copy (no dedup)".

**Verify**: `composer phpcs`/`composer phpstan` for the PHP; `npm run test:integration` (Docker permitting) → updated + new tests pass.

### Step 9: Amend ADR-0015 and update the CHANGELOG

In `docs/adr/0015-gallery-overlays-and-rest-write-path.md`, add a dated amendment section at the end documenting the overturned decisions:

```markdown
## Amendment (2026-06-19): single-click add, de-dup, and replace-in-place overwrite

Two decisions above are superseded:

- **Add-to-media is now a single click**, not a two-click arm-then-fire confirm. The original two-click gate mirrored the destructive trash action, but adding to the Library is additive, not destructive, and the friction was not worth it. Safety against accidental *re-adds* is now provided by de-duplication (below) rather than by a blanket confirm on every add.
- **Add-to-media now de-duplicates instead of "each confirmed click adds another copy."** Each copy is stamped with its source identity — the collection slug and collection-relative path — as attachment post-meta (`_kntnt_photo_drop_collection`, `_kntnt_photo_drop_path`). A later add of the same image is detected (HTTP 409, carrying the existing attachment id) and the gallery raises an inline **Overwrite / Cancel** confirm (modelled on the trash popover). Confirming overwrite **replaces the existing attachment's file in place** — the attachment id is preserved, so any post already embedding it keeps working — regenerating sub-sizes at full resolution. The copy is still an independent copy, never a link (that rejection stands).
```

If Plan 007 has not landed, also include its full-resolution note here or coordinate so both ADR edits are present.

In `CHANGELOG.md` under `## [Unreleased]` → `### Changed`, add:

```markdown
- **Add-to-media is a single click and no longer duplicates.** Copying a collection image into the Media Library now takes one click instead of a two-click confirm; an image already copied is detected (by a stamped source identity) and, instead of silently adding a second copy, the gallery offers an inline **Overwrite** confirm that replaces the existing attachment's file in place (same attachment id). (ADR-0015 amendment)
```

**Verify**: `git diff docs/adr/0015-gallery-overlays-and-rest-write-path.md CHANGELOG.md` → shows only the additions described.

### Step 10: Build and run all gates

```
npm run build          # regenerates committed build/ — required for src/blocks changes
```

Then run the full gate set (Done criteria).

**Verify**: `git status` shows `build/` updated and staged; all gates green.

## Test plan

- **PHP unit** (`tests/Unit/Rest/MediaControllerTest.php`): fresh-add stamps meta + 201; duplicate-no-overwrite → 409 with existing id, nothing sideloaded; duplicate-overwrite → 200 same id via `replace()`; failed overwrite → 500. Harness extended with `update_post_meta`/`get_posts` stubs and `find_existing`/`replace` recording seams. Model after the existing recording-controller tests in the same file.
- **PHP integration** (`tests/Integration/RestAddToMediaTest.php`): the former no-dedup test rewritten to assert 409 + unchanged `attachment_count()`; a new overwrite test asserting 200 + same id + unchanged count. This is the load-bearing proof that meta stamping, the `get_posts` lookup, and real file replacement actually work end-to-end.
- **Block JS unit** (`src/blocks/gallery/add-to-media.test.ts`): rewritten for the four `outcome` variants, the `overwrite` body field, and the defensive malformed-success branch; `confirmGate` tests removed.
- **RED demonstrated**: server unit tests (Step 4) and Jest tests (Step 7) are written before their implementations and seen to fail for the right reason.
- Verification: `composer test`, `npm run test:js`, `npm run test:integration` (Docker permitting) all green.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0; new dedup/overwrite/stamp unit tests exist and pass
- [ ] `composer phpstan` exits 0
- [ ] `composer phpcs` exits 0
- [ ] `npm run test:js` exits 0; `add-to-media.test.ts` covers `created`/`replaced`/`exists`/`error` and the `overwrite` body; no `confirmGate` test remains
- [ ] `npm run lint:js` and `npm run lint:css` clean
- [ ] `npm run build` compiles with no type errors and `build/` is updated/committed
- [ ] `npm run test:integration` green with the updated no-dedup→409 test and the new overwrite→200 test — or committed and reported unrun if Docker is unavailable
- [ ] `grep -rn "confirmGate\|ConfirmGate" src/blocks/gallery/` returns no matches
- [ ] `grep -n "_kntnt_photo_drop_collection\|_kntnt_photo_drop_path" classes/Rest/Media_Controller.php` shows the stamping
- [ ] ADR-0015 carries the amendment section; `CHANGELOG.md` has the entry
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row for 008 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any "Current state" excerpt no longer matches the live code (drift since this plan was written) — especially the `add_to_media()` tail, the `Render_Gallery` add-to-media block, or the `add-to-media.ts` result type.
- The 409 `WP_Error` data does not surface in the REST JSON body in a readable shape (you cannot reliably read the existing id client-side). Then report it and consider returning the existing id via a normal `WP_REST_Response` with a `409` status and an explicit `{ id, code: 'already_in_media' }` body instead of `WP_Error` — but flag this design deviation for the maintainer rather than silently choosing.
- `wp_generate_attachment_metadata` / in-place file replacement behaves unexpectedly against the real Media Library (e.g. WordPress refuses to reuse the path) — report the actual behaviour; do not fall back to delete-and-re-add (the maintainer explicitly chose replace-in-place).
- A verification fails twice after a reasonable fix attempt.
- The work appears to require touching an out-of-scope file (especially `trash-view.ts` — if you feel the pull to share a popover module, STOP and note it as the deferred extraction; do not do it here).

## Maintenance notes

- **Deferred DRY extraction**: the overwrite popover duplicates `trash-view.ts`'s `openConfirm` structure. The intended end state is a shared `src/blocks/gallery/confirm-popover.ts` consumed by both `trash-view.ts` and `add-to-media-view.ts`. Deferred out of this plan to keep scope bounded and avoid regressing the trash tests; do it as a follow-up once this lands and is green.
- **Reviewer focus**:
  - The 409 body shape and the client's read of the existing id must agree — a mismatch silently degrades every duplicate into a generic error (no overwrite offered).
  - `replace()` must keep the attachment id stable and not orphan sub-size files; check the `delete_subsizes` + regenerate sequence against the real Library in the integration run.
  - The big-image threshold suppression must be present in `replace()` too (else overwrites re-introduce the `-scaled` master Plan 007 removed).
  - Confirm the single-click change does not break modified-click (cmd/ctrl/middle) behaviour — those must still fall through to the browser via `shouldInterceptClick`.
- **Source-meta as a new contract**: `_kntnt_photo_drop_collection` / `_kntnt_photo_drop_path` are now a durable link between a collection image and its Library copy. A future feature (e.g. "show which gallery images are already in the Library", or cleanup on collection delete) can build on this meta. Renaming or moving a collection would orphan the link (the relative path changes) — acceptable today, but note it if collection-rename is ever added.
- **Human-verification (cannot be automated)**: that one click *feels* right, the overwrite popover's copy/placement reads clearly, focus lands on Cancel, and Escape/click-away dismiss cleanly on both a grid thumbnail and the open lightbox. List these in the final report per `agents.d/definition-of-done.md`.
