#!/usr/bin/env bash
set -euo pipefail

: "${PROJECT_DESK_DATABASE:?PROJECT_DESK_DATABASE must be the absolute SQLite path}"
: "${PROJECT_DESK_STATUS_DIR:=/var/lib/project-desk}"
: "${PROJECT_DESK_MINIMUM_FREE_BYTES:=5368709120}"
: "${CLAMAV_SIGNATURE_MAX_AGE_SECONDS:=172800}"

if [[ ! "${PROJECT_DESK_MINIMUM_FREE_BYTES}" =~ ^[0-9]+$ \
    || ! "${CLAMAV_SIGNATURE_MAX_AGE_SECONDS}" =~ ^[0-9]+$ ]]; then
    echo 'Operational numeric thresholds must be unsigned integers.' >&2
    exit 2
fi

failures=0

pass() {
    printf 'PASS %s\n' "$1"
}

fail() {
    printf 'FAIL %s\n' "$1" >&2
    failures=$((failures + 1))
}

for service_name in project-desk-queue.service project-desk-scheduler.service clamav-daemon.service; do
    if systemctl is-active --quiet "${service_name}"; then
        pass "service.${service_name}"
    else
        fail "service.${service_name}"
    fi
done

if systemctl is-active --quiet project-desk-offsite-backup.timer; then
    pass 'timer.offsite_backup'
else
    fail 'timer.offsite_backup'
fi

if clamdscan --version >/dev/null 2>&1; then
    pass 'clamav.service'
else
    fail 'clamav.service'
fi

newest_signature=0
for signature_path in /var/lib/clamav/daily.cld /var/lib/clamav/daily.cvd; do
    if [[ -f "${signature_path}" ]]; then
        signature_time="$(stat -c '%Y' "${signature_path}")"
        if ((signature_time > newest_signature)); then
            newest_signature="${signature_time}"
        fi
    fi
done

signature_age=$(( $(date +%s) - newest_signature ))
if ((newest_signature > 0 && signature_age <= CLAMAV_SIGNATURE_MAX_AGE_SECONDS)); then
    pass 'clamav.signatures'
else
    fail 'clamav.signatures'
fi

if [[ -f "${PROJECT_DESK_DATABASE}" ]]; then
    failed_jobs="$(sqlite3 "${PROJECT_DESK_DATABASE}" 'SELECT COUNT(*) FROM failed_jobs;' 2>/dev/null || printf 'invalid')"
    journal_mode="$(sqlite3 "${PROJECT_DESK_DATABASE}" 'PRAGMA journal_mode;' 2>/dev/null | tr '[:upper:]' '[:lower:]')"
else
    failed_jobs='invalid'
    journal_mode='invalid'
fi

if [[ "${failed_jobs}" == '0' ]]; then
    pass 'queue.failed_jobs'
else
    fail 'queue.failed_jobs'
fi

if [[ "${journal_mode}" == 'wal' ]]; then
    pass 'sqlite.wal'
else
    fail 'sqlite.wal'
fi

free_bytes="$(df --output=avail -B1 "${PROJECT_DESK_DATABASE}" 2>/dev/null | tail -n 1 | tr -d '[:space:]' || true)"
if [[ "${free_bytes}" =~ ^[0-9]+$ ]] && ((free_bytes >= PROJECT_DESK_MINIMUM_FREE_BYTES)); then
    pass 'disk.free_space'
else
    fail 'disk.free_space'
fi

backup_status="${PROJECT_DESK_STATUS_DIR}/offsite-backup-status.json"
if [[ -f "${backup_status}" ]] && jq -e '.rclone_check_passed == true' "${backup_status}" >/dev/null 2>&1; then
    verified_at="$(jq -r '.verified_at' "${backup_status}")"
    verified_epoch="$(date --date "${verified_at}" +%s 2>/dev/null || printf '0')"
    backup_age=$(( $(date +%s) - verified_epoch ))
    if ((verified_epoch > 0 && backup_age <= 93600)); then
        pass 'backup.offsite_fresh'
    else
        fail 'backup.offsite_fresh'
    fi
else
    fail 'backup.offsite_fresh'
fi

if ((failures > 0)); then
    printf '%d operational monitor check(s) failed.\n' "${failures}" >&2
    exit 1
fi

printf 'Operational monitor passed.\n'
