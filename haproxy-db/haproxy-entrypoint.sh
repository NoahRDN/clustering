#!/bin/sh
set -eu

CONFIG_LOCAL="/usr/local/etc/haproxy/haproxy.cfg"
PIDFILE="/var/run/haproxy/haproxy.pid"
RUNTIME_DIR="/var/run/haproxy"
FLAG_FILE="${RUNTIME_DIR}/reload.flag"
CONFIG_REMOTE="${RUNTIME_DIR}/remote.cfg"
ACTIVE_CONFIG="${CONFIG_LOCAL}"
APT_READY=0

mkdir -p "${RUNTIME_DIR}"
chmod 777 "${RUNTIME_DIR}" 2>/dev/null || true

download_to_file() {
  url="$1"
  dest="$2"

  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$dest"
    return $?
  fi

  if command -v wget >/dev/null 2>&1; then
    wget -qO "$dest" "$url"
    return $?
  fi

  if command -v apt-get >/dev/null 2>&1; then
    if [ "${APT_READY}" -eq 0 ]; then
      echo "[haproxy-db-entrypoint] Installing curl to download configs..."
      apt-get update >/dev/null
      apt-get install -y curl >/dev/null
      APT_READY=1
    fi
    curl -fsSL "$url" -o "$dest"
    return $?
  fi

  echo "[haproxy-db-entrypoint] No downloader available (curl/wget)" >&2
  return 1
}

fetch_remote_config() {
  if [ -z "${HAPROXY_CONFIG_URL:-}" ]; then
    return 1
  fi

  tmp_file="${CONFIG_REMOTE}.tmp"
  echo "[haproxy-db-entrypoint] Downloading config from ${HAPROXY_CONFIG_URL}"
  if download_to_file "${HAPROXY_CONFIG_URL}" "${tmp_file}"; then
    if haproxy -c -f "${tmp_file}" >/dev/null 2>&1; then
      mv "${tmp_file}" "${CONFIG_REMOTE}"
      ACTIVE_CONFIG="${CONFIG_REMOTE}"
      echo "[haproxy-db-entrypoint] Remote config applied."
      return 0
    else
      echo "[haproxy-db-entrypoint] Remote config invalid, keeping previous process" >&2
    fi
  else
    echo "[haproxy-db-entrypoint] Failed to download ${HAPROXY_CONFIG_URL}" >&2
  fi

  rm -f "${tmp_file}" 2>/dev/null || true
  return 1
}

ensure_config_source() {
  if fetch_remote_config; then
    return 0
  fi
  if [ "${ACTIVE_CONFIG}" != "${CONFIG_LOCAL}" ]; then
    echo "[haproxy-db-entrypoint] Falling back to local haproxy.cfg"
  fi
  ACTIVE_CONFIG="${CONFIG_LOCAL}"
  return 1
}

reload_haproxy() {
  ensure_config_source || true
  echo "[haproxy-db-entrypoint] Reload requested at $(date)"
  if haproxy -c -f "${ACTIVE_CONFIG}" >/dev/null 2>&1; then
    if [ -f "${PIDFILE}" ]; then
      haproxy -W -f "${ACTIVE_CONFIG}" -p "${PIDFILE}" -sf "$(cat "${PIDFILE}")"
    else
      haproxy -W -f "${ACTIVE_CONFIG}" -p "${PIDFILE}"
    fi
  else
    echo "[haproxy-db-entrypoint] Config check failed, keeping previous process" >&2
  fi
}

watch_reload_flag() {
  while true; do
    if [ -f "${FLAG_FILE}" ]; then
      rm -f "${FLAG_FILE}"
      reload_haproxy
    fi
    sleep 1
  done
}

ensure_config_source || true
watch_reload_flag &

exec haproxy -W -f "${ACTIVE_CONFIG}" -p "${PIDFILE}"
