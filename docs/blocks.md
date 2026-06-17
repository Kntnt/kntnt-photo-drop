# Blocks and admin page

Per-block specification: `block.json` attribute schema, editor UI, and render output for the two blocks, plus the create/update/delete UX of the collection-lifecycle admin page. This pins the attribute shapes; the rationale for the design lives in [`design.md`](design.md) and the ADRs. Use the [`CONTEXT.md`](../CONTEXT.md) vocabulary throughout.

Both blocks are **dynamic** (server-rendered via `render.php` → an autoloaded `Render_*` class) and **select-only consumers** of collections: neither can create or reconfigure one. Both register under the `kntnt` block category. Pre-1.0, there are no `deprecated` entries and no attribute migrations.

## Shared concepts

- A block points at a collection by its **slug** (the directory name under the uploads root). The slug is the only durable reference; a renamed (`mv`'d) collection dangles the block, which is expected.
- The collection list in every selector (both blocks' inspectors and the admin page) comes from the **discovery scan** — any directory under the uploads root containing a `collection.json`. There is no registry.
- A **no-collection or broken reference** (unset slug, dangling slug, unreadable descriptor, invalid start path) renders **nothing** for the public and an **editor-only notice** for a logged-in user, so a visitor never learns a collection is gone. A collection that **resolves cleanly but holds no images** is different — a legitimate visitor-facing state — and renders a **configurable message to everyone** ([ADR-0012](adr/0012-imageless-gallery-shows-a-public-message.md)).

---

## Photo Drop Zone — `kntnt-photo-drop/drop-zone`

A capability-gated front-end uploader bound to one existing collection. It selects a collection and uploads into it; it never establishes or reconfigures one, so its inspector has nothing that could conflict with the contract. The block is an **`InnerBlocks` wrapper that is itself the layout container** — a Group-equivalent: it carries the Group's block supports (layout, colour, typography, border, spacing incl. `blockGap`, min-height, shadow, align), and its *editable appearance* is its inner blocks seeded **directly** into that styled wrapper (a centred dashed box by default, from the block's seeded `style`). The **one** wrapper element is the styled box, the drag-drop target, and the drag-over highlight, and at render it becomes the **native drag-drop + click-to-browse** zone. There is no inner `core/group` and no FilePond.

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
		"align": true,
		"color": {
			"background": true,
			"text": true,
			"gradients": true,
			"link": true,
			"__experimentalDefaultControls": { "background": true, "text": true }
		},
		"typography": {
			"fontSize": true,
			"lineHeight": true,
			"__experimentalFontFamily": true,
			"__experimentalTextDecoration": true,
			"__experimentalFontStyle": true,
			"__experimentalFontWeight": true,
			"__experimentalLetterSpacing": true,
			"__experimentalTextTransform": true,
			"__experimentalDefaultControls": { "fontSize": true }
		},
		"__experimentalBorder": {
			"color": true,
			"radius": true,
			"style": true,
			"width": true,
			"__experimentalDefaultControls": { "color": true, "radius": true, "style": true, "width": true }
		},
		"shadow": true,
		"spacing": {
			"margin": true,
			"padding": true,
			"blockGap": true,
			"__experimentalDefaultControls": { "padding": true, "blockGap": true }
		},
		"dimensions": { "minHeight": true },
		"layout": { "allowSizingOnChildren": true, "default": { "type": "constrained" } }
	},
	"attributes": {
		"collection": { "type": "string", "default": "" },
		"style": {
			"type": "object",
			"default": {
				"border": { "color": "#808080", "style": "dashed", "width": "2px", "radius": "4px" },
				"color": { "background": "#fafaff" },
				"spacing": { "padding": "2rem" }
			}
		}
	}
}
```

Unlike the Gallery — which projects its colour/typography/border/shadow supports onto sub-elements via `__experimentalSkipSerialization` — the Drop Zone is a true Group-equivalent: its supports are **serialised onto the wrapper itself**, because the wrapper is the styled box the builder sees. The `layout` support's `default` makes `constrained` the default layout (its `layout` attribute is auto-managed by the layout support, not declared here), and the seeded `style` attribute reproduces today's default look (dashed `#808080` border, `#fafaff` background, `2rem` padding) on a freshly inserted block. `collection` remains the only persisted block-specific attribute; everything about the contract is still read live from the descriptor, never stored on the block.

### Attributes

| Attribute | Type | Default | Meaning |
|---|---|---|---|
| `collection` | string | `""` | The slug of the collection to upload into. The **only** persisted attribute. Everything about the contract is read live from the descriptor, never stored on the block. |

The collection's output contract (max width, quality, WebP, thumbnail width) is **not** a block attribute — it is read from `collection.json` at edit time (for the read-only inspector display) and at render time (to configure the client-side Canvas optimisation). This is what keeps the Drop Zone unable to conflict with the contract.

This is a **dynamic block with inner blocks**: `save` returns `<InnerBlocks.Content />` (the inner-block markup is serialised into `post_content`), and `render.php` consumes that markup as `$content` — gating it by capability, replacing the collection placeholder, and emitting it as the **direct children of the styled wrapper**, which is itself the native drop surface. The block carries the `cloud-upload` icon in the inserter and list view.

### Editor UI (`edit.tsx`)

- **Inner blocks (canvas)** — the block's editable appearance is an `InnerBlocks` region, and the block's **wrapper is itself the layout container** (a Group-equivalent), so the inner blocks are its direct children and the Group block supports style that same wrapper. On insertion it is seeded with a default (unlocked) template — **flattened, with no inner `core/group`**: a level-4 `core/heading` "Photo Drop Zone", a `core/paragraph` `Uploads go into the "{kntnt-drop-zone-collection}" collection.`, a smaller `core/paragraph` "The live uploader appears on the published page for users who can upload files.", and a `core/buttons` holding the two visible upload controls — an **"Add photos"** button and a quieter **"Select a folder"** button, each wired to the uploader by an anchor-token href (`#kntnt-drop-zone-files`, `#kntnt-drop-zone-folder`; [ADR-0010](adr/0010-drop-zone-controls-are-token-wired-links.md)). The wrapper's centred dashed-box look comes from the block's seeded `style` attribute and its `constrained` layout support, not from an inner group. The template is **not locked**, so a site builder can relabel, restyle, reposition, or remove the controls (or any of the surface) — including turning the folder button back into a plain text link; the literal token `{kntnt-drop-zone-collection}` and the two control tokens are defaults and conventions, not a contract. The inspector exposes the full Group-equivalent control set (layout incl. `blockGap`, background/text colour, typography, border, margin/padding, min-height, shadow, and the block-toolbar alignment).
- **Inspector → Collection** — a `SelectControl` listing discovered collections by display name (value = slug). Choosing one sets `collection`. An empty or dangling `collection` shows an inline notice in the panel (prompting selection or noting the collection is gone).
- **Inspector → Output contract (read-only)** — a static display of the selected collection's three rendition tiers (upload width — or "Original" — and quality, full width and quality, thumbnail width and quality), the format (always **WebP**), and the path-components template. No fields to edit; a hint links to the admin page for lifecycle changes, set off by vertical space below the list.

### Render output (`render.php` → `Render_Drop_Zone`)

- Renders **only** for users who hold the upload capability (`upload_files`, filter `kntnt_photo_drop_upload_capability`). For anyone else, the block renders nothing — and crucially **no `wp_rest` nonce** is emitted (defence in depth; see [ADR-0006](adr/0006-server-enforced-contract-rest-upload.md)).
- For a capable user, it replaces the literal `{kntnt-drop-zone-collection}` token in the inner-block markup with the collection's display name (a removed or edited token is simply not replaced), then emits that markup as the **direct children of the block wrapper**, which is itself the native drag-drop + click-to-browse zone. The visible upload controls live **in those inner blocks**: ordinary links (a `core/button`, a text link, anything carrying an `href`) wired to the uploader by an anchor-token href — `#kntnt-drop-zone-files` opens the loose-file picker, `#kntnt-drop-zone-folder` the `webkitdirectory` folder picker ([ADR-0010](adr/0010-drop-zone-controls-are-token-wired-links.md)). The render handler emits only the **non-presentational** chrome inside the same styled box: two **hidden** file inputs the tokened links (and the click-anywhere surface) trigger — the loose-file input and the `webkitdirectory` folder input — plus the `__summary` live region, the `__progress` bar (with its Cancel button), and the `__status` result list. The DOM shape is one wrapper `<div>` (carrying the block supports and the Interactivity `data-wp-context`/`data-wp-init`) → the placeholder-replaced inner blocks (with the builder's tokened controls), the two hidden inputs, the `__summary`, the `__progress`, and the `__status`. **There is no `__surface` div** (the level that wrapped the inner blocks before this slice is gone), so the styled box, the drop target, and the drag-over highlight are the one wrapper element. The descriptor's contract is read server-side and passed to the client so the Canvas downscale + the `canvas.toBlob(…, 'image/webp', quality)` encode are configured from it — the **upload** width/quality, since the client produces only the main image and the server derives the full and thumbnail renditions ([ADR-0013](adr/0013-three-rendition-model.md)).
- **Interaction model.** A pointer click anywhere on the wrapper that does not land on an interactive child — a link, button, input, label, select, or textarea in the builder's markup (which covers the tokened upload-control links), or the `__summary`/`__status` regions — opens the loose-file picker. The browse path is the tokened links themselves (a keyboard/AT user reaches them by Tab and activates with Enter), so the wrapper carries **no `role="button"` and no `tabindex`**. The view module finds the tokened links by their href and binds each click to its hidden input (`preventDefault` stops the fragment navigation). The `__summary`, `__progress`, and `__status` keep their `data-wp-ignore` boundary (the view module owns their DOM); the inner blocks carry none.
- Each file is uploaded one-per-request to `POST /wp-json/kntnt-photo-drop/v1/collections/<slug>/images` (multipart: the file + its `relativePath`), carrying the nonce. The relative path is preserved per file so the server can recreate sub-directories (path hard-sanitised and `realpath`-confined). A folder dragged onto the zone is **walked recursively** (`webkitGetAsEntry()` → `createReader()`), and every image at every level uploads with its source-relative path preserved — the same on-disk placement as picking that folder via the folder picker, with no warning or consent step ([ADR-0008](adr/0008-ingestion-placement-hierarchy-and-uploader-folders.md)). A dropped file's path is derived from the entry's `fullPath` (the picker uses each file's `webkitRelativePath`), and the upload queue dedupes by source relative path across every flow, so two files sharing a basename in different sub-folders both upload. Instead of a per-file line list, the view module drives an **aggregate progress bar** — files reaching a terminal state ÷ total, shown as "N / total" with the total at the far right — a **Cancel** button that aborts in-flight XHRs and stops the queue while leaving already-uploaded files in place, and, on completion or cancel, a **three-bucket summary** (Uploaded as a count; Skipped and Failed listed by filename) with a **Retry-failed** button that re-queues only the failures. The live `__summary` region announces progress to assistive tech, and the one-shot nonce refresh on expiry is unchanged.
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
	"description": "A public, server-rendered gallery of a chosen collection, with a lightbox.",
	"keywords": [ "photo", "gallery", "images", "lightbox", "collection" ],
	"textdomain": "kntnt-photo-drop",
	"editorScript": "file:./index.tsx",
	"viewScriptModule": "file:./view.ts",
	"style": "file:./style-index.css",
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
		"emptyMessage":                 { "type": "string",  "default": "" },
		"layout":                       { "type": "string",  "default": "grid" },
		"minimumColumnWidth":           { "type": "string",  "default": "320px" },
		"imageFit":                     { "type": "string",  "default": "cover" },
		"aspectRatio":                  { "type": "string",  "default": "" },
		"targetRowHeight":              { "type": "number",  "default": 240 },
		"lightbox":                     { "type": "boolean", "default": true },
		"slideshow":                    { "type": "string",  "default": "off" },
		"slideshowButtonLabel":         { "type": "string",  "default": "" },
		"slideshowSeconds":             { "type": "number",  "default": 5 },
		"breadcrumbsVisibility":        { "type": "string",  "default": "off" },
		"breadcrumbsPosition":          { "type": "string",  "default": "bottom-left" },
		"breadcrumbsHideCount":         { "type": "number",  "default": 0 },
		"breadcrumbsSeparator":         { "type": "string",  "default": "›" },
		"downloadVisibility":           { "type": "string",  "default": "off" },
		"downloadPosition":             { "type": "string",  "default": "top-left" },
		"addToMediaVisibility":         { "type": "string",  "default": "off" },
		"addToMediaPosition":           { "type": "string",  "default": "top-right" },
		"trashVisibility":              { "type": "string",  "default": "off" },
		"trashPosition":                { "type": "string",  "default": "top-right" },
		"iconSize":                     { "type": "string",  "default": "2rem" },
		"isEditorPreview":              { "type": "boolean", "default": false }
	}
}
```

The colour, typography, border, and shadow supports all carry `__experimentalSkipSerialization` so WordPress does **not** write them onto the block wrapper. `Render_Gallery` projects them onto the right sub-element instead — the Colour support's text/background onto each overlay (one shared foreground and background across breadcrumbs and icons), Typography onto the breadcrumb, and Border/Shadow onto each `<img>` — through the core style engine (`wp_style_engine_get_styles`), the same skip-serialization pattern core's Image block uses. The `blockGap` spacing support replaces the old custom gap attribute and is read server-side into both layout containers.

### Attributes

| Attribute | Type | Default | Allowed values / notes |
|---|---|---|---|
| `collection` | string | `""` | Collection slug. |
| `startPath` | string | `""` | Editor-set start path relative to the collection root; `""` = root. **Never a visitor query parameter** — validated once against the root, so there is no per-request path-traversal surface ([ADR-0005](adr/0005-recursive-flatten-gallery-no-navigation.md)). |
| `recursive` | boolean | `true` | `true` = all images under `startPath` recursively, flattened; `false` = this folder only. |
| `order` | string | `"asc"` | `"asc"` \| `"desc"`. Pre-order tree traversal (a folder's own images before its subfolders), natural sort within each level; `"desc"` reverses within each level while keeping that structure ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)). Not visitor-controllable. |
| `emptyMessage` | string | `""` | The message shown to **every visitor** when the chosen collection resolves but holds **no images**; `""` = the translated default ("There are currently no images in the gallery. Please try again later."). Output is `esc_html`-escaped. Does **not** cover the no-collection / broken-reference case, which stays an editor-only notice ([ADR-0012](adr/0012-imageless-gallery-shows-a-public-message.md)). |
| `layout` | string | `"grid"` | `"grid"` = mode A (uniform grid, core Grid layout); `"justified"` = mode B (bespoke justified rows). |
| `minimumColumnWidth` | string | `"320px"` | Mode A only. Maps to core Grid's `minimumColumnWidth`. |
| `imageFit` | string | `"cover"` | Mode A only. `"cover"` \| `"contain"`. |
| `aspectRatio` | string | `""` | Mode A only. `""` = use each image's stored ratio (zero layout shift); otherwise a CSS ratio such as `"1"`, `"4/3"`, `"16/9"`. |
| `targetRowHeight` | number | `240` | Mode B only. Target row height in px; per-image `flex-grow`/`flex-basis` are derived from stored dimensions, last row left-aligned. |
| `lightbox` | boolean | `true` | When on, clicking an image opens the Interactivity-API lightbox; the no-JS `<a href="full.webp">` fallback is present regardless ([ADR-0007](adr/0007-lightbox-via-interactivity-api.md)). |
| `slideshow` | string | `"off"` | `"off"` \| `"button"` \| `"custom"` — the slideshow trigger mode ([ADR-0009](adr/0009-slideshow-passive-surface-pluggable-trigger.md)). `"button"` renders the built-in quiet button above the gallery; `"custom"` renders no button — the page designer places any element carrying `data-kntnt-photo-drop-slideshow` (see *Slideshow* below). An unrecognised value renders as `"off"`. |
| `slideshowButtonLabel` | string | `""` | The built-in button's label; `""` = the translated default ("Slideshow"). |
| `slideshowSeconds` | number | `5` | How long each slide stands fully visible, in whole seconds; the ~1 s dissolve comes on top. Clamped server-side to ≥ 1; malformed values fall back to 5. |
| `breadcrumbsVisibility` | string | `"off"` | `"off"` \| `"thumbnail"` \| `"full"` \| `"both"` — where the breadcrumbs overlay shows (`"full"` = the lightbox, which requires `lightbox` on; the slideshow never shows overlays). |
| `breadcrumbsPosition` | string | `"bottom-left"` | Nine-point position (see vocabulary below). The horizontal part also sets text alignment (`*-left`/`*-center`/`*-right`). Breadcrumbs occupy a whole band, so the editor excludes that band from the icon overlays. |
| `breadcrumbsHideCount` | number | `0` | How many leading crumbs to hide. `0` = show all (first crumb = the collection's display name); `1` = hide the collection; `2` = hide the collection and the next; … |
| `breadcrumbsSeparator` | string | `"›"` | Breadcrumb separator (free text). |
| `downloadVisibility` | string | `"off"` | `"off"` \| `"thumbnail"` \| `"full"` \| `"both"`. A click on the icon saves the **main image** (programmatically; no-JS `<a download>` fallback). |
| `downloadPosition` | string | `"top-left"` | Nine-point position. Icons sharing a position auto-cluster in a fixed-order row. |
| `addToMediaVisibility` | string | `"off"` | `"off"` \| `"thumbnail"` \| `"full"` \| `"both"`. Rendered only for users holding `kntnt_photo_drop_add_to_media_capability` (default `upload_files`); a confirm callout then sideloads the main image into the Media Library as an independent attachment ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)). |
| `addToMediaPosition` | string | `"top-right"` | Nine-point position. |
| `trashVisibility` | string | `"off"` | `"off"` \| `"thumbnail"` \| `"full"` \| `"both"`. Rendered only for users holding `kntnt_photo_drop_delete_capability` (default `delete_others_posts`); an inline-popover confirm then **permanently** deletes the image (main + derived artifacts) and removes the tile live ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)). |
| `trashPosition` | string | `"top-right"` | Nine-point position. |
| `iconSize` | string | `"2rem"` | Shared size (a CSS length) for all three action icons, independent of the breadcrumb font size. |
| `isEditorPreview` | boolean | `false` | **Render-time-only.** The editor passes `true` on the `ServerSideRender` `attributes` prop to request the capped, lightbox-suppressed preview; it is never written through `setAttributes`, so — left at its `false` default — it is never serialised into `post_content` and cannot reach a frontend render. It is declared in `block.json` only because the REST block-renderer endpoint (`additionalProperties: false`) would otherwise strip an undeclared attribute before the preview reached the render callback. |

**Overlays are projected onto the image, never the wrapper.** Each of the four overlays — **breadcrumbs**, **download**, **add-to-media**, **trash** — has one visibility (`*Visibility`: Off / Thumbnail / Full / Both), one nine-point position (`*Position`), and one *shared* appearance: the **Colour** support gives every overlay the same foreground (breadcrumb text + icon glyphs) and background; **Typography** gives the breadcrumb its font; `iconSize` gives the three action icons their shared size; and each image's **Border**/**Shadow** come from those supports on the `<img>`. All are `__experimentalSkipSerialization` and projected server-side onto the sub-elements (the core Image-block pattern), so the wrapper stays unstyled. The nine positions are `top-left`, `top-center`, `top-right`, `middle-left`, `middle-center`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right`. "Full" means the lightbox and requires `lightbox` on; **the slideshow never shows overlays** ([ADR-0009](adr/0009-slideshow-passive-surface-pluggable-trigger.md), [ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)). Because Canvas re-encoding strips all EXIF/IPTC at ingestion, overlay text is path-derived only.

**Breadcrumbs** replace the former caption. The text is the image's folder path relative to the uploads root, always humanised, with the **collection's display name as the first crumb**; `breadcrumbsHideCount` drops that many leading crumbs (`0` shows all). Breadcrumbs render on their own line, so the editor enforces **mutual exclusion** between the breadcrumb's band (top/middle/bottom) and the three icon positions. If the line overflows, a pure-CSS single-line **leading ellipsis keeps the tail** (RTL-aware via `direction`) — there is no JS measurement.

#### Overlay surfaces and actions

The `lightbox` toggle still governs the base click: with it on, a plain click on a tile opens the lightbox; the overlays are separate icon-clicks layered on top, and a click on the image *outside* an icon never triggers an overlay action. The overlays appear on the **thumbnail** and/or the **lightbox image** per their visibility; icons sharing a position cluster into a fixed-order row (Download, Add-to-media, Trash).

- **Download** is the sole download trigger, always targeting the **main image**. The icon is an `<a download>` anchor, but with JavaScript a plain click is intercepted and the image saved **programmatically** — fetched into a Blob and handed to the browser through a temporary same-document object-URL anchor — so the save can never be hijacked into navigation or a new tab. When the fetch itself cannot run (a cross-origin uploads host *without* CORS, an offline network, a non-OK response) there is no Blob to hand over, so the save falls back to clicking a **same-document, same-tab `<a download>`** at the image URL — the gallery never forces a current-tab navigation, and never opens a new tab of its own. Where the browser honours `download` (same-origin uploads — the default — or a cross-origin host with CORS) the image saves in place; where it cannot (a cross-origin host without CORS, where browsers ignore the `download` attribute) the browser decides the outcome — and on Safari that decision is a new tab — which the gallery cannot override. Modified clicks (Cmd/Ctrl/Shift/Alt, non-primary button) are left to the browser, and the same-tab `<a download>` is also the no-JS fallback, carrying the identical cross-origin caveat.
- **Add-to-media** and **Trash** are server-side writes, so the gallery — otherwise pure SSR — gains a **nonce + capability gated REST surface** ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)), rendered with its `wp_rest` nonce **only for capable users**. Add-to-media opens a confirm callout, then sideloads the main image into the Media Library as an **independent attachment** (a copy, never a link); each confirmed click adds another copy. Trash opens an inline-popover confirm (a deliberate second click on a destructive button), then **permanently** deletes the main image and its derived artifacts (no recycle bin) and removes the tile live (advancing/closing the lightbox if open); the index self-heals on the next render.

The per-slide download URL and breadcrumb text are mirrored onto each gallery anchor as `data-kntnt-photo-drop-full` and `data-kntnt-photo-drop-breadcrumbs`, so the lightbox shows the right image and overlay text without re-reading the page.

#### Slideshow

A third surface beside the grid and the lightbox, fully orthogonal to the overlays ([ADR-0009](adr/0009-slideshow-passive-surface-pluggable-trigger.md), [ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)): a visitor-started, automatically advancing, endlessly looping fullscreen playback of exactly the gallery's view — the same flattened, ordered image list the lightbox pages through, with the same responsive srcsets but **no overlays** (the slideshow is overlay-free). Playback is **passive**: the only interaction is ending it, via Escape, the browser's native fullscreen exit, or the overlay's discreet always-visible close button (the touch path). Fullscreen uses the Fullscreen API where available and degrades silently to a fixed viewport-filling overlay (notably iPhone Safari). Each slide stands fully visible for `slideshowSeconds`, then dissolves (~1 s crossfade; a hard cut under `prefers-reduced-motion`) to the next; the next image preloads during display and the slideshow **never transitions to an unloaded image** — a slow image extends the current slide. A slide whose image fails to load is skipped; when a whole cycle fails, the playback ends. A screen wake lock is held while playing (best-effort), and the start position is always the first image.

At each **cycle boundary** — the wrap from the last image back to the first, which a single-image gallery reaches every visible period — the slideshow re-syncs its slide list to the gallery's *current* view by refetching the page ([ADR-0011](adr/0011-slideshow-cycle-boundary-resync-by-page-refetch.md)). The resync is full: additions, deletions, srcset changes, and ordering all follow the server, so every cycle plays exactly what a reload would show. The fetch starts when the boundary slide becomes visible and the wrap waits for it to settle, bounded by a short abort timeout; a transport failure (network, HTTP status, timeout) keeps the stale list and retries next cycle, while an **emptied view ends the playback** so a takedown propagates within one cycle — the server renders a deleted or broken collection as no wrapper at all and an emptied-but-present one as the empty-message wrapper with no slide anchors ([ADR-0012](adr/0012-imageless-gallery-shows-a-public-message.md)), so both a missing wrapper and a wrapper with zero anchors count as the empty view. The behaviour is unconditional — no attribute — and applies to the slideshow only: the grid and the lightbox stay frozen at the page-load view.

The trigger is the three-state `slideshow` attribute. In `"button"` mode the server renders a quiet link-styled button above the gallery (`slideshowButtonLabel`, hidden until the view module wires the slideshow, so a no-JS visitor never sees a dead control; the editor preview shows it visible and inert). In `"custom"` mode the page designer places **any element anywhere on the page** carrying the documented attribute `data-kntnt-photo-drop-slideshow`; its value names the target gallery's **HTML anchor** (the core *Advanced → HTML anchor* field, which `Render_Gallery` mirrors onto the wrapper `id`), and a valueless attribute forgivingly targets the page's **first** slideshow-enabled gallery, so the common one-gallery page needs no anchor. A pasted `#anchor` value is accepted as the bare anchor; a value matching no gallery leaves the element's own behaviour untouched. One delegated document-level listener serves every trigger, and both trigger modes register as targets. The wrapper carries `data-kntnt-photo-drop-slideshow-mode` and `data-kntnt-photo-drop-slideshow-seconds` (frontend only, never in the editor preview).

### Editor UI (`edit.tsx`)

Inspector panels:

- **Collection** — `SelectControl` of discovered collections (value = slug) + a `startPath` control (chooses a sub-folder of the selected collection) + a **"This folder only"** toggle (inverse of `recursive`) + an **"Empty-gallery message"** text field (`emptyMessage`, placeholder = the translated default) shown to visitors when the collection has no images.
- **Ordering** — ascending/descending `order` (a pre-order tree traversal; descending reverses within each level).
- **Layout** — mode toggle A/B. Mode A reveals `minimumColumnWidth`, `imageFit`, `aspectRatio`; mode B reveals `targetRowHeight`. The inter-item gap is the core **Block spacing** control (Dimensions); the panel carries a hint pointing there rather than a bespoke gap field. Gallery width/alignment is the core block toolbar.
- **Lightbox** — the **Lightbox** toggle (`lightbox`, default on); it governs the base tile click and gates the "Full" overlay surface.
- **Overlays** — one control group per overlay (Breadcrumbs, Download, Add-to-media, Trash), each a **visibility** select (Off / Thumbnail / Full / Both) and a nine-point **position**. Breadcrumbs add **Hide first N crumbs** (`breadcrumbsHideCount`) and **Separator** (`breadcrumbsSeparator`); the three action icons share one **Icon size** (`iconSize`). The editor enforces the mutual band-exclusion between breadcrumbs and icons by disabling a band's three positions in the other pickers. The overlays' shared foreground/background come from the core **Colour** panel, the breadcrumb font from **Typography**, and per-image border/shadow from **Border**/**Shadow** — applied to sub-elements via skip-serialization, not bespoke attributes. (The Add-to-media and Trash overlays render on the front end only for users holding their capability; the builder always sees their controls here.)
- **Slideshow** — the three-state trigger select (`slideshow`: Off / Built-in button / Custom element). Button mode reveals the **Button label** (`slideshowButtonLabel`, placeholder = the translated default); custom mode reveals a hint with the copy-ready `data-kntnt-photo-drop-slideshow` snippet (including the block's HTML anchor when one is set under *Advanced*); any non-off mode reveals **Seconds per image** (`slideshowSeconds`, min 1).

The editor preview uses `ServerSideRender` so the editor matches the frontend, but in **editor-preview mode**: it sends the render-time-only `isEditorPreview` flag, so the server caps the canvas at the first **6** figures and emits no lightbox markup (clicks stay inert in the editor — a collection of thousands never floods the canvas). The block carries no editor-only preview heading: the canvas shows only images. When there is nothing to render — no collection chosen, a dangling slug, an empty collection, or while the preview loads — the editor shows a grid of **6 grey placeholders** in place of the gallery, rather than a bare notice.

### Render output (`render.php` → `Render_Gallery`)

- Resolves the collection, validates `startPath` against the root once, walks the tree (recursive or single-folder), reading each folder's mtime-validated `index.json` (self-heals if stale; see [ADR-0003](adr/0003-on-disk-collection-layout.md)), and orders by a pre-order tree traversal (natural sort within each level, `order`; [ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)).
- Emits a `<figure>` per image with `loading="lazy"`, stored `width`/`height` (or `aspect-ratio`) for zero layout shift, and a `srcset` listing the **thumbnail and full** renditions — the full image is the display ceiling, and the (possibly unbounded) main is download-only, so it is not a srcset candidate beyond the full width ([ADR-0013](adr/0013-three-rendition-model.md)). The `sizes` hint is layout-aware (grid: derived from `minimumColumnWidth`; justified: per-image from `targetRowHeight` × aspect ratio; both prefixed with `auto` for browsers that support lazy auto-sizes) so a tile never downloads more than the full image. Each anchor also carries `data-kntnt-photo-drop-srcset` so the lightbox image gets the same responsive candidates.
- Each `<figure>` is the sizing box and the positioning context for its overlays: mode A fixes its `aspect-ratio` inline, mode B its `height` inline, and the `<a>` plus `<img>` fill that box absolutely so nothing inside it collapses to zero (the fix for the clipped-under-overlay and alt-text-only-justified-row bugs). The gap for both layouts is read from the `blockGap` spacing support.
- Mode A uses core's Grid layout (`minimumColumnWidth`, the support `blockGap`) plus the bespoke `aspect-ratio`/`imageFit`; mode B emits bespoke justified rows (`flex-grow`/`flex-basis` from stored dimensions, `targetRowHeight`, the support `blockGap`, last row left-aligned). The server computes mode B's last-row flags against an assumed container width as the no-JS/first-paint fallback; the view module re-flags the actual last row on init and on resize (so mode B emits `data-wp-init` even when the lightbox is off).
- Each enabled overlay is emitted at its nine-point position per its visibility: **breadcrumbs** as an anchored element carrying the Colour/Typography declarations and preset classnames; the **action icons** as CSS-masked inline SVGs sharing the Colour foreground/background and `iconSize`; each `<img>` carries the Border/Shadow declarations. All come from the style engine, projected server-side onto the sub-element rather than the wrapper. The breadcrumb text is mirrored onto the anchor as `data-kntnt-photo-drop-breadcrumbs` so the lightbox shows the same overlay.
- The base click is driven by the `data-kntnt-photo-drop-lightbox` wrapper flag the view module reads. The **Download** icon is an `<a download>` overlay anchor — the sole download trigger, with a translated `aria-label`, its `href` pointing at the figure's main image; a plain click is intercepted and the image saved programmatically (Blob + temporary object-URL anchor) so no environment can turn the save into navigation or a new tab. With the lightbox off and no overlay action under the pointer, the view module suppresses the plain thumbnail click so the image itself does nothing. The **Add-to-media** and **Trash** icons are emitted only when the current user holds the matching capability; each drives a `wp_rest` nonce-backed action the view module posts (behind its confirm callout) to the gallery's REST endpoints ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)).
- The lightbox is an Interactivity-API surface (open/close, prev/next, keyboard, swipe, debounced neighbour preload, focus trap, scroll lock, `aria`, loading/error states with a server-translated error element); each thumbnail is wrapped in `<a href="full.webp">` so a no-JS click navigates to the full image, and modified clicks (Cmd/Ctrl/Shift/Alt, non-primary button) are left to the browser. When an overlay's visibility includes the full image, the lightbox carries it: the enlarged image and any action-icon anchors sit inside a shrink-wrapping media wrapper (the view module points the download icon's `href` at the current slide and saves it programmatically), and the breadcrumb `<figcaption>` is filled per slide; a click on the enlarged image itself does nothing. The `data-wp-init` hook is bound on every frontend render — for the lightbox, the justified last-row correction, or the lightbox-off click suppression. The gallery's read path is pure SSR plus the view module; only the Add-to-media and Trash actions use REST ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)).
- The slideshow surface (above) adds, when its mode is not `"off"`: the wrapper flags, the hidden built-in button (button mode), and a hidden dialog-role overlay holding the two stacked slide images the view module crossfades, plus the close button. **The slideshow carries no overlays** — no breadcrumbs and no action icons ([ADR-0015](adr/0015-gallery-overlays-and-rest-write-path.md)). The block's HTML anchor is mirrored onto the wrapper `id` (core does not emit it for dynamic blocks), which is what a custom trigger targets.
- **Empty states** ([ADR-0012](adr/0012-imageless-gallery-shows-a-public-message.md)): a **no-collection or broken reference** (unset slug, dangling slug, unreadable descriptor, invalid start path) emits, on the frontend, a `kntnt-photo-drop-gallery--notice` wrapper carrying *"This gallery has no collection selected. Choose a collection in the block settings."* **only to a user with `edit_posts`** — the public (including logged-in non-editors) gets `''`, so a deletion never leaks. An **imageless-but-valid collection** instead emits a `kntnt-photo-drop-gallery--empty` wrapper carrying the `emptyMessage` (or its translated default), `esc_html`-escaped, **to everyone**.
- **Editor-preview mode** (the render-time-only `isEditorPreview` attribute the editor's `ServerSideRender` sends): the walk is capped to the first **6** images and interactivity is suppressed (no lightbox overlay, no `data-wp-context`, the lightbox flag reads `false`, and no `data-wp-init`), so the canvas stays light and clicks are inert. Overlays may still appear on the figures so the preview matches the published page (the action icons render inertly regardless of the previewing user's capabilities), and the slideshow button renders visible (and inert) so the builder sees its placement — but no slideshow overlay or flags are emitted. A dangling/empty collection in preview mode returns an empty string, which the edit component's `ServerSideRender` treats as its empty case and replaces with the grey placeholders. The flags live only on the preview request, so the frontend render is identical: no cap, lightbox/overlays as configured, full walk.

---

## Collection-lifecycle admin page

Collection **create / update / delete** lives on a dedicated admin page (and the CLI). Blocks are select-only consumers and never appear here. The page is gated by `manage_options` (filter `kntnt_photo_drop_manage_capability`). It is the GUI mirror of the `wp kntnt-photo-drop collection {create,update,delete}` commands ([ADR-0004](adr/0004-cli-import-is-consumer-grouped-subcommands.md)).

### List view

- A table of all discovered collections (the discovery scan), one row each: display name, slug, upload width (or "Original"), full and thumbnail widths, format (**WebP**), and image count. A collection copied in from another site appears automatically; a deleted directory disappears.
- Each row ends with always-visible **Edit** (name, path components, and the re-derivable full/thumbnail settings) and **Delete** buttons in the rightmost column; Delete leads to the confirmation step. A **Create collection** button opens the create form.

### Create

- Fields, in order: **Display name** (optional; defaults to a humanised slug), **Slug** (optional; placeholder shows the unique `sanitize_title` default — suffix from `-2` — refreshed on-blur of Display name; blank uses that default, a *typed* collision is an error), **Path components** (optional; placeholder = the default `%year%/%month%/%day%/%uploader%`; blank uses the default), then the six rendition fields — **Upload width** (blank = original dimensions) ⚠️, **Upload quality** (default 95) ⚠️, **Full width** (blank = no separate full; the main serves the full role), **Full quality** (blank = the upload quality), **Thumbnail width** (blank = the full width), **Thumbnail quality** (default 75) — each pre-filled from its `kntnt_photo_drop_default_*` filter as a starting point ([ADR-0013](adr/0013-three-rendition-model.md), [ADR-0014](adr/0014-path-components-template.md)). The three re-derivable width/quality fields (Full width, Full quality, Thumbnail width) may be cleared to leave them **unset** — stored as `null`, collapsing to the tier above; the upload pair and Thumbnail quality fall back to their filter defaults when blank.
- **No format field** (always WebP). The **Slug** and the **Upload width/quality** fields carry a ⚠️ marker (with an accessible label and a per-field tooltip giving the reason — permanent identity; the source is discarded), rendered in WordPress's amber/warning hue so it is not lost in black body text. A plain-language `notice notice-warning` opens the form, **above every field**, so its scope is the whole form: it explains in everyday words that ⚠ fields are set when the collection is created and cannot be changed afterwards (the original is re-encoded and discarded), while everything else — including the full/thumbnail sizes — can be changed later. A line beside the Save button repeats the set-once rule, and the full/thumbnail fields carry a mild inline note that changing them later regenerates existing images ([#62](https://github.com/Kntnt/kntnt-photo-drop/issues/62)).
- The **Full width** and **Thumbnail width** fields each carry inline help naming the *effective* width — a tier is skipped when the source is no wider ([ADR-0013](adr/0013-three-rendition-model.md)), so the effective full width is the smaller of the entered value and the upload width, and the effective thumbnail width is the smaller of the entered value and the (already-capped) full width. Presentational only — it changes neither the stored values nor the rendition behaviour. ([#65](https://github.com/Kntnt/kntnt-photo-drop/issues/65))
- Submitting establishes the collection: it creates the directory and writes `collection.json`.
- Validation: slug lowercase, URL-safe, unique among existing directories (a typed collision errors; a blank field auto-suffixes); the path-components template is normalised with `%` reserved (a stray `%` after the four placeholders errors); a *present* width is a positive integer and a *present* quality is 0–100, but Upload width, Full width, Full quality, and Thumbnail width may each be left blank (upload width blank = original dimensions; the re-derivable trio blank = unset/collapse-to-parent).

### Update (Edit)

- Editable: **Display name**, **Path components** (both instant), and the re-derivable **Full width/quality** and **Thumbnail width/quality** — editing the latter triggers a browser-driven **regenerate-then-flip** (the gallery serves the old renditions until the batch completes; [ADR-0013](adr/0013-three-rendition-model.md)). The re-derivable Full width, Full quality, and Thumbnail width may be cleared to leave them unset (collapse-to-parent), just as on Create; an unset field renders empty (and as "Auto" in the read-only summaries). The **Full width** and **Thumbnail width** fields carry the same effective-width help as the Create form (effective full = min(entered, upload width); effective thumbnail = min(entered, effective full width)). **Slug** and **Upload width/quality** are shown **read-only** (the ⚠️ permanent fields); the read-only **Upload width/quality** rows carry the same amber ⚠️ marker as the create form, so "amber wherever they appear" holds on this contract too (the Format row is unmarked — WebP by construction) ([#62](https://github.com/Kntnt/kntnt-photo-drop/issues/62)). Submitting rewrites `collection.json`; any attempt to change the slug or the upload contract is rejected server-side.

### Delete

- A confirmation step (the act removes the collection directory and everything under it). After confirming, the directory is deleted; blocks that referenced the slug then dangle, which is expected. Mirrors `collection delete <slug>` (which prompts unless `--yes`).

### Relationship to the CLI

The admin page and the CLI are the only two places a collection's lifecycle is driven, and they are deliberate, trusted contexts. The page never exposes anything the contract model forbids (no format choice, no slug or upload-contract edit after establishment). Everything the page does has a CLI equivalent for headless/automated use.
