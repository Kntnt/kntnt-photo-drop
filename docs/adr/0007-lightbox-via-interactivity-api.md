# Lightbox via the Interactivity API, superseding CSS `:target`

The gallery lightbox is built entirely with the WordPress **Interactivity API** (authored in TypeScript) — open/close, previous/next, keyboard, swipe, neighbour preloading, focus trap and `aria` — with a no-JS fallback where each thumbnail is an `<a href="full.webp">` so that without JavaScript a click simply navigates to the full image. This supersedes the original plan of a pure CSS `:target` lightbox with a small keyboard shim.

## Considered Options

- **CSS `:target` + keyboard shim** (the original design): rejected — `:target` pollutes URL history (the back button becomes the close button), and accessible focus management (focus trap, restoring focus on close, `aria`) is effectively impossible with it. Accessibility on a public page is the deciding factor.

## Consequences

The Interactivity API is the project's stated convention (and is already used by kntnt-gpx-blocks), so this also buys consistency, and its runtime is paid for anyway. After the move to a recursive-flatten gallery (ADR-0005) removed folder navigation, the lightbox is the gallery's only interactive surface. The gallery's caption is always an anchored overlay inside the image, governed by one shared **Caption** panel (content, humanise, breadcrumb prefix/separator, nine-point anchor) whose colour and typography come from the block-support panels (issue #33); those same shared settings drive the lightbox caption when the lightbox is on and the content is not `"none"` — the enlarged image carries the identical overlay `<figcaption>` (issue #34).

## Click-behaviour and download amendment (issue #34)

The single *enable lightbox* toggle is replaced by a two-toggle **click-behaviour** model — **Lightbox** (default on) and **Download** (default off) — without changing the decision above; the lightbox remains an Interactivity-API surface with the no-JS `<a href="main.webp">` fallback. The two toggles form a matrix over what a thumbnail click does and where a download affordance lives:

- **Lightbox off + Download off** — a click does nothing. The anchor still points at the main image (the no-JS fallback navigates there without JavaScript), but the view module suppresses the plain primary click so the gallery is inert with JS.
- **Lightbox on + Download off** — a click opens the lightbox; the enlarged image has no download affordance.
- **Lightbox off + Download on** — a download icon overlays each thumbnail and the anchor carries the `download` attribute, so a click saves the full main image.
- **Lightbox on + Download on** — the thumbnail shows **no** icon and a click opens the lightbox; the download icon and the `download` anchor appear **only inside the lightbox**, so clicking the enlarged image saves the main image.

The download always targets the full-resolution **main image** (`download` attribute on a same-origin anchor, so the browser saves rather than navigates); modified clicks (Cmd/Ctrl/Shift/Alt, non-primary button) stay with the browser, and the plain no-JS anchor fallback still navigates to the main image. The download icon is an anchored overlay (nine-point anchor, the caption's vocabulary) with bespoke size/background/foreground controls — bespoke because the block-support **Colour** panel is already claimed by the caption. The keyboard / swipe / focus-trap / scroll-lock / neighbour-preload behaviour above is unchanged.
