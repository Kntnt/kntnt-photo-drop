# Blocks and admin page

Per-block specification: `block.json` attribute schema, editor UI, and render output for the two blocks, plus the create/update/delete UX of the collection-lifecycle admin page. This pins the attribute shapes; the rationale for the design lives in [`design.md`](design.md) and the ADRs. Use the [`CONTEXT.md`](../CONTEXT.md) vocabulary throughout.

Both blocks are **dynamic** (server-rendered via `render.php` → an autoloaded `Render_*` class) and **select-only consumers** of collections: neither can create or reconfigure one. Both register under the `kntnt` block category. Pre-1.0, there are no `deprecated` entries and no attribute migrations.

## Shared concepts

- A block points at a collection by its **slug** (the directory name under the uploads root). The slug is the only durable reference; a renamed (`mv`'d) collection dangles the block, which is expected.
- The collection list in every selector (both blocks' inspectors and the admin page) comes from the **discovery scan** — any directory under the uploads root containing a `collection.json`. There is no registry.
- A dangling collection reference renders **nothing** for the public and an **editor-only notice** for a logged-in user.

---

## Photo Drop Zone — `kntnt-photo-drop/drop-zone`

A capability-gated front-end uploader bound to one existing collection. It selects a collection and uploads into it; it never establishes or reconfigures one, so its inspector has nothing that could conflict with the contract. The block is an **`InnerBlocks` wrapper**: its *editable appearance* is its inner blocks (a centred dashed group by default), and the whole inner-block surface becomes a **native drag-drop + click-to-browse** zone at render. There is no FilePond.

### `block.json`

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "kntnt-photo-drop/drop-zone",
	"title": "Photo Drop Zone",
	"category": "kntnt",
	"icon": "cloud-upload",
	"description": "A capability-gated front-end uploader that optimises images in the browser and uploads them into a chosen collection.",
	"keywords": [ "photo", "upload", "drop", "image", "collection" ],
	"textdomain": "kntnt-photo-drop",
	"editorScript": "file:./index.tsx",
	"viewScriptModule": "file:./view.ts",
	"style": "file:./style-index.css",
	"editorStyle": "file:./index.css",
	"viewStyle": "file:./view.css",
	"render": "file:./render.php",
	"supports": {
		"anchor": true,
		"html": false,
		"spacing": { "margin": true, "padding": true }
	},
	"attributes": {
		"collection": {
			"type": "string",
			"default": ""
		}
	}
}
```

### Attributes

| Attribute | Type | Default | Meaning |
|---|---|---|---|
| `collection` | string | `""` | The slug of the collection to upload into. The **only** persisted attribute. Everything about the contract is read live from the descriptor, never stored on the block. |

The collection's output contract (max width, quality, WebP, thumbnail width) is **not** a block attribute — it is read from `collection.json` at edit time (for the read-only inspector display) and at render time (to configure the client-side Canvas optimisation). This is what keeps the Drop Zone unable to conflict with the contract.

This is a **dynamic block with inner blocks**: `save` returns `<InnerBlocks.Content />` (the inner-block markup is serialised into `post_content`), and `render.php` consumes that markup as `$content` — gating it by capability, replacing the collection placeholder, and wrapping it in the native drop surface. The block carries the `cloud-upload` icon in the inserter and list view.

### Editor UI (`edit.tsx`)

- **Inner blocks (canvas)** — the block's editable appearance is an `InnerBlocks` region. On insertion it is seeded with a default (unlocked) template: a centred, constrained `core/group` with a dashed border (`#808080`) and background `#fafaff`, holding a level-4 `core/heading` "Photo Drop Zone", a `core/paragraph` `Uploads go into the "{kntnt-drop-zone-collection}" collection.`, and a smaller `core/paragraph` "The live uploader appears on the published page for users who can upload files." The template is **not locked**, so a site builder can rewrite the surface freely; the literal token `{kntnt-drop-zone-collection}` is a default placeholder, not a contract.
- **Inspector → Collection** — a `SelectControl` listing discovered collections by display name (value = slug). Choosing one sets `collection`. An empty or dangling `collection` shows an inline notice in the panel (prompting selection or noting the collection is gone).
- **Inspector → Output contract (read-only)** — a static display of the selected collection's `maxWidth` (or "No limit"), `quality`, format (always **WebP**), and `thumbnailWidths`. No fields to edit; a hint links to the admin page for lifecycle changes, set off by vertical space below the contract list.

### Render output (`render.php` → `Render_Drop_Zone`)

- Renders **only** for users who hold the upload capability (`upload_files`, filter `kntnt_photo_drop_upload_capability`). For anyone else, the block renders nothing — and crucially **no `wp_rest` nonce** is emitted (defence in depth; see [ADR-0006](adr/0006-server-enforced-contract-rest-upload.md)).
- For a capable user, it replaces the literal `{kntnt-drop-zone-collection}` token in the inner-block markup with the collection's display name (a removed or edited token is simply not replaced), then wraps the result so the **whole inner-block surface** is a native drag-drop + click-to-browse zone, alongside a **"Select folder"** control (`webkitdirectory`), wired through the Interactivity API view module. The descriptor's contract is read server-side and passed to the client so the Canvas downscale + the `canvas.toBlob(…, 'image/webp', quality)` encode are configured from it.
- Each file is uploaded one-per-request to `POST /wp-json/kntnt-photo-drop/v1/collections/<slug>/images` (multipart: the file + its `relativePath`), carrying the nonce. `webkitRelativePath` is preserved per file so the server can recreate sub-directories (path hard-sanitised and `realpath`-confined). A folder dragged onto the zone is detected (`webkitGetAsEntry().isDirectory`) and **warned** about, offering to continue flat. The view module keeps the per-file status list, the live summary, real XHR upload progress, and the one-shot nonce refresh on expiry.
- The client optimisation is a bandwidth optimisation only; the server re-enforces the contract on every file.

---

## Photo Drop Gallery — `kntnt-photo-drop/gallery`

A public, server-rendered gallery of one collection — all images under a start path rendered as one flattened set (no in-gallery folder navigation; see [ADR-0005](adr/0005-recursive-flatten-gallery-no-navigation.md)) — with an Interactivity-API lightbox.

### `block.json`

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "kntnt-photo-drop/gallery",
	"title": "Photo Drop Gallery",
	"category": "kntnt",
	"icon": "format-gallery",
	"description": "A public, server-rendered gallery of a collection, with a lightbox.",
	"keywords": [ "photo", "gallery", "images", "lightbox", "collection" ],
	"textdomain": "kntnt-photo-drop",
	"editorScript": "file:./index.tsx",
	"viewScriptModule": "file:./view.ts",
	"style": "file:./style-index.css",
	"editorStyle": "file:./index.css",
	"viewStyle": "file:./view.css",
	"render": "file:./render.php",
	"supports": {
		"anchor": true,
		"html": false,
		"align": [ "wide", "full" ],
		"color": { "background": true, "text": true, "gradients": true, "__experimentalSkipSerialization": true },
		"typography": {
			"fontSize": true,
			"lineHeight": true,
			"__experimentalFontFamily": true,
			"__experimentalSkipSerialization": true
		},
		"__experimentalBorder": {
			"color": true,
			"radius": true,
			"style": true,
			"width": true,
			"__experimentalSkipSerialization": true,
			"__experimentalDefaultControls": { "color": true, "radius": true, "style": true, "width": true }
		},
		"shadow": { "__experimentalSkipSerialization": true },
		"spacing": { "margin": true, "padding": true, "blockGap": true }
	},
	"attributes": {
		"collection":                   { "type": "string",  "default": "" },
		"startPath":                    { "type": "string",  "default": "" },
		"recursive":                    { "type": "boolean", "default": true },
		"order":                        { "type": "string",  "default": "asc" },
		"layout":                       { "type": "string",  "default": "grid" },
		"minimumColumnWidth":           { "type": "string",  "default": "320px" },
		"imageFit":                     { "type": "string",  "default": "cover" },
		"aspectRatio":                  { "type": "string",  "default": "" },
		"targetRowHeight":              { "type": "number",  "default": 240 },
		"lightbox":                     { "type": "boolean", "default": true },
		"download":                     { "type": "boolean", "default": false },
		"downloadIconSize":             { "type": "string",  "default": "2rem" },
		"downloadIconBackground":       { "type": "string",  "default": "#00000080" },
		"downloadIconForeground":       { "type": "string",  "default": "#ffffff" },
		"downloadIconAnchor":           { "type": "string",  "default": "top-left" },
		"captionContent":               { "type": "string",  "default": "none" },
		"captionHumanize":              { "type": "boolean", "default": true },
		"captionIncludeCollectionName": { "type": "boolean", "default": false },
		"captionSeparator":             { "type": "string",  "default": "›" },
		"captionAnchor":                { "type": "string",  "default": "bottom-left" },
		"isEditorPreview":              { "type": "boolean", "default": false }
	}
}
```

The colour, typography, border, and shadow supports all carry `__experimentalSkipSerialization` so WordPress does **not** write them onto the block wrapper. `Render_Gallery` projects them onto the right sub-element instead — colour and typography onto each `<figcaption>`, border and shadow onto each `<img>` — through the core style engine (`wp_style_engine_get_styles`), the same skip-serialization pattern core's Image block uses. The `blockGap` spacing support replaces the old custom gap attribute and is read server-side into both layout containers.

### Attributes

| Attribute | Type | Default | Allowed values / notes |
|---|---|---|---|
| `collection` | string | `""` | Collection slug. |
| `startPath` | string | `""` | Editor-set start path relative to the collection root; `""` = root. **Never a visitor query parameter** — validated once against the root, so there is no per-request path-traversal surface ([ADR-0005](adr/0005-recursive-flatten-gallery-no-navigation.md)). |
| `recursive` | boolean | `true` | `true` = all images under `startPath` recursively, flattened; `false` = this folder only. |
| `order` | string | `"asc"` | `"asc"` \| `"desc"`. Natural sort by full relative path (keeps each folder's images contiguous). Not visitor-controllable. |
| `layout` | string | `"grid"` | `"grid"` = mode A (uniform grid, core Grid layout); `"justified"` = mode B (bespoke justified rows). |
| `minimumColumnWidth` | string | `"320px"` | Mode A only. Maps to core Grid's `minimumColumnWidth`. |
| `imageFit` | string | `"cover"` | Mode A only. `"cover"` \| `"contain"`. |
| `aspectRatio` | string | `""` | Mode A only. `""` = use each image's stored ratio (zero layout shift); otherwise a CSS ratio such as `"1"`, `"4/3"`, `"16/9"`. |
| `targetRowHeight` | number | `240` | Mode B only. Target row height in px; per-image `flex-grow`/`flex-basis` are derived from stored dimensions, last row left-aligned. |
| `lightbox` | boolean | `true` | When on, clicking an image opens the Interactivity-API lightbox; the no-JS `<a href="full.webp">` fallback is present regardless ([ADR-0007](adr/0007-lightbox-via-interactivity-api.md)). |
| `download` | boolean | `false` | When on, clicking an image downloads the full main image. Combined with `lightbox`, drives the click matrix below: the download icon overlays each thumbnail (lightbox off) or appears only inside the lightbox (lightbox on). |
| `downloadIconSize` | string | `"2rem"` | The overlay download icon's size (a CSS length). |
| `downloadIconBackground` | string | `"#00000080"` | The overlay download icon's background colour (a custom colour — the block-support colour panel is claimed by the caption). |
| `downloadIconForeground` | string | `"#ffffff"` | The overlay download icon's foreground (glyph) colour. |
| `downloadIconAnchor` | string | `"top-left"` | The nine-point anchor that places the overlay download icon inside the image (same vocabulary as `captionAnchor`). |
| `captionContent` | string | `"none"` | `"none"` \| `"filename"` \| `"path"` (path-breadcrumb). |
| `captionHumanize` | boolean | `true` | Humanise filenames/segments (strip extension, replace separators with spaces). |
| `captionIncludeCollectionName` | boolean | `false` | Prefix the breadcrumb with the collection's display name. |
| `captionSeparator` | string | `"›"` | Breadcrumb separator (free text). |
| `captionAnchor` | string | `"bottom-left"` | The nine-point anchor of the always-overlay caption: `top-left`, `top-center`, `top-right`, `middle-left`, `middle-center`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right`. |
| `isEditorPreview` | boolean | `false` | **Render-time-only.** The editor passes `true` on the `ServerSideRender` `attributes` prop to request the capped, lightbox-suppressed preview; it is never written through `setAttributes`, so — left at its `false` default — it is never serialised into `post_content` and cannot reach a frontend render. It is declared in `block.json` only because the REST block-renderer endpoint (`additionalProperties: false`) would otherwise strip an undeclared attribute before the preview reached the render callback. |

The caption is **always an overlay inside the image** — there is no position attribute. Its colour and font, and each image's border and shadow, are not bespoke attributes either: they come from the **Colour**, **Typography**, **Border**, and **Shadow** block-support panels (`__experimentalSkipSerialization`), stored under the standard `style` subtree / preset shorthand attributes (`textColor`, `backgroundColor`, `gradient`, `fontSize`, `fontFamily`, `borderColor`, `shadow`) and the gap under the **Block spacing** support (`style.spacing.blockGap`). Because Canvas re-encoding strips all EXIF/IPTC at ingestion, there is no embedded caption or capture date — caption *content* is derived from the filename/path only. The same Caption settings drive the lightbox caption when the lightbox is on and the content is not `"none"`: the lightbox image carries the identical overlay `<figcaption>` (same anchor and colour/typography projection) as the gallery figures.

The download icon's colour controls are **bespoke attributes**, not block supports, because the block-support **Colour** panel is already projected onto the caption — a single colour panel cannot serve two unrelated sub-elements. The icon's glyph is a CSS-masked inline SVG (no icon font, no extra request), painted in `downloadIconForeground` on a `downloadIconBackground` circle of `downloadIconSize`, placed by `downloadIconAnchor`.

#### Click matrix

The `lightbox` and `download` toggles together fix what a click on a gallery image does and where the download icon lives. The download always targets the **full-resolution main image**, and the no-JS `<a href="main.webp">` fallback navigates to that image regardless ([ADR-0007](adr/0007-lightbox-via-interactivity-api.md)):

| `lightbox` | `download` | Gallery thumbnail | In the lightbox |
|---|---|---|---|
| off | off | A click does nothing (the view module suppresses the otherwise-navigating anchor; without JS it navigates to the main image). No download icon. | — (no lightbox) |
| **on** | off | A click opens the lightbox. No download icon. | The enlarged image has no download affordance — clicking it does nothing. |
| off | **on** | A download icon overlays each image; the anchor carries the `download` attribute, so a click saves the main image. | — (no lightbox) |
| **on** | **on** | **No** download icon on the thumbnail; a click opens the lightbox. | The download icon appears **only here**; the enlarged image is wrapped in a `download` anchor, so clicking it saves the main image. |

Modified clicks (Cmd/Ctrl/Shift/Alt, non-primary button) are always left to the browser. The per-slide download URL and caption text are mirrored onto each gallery anchor as `data-kntnt-photo-drop-full` and `data-kntnt-photo-drop-caption`, so the lightbox shows the right image and caption without re-reading the page.

### Editor UI (`edit.tsx`)

Inspector panels:

- **Collection** — `SelectControl` of discovered collections (value = slug) + a `startPath` control (chooses a sub-folder of the selected collection) + a **"This folder only"** toggle (inverse of `recursive`).
- **Ordering** — ascending/descending `order`.
- **Layout** — mode toggle A/B. Mode A reveals `minimumColumnWidth`, `imageFit`, `aspectRatio`; mode B reveals `targetRowHeight`. The inter-item gap is the core **Block spacing** control (Dimensions); the panel carries a hint pointing there rather than a bespoke gap field. Gallery width/alignment is the core block toolbar.
- **Captions** — `captionContent`, then when not "none": `captionHumanize`, (for "path") `captionIncludeCollectionName` and `captionSeparator`, and the nine-point **Anchor** (`captionAnchor`, always shown for any non-"none" content). The caption's colour and font come from the core **Colour** and **Typography** panels; the per-image border and shadow from the core **Border** panel — all applied to the right sub-element via skip-serialization, not to bespoke caption attributes.
- **Click behaviour** — the **Lightbox** (`lightbox`, default on) and **Download** (`download`, default off) toggles, which together drive the click matrix above. When Download is on, the panel reveals the download-icon styling: **Download icon size** (`downloadIconSize`), **Download icon anchor** (`downloadIconAnchor`, the same nine-point select as the caption), and **Download icon colours** (`downloadIconBackground`, `downloadIconForeground`, via a `PanelColorSettings` with custom colours).

The editor preview uses `ServerSideRender` so the editor matches the frontend, but in **editor-preview mode**: it sends the render-time-only `isEditorPreview` flag, so the server caps the canvas at the first **6** figures and emits no lightbox markup (clicks stay inert in the editor — a collection of thousands never floods the canvas). The block carries no editor-only preview heading: the canvas shows only images. When there is nothing to render — no collection chosen, a dangling slug, an empty collection, or while the preview loads — the editor shows a grid of **6 grey placeholders** in place of the gallery, rather than a bare notice.

### Render output (`render.php` → `Render_Gallery`)

- Resolves the collection, validates `startPath` against the root once, walks the tree (recursive or single-folder), reading each folder's mtime-validated `index.json` (self-heals if stale; see [ADR-0003](adr/0003-on-disk-collection-layout.md)), and orders by full relative path (natural sort, `order`).
- Emits a `<figure>` per image with `loading="lazy"`, stored `width`/`height` (or `aspect-ratio`) for zero layout shift, and a `srcset` listing each thumbnail width plus the main — the main is always a candidate, so the browser never upscales a thumbnail. The `sizes` hint is layout-aware (grid: derived from `minimumColumnWidth`; justified: per-image from `targetRowHeight` × aspect ratio; both prefixed with `auto` for browsers that support lazy auto-sizes) so a tile never downloads the full-size main. Each anchor also carries `data-kntnt-photo-drop-srcset` so the lightbox image gets the same responsive candidates.
- Each `<figure>` is the sizing box and the positioning context for its caption: mode A fixes its `aspect-ratio` inline, mode B its `height` inline, and the `<a>` plus `<img>` fill that box absolutely so nothing inside it collapses to zero (the fix for the clipped-under-caption and alt-text-only-justified-row bugs). The gap for both layouts is read from the `blockGap` spacing support.
- Mode A uses core's Grid layout (`minimumColumnWidth`, the support `blockGap`) plus the bespoke `aspect-ratio`/`imageFit`; mode B emits bespoke justified rows (`flex-grow`/`flex-basis` from stored dimensions, `targetRowHeight`, the support `blockGap`, last row left-aligned). The server computes mode B's last-row flags against an assumed container width as the no-JS/first-paint fallback; the view module re-flags the actual last row on init and on resize (so mode B emits `data-wp-init` even when the lightbox is off).
- The caption is always an anchored overlay `<figcaption>` (per `captionContent`/`captionAnchor`), carrying the colour/typography block-support declarations and preset classnames; each `<img>` carries the border/shadow block-support declarations. Both come from the style engine, projected server-side onto the sub-element rather than the wrapper. The caption text is also mirrored onto the anchor as `data-kntnt-photo-drop-caption` so the lightbox can show the same caption.
- The click matrix (above) is driven by two wrapper flags the view module reads — `data-kntnt-photo-drop-lightbox` and `data-kntnt-photo-drop-download`. The download icon is an `aria-hidden` `<span>` overlay placed by `downloadIconAnchor`, styled from three inline custom properties (`--kntnt-photo-drop-download-size`/`-bg`/`-fg`); it overlays the thumbnail only in the download-on / lightbox-off cell, and otherwise (download on, lightbox on) appears inside the lightbox. When `download` is on, the gallery anchor (lightbox off) or the lightbox image (lightbox on) carries the `download` attribute so a plain click saves the main image; with both off the view module suppresses the plain click so it does nothing.
- The lightbox is an Interactivity-API surface (open/close, prev/next, keyboard, swipe, debounced neighbour preload, focus trap, scroll lock, `aria`, loading/error states with a server-translated error element); each thumbnail is wrapped in `<a href="full.webp">` so a no-JS click navigates to the full image, and modified clicks (Cmd/Ctrl/Shift/Alt, non-primary button) are left to the browser. When `download` is on, the lightbox image is wrapped in a `download` anchor whose `href` the view module sets per slide, with the overlay icon beside it; when the caption content is not `"none"`, the lightbox figure carries the mirrored caption `<figcaption>` the view module fills per slide. The `data-wp-init` hook is bound on every frontend render — for the lightbox, the justified last-row correction, or the both-off click suppression. The gallery needs no REST — it is pure SSR plus the view module.
- **Editor-preview mode** (the render-time-only `isEditorPreview` attribute the editor's `ServerSideRender` sends): the walk is capped to the first **6** images and interactivity is suppressed (no lightbox overlay, no `data-wp-context`, the lightbox flag reads `false`, and no `data-wp-init`), so the canvas stays light and clicks are inert. The download icon may still appear on the figures (the download-on / lightbox-off cell) so the preview matches the published page. A dangling/empty collection in preview mode returns an empty string, which the edit component's `ServerSideRender` treats as its empty case and replaces with the grey placeholders. The flags live only on the preview request, so the frontend render is identical: no cap, lightbox/download as configured, full walk.

---

## Collection-lifecycle admin page

Collection **create / update / delete** lives on a dedicated admin page (and the CLI). Blocks are select-only consumers and never appear here. The page is gated by `manage_options` (filter `kntnt_photo_drop_manage_capability`). It is the GUI mirror of the `wp kntnt-photo-drop collection {create,update,delete}` commands ([ADR-0004](adr/0004-cli-import-is-consumer-grouped-subcommands.md)).

### List view

- A table of all discovered collections (the discovery scan), one row each: display name, slug, max width (or "No limit"), quality, format (**WebP**), thumbnail width(s), and image count. A collection copied in from another site appears automatically; a deleted directory disappears.
- Each row ends with always-visible **Edit** (name only) and **Delete** buttons in the rightmost column; Delete leads to the confirmation step. A **Create collection** button opens the create form.

### Create

- Fields: **Slug** (required; becomes the directory name and the durable identity), **Display name** (optional; defaults to a humanised slug), **Maximum width** (required; pre-filled from `kntnt_photo_drop_default_max_width`, default 1920; an explicit "No limit" choice maps to `null`), **Quality** (required; pre-filled from `kntnt_photo_drop_default_quality`, default 80).
- **No format field** (always WebP) and **no thumbnail-width field** (it is filter-driven via `kntnt_photo_drop_thumbnail_width` and re-derivable, so it is never frozen here; [ADR-0002](adr/0002-immutable-webp-output-contract.md)).
- A prominent, unmissable **irreversibility warning** on max width + quality: these fix the output contract at establishment and **cannot be changed afterwards**, because images are downscaled and re-encoded at ingestion and the original is never kept. Submitting establishes the collection: it creates the directory and writes `collection.json`.
- Slug validation: lowercase, URL-safe, unique among existing directories.

### Update (Edit)

- Only the **display name** is editable. Max width, quality, format, and thumbnail width(s) are shown **read-only / disabled**, with a note that the contract is immutable and thumbnail width is changed via the filter + `wp kntnt-photo-drop collection doctor <slug> --repair --force`. Submitting rewrites only `name` in `collection.json`; any attempt to change the contract is rejected server-side.

### Delete

- A confirmation step (the act removes the collection directory and everything under it). After confirming, the directory is deleted; blocks that referenced the slug then dangle, which is expected. Mirrors `collection delete <slug>` (which prompts unless `--yes`).

### Relationship to the CLI

The admin page and the CLI are the only two places a collection's lifecycle is driven, and they are deliberate, trusted contexts. The page never exposes anything the contract model forbids (no format choice, no contract edit after establishment, no thumbnail-width field). Everything the page does has a CLI equivalent for headless/automated use.
