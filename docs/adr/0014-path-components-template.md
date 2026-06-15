# Drop Zone placement is a mutable path-components template

The collection descriptor's immutable boolean `uploaderFolders` is replaced by a **mutable string template, `pathComponents`** (default `%year%/%month%/%day%/%uploader%`), that determines the sub-path under which **Drop Zone uploads** are placed. The template is expanded server-side at upload time and prepended as a prefix before each uploaded file's own source-relative path. This supersedes the uploader-folders half of ADR-0008; the hierarchy-preserving "a drop equals the folder picker" half of that ADR stands unchanged.

## What changed from ADR-0008

- **A template, not a boolean.** ADR-0008 prepended a single `<nicename>/` segment gated by an on/off boolean. The template generalises that to an arbitrary `/`-separated prefix with placeholders: `%year%`, `%month%`, `%day%` (the upload date in the **site's timezone**, not UTC — it is a human-facing folder) and `%uploader%` (the authenticated uploader's `user_nicename`, still derived server-side so it cannot be spoofed). What ADR-0008 called the "uploader folder" is now simply the segment the `%uploader%` placeholder produces.
- **Mutable, not fixed at establishment.** ADR-0008 froze the boolean because flipping it would "split one uploader's images across two layouts with no migration path." A template has no such failure mode: it affects only *future* uploads, past files stay where they were written, and the gallery recursive-flattens for display regardless — so changing it is safe, and it is editable on the admin Edit page.
- **Flat-at-root retired.** The old `--no-uploader-folders` / unchecked-box state landed Drop Zone uploads directly at the collection root. With "an empty field means the default template," no field value yields a flat placement; every Drop Zone upload now nests under at least the template. Flat-at-root was the collision-prone configuration ADR-0008 warned against (two photographers' `IMG_0001.webp` overwriting each other), so retiring it is a net safety gain.

## Considered Options

- **A block-level placement setting:** rejected for the same reason as ADR-0008 — placement is a property of *where images land*, which the collection owns; the Drop Zone is a select-only consumer, and two Drop Zones pointing at one collection must agree, which only the descriptor can guarantee.
- **Apply the template to CLI `image import` too:** rejected — `import` runs in a trusted context with no authenticated uploader, so `%uploader%` has no value. It continues to write to the literal target path it is given, exactly as under ADR-0008.

## Consequences

- **One security boundary.** The expanded path is confined by the existing `Path_Guard` (`realpath`-confined to the collection root) at upload — the sole guard, unchanged. At save the template is only *normalised* (split on `/`, strip leading/trailing separators, collapse empty segments, an empty result falling back to the default) and linted for one thing: `%` is **reserved**, so any `%` remaining after the four known placeholders are recognised is rejected at save. This prevents a mistyped `%moth%` from silently becoming a literal folder, without re-implementing path safety a second time.
- Placeholder values are safe by construction (dates are digits; the nicename is `sanitize_title`'d, with a user-ID fallback), so the upload-time guard is belt-and-suspenders rather than the primary defence.
