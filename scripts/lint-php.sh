#!/usr/bin/env sh
set -eu

if command -v rg >/dev/null 2>&1; then
  files="$(rg --files -g '*.php')"
else
  files="$(find . -type f -name '*.php' | sed 's|^\./||')"
fi

if [ -z "${files}" ]; then
  echo "No PHP files found."
  exit 0
fi

printf '%s\n' "${files}" | while IFS= read -r file; do
  php -l "${file}"
done
