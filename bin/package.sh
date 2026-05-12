#!/usr/bin/env bash
# GML AI SEO — release packager
#
# Usage (from repo root or plugins/gml-seo/):
#   bash bin/package.sh
#
# Output: plugins/gml-seo-vX.Y.Z.zip  (next to the gml-seo folder)
#
# The zip is built from the plugins/ directory so the internal path is
# gml-seo/... (required by WordPress plugin installer).

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"          # .../plugins/gml-seo
PLUGINS_DIR="$( cd "$PLUGIN_DIR/.." && pwd )"          # .../plugins
PLUGIN_SLUG="gml-seo"

# Read version from plugin header
VERSION="$( grep -m1 '^ \* Version:' "$PLUGIN_DIR/gml-seo.php" | sed 's/.*Version: *//' | tr -d '[:space:]' )"
if [ -z "$VERSION" ]; then
  echo "ERROR: could not read version from gml-seo.php" >&2
  exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
ZIP_PATH="${PLUGINS_DIR}/${ZIP_NAME}"

echo "Packaging ${PLUGIN_SLUG} v${VERSION} → ${ZIP_PATH}"

# Remove old zip if present
rm -f "$ZIP_PATH"

# Build from plugins/ so internal path is gml-seo/...
cd "$PLUGINS_DIR"
zip -r "$ZIP_NAME" "$PLUGIN_SLUG" \
  --exclude "${PLUGIN_SLUG}/.git/*" \
  --exclude "${PLUGIN_SLUG}/.gitignore" \
  --exclude "${PLUGIN_SLUG}/tests/*" \
  --exclude "${PLUGIN_SLUG}/.DS_Store" \
  --exclude "${PLUGIN_SLUG}/Thumbs.db" \
  --exclude "${PLUGIN_SLUG}/bin/*" \
  -q

# Verify internal path
FIRST_PHP="$( unzip -l "$ZIP_NAME" | grep '\.php' | head -1 )"
if echo "$FIRST_PHP" | grep -q "^.*${PLUGIN_SLUG}/${PLUGIN_SLUG}\.php"; then
  echo "✓ Internal path OK: ${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
else
  echo "WARNING: unexpected internal path — check the zip before uploading"
  echo "  $FIRST_PHP"
fi

SIZE="$( du -sh "$ZIP_NAME" | cut -f1 )"
echo "✓ Done: ${ZIP_NAME} (${SIZE})"
echo ""
echo "Next steps:"
echo "  git tag -a v${VERSION} -m \"v${VERSION}: <summary>\""
echo "  git push origin main && git push origin v${VERSION}"
echo "  gh release create v${VERSION} --title \"v${VERSION} — <title>\" \\"
echo "    --notes-file .release-notes.md --latest \\"
echo "    --repo hwc0212/gml-seo ${ZIP_NAME}"
