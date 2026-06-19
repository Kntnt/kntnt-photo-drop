# Plan 009 — Stop Firefox opening the image in a new tab on download

**Status:** TODO
**Priority:** P1 (maintainer-reported functional bug)
**Effort:** S
**Risk of the fix:** Low — one localized change in a single function, covered by existing + new Jest tests.
**Depends on:** — (independent of 001–008)
**Planned against commit:** `2cf63ed`
**Origin:** Maintainer bug report (2026-06-19), not the code-quality audit that produced 001–006.

---

## 1. The problem, in the maintainer's words

> "How images are downloaded when the download overlay is on. As it is now, the image **opens in a new tab at the same time as it downloads**. I want it to only download, without a new tab opening."

The gallery's **download overlay** (ADR-0015) puts a download icon on each image (and, when configured, on the lightbox). Clicking that icon is supposed to save the image as a file and nothing else. In **Firefox** it does save the file **but also opens the image in a new tab**. Chromium/Safari do not show this.

## 2. Root cause (confirmed, not guessed)

The download is performed by `saveFile()` in `src/blocks/gallery/save-file.ts`. It `fetch()`es the image, wraps the bytes in a `Blob`, creates an object URL, and clicks a temporary `<a download>` at that `blob:` URL. This same function backs **both** the grid icon (`view.ts` → `wireIconDownloads`) and the lightbox icon (`lightbox.ts` download handler), so fixing it once fixes both surfaces.

The maintainer captured a HAR while reproducing in **Firefox 152.0.1** on `https://test.ddev.site`. The image request shows:

- `status: 200`, `content-type: image/webp`
- `Sec-Fetch-Mode: cors`, `Sec-Fetch-Site: same-origin`
- ~6.8 MB body (the main rendition)

So the **`fetch` succeeds** and the **blob path runs** — the fallback path (fetch-failure → `<a download>` at the remote URL) is **not** involved. There is **no** second document-navigation entry in the HAR, which means Firefox is opening the **`blob:` URL itself** in a tab.

This is a long-standing, documented Firefox behavior: for a `blob:` URL whose MIME type Firefox can **render inline** (PDFs, and images such as **WebP**), Firefox opens the blob in a tab **even when the `download` attribute is set**, while *also* honoring `download` and saving the file — i.e. exactly "downloads **and** opens a new tab." The fetched blob inherits `type: "image/webp"` from the response's `content-type`, so Firefox renders it.

References:
- Bugzilla 1766420 — files not downloaded when an anchor has `download` and a `blob:` URL (MIME-dependent).
- Bugzilla 1756980 — `download`/`Content-Disposition: attachment` opening in a tab rather than downloading.

**The fix:** before creating the object URL, re-wrap the fetched bytes in a `Blob` with a **non-renderable** MIME type (`application/octet-stream`). Firefox cannot render `application/octet-stream` inline, so it must download it; the `download` attribute still supplies the `.webp` filename, so the saved file is unchanged. This is the same technique FileSaver.js uses. It is a pure improvement to the blob path and changes no architecture.

## 3. Why this contradicts no ADR

`save-file.ts`'s own file docblock already states the goal: a programmatic blob download "no environment could turn into a new tab." That promise was simply not fully met on Firefox for renderable image types. This change **fulfils** the documented contract; it does not redesign anything. ADR-0015 (the overlay write-path) and ADR-0013 (the rendition model) are untouched. No ADR amendment is required.

## 4. Exact change

**File:** `src/blocks/gallery/save-file.ts`
**Function:** `saveFile()` — the success branch of the `try`.

### Current code (lines 168–192 at commit `2cf63ed`)

```ts
export async function saveFile( url: string ): Promise< void > {
	try {
		// Fetch the image into a Blob; a non-OK response is a failure.
		const response = await fetch( url );
		if ( ! response.ok ) {
			throw new Error(
				`Unexpected response status ${ response.status }.`
			);
		}
		const blob = await response.blob();

		// Click a temporary object-URL anchor to hand the Blob to the browser's
		// download machinery, then revoke the URL once the hand-off has settled.
		const objectUrl = URL.createObjectURL( blob );
		clickDownloadAnchor( objectUrl, filenameFromUrl( url ) );
		setTimeout( () => URL.revokeObjectURL( objectUrl ), REVOKE_DELAY );
	} catch {
		// Last resort: a same-tab <a download> at the remote URL still requests a
		// save without the gallery navigating its own tab. Where the browser
		// honours `download` this saves in place; for a cross-origin host without
		// CORS the browser decides (Safari may open a new tab), which the gallery
		// cannot override.
		clickDownloadAnchor( url, filenameFromUrl( url ) );
	}
}
```

### Required end-state

Re-wrap the blob with `application/octet-stream` before `createObjectURL`. Replace the success-branch paragraph (the two lines `const blob = await response.blob();` … through the `setTimeout(...)`) so it reads like:

```ts
		const blob = await response.blob();

		// Re-wrap the bytes with a non-renderable MIME type before handing them
		// to the download machinery. Firefox opens a `blob:` URL it can render
		// inline (PDFs, and images such as WebP) in a new tab even with the
		// `download` attribute set — so the fetched `image/webp` blob would both
		// download AND open a tab. `application/octet-stream` is not renderable,
		// so every browser downloads it; the `download` filename keeps the
		// `.webp` extension, so the saved file is unchanged. (Firefox bug 1766420.)
		const downloadBlob = new Blob( [ blob ], {
			type: 'application/octet-stream',
		} );

		// Click a temporary object-URL anchor to hand the Blob to the browser's
		// download machinery, then revoke the URL once the hand-off has settled.
		const objectUrl = URL.createObjectURL( downloadBlob );
		clickDownloadAnchor( objectUrl, filenameFromUrl( url ) );
		setTimeout( () => URL.revokeObjectURL( objectUrl ), REVOKE_DELAY );
```

**Do not** change the `catch` fallback, `clickDownloadAnchor`, `filenameFromUrl`, `shouldInterceptClick`, `REVOKE_DELAY`, or `FALLBACK_FILENAME`. The re-wrap is cheap: `new Blob([blob])` references the existing blob's data rather than copying the 6.8 MB.

### Docstring updates (same file)

Two docblocks currently claim the blob path downloads "in every browser … without any navigation the environment could redirect." That claim was the bug. Tighten them to name the real mechanism:

1. **File-level docblock (≈ lines 5–25):** in the paragraph that explains the blob hand-off, add one sentence noting the blob is re-typed to `application/octet-stream` so Firefox cannot render it inline (which would otherwise open a tab for renderable types like WebP). Keep the existing description of the fetch-failure fallback unchanged.

2. **`saveFile` docblock (≈ lines 149–167):** update the sentence describing the success path to mention the octet-stream re-typing as the reason it cannot become a tab. Add an `@since` line:
   ```
   * @since 0.11.0 Re-type the blob to application/octet-stream so Firefox cannot open it in a tab.
   ```
   Use **`0.11.0`** to match the existing unreleased `@since` tags already in this file (e.g. the `@since 0.11.0` on the fallback). Do **not** invent a new version; plan 004 owns any later global `@since` renumbering. If `package.json`'s version has been bumped past `0.11.0` by the time you run this, use the current unreleased version that the rest of `save-file.ts` uses instead, and note the choice in your report.

## 5. Test plan

**File:** `src/blocks/gallery/save-file.test.ts` (Jest via `wp-scripts test-unit-js`).

The existing success tests (lines 90–139) keep passing unchanged: `URL.createObjectURL` is mocked to return a fixed `objectUrl`, so `clickedHref` is still that URL regardless of the blob's type.

**Add one test** in the `describe( 'saveFile', … )` block that pins the regression — that the blob handed to `createObjectURL` is non-renderable:

```ts
	it( 'hands the download machinery a non-renderable blob so Firefox cannot open it in a tab', async () => {
		// Firefox opens a renderable blob (image/webp) in a tab even with the
		// download attribute; the save must re-type it to application/octet-stream.
		const blob = new Blob( [ 'webp-bytes' ], { type: 'image/webp' } );
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			blob: () => Promise.resolve( blob ),
		} as unknown as Response );

		let createdType = '';
		( URL.createObjectURL as jest.Mock ).mockImplementation(
			( b: Blob ) => {
				createdType = b.type;
				return objectUrl;
			}
		);

		await saveFile( 'https://example.test/photos/sunrise.jpg.webp' );

		expect( createdType ).toBe( 'application/octet-stream' );
	} );
```

Notes for the executor:
- `objectUrl` is the module-scoped constant already defined at the top of the `describe` block (line 72); reuse it, do not redeclare.
- `URL.createObjectURL` is already replaced with a `jest.fn()` in `beforeEach` (line 80). Casting it to `jest.Mock` to call `.mockImplementation` is consistent with the file's existing typing style (`as unknown as Response`).
- jsdom's `Blob` records `type` from the constructor options, so `b.type` is observable without a real browser.

**TDD (RED first):** write this test and run it **before** editing `save-file.ts`. It must FAIL (the current code passes the original `image/webp` blob, so `createdType` is `'image/webp'`). Capture that failing run — it is the proof the test constrains the behavior. Then apply the fix and watch it go green.

## 6. Verification gates (all must pass)

Run from the repo root:

```bash
npm run test:js        # Jest — the new test goes green, all existing save-file tests still pass
npm run lint:js        # ESLint via wp-scripts — clean
npm run build          # wp-scripts build — type-checks TS and recompiles build/blocks/gallery/view.js
```

Expected:
- `npm run test:js` exits 0; the new test "hands the download machinery a non-renderable blob…" passes; the four existing `saveFile` success/fallback tests still pass.
- `npm run lint:js` exits 0.
- `npm run build` exits 0 and **rewrites `build/blocks/gallery/view.js`** (the build output is committed to git in this repo — include the rebuilt file in the change, do not hand-edit it).

## 7. Scope boundaries

**In scope:**
- `src/blocks/gallery/save-file.ts` — the success-branch re-wrap + the two docblock tweaks.
- `src/blocks/gallery/save-file.test.ts` — one new test.
- `build/blocks/gallery/view.js` — regenerated by `npm run build`.

**Explicitly out of scope (do not touch):**
- The `catch` fallback in `saveFile` and any cross-origin/Safari behavior — unrelated to this bug, and the maintainer's HAR shows the success path, not the fallback.
- `view.ts`, `lightbox.ts`, `overlay-bands.ts`, the PHP renderers (`Render_Gallery.php`, `Overlay_Renderer.php`) — the wiring and markup are correct; both surfaces inherit the fix through `saveFile`.
- `event.stopPropagation()` / any anti-third-party-handler change — the maintainer confirmed a clean test site with no theme/plugin image handler, so propagation is not the cause.
- Any ADR, `design.md`, `CONTEXT.md`.
- `CHANGELOG.md` — leave changelog entries to the maintainer's release flow unless the other plans in this set establish a convention you are matching; if you do add one, follow the existing `CHANGELOG.md` format exactly.

## 8. Escape hatches — STOP and report instead of improvising

- **If `npm run test:js` shows the new test already passing before you edit `save-file.ts`**, the code has changed since this plan was written (commit `2cf63ed`). STOP, re-read `saveFile`, and report — do not assume the fix is unnecessary.
- **If, after the octet-stream change, manual Firefox testing still opens a tab** (see §9): do **not** start layering on `target=`, `iframe`, `stopPropagation`, or deferred `anchor.remove()` changes blindly. STOP and report that octet-stream alone did not suffice on this Firefox build, with the Firefox version — the next measure (e.g. deferring the anchor removal, per the secondary Firefox reports) is a separate decision.
- **If `package.json`'s version no longer matches the `@since` tags already in `save-file.ts`**, use the version the surrounding file uses and note it; do not block.

## 9. Human verification (cannot be automated here)

Jest with jsdom cannot reproduce Firefox's real blob-rendering behavior — it can only pin that the blob is re-typed (the mechanism), not that the tab stops opening. The maintainer (or executor, if a browser is available) should confirm on the reproducing setup:

1. In **Firefox** (the report was 152.0.1), on a gallery page with the download overlay on, click a download icon.
2. **Expected:** the image saves as a `.webp` file and **no new tab opens**.
3. Repeat with the download icon **in the lightbox** (open an image, click its download icon) — same expectation, since both share `saveFile`.
4. Sanity-check **Chromium and Safari** still download in place (no regression).

## 10. Maintenance note

`saveFile` is the single choke point for every gallery download (grid + lightbox), which is why a one-function change covers both. Anyone later "simplifying" the blob path by dropping the `application/octet-stream` re-wrap and passing the raw `image/webp` blob to `createObjectURL` will reintroduce this Firefox tab. The new test guards exactly that line; keep it. The fetch-failure `catch` fallback is intentionally left as a remote-URL `<a download>` (ADR/issue #59) and is a different code path with its own tests — don't fold the two together.
