#!/usr/bin/env bash
# Fails unless the plugin header Version and QC_VERSION match (dual version bump rule).
set -euo pipefail
cd "$(dirname "$0")/.."
header=$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' qhta-competitions.php | tr -d '[:space:]')
const=$(sed -n "s/^define( 'QC_VERSION', '\(.*\)' );$/\1/p" qhta-competitions.php)
if [[ -z "$header" || "$header" != "$const" ]]; then
  echo "Version mismatch: header='$header' QC_VERSION='$const'" >&2
  exit 1
fi
echo "Version OK: $header"
