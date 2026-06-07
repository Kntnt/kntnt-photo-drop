# CLI: grouped subcommands; `import` is a pure consumer

The WP-CLI surface is grouped by object with verb subcommands — `collection {create, update, delete, doctor}` and `image {import, delete}` — following WordPress's own pattern (`wp post create`, `wp db check`). **`import` requires an existing collection and carries no contract flags**: it reads the target collection's descriptor and optimises to that contract, making it a pure consumer just like the blocks. Establishing a collection (fixing the immutable contract) is the *only* job of `collection create`, which is the one deliberate CLI place a contract is set.

## Considered Options

- **`import` establishes a collection from flags** (original settled decision #7, which listed the surface as `import + doctor + remove` and let `import` establish): superseded. Letting `import` establish would smuggle the irreversible act of fixing a contract into a routine command; a dedicated `create` keeps establishment deliberate and mirrors the admin page.
- **Per-image command named `remove`:** renamed to `image delete` so a single verb means "remove" throughout.

## Consequences

`collection create <slug>` takes `slug` positionally; `--name` is optional (defaults to a humanised slug, since the display name is mutable); `--max-width` and `--quality` are **required flags** (the contract is irreversible, so no silent default may freeze it — the `kntnt_photo_drop_default_*` filters instead pre-fill the admin form). `collection update` changes only the mutable `--name` and rejects any attempt to change the immutable contract. `image import` is idempotent (skip-if-target-exists, `--overwrite` to force). Both deletes prompt for confirmation unless `--yes`. CLI runs in a trusted context with no capability checks. Read commands use WP-CLI `format_items()`. Deliberately excluded: `verify` (subsumed by doctor), `list`/`mv` (the filesystem plus `find` and self-healing indexes cover them), and a standalone in-place `process`.
