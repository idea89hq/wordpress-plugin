#!/usr/bin/env bash
#
# Builds a clean, wordpress.org-shaped idea89-assistant.zip from tracked
# source files only.
#
# Source of truth is git: the file list comes from `git ls-files`, so
# anything not tracked (vendor/, .phpunit.result.cache, local scratch files)
# is never a candidate in the first place. .distignore then strips the
# remaining dev-only paths (tests/, composer.*, lint configs, contributor
# docs) that ARE tracked but must not ship to a merchant's site or to
# wordpress.org.
#
# Usage: ./build-zip.sh   (run from woocommerce-plugin/)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

SLUG="idea89-ai-shopping-assistant"
BUILD_DIR="$(mktemp -d)"
STAGE_DIR="${BUILD_DIR}/${SLUG}"
OUT_ZIP="$(pwd)/${SLUG}.zip"

trap 'rm -rf "${BUILD_DIR}"' EXIT

mkdir -p "${STAGE_DIR}"

# Tracked (and staged) files only.
git ls-files > "${BUILD_DIR}/tracked-files.txt"

is_ignored() {
  local file="$1"
  local pattern
  while IFS= read -r pattern; do
    [[ -z "${pattern}" || "${pattern}" == \#* ]] && continue
    pattern="${pattern#/}"
    pattern="${pattern%/}"
    if [[ "${file}" == "${pattern}" || "${file}" == "${pattern}"/* ]]; then
      return 0
    fi
    # Bare glob patterns (e.g. *.zip) applied against the basename.
    if [[ "${pattern}" == \** && "$(basename "${file}")" == ${pattern} ]]; then
      return 0
    fi
  done < .distignore
  return 1
}

count=0
while IFS= read -r file; do
  if ! is_ignored "${file}"; then
    mkdir -p "${STAGE_DIR}/$(dirname "${file}")"
    cp "${file}" "${STAGE_DIR}/${file}"
    count=$((count + 1))
  fi
done < "${BUILD_DIR}/tracked-files.txt"

if [[ "${count}" -eq 0 ]]; then
  echo "error: no files staged -- refusing to write an empty zip" >&2
  exit 1
fi

rm -f "${OUT_ZIP}"
(cd "${BUILD_DIR}" && zip -rq "${OUT_ZIP}" "${SLUG}")

echo "Built ${OUT_ZIP} (${count} files)"
