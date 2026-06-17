# Three-rendition model: main, full, thumbnail

A collection stores **three renditions** of each image instead of two. The **main image** (bounded by the *upload* width, encoded at the *upload* quality) is the highest-fidelity stored rendition and the unit of truth — served for download and for copying into the Media Library. The **full image** (full width/quality) is a re-derived mid-size rendition shown in the lightbox and slideshow. The **thumbnail** (thumbnail width/quality) is the small rendition shown in the gallery grid. Each tier is derived from the one above it and **skipped when the source is no wider** (`main → full → thumbnail`), so a small image collapses to a single file that serves every role. This amends ADR-0002 (immutability) and ADR-0003 (on-disk layout).

The point of the middle tier is to separate the download/archival rendition from the display renditions: the gallery never has to ship a multi-megabyte main image to render a tile, while a high-fidelity copy stays available on demand.

## What is immutable, what is re-derivable

The **immutable output contract shrinks to upload width + upload quality** — the only irreversible pair, because the source bytes are discarded once the main image is encoded. This is ADR-0002's principle, narrowed. **Full and thumbnail width/quality become re-derivable settings**: losslessly regenerable from the main image, so they are admin-editable after establishment, and changing one regenerates the affected derived artifacts. This generalises ADR-0002's treatment of thumbnail width (previously filter-only, with no UI field) into two admin-editable rendition pairs.

## Considered Options

- **Keep two tiers (main + thumbnail) and serve the main in the lightbox:** rejected — the main may be unbounded (upload width may be empty, meaning the source's own dimensions), so serving it for display ships far more bytes than a viewer needs. A bounded full tier is the display ceiling.
- **Freeze all six values at establishment:** rejected — only the upload pair is irreversible. Freezing the re-derivable pairs would forbid harmless, losslessly-regenerable changes for no reason.

## Consequences

- **Descriptor shape** (amends ADR-0003): `{ schema, name, maxWidth, quality, thumbnailWidths }` becomes `{ schema, name, uploadWidth, uploadQuality, fullWidth, fullQuality, thumbnailWidth, thumbnailQuality, pathComponents }` — the multi-width thumbnail array is dropped (a single thumbnail width). The per-folder index is unchanged (`{ file, width, height }` = the main's dimensions); which derived files exist is *computed* from the main width and the descriptor (a full exists iff `main > fullWidth`; a thumbnail exists iff the full rendition is wider than `thumbnailWidth`), so the doctor reconciles from the same inputs without storing more.
- **On disk:** the full image is "just another width" under the existing hidden corral — `.kntnt-thumbnails/<fullWidth>/<name>.webp` alongside `.kntnt-thumbnails/<thumbnailWidth>/…`. Degenerate width orderings collapse a tier rather than colliding: equal full and thumbnail widths produce one file (generated at the larger tier's quality, so the thumbnail-quality value is simply unused there); a full width at or above the upload width produces no separate full, and the main serves that role.
- **srcset becomes `{ thumbnail, full }`, and the full image is the display ceiling** (amends ADR-0002's "the main is always a srcset candidate" grain-defence). The main is download-only and is excluded from srcset whenever it exceeds the full width, because an unbounded main pulled into a grid tile would defeat the entire point of the tiers. When the main is no wider than the full width it *is* the full rendition and stays a candidate, so nothing is upscaled there; only viewports wider than the full width upscale the full image slightly, which is acceptable for a photo gallery.
- **Editing a re-derivable setting** takes effect by **regenerate-then-flip**: a browser-driven batched regeneration writes the new-width derived files while the gallery keeps serving the old ones, and the descriptor's active widths flip only on success (the old-width files are then pruned). Interruption is safe — the descriptor was never flipped, so the gallery keeps serving the old renditions and a re-run reconciles.

## Amendment (#71): unset re-derivable settings

The three re-derivable settings — **full width, full quality, and thumbnail width** — may be stored **unset (`null`)** on disk, distinct from any concrete value, meaning *collapse to the tier above*:

- **Unset full width** → no separate full image; the **main serves the full role**. This is the explicit, user-chosen form of the existing "a full width at or above the upload width produces no separate full" rule above.
- **Unset thumbnail width** → the **effective full width**.
- **Unset full quality** → the **upload quality**.

The upload pair and the thumbnail quality remain always-concrete (the upload pair is the immutable contract — a blank upload quality resolves to the maximum 100 per #70; a blank thumbnail quality takes its filter default). The descriptor JSON persists an unset value as `null` — distinct from a number and from a malformed value — and **`Descriptor::effective_renditions()` is the single authority** that resolves the collapse, read by every deriving consumer (ingestor, doctor, regenerator, gallery `srcset`, REST regenerate, CLI), so the doctor and regenerator reconcile from the same inputs. This generalises "skipped when the source is no wider" and "the main serves that role" from a width-ordering accident into an explicit, settable intent; it does not touch the immutability boundary. Per the pre-1.0 policy there is no migration — a descriptor written before this change simply carries concrete values.
