#!/usr/bin/env bash

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/babahdigital/wartelpas.git}"
BASE_DIR="${BASE_DIR:-/home/abdullah/lpsaring}"
APP_DIR="${APP_DIR:-$BASE_DIR/wartelpas}"
BACKUP_DIR="${BACKUP_DIR:-$BASE_DIR/.wartelpas-runtime-backup}"
PROXY_NETWORK="${PROXY_NETWORK:-proxy-network}"
APP_CONTAINER_NAME="${APP_CONTAINER_NAME:-wartelpas}"
APP_SERVICE_NAME="${APP_SERVICE_NAME:-mikhmon}"

RUNTIME_FILES=(
  "custom.ini"
  "include/env.php"
  "include/config.php"
  "include/config_legacy.php"
  "include/quickbt.php"
)

print_step() {
  echo
  echo "==> $1"
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Error: command '$1' tidak ditemukan"
    exit 1
  fi
}

copy_if_missing() {
  local src="$1"
  local dst="$2"

  if [[ -f "$dst" ]]; then
    return 0
  fi

  if [[ -f "$src" ]]; then
    cp -f "$src" "$dst"
    echo "Create from example: $dst"
    return 0
  fi

  echo "Warning: tidak menemukan '$dst' dan contoh '$src'"
}

require_cmd git
require_cmd docker

mkdir -p "$BASE_DIR"
mkdir -p "$BACKUP_DIR"

print_step "Backup runtime files (jika ada)"
rm -rf "$BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

if [[ -d "$APP_DIR" ]]; then
  for rel_path in "${RUNTIME_FILES[@]}"; do
    src="$APP_DIR/$rel_path"
    dst="$BACKUP_DIR/$rel_path"
    if [[ -f "$src" ]]; then
      mkdir -p "$(dirname "$dst")"
      cp -f "$src" "$dst"
      echo "Backup: $rel_path"
    fi
  done
fi

print_step "Hapus source lama Wartelpas"
rm -rf "$APP_DIR"

print_step "Clone fresh dari GitHub"
git clone "$REPO_URL" "$APP_DIR"

print_step "Restore runtime files"
for rel_path in "${RUNTIME_FILES[@]}"; do
  src="$BACKUP_DIR/$rel_path"
  dst="$APP_DIR/$rel_path"
  if [[ -f "$src" ]]; then
    mkdir -p "$(dirname "$dst")"
    cp -f "$src" "$dst"
    echo "Restore: $rel_path"
  fi
done

copy_if_missing "$APP_DIR/custom.ini.example" "$APP_DIR/custom.ini"
copy_if_missing "$APP_DIR/include/env.example.php" "$APP_DIR/include/env.php"
copy_if_missing "$APP_DIR/include/config.example.php" "$APP_DIR/include/config.php"
copy_if_missing "$APP_DIR/include/config_legacy.example.php" "$APP_DIR/include/config_legacy.php"

print_step "Build fresh tanpa cache (khusus Wartelpas)"
cd "$APP_DIR"
docker compose build --no-cache "$APP_SERVICE_NAME"

print_step "Up service Wartelpas saja"
docker compose up -d --force-recreate "$APP_SERVICE_NAME"

print_step "Connect container Wartelpas ke network global proxy"
if docker network inspect "$PROXY_NETWORK" >/dev/null 2>&1; then
  docker network connect "$PROXY_NETWORK" "$APP_CONTAINER_NAME" >/dev/null 2>&1 || true
  echo "Network OK: $PROXY_NETWORK"
else
  echo "Warning: network '$PROXY_NETWORK' tidak ditemukan, skip connect"
fi

print_step "Status akhir"
docker compose ps
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep -E "NAMES|^${APP_CONTAINER_NAME}[[:space:]]" || true

echo
echo "Selesai. Hanya stack Wartelpas yang diproses; container Docker lain tidak disentuh."
