#!/usr/bin/env sh
set -eu

if command -v rg >/dev/null 2>&1; then
  files="$(rg --files \
    -g '*.php' \
    -g '!vendor/**' \
    -g '!demo/**' \
    -g '!node_modules/**' \
    -g '!.git/**' \
    -g '!.idea/**')"
else
  files="$(
    find . \
      \( -type d \( -name vendor -o -name demo -o -name node_modules -o -name .git -o -name .idea \) -prune \) \
      -o \( -type f -name '*.php' -print \) \
      | sed 's|^\./||'
  )"
fi

if [ -z "${files}" ]; then
  echo "No PHP files found."
  exit 0
fi

printf '%s\n' "${files}" | while IFS= read -r file; do
  php -l "${file}"
done
