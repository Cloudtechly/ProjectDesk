#!/usr/bin/env bash
set -euo pipefail

: "${PROJECT_DESK_ROOT:=/var/www/project-desk}"
: "${PROJECT_DESK_URL:?PROJECT_DESK_URL must be the public HTTPS URL}"
: "${PROJECT_DESK_DATABASE:?PROJECT_DESK_DATABASE must be the absolute SQLite path}"
: "${PROJECT_DESK_MINIMUM_FREE_BYTES:=5368709120}"

if [[ ! "${PROJECT_DESK_MINIMUM_FREE_BYTES}" =~ ^[0-9]+$ ]]; then
    echo 'PROJECT_DESK_MINIMUM_FREE_BYTES must be an unsigned integer.' >&2
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

check_command() {
    if command -v "$1" >/dev/null 2>&1; then
        pass "command.${1}"
    else
        fail "command.${1}"
    fi
}

for required_command in php nginx systemctl curl clamdscan rclone sqlite3 findmnt stat jq runuser; do
    check_command "${required_command}"
done

if [[ ! -d "${PROJECT_DESK_ROOT}" ]]; then
    fail 'application.root'
elif [[ "$(stat -c '%U:%G' "${PROJECT_DESK_ROOT}")" == 'project-desk:project-desk' ]]; then
    pass 'application.ownership'
else
    fail 'application.ownership'
fi

for environment_file in /etc/project-desk/app.env /etc/project-desk/operations.env; do
    if [[ -f "${environment_file}" && "$(stat -c '%a' "${environment_file}")" == '600' ]]; then
        pass "secrets.$(basename "${environment_file}")"
    else
        fail "secrets.$(basename "${environment_file}")"
    fi
done

if nginx -t >/dev/null 2>&1; then
    pass 'nginx.config'
else
    fail 'nginx.config'
fi

for service_name in project-desk-queue.service project-desk-scheduler.service clamav-daemon.service php8.4-fpm.service nginx.service; do
    if systemctl is-active --quiet "${service_name}"; then
        pass "service.${service_name}"
    else
        fail "service.${service_name}"
    fi
done

if systemctl is-active --quiet project-desk-offsite-backup.timer \
    && systemctl is-enabled --quiet project-desk-offsite-backup.timer; then
    pass 'timer.offsite_backup'
else
    fail 'timer.offsite_backup'
fi

if systemctl is-active --quiet project-desk-operations-monitor.timer \
    && systemctl is-enabled --quiet project-desk-operations-monitor.timer; then
    pass 'timer.operations_monitor'
else
    fail 'timer.operations_monitor'
fi

http_url="http://${PROJECT_DESK_URL#https://}"
http_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "${http_url}/login" || true)"
redirect_url="$(curl --silent --output /dev/null --write-out '%{redirect_url}' "${http_url}/login" || true)"
if [[ "${http_status}" =~ ^30[1278]$ && "${redirect_url}" == "${PROJECT_DESK_URL}"* ]]; then
    pass 'https.redirect'
else
    fail 'https.redirect'
fi

headers="$(curl --silent --show-error --head "${PROJECT_DESK_URL}/login" || true)"
if grep -Eiq '^strict-transport-security:.*max-age=' <<< "${headers}"; then
    pass 'https.hsts'
else
    fail 'https.hsts'
fi

cookie_headers="$(grep -Ei '^set-cookie:' <<< "${headers}" || true)"
if [[ -n "${cookie_headers}" ]] \
    && grep -Eiq 'Secure' <<< "${cookie_headers}" \
    && grep -Eiq 'HttpOnly' <<< "${cookie_headers}" \
    && grep -Eiq 'SameSite=' <<< "${cookie_headers}"; then
    pass 'https.secure_cookie'
else
    fail 'https.secure_cookie'
fi

database_type="$(findmnt --noheadings --output FSTYPE --target "${PROJECT_DESK_DATABASE}" 2>/dev/null | tr -d '[:space:]')"
if [[ -f "${PROJECT_DESK_DATABASE}" && ! "${database_type}" =~ ^(nfs|nfs4|cifs|smb3)$ ]]; then
    pass 'sqlite.local_storage'
else
    fail 'sqlite.local_storage'
fi

journal_mode="$(sqlite3 "${PROJECT_DESK_DATABASE}" 'PRAGMA journal_mode;' 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)"
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

if clamdscan --version >/dev/null 2>&1; then
    pass 'clamav.service'
else
    fail 'clamav.service'
fi

cd "${PROJECT_DESK_ROOT}"
if runuser --preserve-environment -u project-desk -- /usr/bin/php artisan project-desk:production-readiness --json; then
    pass 'application.production_readiness'
else
    fail 'application.production_readiness'
fi

if ((failures > 0)); then
    printf '%d preflight check(s) failed.\n' "${failures}" >&2
    exit 1
fi

printf 'Production preflight passed.\n'
