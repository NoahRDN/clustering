#!/bin/bash
set -euo pipefail

CONFIG="/usr/local/etc/haproxy/haproxy.cfg"
PIDFILE="/var/run/haproxy/haproxy.pid"
RUNTIME_DIR="/var/run/haproxy"
FLAG_FILE="${RUNTIME_DIR}/reload.flag"

mkdir -p "${RUNTIME_DIR}" || true
chmod 777 "${RUNTIME_DIR}" 2>/dev/null || true

reload_haproxy() {
  echo "[haproxy-entrypoint] Reload requested at $(date --iso-8601=seconds)"
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
    if [[ -f "${FLAG_FILE}" ]]; then
      rm -f "${FLAG_FILE}"
      reload_haproxy
    fi
    sleep 1
  done
}

watch_reload_flag &

exec haproxy -W -f "${CONFIG}" -p "${PIDFILE}"
