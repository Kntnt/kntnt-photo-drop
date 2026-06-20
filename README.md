# kntnt-photo-drop

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4.svg)](https://www.php.net/)
[![Release](https://img.shields.io/github/v/release/Kntnt/kntnt-photo-drop?sort=semver)](https://github.com/Kntnt/kntnt-photo-drop/releases/latest)

A WordPress plugin with two Gutenberg blocks: a front-end **Photo Drop Zone** that optimises images in the browser and uploads them in bulk, and a public **Photo Drop Gallery** that renders those images with a lightbox. It lets a field photographer drag in hundreds of images at once, and lets anyone browse them later.

## Description

`kntnt-photo-drop` solves one job end to end: getting a large set of photos onto a WordPress site quickly, in a sensible web format, and presenting them well. The Photo Drop Zone block is a capability-gated uploader you place on a page; a logged-in user with upload rights drags in single images, many images, or a whole folder, and each is downscaled, converted to WebP, and compressed in the browser before it ever leaves the machine. The Photo Drop Gallery block renders a chosen set of those images as a server-rendered grid or justified-rows layout, with an accessible lightbox.

The plugin does not use the WordPress Media Library. Images live as files on disk, in **collections** under the site's uploads directory, and the filesystem is the single source of truth: there are no database rows for collection images. A collection carries a fixed set of output rules, so everything inside it is conforming by construction. Because the images are plain files, they are served directly by URL, which is what makes the gallery, responsive `srcset`, and native lazy-loading work without a PHP proxy.

### Key Features

- **Two blocks.** Photo Drop Zone (front-end bulk uploader) and Photo Drop Gallery (public gallery with a lightbox), both server-rendered and registered under the *Kntnt* block category.
- **In-browser optimisation.** Images are downscaled and re-encoded to WebP in the browser (via the Canvas API) before upload, so a several-hundred-image batch transfers a fraction of the original bytes.
- **Collections on disk.** Each collection is a directory under the uploads root; discovery is a directory scan, so a collection copied in from another site appears automatically and a deleted directory disappears, with no registry to keep in sync.
- **Three renditions per image.** Each image is stored as up to three renditions: a high-fidelity **main image** (the download/archival copy), a mid-size **full image** shown in the lightbox and slideshow, and a small **thumbnail** shown in the grid. Each tier is derived from the one above it and skipped when the source is no wider, so a small image collapses to a single file.
- **An immutable upload contract, re-derivable display sizes.** A collection fixes its **upload width** and **upload quality** once, at creation — the only irreversible pair, because the original bytes are discarded once the main image is encoded. The stored format is always WebP. The **full** and **thumbnail** width/quality are *not* frozen: they are losslessly re-derivable from the main image, so they stay editable on the admin page and regenerate the affected renditions when changed.
- **Folder-aware uploads with a placement template.** Dropping a folder and choosing one through the *Select a folder* control behave identically: the folder is walked recursively and its sub-directory structure is preserved on the server. A mutable **path-components template** (default `%year%/%month%/%day%/%uploader%`) then prefixes every Drop Zone upload, expanded server-side in the site timezone, so uploads are organised by date and contributor without collisions.
- **Two gallery layouts with an overlay system.** A uniform grid (core Grid layout) or bespoke justified rows, an Interactivity-API lightbox that degrades to a plain link without JavaScript, and four configurable image overlays — **breadcrumbs**, **download**, **add-to-media**, and **trash** — each with its own visibility, nine-point position, and shared appearance.
- **Fullscreen slideshow.** An optional, endlessly looping fullscreen playback of the gallery with a configurable per-image time and a dissolve transition, started from a built-in button or any custom element on the page, and ended with Escape or a close button. Images uploaded while the slideshow plays join the rotation on the next loop — ideal for a photo frame rolling during an ongoing event.
- **A complete WP-CLI surface.** Create, update, delete, and `doctor` collections, and import or delete images, from the command line.
- **First-party by design.** No third-party request is made when a visitor views a page. The only external call is the admin-side update check against GitHub.

### The problem

Bulk-uploading photos to WordPress is awkward. The Media Library accepts originals at full size, so a folder of camera images consumes large amounts of disk and bandwidth unless each file is resized by hand first. There is no built-in way to enforce a consistent maximum size and format across a set of images, and no simple way to drop in a whole folder and have the structure preserved. Presenting the result as a tidy, responsive gallery then means reaching for a heavier gallery plugin.

### How this plugin helps

A collection fixes its own upload rules – an upload width and an upload quality for the main image – and every image that enters it, whether through the Drop Zone or through `image import`, is made to conform at the point of entry, then has its full image and thumbnail derived from it. The browser does the heavy resizing and WebP encoding before upload, so the transfer is small; the server re-applies the same rules on arrival, so a file cannot enter non-conforming even if it bypasses the browser. Because the images are ordinary files served by URL, the Gallery block can render them with responsive `srcset` and lazy-loading straight from disk, and you compose pages out of the two blocks like any other Gutenberg content.

### Limitations

- **Collections are public-by-path.** Images are served directly as files, so anyone who knows a file's URL can fetch it, including an image not yet shown in any gallery. Directory listing is disabled so paths cannot be enumerated, but this is a public-gallery model, not access-controlled storage. True access control would require a PHP proxy and is out of scope.
- **The upload contract is irreversible.** Upload width and upload quality are fixed when a collection is created and cannot be changed afterwards. Raising the upload width later cannot enlarge images that were already downscaled, because the original is not kept. (The full and thumbnail sizes are re-derivable and *can* be changed afterwards.)
- **No EXIF or IPTC metadata survives.** Re-encoding through the Canvas API strips all embedded metadata, so there is no capture date or embedded caption to draw on; the gallery's breadcrumbs overlay is derived from the folder path only.
- **The gallery does not navigate folders.** A Gallery block renders all images under a start path as one flattened set. To present folders separately, place several blocks and compose them with the page builder.

## Requirements

- **WordPress** 7.0 or later. The blocks use current block-editor APIs (the `react-jsx-runtime` script handle and stabilised block-support keys); no support for older WordPress is carried.
- **PHP** 8.4 or later. (The plugin checks the PHP version on load and deactivates itself with an admin notice on an older runtime.)
- A server image library – **GD** or **Imagick** with WebP support – for the server-side re-encoding that backs uploads and `image import`.

The plugin is not on the WordPress.org directory; it is distributed through GitHub Releases and updates itself from there.

## Installation

1. Download the latest release ZIP: [`kntnt-photo-drop.zip`](https://github.com/Kntnt/kntnt-photo-drop/releases/latest/download/kntnt-photo-drop.zip).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and install it.
3. Activate **Kntnt Photo Drop**.

On activation the plugin creates its uploads root (`wp-content/uploads/kntnt-photo-drop/` by default) and seeds it so the directory cannot be listed. No further setup is required before you create your first collection.

Once installed, the plugin keeps itself up to date. It checks `https://api.github.com/repos/Kntnt/kntnt-photo-drop/releases/latest` during WordPress's normal update cycle and offers any newer release in the admin UI, just like a plugin from the directory. This check runs admin-side only; it is the single external request the plugin makes.

## Usage

The plugin has three surfaces: an admin page where collections are created and managed, the Photo Drop Zone block for uploading, and the Photo Drop Gallery block for displaying.

### Create a collection

Collections are created and managed on a dedicated admin page at **Media → Photo Drop** (gated by `manage_options`). Blocks never create or reconfigure a collection; they only select one.

To create a collection, open the page and choose **Create collection**. You provide:

- a **display name** (optional; defaults to a humanised slug);
- a **slug** (optional; lowercase, URL-safe, and unique) – the placeholder shows the unique default derived from the display name, and a blank field uses it; a *typed* collision is an error. It becomes the directory name and the durable identity a block stores to point at the collection;
- **path components** (optional; defaults to `%year%/%month%/%day%/%uploader%`) – the template that prefixes every Drop Zone upload, expanded server-side at upload time (`%year%`/`%month%`/`%day%` from the upload date in the site timezone, `%uploader%` from the uploader's nicename);
- then the six rendition fields: **upload width** (blank = the source's own dimensions) ⚠️, **upload quality** (default 95) ⚠️, **full width** (default 1920), **full quality** (default 85), **thumbnail width** (default 640), and **thumbnail quality** (default 75), each pre-filled from its `kntnt_photo_drop_default_*` filter (see [Extending](#extending)).

There is no format field – the format is always WebP. The slug and the **upload** width/quality fields carry a ⚠️ marker: they are set once and cannot be changed after the collection is created. The full and thumbnail fields carry a mild note that changing them later regenerates existing images.

> [!WARNING]
> Upload width and upload quality fix the collection's **immutable output contract**, and that pair **cannot be changed afterwards**. Every image is downscaled and re-encoded to WebP as it enters the collection, and the original is never kept, so raising the upload width later cannot recover detail that was already discarded. Choose these two values deliberately. The display name, the path-components template, and the re-derivable full/thumbnail sizes all remain editable after creation; the slug and the upload contract do not.

The list view shows every discovered collection – name, slug, upload width (or *Original*), full and thumbnail widths, format (WebP), and image count – with always-visible **Edit** and **Delete** buttons on each row. Edit changes the display name, the path-components template, and the re-derivable full/thumbnail settings; the slug and upload contract are shown read-only. Deleting a collection removes its directory and everything under it; blocks that referenced its slug then render nothing for visitors and an editor-only notice for logged-in users.

### Place a Photo Drop Zone

Add the **Photo Drop Zone** block to a page or post. In the block inspector, pick the collection to upload into; the inspector also shows that collection's three rendition tiers (upload width — or *Original* — and quality, full width and quality, thumbnail width and quality), the format (WebP), and the path-components template, all read-only, so there is nothing on the block that could conflict with the contract.

The block's visible surface – a heading, an explanatory text and the two upload controls, an **Add photos** button and a quieter **Select a folder** link-style button – is ordinary editable inner blocks, so a site builder can relabel, restyle, or rearrange it freely; the controls stay wired to the uploader through their anchor-token hrefs (`#kntnt-drop-zone-files`, `#kntnt-drop-zone-folder`).

On the front end, the block renders its uploader **only** for users who hold the `upload_files` capability – for anyone else it renders nothing, and no upload nonce is emitted. A capable user can:

- drag in single images or many images at once, or click the zone to browse;
- drag a whole folder onto the zone – it is walked recursively and every image uploads with its sub-directory structure preserved;
- use the **Select a folder** control to pick a folder through the browser's folder picker – the result is identical to dropping it.

The server places every upload under the collection's **path-components template** (default `%year%/%month%/%day%/%uploader%`), expanded server-side and prepended ahead of the upload's own relative path, so files are organised by date and contributor. Each image is downscaled and encoded to WebP in the browser, then uploaded one file per request. The browser-side step is a bandwidth optimisation only: the server re-applies the collection's upload contract to every file on arrival (and derives the full image and thumbnail), so nothing non-conforming can enter, even a file posted directly to the endpoint.

As the batch runs, the block shows an **aggregate progress bar** (files finished ÷ total, as *N / total*) with a **Cancel** button that aborts in-flight uploads and stops the queue while leaving already-uploaded files in place. On completion or cancel it shows a **three-bucket summary** — *Uploaded* as a count, *Skipped* and *Failed* listed by filename — with a **Retry failed** button that re-queues only the failures. A clash with an existing path is skipped by default.

### Place a Photo Drop Gallery

Add the **Photo Drop Gallery** block where you want the images shown, and select a collection in the inspector. The block renders all images under a start path as one flattened gallery (or, with *This folder only*, just that folder). Key settings:

- **Layout** – a uniform **grid** (mode A, built on core's Grid layout, with a minimum column width and per-image aspect ratio for zero layout shift) or bespoke **justified rows** (mode B, with a target row height). The inter-item gap is the core **Block spacing** control.
- **Ordering** – ascending or descending. Images order by a **pre-order tree traversal**: a folder's own images first, then each subfolder fully explored before the next, with a natural sort within each level. Descending reverses the sort within each level while keeping the own-images-before-subfolders structure.
- **Empty-gallery message** – the text shown to every visitor when the chosen collection has no images yet (for example before a photographer has uploaded). It defaults to *"There are currently no images in the gallery. Please try again later."* and is freely editable; leave it empty for the default. This applies only to a real collection that happens to be empty — a block pointing at no collection, or at a deleted one, still shows nothing to visitors and an editor-only notice to logged-in editors.
- **Lightbox** – on by default. It opens the image in a modal viewer built with the WordPress Interactivity API (keyboard, swipe, focus trap, neighbour preload), governs the base tile click, and gates the *Full* overlay surface. Each thumbnail is always a plain link to the full image, so the gallery still works without JavaScript.
- **Overlays** – four image overlays, each off by default and each with its own **visibility** (*Off* / *Thumbnail* / *Full* / *Both*, where *Full* means inside the lightbox and requires the lightbox on) and **nine-point position**. They share one appearance: foreground and background from the core **Colour** panel, the breadcrumb font from **Typography**, a single **Icon size** for the three action icons, and each image's border and shadow from **Border** and **Shadow** — all projected onto the image sub-elements, never the wrapper.
  - **Breadcrumbs** – the image's humanised folder path on its own line, with the collection's display name as the first crumb; a *hide first N crumbs* count and a custom separator are available, and overflow keeps the tail with a leading ellipsis. (This replaces the former caption.)
  - **Download** – a download icon that saves the **main image** programmatically (no tab or window), with an `<a download>` no-JS fallback. A click on the image outside an icon never downloads.
  - **Add-to-media** – rendered only for users who can upload; after an inline confirm it copies the main image into the Media Library as an independent attachment (a copy, never a link).
  - **Trash** – rendered only for users who can delete others' posts; after an inline-popover confirm it **permanently** deletes the image (main plus derived artifacts) and removes the tile live.

  Icons sharing a position cluster into a fixed-order row; breadcrumbs occupy a whole band, which the editor keeps clear of the icon positions. The slideshow shows no overlays.
- **Slideshow** – off by default. When on, a visitor can start a fullscreen, endlessly looping playback of the gallery: each image stands for a configurable number of seconds, dissolves to the next, and wraps around until the visitor exits with Escape or the close button. The trigger is either a quiet built-in button above the gallery (with an editable label) or any element you place yourself anywhere on the page carrying `data-kntnt-photo-drop-slideshow` (its value targets a gallery by its HTML anchor; without a value it targets the page's first slideshow-enabled gallery). The slideshow holds a screen wake lock while playing, respects reduced-motion preferences with a hard cut, and never advances to an image that has not finished loading. At the start of every new loop it quietly re-syncs with the gallery: images uploaded meanwhile join the rotation, deleted images leave it, and if the gallery has been emptied the playback ends — no page reload needed. The gallery grid itself follows the normal page lifecycle and updates on reload.

The gallery's read path is pure server-rendered output plus its view module, with no REST or third-party request at view time. The two write overlays — add-to-media and trash — are the exception: they post to a nonce- and capability-gated REST surface that the block emits (along with its nonce) only for capable users.

### Privacy

The plugin makes no third-party request when a visitor views a page. The drop surface, the Interactivity API, and the WebP encoding all run as bundled, local assets, and the images are first-party files served from your own site – there is no external embed to consent to. The only outbound request the plugin makes is the admin-side update check against the GitHub Releases API, which never runs on a visitor-facing page.

## Frequently asked questions (FAQ)

#### Where are the images stored?

As files on disk under `wp-content/uploads/kntnt-photo-drop/<slug>/`, outside the Media Library, with no database rows. The directory is the source of truth; the descriptor (`collection.json`) and the per-folder index (`index.json`, a regenerable cache) live alongside the images.

#### Can I change a collection's upload width or quality after creating it?

No. Those two values are the immutable output contract. Because the main image is re-encoded on entry and the original is discarded, the upload pair cannot be changed. If you need different upload rules, create a new collection and import into it.

#### Can I change the full or thumbnail size?

Yes. The full and thumbnail width/quality are not part of the contract – they are losslessly re-derivable from the main image. Edit them on the collection's admin **Edit** page (or with `wp kntnt-photo-drop collection update <slug> --full-width=… --thumbnail-width=…`); the existing images regenerate to the new sizes.

#### What happens if I rename or move a collection's directory?

The filesystem is the truth, so a renamed directory is simply a new collection identity. Any block that stored the old slug then dangles – it renders nothing for visitors and an editor-only notice for logged-in users. This is expected behaviour.

#### Who can upload through the Drop Zone?

Any user with the `upload_files` capability (Author and above by default). The capability is overridable with the `kntnt_photo_drop_upload_capability` filter. The block emits its uploader and nonce only for capable users.

## Questions, bugs, and feature requests

Have a usage question or something to discuss? Please use [Discussions](https://github.com/Kntnt/kntnt-photo-drop/discussions).

Found a bug or want to request a feature? Please [open an issue](https://github.com/Kntnt/kntnt-photo-drop/issues). Search the existing issues first to avoid duplicates.

## Command-line interface (WP-CLI)

The plugin registers two WP-CLI command groups for headless and automated use. They run in a trusted context with no capability check.

### `collection`

```
wp kntnt-photo-drop collection create <slug> [--name=<name>] [--path-components=<template>] [--upload-width=<pixels>] [--upload-quality=<1-100>] [--full-width=<pixels>] [--full-quality=<1-100>] [--thumbnail-width=<pixels>] [--thumbnail-quality=<1-100>]
wp kntnt-photo-drop collection update <slug> [--name=<name>] [--path-components=<template>] [--full-width=<pixels>] [--full-quality=<1-100>] [--thumbnail-width=<pixels>] [--thumbnail-quality=<1-100>]
wp kntnt-photo-drop collection delete <slug> [--yes]
wp kntnt-photo-drop collection doctor <slug> [--repair] [--force] [--ignore=<glob>] [--show-ignored]
```

- **`create`** establishes a collection. `slug` is positional. The **upload** flags fix the irreversible contract: `--upload-width` defaults to empty (the source's own dimensions) and `--upload-quality` defaults to 95. The re-derivable **full** flags (`--full-width` 1920, `--full-quality` 85) and **thumbnail** flags (`--thumbnail-width` 640, `--thumbnail-quality` 75), plus `--path-components` (default `%year%/%month%/%day%/%uploader%`) and `--name` (default a humanised slug), carry the same defaults the admin form pre-fills. There is no format flag – the format is always WebP.
- **`update`** mutates `--name`, `--path-components`, and the re-derivable full/thumbnail flags (regenerating derived renditions when those change). It **rejects** `--upload-width` and `--upload-quality`, which are fixed at establishment.
- **`delete`** removes the collection directory and everything under it. It prompts unless `--yes` is given.
- **`doctor`** inspects a collection and reconciles its derived artifacts to the main images. It is **report-only by default** (the report is the dry run). `--repair` acts: it creates missing full images and thumbnails, refreshes the index, and removes orphaned derived artifacts. `--repair --force` re-derives everything – use it after a full- or thumbnail-size change. The doctor never alters a main image and never deletes a foreign file. Foreign files are reported, except a built-in ignore list of OS junk (`.DS_Store`, `._*`, `.Spotlight-V100`, `.Trashes`, `.fseventsd`, `Thumbs.db`, `desktop.ini`); `--ignore=<glob>` extends that list and `--show-ignored` reveals what was skipped.

### `image`

```
wp kntnt-photo-drop image import <slug> <source>... [--overwrite]
wp kntnt-photo-drop image delete <slug> <path> [--yes]
```

- **`import`** brings external files into an existing collection, optimising each to that collection's contract. It carries no contract flags (it is a pure consumer of the collection) and is idempotent – an existing target is skipped unless `--overwrite` is given.
- **`delete`** removes one main image and its derived artifacts (full image, thumbnail, index entry). `<path>` is the image's path relative to the collection root, given as either its stored name or its original name, and is confined to the collection root. It prompts unless `--yes` is given.

`doctor` and `import` present their per-file results as standard WP-CLI tables.

## Extending

The plugin exposes its behaviour through filters, all namespaced `kntnt_photo_drop_*`. Each is documented below with its default and effect.

### `kntnt_photo_drop_root`

```php
add_filter( 'kntnt_photo_drop_root', fn( string $root ): string => '/custom/path/kntnt-photo-drop' );
```

The absolute path to the uploads root that holds every collection. Default: `wp_upload_dir()['basedir'] . '/kntnt-photo-drop'`. The path must stay web-served (collections are served by URL), and on multisite `wp_upload_dir()` already yields a per-site basedir, so each site gets an isolated root.

### `kntnt_photo_drop_default_{upload,full,thumbnail}_{width,quality}`

```php
add_filter( 'kntnt_photo_drop_default_upload_width', fn() => 2560 );
add_filter( 'kntnt_photo_drop_default_upload_quality', fn() => 90 );
add_filter( 'kntnt_photo_drop_default_full_width', fn() => 2048 );
add_filter( 'kntnt_photo_drop_default_full_quality', fn() => 82 );
add_filter( 'kntnt_photo_drop_default_thumbnail_width', fn() => 480 );
add_filter( 'kntnt_photo_drop_default_thumbnail_quality', fn() => 70 );
```

Six filters, one per rendition field, that pre-fill the *Create collection* form. Defaults: upload width empty (the source's own dimensions) at quality `95`; full width `1920` at quality `85`; thumbnail width `640` at quality `75`. These are convenience defaults for the admin form only; they do not change any existing collection. The upload pair seeds the immutable contract, while the full and thumbnail pairs seed the re-derivable settings (which stay editable on the admin **Edit** page after creation).

### `kntnt_photo_drop_upload_capability`

```php
add_filter( 'kntnt_photo_drop_upload_capability', fn() => 'edit_posts' );
```

The capability a user must hold to upload through the Drop Zone block and its REST endpoint. Default: `upload_files`. The block renders its uploader and emits its nonce only for users who hold this capability.

### `kntnt_photo_drop_add_to_media_capability`

```php
add_filter( 'kntnt_photo_drop_add_to_media_capability', fn() => 'edit_posts' );
```

The capability a user must hold to copy a gallery image into the Media Library through the **add-to-media** overlay and its REST endpoint. Default: `upload_files`. The overlay and its nonce render only for users who hold this capability.

### `kntnt_photo_drop_delete_capability`

```php
add_filter( 'kntnt_photo_drop_delete_capability', fn() => 'manage_options' );
```

The capability a user must hold to permanently delete a gallery image through the **trash** overlay and its REST endpoint. Default: `delete_others_posts` (the closest core analog to deleting others' media). The overlay and its nonce render only for users who hold this capability.

### `kntnt_photo_drop_manage_capability`

```php
add_filter( 'kntnt_photo_drop_manage_capability', fn() => 'edit_pages' );
```

The capability a user must hold to reach the collection-management admin page. Default: `manage_options`.

### `kntnt_photo_drop_list_capability`

```php
add_filter( 'kntnt_photo_drop_list_capability', fn() => 'upload_files' );
```

The capability required to read the collection-list REST route (`GET /wp-json/kntnt-photo-drop/v1/collections`), which the block editor uses to populate its collection selectors. Default: `edit_posts`.

### `kntnt_photo_drop_editor_notice_capability`

```php
add_filter( 'kntnt_photo_drop_editor_notice_capability', fn() => 'manage_options' );
```

The capability a user must hold to see the Gallery block's editor-only notice when its collection reference is unset or dangling. The public never sees this notice; only a logged-in user with this capability does, so a broken reference prompts an editor to re-select a collection without ever leaking to visitors that a collection is gone. Default: `edit_posts`.

### `kntnt_photo_drop_max_input_megapixels`

```php
add_filter( 'kntnt_photo_drop_max_input_megapixels', fn() => 100 );
```

The largest source image, in megapixels, the server will decode during ingestion (`image import` and the REST upload). Sources declaring more pixels are rejected per-file before any decode, so a decompression bomb or an oversized original cannot exhaust PHP's memory and kill the request. Default: `50`.

## Development

### Build from source

```bash
git clone https://github.com/Kntnt/kntnt-photo-drop.git
cd kntnt-photo-drop
composer install        # PHP dependencies and the PSR-4 autoloader
npm install             # the block toolchain
npm run build           # compile src/blocks/** into build/blocks/**
```

`npm run start` watches the sources and rebuilds on change. The compiled `build/` directory is committed to the repository.

### Build the distribution ZIP locally

```bash
./build-zip.sh
```

This produces `dist/kntnt-photo-drop.zip` (the `dist/` directory is gitignored), containing the runtime artefacts only (PHP, the production Composer install, and the compiled `build/`) under a single top-level folder. It is a **build step only** — it never uploads anything — so it is safe to run for local inspection or to test the packaged plugin. The filename is deliberately version-less: the auto-updater selects the release asset by its `application/zip` content type, not by name, so a release published without this ZIP offers no installable package.

Cutting an actual release is a separate, deliberate act: push a `vX.Y.Z` tag and the CI release workflow runs the gates, builds this same ZIP, and attaches it to a **draft** GitHub Release for you to review and publish. See the release sequence in [`docs/updater.md`](docs/updater.md).

### Run tests

The quality gates, all of which must pass:

```bash
composer test           # PHP unit tests (Pest + Brain Monkey)
composer phpstan        # PHPStan at level max
composer phpcs          # PHP_CodeSniffer, WordPress standard
npm run lint:js         # ESLint via @wordpress/scripts
npm run lint:css        # Stylelint via @wordpress/scripts
npm run test:js         # Jest block-JS unit tests
```

Integration and end-to-end layers run against a real WordPress through [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (Docker):

```bash
npx wp-env start        # boots WordPress at http://localhost:8888 with the plugin mounted
```

The WP-CLI surface is then reachable via `npx wp-env run cli wp kntnt-photo-drop ...`, and the Playwright end-to-end tests drive the same instance.

### Technical documentation

The design and its rationale live under [`docs/`](docs/): the overall plan, the block behaviour, and the admin-page UX in [`docs/design.md`](docs/design.md) (each block's attribute schema is authoritative in its own `block.json` under `src/blocks/`), the testing strategy in [`agents.d/testing.md`](agents.d/testing.md), the release-and-update mechanism in [`docs/updater.md`](docs/updater.md), and the decisions with real trade-offs as architecture decision records under [`docs/adr/`](docs/adr/). The domain vocabulary is in [`CONTEXT.md`](CONTEXT.md), and the bootstrap playbook for AI coding agents is in [`AGENTS.md`](AGENTS.md).

## How you can contribute

Contributions are welcome, large or small: open an issue to report a bug or request a feature, submit a pull request, or contribute localisation or documentation. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the development setup, the quality gates a change must pass, the coding and writing standards, and the pull-request process.

## License

This plugin is licensed under the GNU General Public License, version 2 or later. See [`LICENSE`](LICENSE) for the full text.

## Changelog

All notable changes are recorded in [`CHANGELOG.md`](CHANGELOG.md), which follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/). Each tagged release is also published on the [GitHub Releases page](https://github.com/Kntnt/kntnt-photo-drop/releases).
