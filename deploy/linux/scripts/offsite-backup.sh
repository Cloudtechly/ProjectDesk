#!/usr/bin/env bash
set -euo pipefail

: "${RCLONE_REMOTE:?RCLONE_REMOTE must be configured}"
: "${PROJECT_DESK_ROOT:=/var/www/project-desk}"

backup_source="${PROJECT_DESK_ROOT}/storage/app/private/backups/project-desk"
case "${backup_source}" in
    "${PROJECT_DESK_ROOT}"/*) ;;
    *) echo "Backup source is outside PROJECT_DESK_ROOT" >&2; exit 2 ;;
esac

cd "${PROJECT_DESK_ROOT}"
/usr/bin/php artisan project-desk:automatic-backup
/usr/bin/rclone copy "${backup_source}" "${RCLONE_REMOTE}" --checksum --immutable --create-empty-src-dirs
/usr/bin/rclone check "${backup_source}" "${RCLONE_REMOTE}" --checksum --one-way
