#!/usr/bin/env bash
#
# Build the deploy zip for wp-admin -> Plugins -> Add New -> Upload Plugin.
#
# Reads the version from the plugin header and refuses to build unless it
# matches QC_VERSION (the dual-version rule). Lints every PHP file and checks
# the files the plugin cannot run without. Ships no tests, docs or tooling.
#
# Usage: ./scripts/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="qhta-competitions"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
BOOTSTRAP="$PLUGIN_DIR/$PLUGIN_SLUG.php"

if [[ "$(basename "$PLUGIN_DIR")" != "$PLUGIN_SLUG" ]]; then
	echo "error: the plugin folder must be named $PLUGIN_SLUG (it is $(basename "$PLUGIN_DIR"))." >&2
	exit 1
fi

"$PLUGIN_DIR/scripts/check-version.sh" >/dev/null || { "$PLUGIN_DIR/scripts/check-version.sh"; exit 1; }
VERSION="$(sed -n "s/^define( 'QC_VERSION', '\(.*\)' );$/\1/p" "$BOOTSTRAP")"
ZIP_PATH="$PLUGIN_DIR/$PLUGIN_SLUG-$VERSION.zip"

for required in \
	"qhta-competitions.php" "uninstall.php" "README.md" \
	"includes/class-plugin.php" "includes/class-flags.php" "includes/class-form-handler.php" \
	"admin/class-admin-programs.php" \
	"templates/form.php" "templates/email.php" "templates/confirm-page.php" \
	"assets/js/form.js" "assets/js/admin-editor.js" "assets/css/form.css" "assets/css/admin.css" \
	"presets/ecc-bursary-2027.json" "presets/writing-competition-2026-demo.json"
do
	if [[ ! -e "$PLUGIN_DIR/$required" ]]; then
		echo "error: $required is missing." >&2
		exit 1
	fi
done

if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null || { echo "error: PHP syntax error in $php_file" >&2; exit 1; }
	done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -not -path '*/tests/*' -print0)
else
	echo "note: php not on PATH, skipping syntax check" >&2
fi

if command -v python3 >/dev/null 2>&1; then
	for preset in "$PLUGIN_DIR"/presets/*.json; do
		python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$preset" || { echo "error: invalid JSON in $preset" >&2; exit 1; }
	done
fi

if command -v node >/dev/null 2>&1; then
	node --check "$PLUGIN_DIR/assets/js/form.js"
	node --check "$PLUGIN_DIR/assets/js/admin-editor.js"
fi

rm -f "$ZIP_PATH"
staging_dir="$(mktemp -d)"
trap 'rm -rf "$staging_dir"' EXIT
staging_zip="$staging_dir/$PLUGIN_SLUG-$VERSION.zip"

# Top-level folder must be the plugin slug. Build outside the tree so the zip
# can't include itself.
cd "$PARENT_DIR"
zip -rq "$staging_zip" "$PLUGIN_SLUG" \
	-x "*.DS_Store" \
	   "*.git*" \
	   "*.claude*" \
	   "*.zip" \
	   "*__pycache__*" \
	   "$PLUGIN_SLUG/tests/*" \
	   "$PLUGIN_SLUG/docs/*" \
	   "$PLUGIN_SLUG/scripts/*" \
	   "$PLUGIN_SLUG/AGENTS.md" \
	   "$PLUGIN_SLUG/CLAUDE.md"

mv "$staging_zip" "$ZIP_PATH"
echo "built $ZIP_PATH ($VERSION, $(du -k "$ZIP_PATH" | cut -f1) KB)"
echo
echo "Next: follow docs/DEPLOY.md (staging first, then live)."
