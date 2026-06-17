# Immutable WebP output contract; thumbnail width is a re-derivable setting

> **Amended by [ADR-0013](0013-three-rendition-model.md).** The immutable contract is now **upload width + upload quality** only; a re-derived **full image** tier joins the thumbnail, and both full and thumbnail width/quality are re-derivable, admin-editable settings. The "main is always a srcset candidate" grain-defence below becomes a full-image **display ceiling** (the main is download-only).

A collection's **output contract** is exactly two lossy values fixed at establishment — **maximum width** and **compression quality** — and the stored format is **always WebP** (not a choice; inputs in other formats are accepted and converted). The contract is immutable because downscaling and re-encoding are irreversible and the original is never kept; raising the maximum later cannot retroactively enlarge already-imported images. This amends the original load-bearing invariant, which listed thumbnail width as part of the immutable contract.

## Considered Options

- **Selectable format (WebP/JPEG/PNG/AVIF):** rejected — output is always WebP. WebP covers both photos and transparency, encodes reliably both client-side (Canvas) and server-side, and avoiding a choice removes the client/server parity problem (Canvas cannot reliably encode AVIF).

- **Storing the uploaded original as-is, or a lossless-WebP main, rather than a downscaled lossy WebP:** rejected — the Drop Zone exists to make uploads small and fast, and re-encoding to WebP shrinks each payload below the original at the same dimensions. `width = original and quality ≤ 100` already provides a visually-lossless ceiling: measured over real high-detail photographs, q95 is indistinguishable from q100 (≈40–42 dB PSNR, SSIM > 0.99) at ~20–28 % smaller, so 95 is the recommended quality and 100 spends ~30 % more bytes for no visible gain. A lossless-WebP main is no free win either: the browser Canvas encoder is lossy-only, so the client could not produce one without uploading heavier intermediates, against the whole point of the Drop Zone.

## Consequences

**Thumbnail width is split out of the contract** because, unlike max-width/quality, it is losslessly **re-derivable from the main image at any time**. It is therefore a *changeable* setting, not frozen at establishment: it is supplied by the `kntnt_photo_drop_thumbnail_width` filter (default `640`, may return an array, `[]`/`0` = no thumbnail), recorded in the descriptor, and changing it means re-running `wp kntnt-photo-drop collection doctor <slug> --repair --force` to regenerate thumbnails. There is **no UI field and no `create` flag** for it. Graininess is impossible because the gallery's `srcset` always keeps the main image as a candidate, so the browser upgrades to the main whenever a thumbnail would be too small.
