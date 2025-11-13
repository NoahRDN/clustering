#!/bin/bash
set -euo pipefail

CONFIG="/usr/local/etc/haproxy/haproxy.cfg"
PIDFILE="/var/run/haproxy/haproxy.pid"
RUNTIME_DIR="/var/run/haproxy"
EXTERNAL_RUNTIME="/haproxy-runtime"

mkdir -p "${RUNTIME_DIR}" || true
chmod 777 "${RUNTIME_DIR}" 2>/dev/null || true
if [[ -n "${EXTERNAL_RUNTIME}" ]]; then
  mkdir -p "${EXTERNAL_RUNTIME}" 2>/dev/null || true
fi

FLAG_FILES=()
add_flag_targets() {
  local base="$1"
  if [[ -z "${base}" || ! -d "${base}" ]]; then
    return
  fi
  FLAG_FILES+=("${base}/reload.flag")
  FLAG_FILES+=("${base}/restart.flag")
}

add_flag_targets "${RUNTIME_DIR}"
add_flag_targets "${EXTERNAL_RUNTIME}"

reload_haproxy() {
  local source="${1:-manual}"
  echo "[haproxy-entrypoint] Reload requested (${source}) at $(date --iso-8601=seconds)"
  if haproxy -c -f "${CONFIG}" >/dev/null 2>&1; then
    if [[ -f "${PIDFILE}" ]]; then
      haproxy -W -f "${CONFIG}" -p "${PIDFILE}" -sf "$(cat "${PIDFILE}")"
    else
      haproxy -W -f "${CONFIG}" -p "${PIDFILE}"
    fi
  else
    echo "[haproxy-entrypoint] Config check failed, keeping previous process" >&2
  fi
}

watch_reload_flag() {
  while true; do
    for flag in "${FLAG_FILES[@]}"; do
      if [[ -f "${flag}" ]]; then
        rm -f "${flag}"
        reload_haproxy "${flag}"
      fi
    done
    sleep 1
  done
}

watch_reload_flag &

exec haproxy -W -f "${CONFIG}" -p "${PIDFILE}"
