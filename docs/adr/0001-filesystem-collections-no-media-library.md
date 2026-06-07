# Filesystem collections, no Media Library backend

The plugin stores every collection image as files on disk under `wp_upload_dir()/kntnt-photo-drop/<slug>/`, outside the WordPress Media Library, with no database rows. We deliberately rejected a Media-Library-backed storage mode because the Media Library is an openly-writable shared store that cannot enforce a collection's output contract — an attachment can be replaced with a non-conforming file at any time — which would break the "conforming by construction" invariant. The filesystem is the single source of truth; a collection copied in from another site appears automatically via the discovery scan, and there is no registry to keep in sync.

## Considered Options

- **Media Library mode** (images as attachments): rejected — no enforcement boundary, breaks conforming-by-construction, contradicts the glossary's definition of a Collection, and would double the surface area (two backends for discovery, doctor, index, gallery).
- **Adopt NextGEN / Piwigo / Lychee**: rejected as too heavy or more than wanted inside WordPress (original settled decision #1).

## Consequences

Images are served **directly by URL** (no PHP proxy), which is what makes the server-rendered gallery, `srcset`, and `loading="lazy"` work — but it also means **collections are public-by-path**: anyone who knows the path can fetch a file, even one not yet shown in a gallery. This matches the "public gallery" intent. Directory listing is disabled so paths cannot be enumerated; true access control would require a proxy and is out of scope.
