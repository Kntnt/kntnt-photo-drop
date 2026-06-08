# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Photo Drop Zone** block — a capability-gated front-end bulk uploader that downscales, converts to WebP, and compresses images in the browser (FilePond + the Canvas API) before uploading them one at a time, with a "Select folder" control that preserves folder structure and a warning when a folder is dragged onto the zone.
- **Photo Gallery** block — a public, server-rendered gallery of a chosen collection with responsive `srcset`, two layout modes (uniform grid and justified rows), configurable captions (filename or path breadcrumb, with overlay positioning), and an accessible lightbox built on the WordPress Interactivity API with a no-JavaScript fallback.
- **Collections** stored on disk outside the Media Library, under the uploads directory, with the filesystem as the single source of truth — no database rows. Collections are discovered by scanning for a `collection.json` descriptor.
- An immutable per-collection **output contract** (maximum width and quality; the stored format is always WebP), re-enforced on the server for every upload so every image inside a collection is conforming by construction.
- A **collection-lifecycle admin page** (under *Media*) to create, rename, and delete collections, with an explicit warning that a collection's contract is irreversible.
- A **REST upload endpoint** (`/wp-json/kntnt-photo-drop/v1/...`) gated by both a nonce and the `upload_files` capability, which re-applies the output contract server-side.
- **WP-CLI** commands `wp kntnt-photo-drop collection {create,update,delete,doctor}` and `wp kntnt-photo-drop image {import,delete}`.
- A **doctor** command that reconciles thumbnails and per-folder indexes to the main images, reports contract-violating and foreign files, and never alters main images or deletes foreign files.
- Per-folder thumbnail indexes that self-heal from the directory's modification time, so a large upload batch causes no write contention.
- A **GitHub-Releases auto-updater** that installs new versions from the published release ZIP.
- Public filters: `kntnt_photo_drop_root`, `kntnt_photo_drop_thumbnail_width`, `kntnt_photo_drop_default_max_width`, `kntnt_photo_drop_default_quality`, `kntnt_photo_drop_upload_capability`, `kntnt_photo_drop_manage_capability`, and `kntnt_photo_drop_list_capability`.
