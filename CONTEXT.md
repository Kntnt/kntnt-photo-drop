# Glossary — kntnt-photo-drop

The ubiquitous language for this project. Terms only — no implementation details, no decisions. This file is a glossary and nothing else.

**Collection** — A named, self-contained set of images managed by the plugin independently of the WordPress Media Library, with a single fixed set of output rules that applies to every image in it.

**Output contract** — The fixed, *lossy* rules a collection applies to every main image at ingestion: maximum width and compression quality. The stored format is always WebP (not a choice); inputs in other formats are accepted and converted. Fixed at establishment and irreversible, because the original is not kept.

**Thumbnail width** — The width(s) at which thumbnails are derived. Unlike the output contract it is re-derivable from the main image at any time, so it is a changeable setting (changing it regenerates thumbnails) rather than something frozen at establishment.

**Descriptor** — The stored record of a collection's output contract, its display name, and the thumbnail width(s) in use.

**Slug** — A collection's identity: its directory name relative to the plugin's uploads root. The stable reference a block stores to point at a collection.

**Display name** — The human-readable name of a collection, kept in its descriptor, distinct from the slug.

**Establishment** — The moment a collection's output contract is first fixed. After establishment the contract is treated as immutable.

**Main image** — The primary, full-size stored rendition of an image, bounded by the collection's maximum width. The unit of truth for whether an image exists.

**Thumbnail** (also **variant**) — A smaller rendition derived from a main image, used for grid display and for responsive selection by the browser.

**Derived artifact** — Anything generated from a main image: its thumbnail(s) and its index entry. Always slaved to the main image — created from it, removed when it is gone.

**Index** — A per-folder record of the images present in that folder and their metadata, used to present the gallery without inspecting each image file. A regenerable cache, not the source of truth; stored hidden alongside the thumbnails.

**Uploader folder** — A top-level folder within a collection that groups everything an individual uploader contributes through the Drop Zone, named after that uploader. Whether a collection uses uploader folders is a property of the collection.

**Drop Zone** — The block that presents a front-end uploader bound to one existing collection. A consumer of collections: it selects one, and never creates or reconfigures one.

**Gallery** — The block that renders a public, browsable view of a collection, including a lightbox.

**Slideshow** — A visitor-started, automatically advancing fullscreen playback of a Gallery's view — the same images in the same order the gallery shows — looping endlessly until the visitor ends it and returns to the gallery.

**Import** — Bringing external image files into an *existing* collection, optimising each to that collection's output contract at the point of entry. A pure consumer: it never creates or reconfigures a collection.

**Doctor** — The diagnostic that inspects a collection and reports inconsistencies. In its acting mode it reconciles derived artifacts to the main images.

**Repair** — Doctor's acting mode: it creates missing derived artifacts and removes orphaned ones. It never alters main images and never deletes foreign files.

**Conforming** — Said of an image that matches its collection's output contract.

**Foreign file** — A file inside a collection that is none of: a main image, a thumbnail, an index, or a descriptor.

**Ignore list** — The set of operating-system junk filenames that Doctor skips when reporting foreign files.
