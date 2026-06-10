# Lightbox via the Interactivity API, superseding CSS `:target`

The gallery lightbox is built entirely with the WordPress **Interactivity API** (authored in TypeScript) — open/close, previous/next, keyboard, swipe, neighbour preloading, focus trap and `aria` — with a no-JS fallback where each thumbnail is an `<a href="full.webp">` so that without JavaScript a click simply navigates to the full image. This supersedes the original plan of a pure CSS `:target` lightbox with a small keyboard shim.

## Considered Options

- **CSS `:target` + keyboard shim** (the original design): rejected — `:target` pollutes URL history (the back button becomes the close button), and accessible focus management (focus trap, restoring focus on close, `aria`) is effectively impossible with it. Accessibility on a public page is the deciding factor.

## Consequences

The Interactivity API is the project's stated convention (and is already used by kntnt-gpx-blocks), so this also buys consistency, and its runtime is paid for anyway. After the move to a recursive-flatten gallery (ADR-0005) removed folder navigation, the lightbox is the gallery's only interactive surface. The gallery's caption is always an anchored overlay inside the image, governed by one shared **Caption** panel (content, humanise, breadcrumb prefix/separator, nine-point anchor) whose colour and typography come from the block-support panels (issue #33); those same shared settings drive the lightbox caption when the lightbox is wired (the mirroring lands with the click-behaviour slice, issue #34).
