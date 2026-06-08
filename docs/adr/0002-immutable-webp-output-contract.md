# Immutable WebP output contract; thumbnail width is a re-derivable setting

A collection's **output contract** is exactly two lossy values fixed at establishment — **maximum width** and **compression quality** — and the stored format is **always WebP** (not a choice; inputs in other formats are accepted and converted). The contract is immutable because downscaling and re-encoding are irreversible and the original is never kept; raising the maximum later cannot retroactively enlarge already-imported images. This amends the original load-bearing invariant, which listed thumbnail width as part of the immutable contract.

## Considered Options

- **Selectable format (WebP/JPEG/PNG/AVIF):** rejected — output is always WebP. WebP covers both photos and transparency, encodes reliably both client-side (Canvas) and server-side, and avoiding a choice removes the client/server parity problem (Canvas cannot reliably encode AVIF).

## Consequences

**Thumbnail width is split out of the contract** because, unlike max-width/quality, it is losslessly **re-derivable from the main image at any time**. It is therefore a *changeable* setting, not frozen at establishment: it is supplied by the `kntnt_photo_drop_thumbnail_width` filter (default `640`, may return an array, `[]`/`0` = no thumbnail), recorded in the descriptor, and changing it means re-running `wp kntnt-photo-drop collection doctor <slug> --repair --force` to regenerate thumbnails. There is **no UI field and no `create` flag** for it. Graininess is impossible because the gallery's `srcset` always keeps the main image as a candidate, so the browser upgrades to the main whenever a thumbnail would be too small.
