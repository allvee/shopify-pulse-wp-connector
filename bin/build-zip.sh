#!/usr/bin/env bash
#
# Build an installable WordPress plugin zip for the current version.
#
# The version is read from the plugin header in shopify-pulse-connector.php,
# so bumping the version there is the ONLY thing you edit per release — this
# script always packages "the latest version".
#
# Output: dist/shopify-pulse-connector-<version>.zip, with a single top-level
# folder "shopify-pulse-connector/" (what WordPress expects on upload).
#
# Usage:
#   bin/build-zip.sh          # build dist/shopify-pulse-connector-<version>.zip
#   composer build            # same, via the composer script alias
#
set -euo pipefail

SLUG="shopify-pulse-connector"
MAIN_FILE="${SLUG}.php"

# Repo root = parent of this script's dir, regardless of where it's called from.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f "$MAIN_FILE" ]]; then
	echo "error: $MAIN_FILE not found in $ROOT" >&2
	exit 1
fi

# Pull "Version: X.Y.Z" from the plugin header.
VERSION="$(grep -m1 -Eo '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9A-Za-z.\-]+' "$MAIN_FILE" \
	| grep -Eo '[0-9A-Za-z.\-]+$' || true)"
if [[ -z "${VERSION:-}" ]]; then
	echo "error: could not read Version from $MAIN_FILE header" >&2
	exit 1
fi

OUT_DIR="$ROOT/dist"
OUT="$OUT_DIR/${SLUG}-${VERSION}.zip"
mkdir -p "$OUT_DIR"
rm -f "$OUT"

# Stage runtime files under a folder named exactly $SLUG so the zip unpacks to
# wp-content/plugins/shopify-pulse-connector/.
STAGE_ROOT="$(mktemp -d)"
STAGE="$STAGE_ROOT/$SLUG"
mkdir -p "$STAGE"

# Ship only what runs. Everything dev/build/VCS is excluded.
rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='tests' \
	--exclude='bin' \
	--exclude='dist' \
	--exclude='vendor' \
	--exclude='node_modules' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit.xml.dist' \
	--exclude='*.zip' \
	--exclude='.DS_Store' \
	--exclude='.idea' \
	--exclude='.vscode' \
	--exclude='*.log' \
	./ "$STAGE/"

( cd "$STAGE_ROOT" && zip -rq "$OUT" "$SLUG" )
rm -rf "$STAGE_ROOT"

echo "built: dist/${SLUG}-${VERSION}.zip"
echo "files: $(unzip -Z1 "$OUT" | grep -c -v '/$')"
