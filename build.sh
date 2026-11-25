#!/usr/bin/env bash
# Sestavení ZIPu + SHA-256 pro GitHub Release.  ./build.sh → dist/
set -euo pipefail
cd "$(dirname "$0")"
SLUG="bombora-mergado-wpml"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' ${SLUG}.php | sed -E 's/.*Version:\s*//; s/\s+$//')"
CONST="$(grep -m1 "define( 'BMW_VERSION'" ${SLUG}.php | sed -E "s/.*'BMW_VERSION', '([^']+)'.*/\1/")"
[[ "$VERSION" == "$CONST" ]] || { echo "Chyba: Version ($VERSION) ≠ BMW_VERSION ($CONST)" >&2; exit 1; }
grep -q "^## \[$VERSION\]" CHANGELOG.md || { echo "Chyba: CHANGELOG.md bez sekce [$VERSION]" >&2; exit 1; }
find . -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l >/dev/null
rm -rf dist build && mkdir -p "build/${SLUG}" dist
tar --exclude-from=.distignore --exclude=./build --exclude=./dist -cf - . | tar -xf - -C "build/${SLUG}"
( cd build && zip -qr9X "../dist/${SLUG}.zip" "${SLUG}" )
( cd dist && sha256sum "${SLUG}.zip" > "${SLUG}.zip.sha256" )
rm -rf build
echo "Hotovo: dist/${SLUG}.zip (v${VERSION})"; cat "dist/${SLUG}.zip.sha256"
