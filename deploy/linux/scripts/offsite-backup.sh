#!/usr/bin/env bash
set -euo pipefail

: "${RCLONE_REMOTE:?RCLONE_REMOTE must be configured}"
: "${PROJECT_DESK_ROOT:=/var/www/project-desk}"
: "${PROJECT_DESK_STATUS_DIR:=/var/lib/project-desk}"

backup_source="${PROJECT_DESK_ROOT}/storage/app/private/backups/project-desk"
status_file="${PROJECT_DESK_STATUS_DIR}/offsite-backup-status.json"
case "${backup_source}" in
    "${PROJECT_DESK_ROOT}"/*) ;;
    *) echo "Backup source is outside PROJECT_DESK_ROOT" >&2; exit 2 ;;
esac

cd "${PROJECT_DESK_ROOT}"
/usr/bin/php artisan project-desk:automatic-backup --force --no-interaction

latest_backup="$(find "${backup_source}" -maxdepth 1 -type f -name '*.pdesk' -printf '%T@ %p\n' | sort -nr | sed -n '1p' | cut -d' ' -f2-)"
if [[ -z "${latest_backup}" || ! -f "${latest_backup}" ]]; then
    echo "No .pdesk backup was produced" >&2
    exit 3
fi

backup_name="$(basename "${latest_backup}")"
remote_object="${RCLONE_REMOTE%/}/${backup_name}"
checksum_sha256="$(sha256sum "${latest_backup}" | awk '{print $1}')"

/usr/bin/rclone copyto "${latest_backup}" "${remote_object}" --checksum --immutable
/usr/bin/rclone check "${backup_source}" "${RCLONE_REMOTE}" --include "/${backup_name}" --checksum --one-way

install -d -m 0700 "${PROJECT_DESK_STATUS_DIR}"
temporary_status="$(mktemp "${PROJECT_DESK_STATUS_DIR}/offsite-backup-status.XXXXXX")"
trap 'rm -f "${temporary_status}"' EXIT

/usr/bin/jq -n \
    --arg verified_at "$(date --iso-8601=seconds)" \
    --arg local_file "${backup_name}" \
    --arg remote_object "${remote_object}" \
    --arg checksum_sha256 "${checksum_sha256}" \
    '{
        verified_at: $verified_at,
        local_file: $local_file,
        remote_object: $remote_object,
        checksum_sha256: $checksum_sha256,
        rclone_check_passed: true
    }' > "${temporary_status}"
chmod 0600 "${temporary_status}"
mv -f "${temporary_status}" "${status_file}"
trap - EXIT
