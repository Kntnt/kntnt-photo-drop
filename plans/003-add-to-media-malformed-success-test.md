# Plan 003: Cover the untested malformed-success branch of `addToMedia`

> **Executor instructions**: Follow this plan step by step. Write the test,
> demonstrate RED, then GREEN. Run every verification command. If a STOP
> condition occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 2cf63ed..HEAD -- src/blocks/gallery/add-to-media.ts src/blocks/gallery/add-to-media.test.ts`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code first; on a mismatch, STOP.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `2cf63ed`, 2026-06-19

## Why this matters

`addToMedia()` defends against a malformed success response: a 2xx whose JSON body lacks a numeric `id` is treated as a failure, not a phantom attachment id (`add-to-media.ts:74-79`). That guard is real and correct, but the test suite never exercises its false branch — every success test sends a well-formed `{ id: <number> }`. If a future refactor loosened the check (say, to `data.id != null`), a server emitting `{ id: "42" }` or `{}` on success would silently produce a broken `ok: true` result and no test would notice. This adds the two-line regression guard. (It is the single genuine TS-helper coverage gap found in the audit — the rest of the gallery/drop-zone pure helpers are already thoroughly tested.)

## Current state

`src/blocks/gallery/add-to-media.ts:53-84` — `addToMedia(url, path, nonce, fetchImpl)`. The untested branch (verbatim):

```ts
// A created attachment carries a numeric id; a malformed success body is
// treated as a failure rather than a phantom id.
const data = ( await response.json() ) as { id?: unknown };
return typeof data.id === 'number'
	? { ok: true, id: data.id }
	: { ok: false, status: response.status };
```

So a 2xx response whose parsed body has `id` missing or non-numeric returns `{ ok: false, status: <the 2xx code> }`.

`src/blocks/gallery/add-to-media.test.ts` — the co-located Jest test. It uses a `fakeFetch( status, jsonBody )` helper that returns `{ fetch }` (an injectable `fetchImpl`). Existing cases to mirror (verbatim shape):

```ts
it( 'reports the created attachment id on a 201', async () => {
	const { fetch } = fakeFetch( 201, { id: 42 } );
	const result = await addToMedia( '/media', 'a.webp', 'n', fetch );
	expect( result.ok ).toBe( true );
	if ( result.ok ) {
		expect( result.id ).toBe( 42 );
	}
} );

it( 'reports a non-OK status as a failure carrying that status', async () => {
	const { fetch } = fakeFetch( 403, { code: 'forbidden' } );
	const result = await addToMedia( '/media', 'a.webp', 'n', fetch );
	expect( result.ok ).toBe( false );
	if ( ! result.ok ) {
		expect( result.status ).toBe( 403 );
	}
} );
```

Conventions (`docs/coding-standards.md`, TypeScript + WordPress-blocks sections): block tests run on `@wordpress/scripts`' Jest — do not introduce Vitest. Tab indent, `it( 'behavior', async () => { ... } )`, the `result.ok` type-guard narrowing pattern shown above (the result is a discriminated union, so narrow with `if ( ! result.ok )` before reading `.status`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Run this test file | `npm run test:js -- add-to-media` | green, including new cases |
| Full block JS unit suite | `npm run test:js` | Jest green |
| Type-check + build | `npm run build` | compiles, no type errors |
| Lint | `npm run lint:js` | exit 0 |

## Scope

**In scope** (modify only this):
- `src/blocks/gallery/add-to-media.test.ts` (add test cases)

**Out of scope** (do NOT touch):
- `src/blocks/gallery/add-to-media.ts` — the production guard is already correct; this adds coverage only. If you think it is wrong, STOP and report.
- Any other file. Do not rebuild `build/` by hand (the build step regenerates it; committing `build/` is a separate concern outside this plan).

## Git workflow

- Branch: `advisor/003-add-to-media-malformed-success-test`
- Commit style: conventional commits (e.g. `test(gallery): cover addToMedia malformed-success rejection`).
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add the failing tests (RED)

Add two `it(...)` cases to the same `describe` block that holds `'reports the created attachment id on a 201'` in `src/blocks/gallery/add-to-media.test.ts`:

1. `it( 'treats a 2xx success with a missing id as a failure carrying that status', ... )` — `fakeFetch( 200, {} )`, assert `result.ok === false` and (narrowed) `result.status === 200`.
2. `it( 'treats a 2xx success with a non-numeric id as a failure', ... )` — `fakeFetch( 201, { id: '42' } )`, assert `result.ok === false` and (narrowed) `result.status === 201`.

Use the exact `result.ok` type-guard narrowing pattern from the existing tests.

**Verify (RED)**: temporarily edit `add-to-media.ts` line 77 from `typeof data.id === 'number'` to `data.id != null` and run `npm run test:js -- add-to-media` → the two new tests FAIL (the `{ id: '42' }` case now wrongly returns `ok: true`). This proves they exercise the guard. **Revert the production edit immediately** (`git checkout src/blocks/gallery/add-to-media.ts`).

### Step 2: Confirm GREEN

With production code unchanged, run the suite.

**Verify (GREEN)**: `npm run test:js -- add-to-media` → all pass. Then `npm run test:js` → whole block-JS suite green.

## Test plan

- **New tests**: two `it(...)` cases in `add-to-media.test.ts` covering a 2xx body with (a) missing `id` and (b) string `id`, both expected to return `{ ok: false, status: <2xx code> }`.
- **Pattern to mirror**: the existing `'reports the created attachment id on a 201'` and `'reports a non-OK status…'` cases.
- **Verification**: `npm run test:js` green with two new passing tests.

## Done criteria

ALL must hold:

- [ ] `npm run test:js` exits 0 with the two new tests passing
- [ ] The RED was demonstrated (the temporary `data.id != null` edit made them fail) and the production file was reverted — `git status` shows only `add-to-media.test.ts` changed
- [ ] `npm run lint:js` exits 0
- [ ] `plans/README.md` status row for 003 updated

## STOP conditions

Stop and report (do not improvise) if:

- `add-to-media.ts` differs from the "Current state" excerpt (drift since `2cf63ed`).
- The new tests do not go RED under the temporary `data.id != null` edit — they are not reaching the branch; fix once, and if still not RED, STOP.
- Making them pass appears to require editing `add-to-media.ts`.
- `git status` shows `add-to-media.ts` still modified at the end (you forgot to revert the RED edit).

## Maintenance notes

- These guard the "no phantom attachment id" contract. If the success-body shape ever gains fields, keep the `typeof … === 'number'` check (or update both the guard and these tests together).
- A reviewer should confirm the production file is unmodified at merge and that the RED was real.
