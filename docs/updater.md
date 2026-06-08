# Updater and distribution

How kntnt-photo-drop ships and how it updates itself. The plugin is not on the WordPress.org directory; it is distributed through **GitHub Releases**, and the `Updater` class teaches WordPress to find new releases there. This document describes the mechanism and the six-step release sequence. It mirrors kntnt-gpx-blocks and is consistent with [`AGENTS.md`](../AGENTS.md) § *Cutting a release* — if the two ever drift, `AGENTS.md` is the authoritative checklist and this document explains the why.

## The update mechanism

`Kntnt\Photo_Drop\Updater` is wired in `Plugin::__construct()` onto the `pre_set_site_transient_update_plugins` filter — the same transient WordPress core fills from the .org API. On each update check, `Updater::check_for_updates()` runs entirely admin-side and does the following:

1. Passes the payload straight through untouched when it is not a `\stdClass` (third-party code may legitimately call `set_site_transient( 'update_plugins', false )` to clear the transient) or when `checked` is empty.
2. Reads the plugin header via `Plugin::get_plugin_data()` and derives the GitHub `owner/repo` from the **Plugin URI** (`https://github.com/Kntnt/kntnt-photo-drop`).
3. Fetches `https://api.github.com/repos/{owner}/{repo}/releases/latest` with `wp_remote_get`.
4. Compares the installed `Version:` with the release `tag_name` (leading `v` stripped) via `version_compare`.
5. Selects the release asset whose **`content_type === "application/zip"`** — by content type, never by filename. This is what lets the release ZIP keep a stable, version-less name (see below).
6. Injects an update record (`slug`, `plugin`, `new_version`, `url`, `package`, `tested`) into the transient's `response` array so WordPress offers the update in the admin UI.

It **bails quietly** — returning the transient unmodified, never erroring — whenever the repo cannot be derived, the API errors or returns a non-200 status, the released version is not newer, or no `application/zip` asset exists. The only external request the plugin makes is this admin-side check; there is no visitor-facing call to gate (see [`design.md`](design.md) § *Distribution and privacy*).

The behaviour is unit-tested with `wp_remote_get` stubbed via Brain Monkey (`tests/Unit/UpdaterTest.php`); the live GitHub path is deliberately not exercised in tests (see [`testing.md`](testing.md) § *Updater*).

## Why the ZIP filename is version-less

`build-release-zip.sh` always writes `kntnt-photo-drop.zip` — no version segment. The per-release tag in the GitHub asset URL already encodes the version, and the `Updater` matches the asset by `content_type`, so a stable filename keeps the asset URL predictable across releases. **Skipping the ZIP on a release means the auto-updater sees no installable package and offers nothing**, even though a newer tag exists.

## The release ZIP

`build-release-zip.sh` (project root, executable) stages **runtime artefacts only** under a single top-level `kntnt-photo-drop/` folder and zips them:

- `kntnt-photo-drop.php`, `autoloader.php`, `install.php`, `uninstall.php`, `README.md`, `LICENSE`
- `classes/`, `vendor/` (production install), `build/` (compiled blocks)
- `js/`, `css/`, `languages/` — copied only if present (the plugin currently ships none; all client code compiles into `build/`)

It parses the `Version:` header, runs `composer install --no-dev --optimize-autoloader`, `npm ci`, and `npm run build`, stages into a `mktemp -d` directory cleaned up by an `EXIT` trap, writes `kntnt-photo-drop.zip` to the project root, and finally **restores the development composer install** so the working tree returns to dev mode. Dev-only files (`tests/`, `node_modules/`, `src/`, `docs/`, dotfiles, the lock files) never enter the archive.

## Cutting a release — the six steps

Mirror gpx-blocks. Run these in order from a clean, merged working tree:

1. **Bump the version in lockstep** — the `Version:` header in `kntnt-photo-drop.php` **and** `"version"` in `package.json` must match.
2. **Run every gate** over the merged work: `composer phpstan`, `composer phpcs`, `composer test`, `npm run build` (plus the JS lint/test gates). All green — the union of merged work, not just the last change. See [`definition-of-done.md`](definition-of-done.md).
3. **Commit** the version bump.
4. **Tag** `vX.Y.Z` on that commit.
5. **Build the ZIP**: `./build-release-zip.sh` produces `kntnt-photo-drop.zip` (runtime artefacts only, single top-level folder), then restores the dev composer install.
6. **Push and publish**: push the commit and the tag, then `gh release create vX.Y.Z ./kntnt-photo-drop.zip`. GitHub serves the asset with `content_type: application/zip`, which is exactly what the `Updater` looks for.

Once the release is published, sites running the plugin pick up the new version on their next update check.
