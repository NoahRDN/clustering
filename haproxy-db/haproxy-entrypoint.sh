#!/bin/bash
set -euo pipefail

CONFIG="/usr/local/etc/haproxy/haproxy.cfg"
PIDFILE="/var/run/haproxy/db/haproxy.pid"
RUNTIME_DIR="/var/run/haproxy/db"
EXTERNAL_RUNTIME="/haproxy-db-runtime"

ensure_runtime_dir() {
  local dir="$1"
  if [[ -z "${dir}" ]]; then
    return
  fi
  mkdir -p "${dir}" 2>/dev/null || true
  chown haproxy:haproxy "${dir}" 2>/dev/null || true
  chmod 770 "${dir}" 2>/dev/null || true
}

cleanup_socket() {
  local dir="$1"
  if [[ -z "${dir}" ]]; then
    return
  fi
  local sock="${dir}/admin.sock"
  if [[ -S "${sock}" ]]; then
    rm -f "${sock}" 2>/dev/null || true
  fi
}

ensure_runtime_dir "${RUNTIME_DIR}"
cleanup_socket "${RUNTIME_DIR}"
if [[ -n "${EXTERNAL_RUNTIME}" ]]; then
  ensure_runtime_dir "${EXTERNAL_RUNTIME}"
  cleanup_socket "${EXTERNAL_RUNTIME}"
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
  echo "[haproxy-db-entrypoint] Reload requested (${source}) at $(date --iso-8601=seconds)"
  if haproxy -c -f "${CONFIG}" >/dev/null 2>&1; then
    if [[ -f "${PIDFILE}" ]]; then
      haproxy -W -f "${CONFIG}" -p "${PIDFILE}" -sf "$(cat "${PIDFILE}")"
    else
      haproxy -W -f "${CONFIG}" -p "${PIDFILE}"
    fi
  else
    echo "[haproxy-db-entrypoint] Config check failed, keeping previous process" >&2
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
