# kntnt-photo-drop

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4.svg)](https://www.php.net/)
[![Release](https://img.shields.io/github/v/release/Kntnt/kntnt-photo-drop?sort=semver)](https://github.com/Kntnt/kntnt-photo-drop/releases/latest)

A WordPress plugin with two Gutenberg blocks: a front-end **Photo Drop Zone** that optimises images in the browser and uploads them in bulk, and a public **Photo Gallery** that renders those images with a lightbox. It lets a field photographer drag in hundreds of images at once, and lets anyone browse them later.

## Description

`kntnt-photo-drop` solves one job end to end: getting a large set of photos onto a WordPress site quickly, in a sensible web format, and presenting them well. The Photo Drop Zone block is a capability-gated uploader you place on a page; a logged-in user with upload rights drags in single images, many images, or a whole folder, and each is downscaled, converted to WebP, and compressed in the browser before it ever leaves the machine. The Photo Gallery block renders a chosen set of those images as a server-rendered grid or justified-rows layout, with an accessible lightbox.

The plugin does not use the WordPress Media Library. Images live as files on disk, in **collections** under the site's uploads directory, and the filesystem is the single source of truth: there are no database rows for collection images. A collection carries a fixed set of output rules, so everything inside it is conforming by construction. Because the images are plain files, they are served directly by URL, which is what makes the gallery, responsive `srcset`, and native lazy-loading work without a PHP proxy.

### Key Features

- **Two blocks.** Photo Drop Zone (front-end bulk uploader) and Photo Gallery (public gallery with a lightbox), both server-rendered and registered under the *Kntnt* block category.
- **In-browser optimisation.** Images are downscaled and re-encoded to WebP in the browser (via FilePond and the Canvas API) before upload, so a several-hundred-image batch transfers a fraction of the original bytes.
- **Collections on disk.** Each collection is a directory under the uploads root; discovery is a directory scan, so a collection copied in from another site appears automatically and a deleted directory disappears, with no registry to keep in sync.
- **An immutable output contract.** A collection fixes its maximum width and compression quality once, at creation. The stored format is always WebP. The contract cannot be changed afterwards, because the original is never kept.
- **Re-derivable thumbnails.** Thumbnail width is a filter-driven setting, not part of the contract, and can be changed and regenerated at any time.
- **Folder-aware uploads.** A *Select folder* control preserves sub-directory structure; loose drag-and-drop flattens, with a warning when a folder is dropped.
- **Two gallery layouts.** A uniform grid (core Grid layout) or bespoke justified rows, with filename or path-breadcrumb captions and an Interactivity-API lightbox that degrades to a plain link without JavaScript.
- **A complete WP-CLI surface.** Create, update, delete, and `doctor` collections, and import or delete images, from the command line.
- **First-party by design.** No third-party request is made when a visitor views a page. The only external call is the admin-side update check against GitHub.

### The problem

Bulk-uploading photos to WordPress is awkward. The Media Library accepts originals at full size, so a folder of camera images consumes large amounts of disk and bandwidth unless each file is resized by hand first. There is no built-in way to enforce a consistent maximum size and format across a set of images, and no simple way to drop in a whole folder and have the structure preserved. Presenting the result as a tidy, responsive gallery then means reaching for a heavier gallery plugin.

### How this plugin helps

A collection fixes its own output rules – a maximum width and a compression quality – and every image that enters it, whether through the Drop Zone or through `image import`, is made to conform at the point of entry. The browser does the heavy resizing and WebP encoding before upload, so the transfer is small; the server re-applies the same rules on arrival, so a file cannot enter non-conforming even if it bypasses the browser. Because the images are ordinary files served by URL, the Gallery block can render them with responsive `srcset` and lazy-loading straight from disk, and you compose pages out of the two blocks like any other Gutenberg content.

### Limitations

- **Collections are public-by-path.** Images are served directly as files, so anyone who knows a file's URL can fetch it, including an image not yet shown in any gallery. Directory listing is disabled so paths cannot be enumerated, but this is a public-gallery model, not access-controlled storage. True access control would require a PHP proxy and is out of scope.
- **The output contract is irreversible.** Maximum width and quality are fixed when a collection is created and cannot be changed afterwards. Raising the maximum later cannot enlarge images that were already downscaled, because the original is not kept.
- **No EXIF or IPTC metadata survives.** Re-encoding through the Canvas API strips all embedded metadata, so there is no capture date or embedded caption to draw on; gallery captions are derived from the filename or path only.
- **The gallery does not navigate folders.** A Gallery block renders all images under a start path as one flattened set. To present folders separately, place several blocks and compose them with the page builder.

## Requirements

- **WordPress** 6.5 or later.
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

The plugin has three surfaces: an admin page where collections are created and managed, the Photo Drop Zone block for uploading, and the Photo Gallery block for displaying.

### Create a collection

Collections are created and managed on a dedicated admin page at **Media → Photo Drop** (gated by `manage_options`). Blocks never create or reconfigure a collection; they only select one.

To create a collection, open the page and choose **Create collection**. You provide:

- a **slug** (required) – lowercase, URL-safe, and unique; it becomes the directory name and the durable identity a block stores to point at the collection;
- a **display name** (optional; defaults to a humanised slug);
- a **maximum width** in pixels (required; pre-filled at 1920, with an explicit *No limit* option);
- a **compression quality** (required; pre-filled at 80).

There is no format field – the format is always WebP – and no thumbnail-width field, because thumbnail width is re-derivable and filter-driven (see [Extending](#extending)).

> [!WARNING]
> Maximum width and quality fix the collection's **output contract**, and the contract **cannot be changed afterwards**. Every image is downscaled and re-encoded to WebP as it enters the collection, and the original is never kept, so raising the maximum later cannot recover detail that was already discarded. Choose these two values deliberately. Only the display name remains editable after creation.

The list view shows every discovered collection – name, slug, maximum width, quality, format, thumbnail width, and image count – with row actions to edit the display name or delete the collection. Deleting a collection removes its directory and everything under it; blocks that referenced its slug then render nothing for visitors and an editor-only notice for logged-in users.

### Place a Photo Drop Zone

Add the **Photo Drop Zone** block to a page or post. In the block inspector, pick the collection to upload into; the inspector also shows that collection's contract (maximum width, quality, WebP, thumbnail width) read-only, so there is nothing on the block that could conflict with the contract.

On the front end, the block renders its uploader **only** for users who hold the `upload_files` capability – for anyone else it renders nothing, and no upload nonce is emitted. A capable user can:

- drag in single images or many images at once;
- use the **Select folder** control to upload a folder while preserving its sub-directory structure;
- drag a whole folder onto the zone – the block detects this and warns, offering to continue with the files flattened (recursive drag traversal is not supported).

Each image is downscaled and encoded to WebP in the browser, then uploaded one file per request. The browser-side step is a bandwidth optimisation only: the server re-applies the collection's contract to every file on arrival, so nothing non-conforming can enter, even a file posted directly to the endpoint.

### Place a Photo Gallery

Add the **Photo Gallery** block where you want the images shown, and select a collection in the inspector. The block renders all images under a start path as one flattened gallery (or, with *This folder only*, just that folder). Key settings:

- **Layout** – a uniform **grid** (mode A, built on core's Grid layout, with a minimum column width and per-image aspect ratio for zero layout shift) or bespoke **justified rows** (mode B, with a target row height).
- **Ordering** – ascending or descending, by natural sort of the full relative path, so each folder's images stay together.
- **Captions** – none, filename, or a path breadcrumb, positioned under, above, or as an overlay, with the usual colour and typography controls.
- **Lightbox** – on by default, built with the WordPress Interactivity API (keyboard, swipe, focus trap, neighbour preload). Each thumbnail is also a plain link to the full image, so the gallery still works without JavaScript.

The gallery is pure server-rendered output plus its view module; it makes no REST or third-party request at view time.

### Privacy

The plugin makes no third-party request when a visitor views a page. FilePond, the Interactivity API, and the WebP encoding all run as bundled, local assets, and the images are first-party files served from your own site – there is no external embed to consent to. The only outbound request the plugin makes is the admin-side update check against the GitHub Releases API, which never runs on a visitor-facing page.

## Frequently asked questions (FAQ)

#### Where are the images stored?

As files on disk under `wp-content/uploads/kntnt-photo-drop/<slug>/`, outside the Media Library, with no database rows. The directory is the source of truth; the descriptor (`collection.json`) and the per-folder index (`index.json`, a regenerable cache) live alongside the images.

#### Can I change a collection's maximum width or quality after creating it?

No. Those two values are the immutable output contract. Because images are re-encoded on entry and the original is discarded, the contract cannot be changed. If you need different rules, create a new collection and import into it.

#### Can I change the thumbnail size?

Yes. Thumbnail width is not part of the contract – it is re-derivable from the main image. Set it with the `kntnt_photo_drop_thumbnail_width` filter, then regenerate with `wp kntnt-photo-drop collection doctor <slug> --repair --force`.

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
wp kntnt-photo-drop collection create <slug> --max-width=<pixels> --quality=<0-100> [--name=<name>]
wp kntnt-photo-drop collection update <slug> --name=<name>
wp kntnt-photo-drop collection delete <slug> [--yes]
wp kntnt-photo-drop collection doctor <slug> [--repair] [--force] [--ignore=<glob>] [--show-ignored]
```

- **`create`** establishes a collection and fixes its contract. `--max-width` (a positive integer, or `none` for no limit) and `--quality` (0–100) are **required**, because the contract is irreversible; `--name` is optional and defaults to a humanised slug. There is no thumbnail-width flag – that is filter-driven.
- **`update`** changes the display name only. Any attempt to change the contract is rejected.
- **`delete`** removes the collection directory and everything under it. It prompts unless `--yes` is given.
- **`doctor`** inspects a collection and reconciles its derived artifacts to the main images. It is **report-only by default** (the report is the dry run). `--repair` acts: it creates missing thumbnails, refreshes the index, and removes orphaned thumbnails. `--repair --force` re-derives everything – use it after a thumbnail-width change. The doctor never alters a main image and never deletes a foreign file. Foreign files are reported, except a built-in ignore list of OS junk (`.DS_Store`, `._*`, `.Spotlight-V100`, `.Trashes`, `.fseventsd`, `Thumbs.db`, `desktop.ini`); `--ignore=<glob>` extends that list and `--show-ignored` reveals what was skipped.

### `image`

```
wp kntnt-photo-drop image import <slug> <source>... [--overwrite]
wp kntnt-photo-drop image delete <slug> <path> [--yes]
```

- **`import`** brings external files into an existing collection, optimising each to that collection's contract. It carries no contract flags (it is a pure consumer of the collection) and is idempotent – an existing target is skipped unless `--overwrite` is given.
- **`delete`** removes one main image and its thumbnails. `<path>` is the image's path relative to the collection root, given as either its stored name or its original name, and is confined to the collection root. It prompts unless `--yes` is given.

Read commands present their output through WP-CLI's standard `--format` (`table`, `csv`, `json`, `yaml`, `ids`, `count`).

## Extending

The plugin exposes its behaviour through filters, all namespaced `kntnt_photo_drop_*`. Each is documented below with its default and effect.

### `kntnt_photo_drop_root`

```php
add_filter( 'kntnt_photo_drop_root', fn( string $root ): string => '/custom/path/kntnt-photo-drop' );
```

The absolute path to the uploads root that holds every collection. Default: `wp_upload_dir()['basedir'] . '/kntnt-photo-drop'`. The path must stay web-served (collections are served by URL), and on multisite `wp_upload_dir()` already yields a per-site basedir, so each site gets an isolated root.

### `kntnt_photo_drop_thumbnail_width`

```php
add_filter( 'kntnt_photo_drop_thumbnail_width', fn() => [ 480, 960 ] );
```

The width or widths at which thumbnails are derived. Default: `640`. May return a single integer or an array of integers; `[]` or `0` means no thumbnail. Because thumbnail width is re-derivable from the main image, it is not frozen in the contract – changing it and running `collection doctor --repair --force` regenerates the thumbnails.

### `kntnt_photo_drop_default_max_width`

```php
add_filter( 'kntnt_photo_drop_default_max_width', fn() => 2560 );
```

The maximum width that pre-fills the *Create collection* form. Default: `1920`. This is only a convenience default for the admin form; it does not change any existing collection's contract.

### `kntnt_photo_drop_default_quality`

```php
add_filter( 'kntnt_photo_drop_default_quality', fn() => 82 );
```

The compression quality that pre-fills the *Create collection* form. Default: `80`. As with the width default, it affects only the form's initial value, not any existing collection.

### `kntnt_photo_drop_upload_capability`

```php
add_filter( 'kntnt_photo_drop_upload_capability', fn() => 'edit_posts' );
```

The capability a user must hold to upload through the Drop Zone block and its REST endpoint. Default: `upload_files`. The block renders its uploader and emits its nonce only for users who hold this capability.

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

### Build a release artefact

```bash
./build-release-zip.sh
```

This produces `kntnt-photo-drop.zip` in the project root, containing the runtime artefacts only (PHP, the production Composer install, and the compiled `build/`) under a single top-level folder. The filename is deliberately version-less: the auto-updater selects the release asset by its `application/zip` content type, not by name, so a release published without this ZIP offers no installable package.

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

The design and its rationale live under [`docs/`](docs/): the overall plan in [`docs/design.md`](docs/design.md), the block attribute schemas and admin-page UX in [`docs/blocks.md`](docs/blocks.md), the testing strategy in [`docs/testing.md`](docs/testing.md), the release-and-update mechanism in [`docs/updater.md`](docs/updater.md), and the decisions with real trade-offs as architecture decision records under [`docs/adr/`](docs/adr/). The domain vocabulary is in [`CONTEXT.md`](CONTEXT.md), and the bootstrap playbook for AI coding agents is in [`AGENTS.md`](AGENTS.md).

## How you can contribute

Contributions are welcome, large or small: open an issue to report a bug or request a feature, submit a pull request, or contribute localisation or documentation. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the development setup, the quality gates a change must pass, the coding and writing standards, and the pull-request process.

## License

This plugin is licensed under the GNU General Public License, version 2 or later. See [`LICENSE`](LICENSE) for the full text.

## Changelog

All notable changes are recorded in [`CHANGELOG.md`](CHANGELOG.md), which follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/). Each tagged release is also published on the [GitHub Releases page](https://github.com/Kntnt/kntnt-photo-drop/releases).
