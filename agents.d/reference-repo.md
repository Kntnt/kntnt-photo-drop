# Reference repo

Read when adding or changing build infrastructure or top-level scaffolding.

This plugin mirrors the structure, build chain, and conventions of [`kntnt-gpx-blocks`](https://github.com/Kntnt/kntnt-gpx-blocks). Clone as the template:

```bash
git clone --depth 1 https://github.com/Kntnt/kntnt-gpx-blocks.git /tmp/kntnt-gpx-blocks
```

Mirror in particular: the `Plugin` singleton (component wiring + the four-level `error`/`warning`/`info`/`debug` logging API gated by `KNTNT_PHOTO_DROP_LOG_LEVEL`), the `Updater` (GitHub-Releases auto-update by ZIP `content_type`), `autoloader.php`, `composer.json` / `package.json` / `phpcs.xml.dist` / `phpstan.neon.dist` / `tsconfig.json`, `build-zip.sh`, the Pest + Brain Monkey harness (`tests/Unit/TestCase.php`, `tests/Pest.php`), and the dynamic-block layout `src/blocks/<slug>/` → `build/blocks/<slug>/`.

Where gpx-blocks and this plugin's specs disagree (e.g. gpx is consent-gated for map tiles; we have no third-party embed to gate), **the specs in `docs/` win**.
