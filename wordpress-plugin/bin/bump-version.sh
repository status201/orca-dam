#!/usr/bin/env bash
#
# Bump the WP plugin version across every file that holds it.
#
# Usage:    bin/bump-version.sh <new-version>
# Example:  bin/bump-version.sh 0.2.0
#
# Touches:
#   orca-dam-picker.php  -- "Version:" header + ORCA_DAM_PICKER_VERSION constant
#   readme.txt           -- "Stable tag:"
#   package.json         -- "version"
#   package-lock.json    -- "version" (auto-synced by npm)
#
# The release workflow (.github/workflows/wordpress-plugin-release.yml) re-stamps
# the PHP file from the tag at build time, so the PHP edits here are mostly for
# clarity on the source branch and for plugin-update-checker. readme.txt and
# package.json are NOT auto-stamped, so this script is the only place they get
# updated.

set -euo pipefail

cd "$(dirname "$0")/.."

NEW="${1:-}"
if [[ -z "$NEW" ]]; then
    echo "Usage: bin/bump-version.sh <new-version>" >&2
    echo "Example: bin/bump-version.sh 0.2.0" >&2
    exit 1
fi
if ! [[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$ ]]; then
    echo "Error: version must look like X.Y.Z (optionally X.Y.Z-prerelease), got: $NEW" >&2
    exit 1
fi

OLD=$(sed -n "s/.*define('ORCA_DAM_PICKER_VERSION', '\([^']*\)');.*/\1/p" orca-dam-picker.php)
if [[ -z "$OLD" ]]; then
    echo "Error: could not parse current version from orca-dam-picker.php" >&2
    exit 1
fi
if [[ "$OLD" == "$NEW" ]]; then
    echo "Already at $NEW, nothing to do."
    exit 0
fi

echo "Bumping $OLD -> $NEW"

sed_inplace() {
    local script="$1" file="$2"
    sed "$script" "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

sed_inplace "s/^ \* Version:.*/ * Version:           ${NEW}/" orca-dam-picker.php
sed_inplace "s/define('ORCA_DAM_PICKER_VERSION', '[^']*');/define('ORCA_DAM_PICKER_VERSION', '${NEW}');/" orca-dam-picker.php
sed_inplace "s/^Stable tag: .*/Stable tag: ${NEW}/" readme.txt

npm version "$NEW" --no-git-tag-version >/dev/null

echo
echo "Updated files:"
git diff --stat -- orca-dam-picker.php readme.txt package.json package-lock.json 2>/dev/null || true
echo
echo "Next steps (run from repo root):"
echo
echo "  git add wordpress-plugin/orca-dam-picker.php \\"
echo "          wordpress-plugin/readme.txt \\"
echo "          wordpress-plugin/package.json \\"
echo "          wordpress-plugin/package-lock.json"
echo "  git commit -m 'Bump WP plugin to ${NEW}'"
echo "  git tag wp-v${NEW}"
echo "  git push origin main wp-v${NEW}"
echo
echo "Then watch the release build:"
echo "  gh run watch"
