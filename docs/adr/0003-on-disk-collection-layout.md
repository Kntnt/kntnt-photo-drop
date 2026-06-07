# On-disk collection layout

A collection's **identity is its directory slug** (relative to the uploads root); the human display name lives in the descriptor. A main image is stored as `<original-filename>.webp` (the original name is preserved and `.webp` appended, except an already-`.webp` input is not doubled), which is collision-free by construction on the case-sensitive Linux server and reversible to the original name. Every content folder holds its visible main `*.webp` images plus one **visible `collection.json`** (descriptor, at the collection root only) and one **hidden `.kntnt-thumbnails/`** directory that corrals all *regenerable* artifacts — the thumbnails (`.kntnt-thumbnails/<width>/<name>.webp`) and that folder's `index.json`.

## Considered Options

- **Identity via opaque UUID in the descriptor:** rejected — a slug is faithful to "the filesystem is the source of truth" (identity is what you can `ls`), has no duplicate-id failure mode when a folder is copied, and needs no id-resolution layer. The cost (renaming a folder dangles blocks that referenced the old slug) is acceptable, since a rename is a deliberate admin act; renaming is done with `mv`, not a CLI command.
- **Thumbnails suffixed beside mains** (`foto.thumb-640.webp`): rejected — a hidden namespaced subdirectory gives collision-proof classification for `doctor`, corrals the clutter, and scales to N widths for free.
- **Visible `index.json` / `manifest.json` per folder:** rejected — the per-folder index is a *regenerable cache* like the thumbnails, so it does not need the visibility protection that the irreplaceable `collection.json` needs, and a visible `index.json` collides with web conventions and with user content in mirrored folders. Renamed "manifest" → "index" to match the glossary.

## Consequences

The index is **validated by the content folder's directory mtime** (`dirMtime`): one `stat` tells whether anything was added/removed/renamed, so the gallery trusts the cache or regenerates it without re-reading every image's dimensions. The index stores each image's `file`, `width`, `height` (sorted ascending), plus the folder's `subdirs`. `collection.json` stores `schema`, `name`, `maxWidth`, `quality`, `thumbnailWidths`. The `.kntnt-thumbnails` directory is dot-hidden but namespaced so a user's own `.thumbnails` (digiKam/gThumb caches) is treated as a foreign file, not ours.
