# Gallery is recursive-flatten; no in-gallery folder navigation

The Gallery block targets a collection (slug) plus an optional **start path** (default the collection root), both set in the editor, and renders **all images under that path recursively as one flattened gallery**. There are no clickable folder tiles and no in-gallery drill-down navigation; to present folders separately, the editor places multiple Gallery blocks and uses WordPress's native page composition (headings, layout) between them. A photographer's folder structure is usually incidental filing, not a viewer-facing taxonomy, so building a file-browser inside a block would reinvent navigation against the server-rendered model.

## Considered Options

- **Navigate into folders** (folder tiles, breadcrumbs, drill-down): rejected — reinvents navigation, and would require a *visitor-controlled* path query parameter, which is an attacker-controlled path-traversal surface on every request.
- **One block per folder, subfolders hidden:** rejected as the *only* option, but available as a per-block "this folder only" toggle alongside the recursive default.
- **Wrapper + InnerBlocks "image template"** (Query-Loop pattern): genuinely attractive and the likely v2 direction, but rejected for v1 — our items are filesystem images, not posts, so it needs custom block context/bindings and a repeated-SSR engine on maturing APIs, it roughly triples block complexity, and dimension-aware justified layout fights arbitrary per-card templates.

## Consequences

Because the start path is an **editor-set attribute, not a visitor query parameter**, the path-traversal attack surface disappears — the path is validated once against the collection root, not per request. Flattened ordering is by **full relative path** (natural sort, ascending/descending) so each folder's images stay contiguous. Folder context, otherwise invisible, can be restored per-image by the optional **path/breadcrumb caption** (the alternative to folder section headings, which were rejected). Layout delegates to core block supports where possible: mode **A (uniform grid)** uses core's Grid layout (`minimumColumnWidth` default 320px, `blockGap` default 12px) plus bespoke aspect-ratio/fit; mode **B (justified rows)** is bespoke (per-image `flex-grow`/`flex-basis` from stored dimensions, target row height default 240px). A dangling collection reference renders nothing for the public and a notice only for a logged-in editor.
