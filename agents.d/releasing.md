# Releasing

Authoritative release checklist. Release is **tag-triggered, runs on CI** — never a local upload.

1. Bump `Version:` in `kntnt-photo-drop.php` **and** `"version"` in `package.json` (must match).
2. Commit, push the branch.
3. Push tag `vX.Y.Z`.

The `release` job in `.github/workflows/ci.yml` fires only on a `v*` tag, only after every gate (PHP, Node, integration/e2e) is green: runs `./build-zip.sh`, takes the `## [X.Y.Z]` section of `CHANGELOG.md` as the release body, **publishes** (not drafts) Release `vX.Y.Z` with `dist/kntnt-photo-drop.zip` attached.

Tag push = irreversible, live to users. Don't push until changelog + version final. `Updater` reads only the *latest published* release, finds the asset by `content_type === "application/zip"` (version-less filename intentional) — no ZIP, no installable package.

**The `kntnt-code-skills:release` skill must NOT build the ZIP or run `gh release create`/`gh release edit` here** — CI owns build + publish. Skill's job ends at: reconcile changelog, bump version everywhere, commit, tag `vX.Y.Z`, push branch + tag. `./build-zip.sh` is build-only (never uploads) — run locally to inspect the package.
