# Server re-enforces the contract; REST upload gated by capability + nonce

The Drop Zone's client-side Canvas optimisation is treated purely as a **bandwidth optimisation, never the security boundary**: the single REST write endpoint re-applies the output contract server-side (the same code path as `image import` — accept as-is if already conforming to avoid double compression, otherwise decode/downscale/re-encode to WebP), so "conforming by construction" holds even against a file POSTed directly to the API, bypassing the client. The endpoint requires **both** a valid `wp_rest` nonce **and** `current_user_can('upload_files')`, because the two defend different things and page-level protection cannot secure a separate API door.

## Considered Options

- **Trust the client optimisation / page-password the Drop Zone page:** rejected — the REST endpoint is a separate URL, reachable directly regardless of the page's protection; an attacker can POST arbitrary/oversized files to it.
- **Nonce only, no capability check:** rejected — a nonce is CSRF protection, not authorisation. On open-registration sites "logged in" includes self-registered Subscribers, who obtain a perfectly valid nonce; without `upload_files` they could write files. Nonce stops forgery; the capability stops the wrong people.
- **Bespoke plugin capabilities / role mutation:** rejected — reuse existing core caps (`upload_files` for upload, `manage_options` for the admin page), each overridable via a filter; no custom caps to register or clean up.

## Consequences

The endpoint is `POST /wp-json/kntnt-photo-drop/v1/collections/<slug>/images`, one file per request, carrying the file plus an attacker-controlled `relativePath` that is **hard-sanitised and `realpath`-confined** to the collection root. Responses are per-file (`stored | skipped | reencoded | rejected`) so one failure never aborts the batch. The upload handler writes only the main image and its thumbnail(s) and **never touches the index**, which self-heals via `dirMtime` on the next gallery view — so a 300-file batch causes no index write contention. No chunking is needed because files are already downscaled. As defence in depth, the Drop Zone block renders its UI and nonce only for users who hold the capability.

## Amendment — Drop Zone client moves from FilePond to a native drop surface (does not change this decision)

The Drop Zone's client uploader was reworked from a FilePond widget into an `InnerBlocks` wrapper whose whole inner-block surface is a native drag-drop + click-to-browse zone (issue #31). **This is purely a client-side change and leaves every security property of this ADR untouched.** The render handler (`Render_Drop_Zone`) still renders nothing — and emits no `wp_rest` nonce — for a user without the upload capability, and emits both UI and nonce only for a capable user; the endpoint still requires both a valid `wp_rest` nonce **and** `current_user_can('upload_files')` and still re-applies the contract server-side on every file. "One file per request" is unchanged; the per-file `relativePath` is now carried by the block's own intake rather than as FilePond item metadata, and is sanitised and confined identically. The capability/nonce gate and nonce privacy described above are **explicitly unchanged** — only the intake/queue/widget layer was replaced.
