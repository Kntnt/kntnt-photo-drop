#!/usr/bin/env bash
#
# Builds a distribution ZIP for kntnt-photo-drop.
#
# Reads the version from the plugin file's Version: header, runs production
# composer and npm builds, copies the runtime artefacts into a staging
# directory under a single top-level folder, and zips the result. The dev
# composer install is restored by the EXIT trap — on success and on failure
# alike — so the working tree always returns to development mode.
#
# Arguments: none.
#
# Output: kntnt-photo-drop.zip in the project root.
#
# Exit codes:
#   0  Success.
#   1  A required CLI tool (composer, npm, zip) is not on PATH.
#   2  The Version: header could not be parsed from the plugin file.
#
# The output filename intentionally has no version segment so that the
# GitHub Releases asset URL stays stable across versions — the per-release
# tag in the URL already encodes the version. The Updater identifies the
# right asset by its content_type, not its filename.

set -euo pipefail

# Resolve the project root from this script's location so the script can be
# invoked from any working directory.
project_root="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$project_root"

plugin_slug="kntnt-photo-drop"
plugin_file="${project_root}/${plugin_slug}.php"

# Verify required CLI tools are present before doing any work.
for tool in composer npm zip; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "Error: required tool '$tool' is not on PATH." >&2
		exit 1
	fi
done

# Parse the Version: header from the plugin file. The header line follows the
# WordPress plugin-header convention "Version: X.Y.Z" (or X.Y.Z-suffix).
version="$( grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$plugin_file" \
	| head -n 1 \
	| sed -E 's/.*Version:[[:space:]]*([0-9A-Za-z.+-]+).*/\1/' )"
if [[ -z "$version" ]]; then
	echo "Error: could not parse Version: header from $plugin_file" >&2
	exit 2
fi

zip_name="${plugin_slug}.zip"
echo "Building ${zip_name} (version ${version})"

# Build a temporary staging directory and ensure it is removed on any exit.
# The same trap restores the development composer install when the --no-dev
# install below has run, so a failed npm/zip step never strands the working
# tree in production mode. The dirty flag makes the restore idempotent: it is
# reset after the restore so the trap can never double-restore.
staging_dir="$( mktemp -d )"
dev_deps_dirty=0
cleanup() {
	rm -rf "$staging_dir"
	if [[ "${dev_deps_dirty}" -eq 1 ]]; then
		echo "Restoring development composer install"
		composer install --quiet \
			|| echo "Warning: could not restore dev dependencies; run 'composer install' manually." >&2
		dev_deps_dirty=0
	fi
}
trap cleanup EXIT

target="${staging_dir}/${plugin_slug}"
mkdir -p "$target"

# Run a production composer install so vendor/ contains only runtime deps.
# Mark the tree dirty first, so the EXIT trap restores dev deps even when
# this very install (or any later step) fails.
echo "Running composer install --no-dev --optimize-autoloader"
dev_deps_dirty=1
composer install --no-dev --optimize-autoloader --quiet

# Run npm ci + npm run build so build/ is fresh and consistent.
echo "Running npm ci"
npm ci --silent
echo "Running npm run build"
npm run build --silent

# Copy each runtime artefact explicitly. This is more verbose than rsync with
# include/exclude rules but is obviously correct on review.
cp "$plugin_file" "$target/"
cp "${project_root}/autoloader.php" "$target/"
cp "${project_root}/install.php" "$target/"
cp "${project_root}/uninstall.php" "$target/"
cp "${project_root}/README.md" "$target/"
cp "${project_root}/LICENSE" "$target/"

cp -R "${project_root}/classes" "$target/classes"
cp -R "${project_root}/vendor" "$target/vendor"
cp -R "${project_root}/build" "$target/build"

# js/ and css/ are plain-asset directories that this plugin does not currently
# ship (all client code compiles into build/ via @wordpress/scripts). Copy them
# only if a future change adds them, so the script stays correct either way.
if [[ -d "${project_root}/js" ]]; then
	cp -R "${project_root}/js" "$target/js"
fi
if [[ -d "${project_root}/css" ]]; then
	cp -R "${project_root}/css" "$target/css"
fi

# languages/ is optional in early versions; copy when present.
if [[ -d "${project_root}/languages" ]]; then
	cp -R "${project_root}/languages" "$target/languages"
fi

# Remove any previous ZIP with the same name before writing the new one.
output_zip="${project_root}/${zip_name}"
rm -f "$output_zip"

# Zip the staging folder so the archive contains a single top-level directory,
# excluding macOS filesystem junk that must never ship in a release.
( cd "$staging_dir" && zip -r -q "$output_zip" "$plugin_slug" -x "*.DS_Store" -x "*__MACOSX*" )

echo "Created: ${output_zip}"
