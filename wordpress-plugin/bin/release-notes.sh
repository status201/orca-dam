#!/usr/bin/env bash
#
# Print one version's changelog entry from readme.txt, for the GitHub release body.
#
# Usage:    bin/release-notes.sh <version>
# Example:  bin/release-notes.sh 0.4.9
#
# The release workflow (.github/workflows/wordpress-plugin-release.yml) uses the
# output as the release notes. plugin-update-checker reads those notes on every
# update check, and GitHubUpdater shows them in WordPress's "View version X
# details" window for sites still on an older version. Exits 1 if readme.txt has
# no `= <version> =` entry, so a release can't ship without one.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    echo "Usage: bin/release-notes.sh <version>" >&2
    exit 1
fi

# The entry runs from `= X.Y.Z =` to the next `= …` / `== … ==` line. Leading and
# trailing blank lines are dropped; CRs are stripped for Windows checkouts.
NOTES=$(awk -v want="= ${VERSION} =" '
    { sub(/\r$/, "") }
    $0 == want { found = 1; next }
    found && /^=/ { exit }
    found { lines[++n] = $0 }
    END {
        first = 1
        while (first <= n && lines[first] ~ /^[[:space:]]*$/) first++
        while (n >= first && lines[n] ~ /^[[:space:]]*$/) n--
        for (i = first; i <= n; i++) print lines[i]
    }
' readme.txt)

if [[ -z "$NOTES" ]]; then
    echo "Error: readme.txt has no changelog entry \"= ${VERSION} =\"." >&2
    exit 1
fi

printf '%s\n' "$NOTES"
