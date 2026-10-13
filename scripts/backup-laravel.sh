#!/usr/bin/env bash
# Standalone Laravel backup script for cron/cPanel.
# Creates one zip containing the project files and a MySQL dump, then keeps only 3 days of backups.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
ENV_FILE="${ENV_FILE:-${PROJECT_ROOT}/.env}"
BACKUP_DIR="${BACKUP_DIR:-${PROJECT_ROOT}/backup}"
RETENTION_DAYS="${RETENTION_DAYS:-3}"
APP_SLUG="${APP_SLUG:-$(basename "${PROJECT_ROOT}")}"
TIMESTAMP="$(date +"%Y%m%d_%H%M%S")"
WORK_DIR="${BACKUP_DIR}/.tmp_${TIMESTAMP}"
SQL_FILE="${WORK_DIR}/${APP_SLUG}_${TIMESTAMP}.sql"
ZIP_FILE="${BACKUP_DIR}/${APP_SLUG}_backup_${TIMESTAMP}.zip"

log() {
  printf '[%s] %s\n' "$(date +"%Y-%m-%d %H:%M:%S")" "$*"
}

fail() {
  log "ERROR: $*"
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Command '$1' tidak ditemukan di server."
}

env_value() {
  local key="$1"
  local value

  value="$(awk -v key="${key}" '
    BEGIN { FS = "=" }
    $0 !~ /^[[:space:]]*#/ && $1 == key {
      sub(/^[^=]*=/, "")
      print
      exit
    }
  ' "${ENV_FILE}")"

  value="${value%$'\r'}"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

cleanup() {
  rm -rf "${WORK_DIR}"
}

trap cleanup EXIT

require_command awk
require_command date
require_command find
require_command mkdir
require_command mysqldump
require_command rm
require_command zip

[[ -f "${ENV_FILE}" ]] || fail "File .env tidak ditemukan: ${ENV_FILE}"

DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"
DB_SOCKET="$(env_value DB_SOCKET)"

[[ "${DB_CONNECTION}" == "mysql" ]] || fail "Backup SQL hanya disiapkan untuk DB_CONNECTION=mysql."
[[ -n "${DB_DATABASE}" ]] || fail "DB_DATABASE kosong di .env."
[[ -n "${DB_USERNAME}" ]] || fail "DB_USERNAME kosong di .env."

mkdir -p "${BACKUP_DIR}" "${WORK_DIR}"
chmod 700 "${BACKUP_DIR}" "${WORK_DIR}" 2>/dev/null || true

if [[ ! -f "${BACKUP_DIR}/.htaccess" ]]; then
  cat > "${BACKUP_DIR}/.htaccess" <<'HTACCESS'
Require all denied
Deny from all
HTACCESS
fi

log "Dump database '${DB_DATABASE}'..."
MYSQLDUMP_ARGS=(
  --single-transaction
  --quick
  --routines
  --triggers
  --events
  --default-character-set=utf8mb4
  "--user=${DB_USERNAME}"
)

if [[ -n "${DB_SOCKET}" ]]; then
  MYSQLDUMP_ARGS+=("--socket=${DB_SOCKET}")
else
  MYSQLDUMP_ARGS+=("--host=${DB_HOST:-127.0.0.1}" "--port=${DB_PORT:-3306}")
fi

if [[ -n "${DB_PASSWORD}" ]]; then
  MYSQLDUMP_ARGS+=("--password=${DB_PASSWORD}")
fi

mysqldump "${MYSQLDUMP_ARGS[@]}" "${DB_DATABASE}" > "${SQL_FILE}"

log "Buat zip backup: ${ZIP_FILE}"
(
  cd "${PROJECT_ROOT}"
  zip -r -q "${ZIP_FILE}" . \
    -x "backup/*" \
    -x ".git/*" \
    -x "node_modules/*" \
    -x "storage/logs/*" \
    -x "storage/framework/cache/*" \
    -x "storage/framework/sessions/*" \
    -x "storage/framework/views/*"
)

(
  cd "${WORK_DIR}"
  zip -q "${ZIP_FILE}" "$(basename "${SQL_FILE}")"
)

log "Hapus backup lebih lama dari ${RETENTION_DAYS} hari..."
find "${BACKUP_DIR}" -maxdepth 1 -type f -name "${APP_SLUG}_backup_*.zip" -mtime +"${RETENTION_DAYS}" -delete

log "Selesai: ${ZIP_FILE}"
