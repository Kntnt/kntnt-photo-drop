# Glossary — kntnt-photo-drop

The ubiquitous language for this project. Terms only — no implementation details, no decisions. This file is a glossary and nothing else.

**Collection** — A named, self-contained set of images managed by the plugin independently of the WordPress Media Library, with a single fixed set of output rules that applies to every image in it.

**Output contract** — The fixed, *lossy* rules a collection applies to every main image at ingestion: the **upload width** (a maximum width; may be unset, meaning the source's own dimensions) and the **upload quality**. The stored format is always WebP (not a choice); inputs in other formats are accepted and converted. Fixed at establishment and irreversible, because the source bytes are discarded once the main image is encoded.

**Derived settings** — A collection's re-derivable rendition knobs that are *not* part of the immutable output contract: the **full-image width** and **full-image quality**, and the **thumbnail width** and **thumbnail quality**. Changeable after establishment; changing one regenerates the affected derived artifacts (via the doctor), because each is losslessly re-derivable from the main image.

**Descriptor** — The stored record of a collection's output contract, its display name, and its derived settings.

**Slug** — A collection's identity: its directory name relative to the plugin's uploads root. The stable reference a block stores to point at a collection.

**Display name** — The human-readable name of a collection, kept in its descriptor, distinct from the slug.

**Establishment** — The moment a collection's output contract is first fixed. After establishment the contract is treated as immutable.

**Main image** — The primary, highest-fidelity stored rendition of an image, bounded by the collection's upload width. The unit of truth for whether an image exists, and the rendition served for download and for copying into the Media Library. (Its settings are labelled "upload width/quality" in the UI, because it is what the Drop Zone produces.)

**Full image** — The mid-size rendition derived from the main image, bounded by the collection's full-image width, shown in the lightbox and the slideshow. Re-derivable; when the main image is no wider than the full-image width, the main image serves this role directly.

**Thumbnail** (also **variant**) — The small rendition derived from the full image (or, when there is no separate full image, from the main image), bounded by the collection's thumbnail width, shown in the gallery grid. Re-derivable; the browser may still upgrade to the full or main image via srcset.

**Derived artifact** — Anything generated from a main image: its full image, its thumbnail, and its index entry. Always slaved to the main image — created from it, removed when it is gone.

**Index** — A per-folder record of the images present in that folder and their metadata, used to present the gallery without inspecting each image file. A regenerable cache, not the source of truth; stored hidden alongside the thumbnails.

**Path components** — A collection's template for the sub-path under which Drop Zone uploads are placed: a `/`-separated string (separator is always `/`, leading/trailing ones stripped) expanded server-side at upload time and prepended before each uploaded file's own source-relative path. Supports the placeholders `%year%`, `%month%`, `%day%` (the upload date in the site's timezone) and `%uploader%` (the uploader's nicename). A *mutable* collection property (default `%year%/%month%/%day%/%uploader%`); it governs the Drop Zone path only — CLI import writes to the literal path it is given.

**Uploader folder** — The path segment produced by the `%uploader%` placeholder: a folder named after an individual uploader (their nicename) that groups everything they contribute through the Drop Zone. No longer a separate on/off property — it exists exactly when `%uploader%` appears in the collection's path components.

**Drop Zone** — The block that presents a front-end uploader bound to one existing collection. A consumer of collections: it selects one, and never creates or reconfigures one.

**Gallery** — The block that renders a public, browsable view of a collection, including a lightbox.

**Overlay** — A visual element layered on top of a gallery image. Four exist: **breadcrumbs**, the **download** icon, the **add-to-media** icon, and the **trash** icon. Each overlay has a single configuration — one nine-point position and one appearance — applied identically wherever it shows. A per-overlay visibility selector chooses where it appears: nowhere, on the gallery thumbnail, on the full image (in the lightbox), or both. The slideshow never shows overlays, and the full-image surface requires the lightbox to be enabled.

**Breadcrumbs** — The overlay that replaces the former caption: the image's humanised folder path, the first crumb being the collection's display name, shown on its own line. Replaces the earlier caption concept entirely.

**Add-to-media** — The overlay (and its action) that copies an image's main rendition into the WordPress Media Library as an ordinary, independent attachment. A copy, never a link: the collection image and the Media Library item then live separate lives.

**Trash** — The overlay (and its action) that permanently deletes an image — its main image and every derived artifact — from a collection. There is no recycle bin; an inline confirmation is the only safety.

**Slideshow** — A visitor-started, automatically advancing fullscreen playback of a Gallery's view — the same images in the same order the gallery shows — looping endlessly until the visitor ends it and returns to the gallery. At each cycle boundary it re-syncs its image set to the Gallery's current view, so images added or removed during playback appear or disappear on the next cycle.

**Cycle** — One full pass through the Slideshow's image set. The cycle boundary — the moment the last image has stood its time and playback restarts from the first — is when the Slideshow re-syncs to the Gallery's current view.

**Import** — Bringing external image files into an *existing* collection, optimising each to that collection's output contract at the point of entry. A pure consumer: it never creates or reconfigures a collection.

**Doctor** — The diagnostic that inspects a collection and reports inconsistencies. In its acting mode it reconciles derived artifacts to the main images.

**Repair** — Doctor's acting mode: it creates missing derived artifacts and removes orphaned ones. It never alters main images and never deletes foreign files.

**Conforming** — Said of an image that matches its collection's output contract.

**Foreign file** — A file inside a collection that is none of: a main image, a thumbnail, an index, or a descriptor.

**Ignore list** — The set of operating-system junk filenames that Doctor skips when reporting foreign files.
