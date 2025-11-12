#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_SYNC_FILE="${ROOT_DIR}/.env.sync"

if [[ -f "${ENV_SYNC_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_SYNC_FILE}"
fi

: "${SYNC_REMOTE_USER:?Définissez SYNC_REMOTE_USER dans .env.sync}"
: "${SYNC_REMOTE_HOST:?Définissez SYNC_REMOTE_HOST dans .env.sync}"
: "${SYNC_REMOTE_PATH:?Définissez SYNC_REMOTE_PATH dans .env.sync}"

LOCAL_DIR="${ROOT_DIR}/shared-config/"
REMOTE="${SYNC_REMOTE_USER}@${SYNC_REMOTE_HOST}:${SYNC_REMOTE_PATH%/}/"

rsync -avz --delete "${LOCAL_DIR}" "${REMOTE}"
echo "✅ Synchronisation vers ${REMOTE} terminée."
