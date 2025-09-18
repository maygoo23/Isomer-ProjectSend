#!/usr/bin/env bash
set -euo pipefail

# Make ALTERs idempotent in all upgrade scripts to prevent “duplicate column/key” fatals.
shopt -s nullglob
for f in /var/www/html/includes/upgrades/*.php; do
  # Only patch once
  grep -q "__PATCHED_IDEMPOTENT__" "$f" && continue

  sed -i -E '
    s/(ADD[[:space:]]+COLUMN)[[:space:]]+`/\1 IF NOT EXISTS ` /Ig;
    s/(DROP[[:space:]]+COLUMN)[[:space:]]+`/\1 IF EXISTS ` /Ig;
    s/(ADD[[:space:]]+UNIQUE[[:space:]]+KEY)[[:space:]]+`/\1 IF NOT EXISTS ` /Ig;
    s/(ADD[[:space:]]+UNIQUE[[:space:]]+INDEX)[[:space:]]+`/\1 IF NOT EXISTS ` /Ig;
    s/(ADD[[:space:]]+KEY)[[:space:]]+`/\1 IF NOT EXISTS ` /Ig;
    s/(ADD[[:space:]]+INDEX)[[:space:]]+`/\1 IF NOT EXISTS ` /Ig;
    s/(DROP[[:space:]]+FOREIGN[[:space:]]+KEY)[[:space:]]+`/\1 IF EXISTS ` /Ig;
  ' "$f"

  # Mark as patched
  printf "
/* __PATCHED_IDEMPOTENT__ */
" >> "$f"
done

# Special case (seen in your logs): 2022102701 drops columns unguarded
F=/var/www/html/includes/upgrades/2022102701.php
if [ -f "$F" ]; then
  sed -i -E '
    s/` DROP COLUMN `client_id`/` DROP COLUMN IF EXISTS `client_id`/I;
    s/` DROP COLUMN `group_id`/` DROP COLUMN IF EXISTS `group_id`/I;
  ' "$F" || true
fi

F=/var/www/html/includes/upgrades/2022110501.php
if [ -f "$F" ]; then
  if ! grep -qi 'ADD COLUMN' "$F"; then
    sed -i -E 's/ADD[[:space:]]+([^[:space:]]+)/ADD COLUMN IF NOT EXISTS \1/I' "$F" || true
  fi
fi

F=/var/www/html/includes/upgrades/2022091001.php
if [ -f "$F" ]; then
  sed -i -E 's/`CHANGE/` CHANGE/g' "$F" || true
  sed -i 's/``/` `/g' "$F" || true
  sed -i -E 's/`([[:alnum:]_]+)`([[:alpha:]])/`\1` \2/g' "$F" || true
fi
