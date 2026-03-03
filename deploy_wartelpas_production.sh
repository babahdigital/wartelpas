#!/usr/bin/env bash

set -euo pipefail

SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_raspi_ed25519}"
SSH_USER_HOST="${SSH_USER_HOST:-abdullah@159.89.192.31}"
SSH_PORT="${SSH_PORT:-1983}"

REPO_URL="${REPO_URL:-https://github.com/babahdigital/wartelpas.git}"
REMOTE_BASE="/home/abdullah/lpsaring"
REMOTE_APP="$REMOTE_BASE/wartelpas"
REMOTE_BACKUP="$REMOTE_BASE/.wartelpas-runtime-backup"
PROXY_NETWORK="proxy-network"
APP_CONTAINER_NAME="wartelpas"
APP_SERVICE_NAME="mikhmon"

STRICT=0
CLEAN=1
BUILD=1
NO_CACHE=1
RECREATE=1

RUNTIME_FILES=(
  ".htaccess"
  "htaccess-templated"
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

usage() {
  cat <<'EOF'
Usage:
  ./deploy_wartelpas_production.sh [flags]

Flags utama:
  --clean            Hapus folder wartelpas lalu clone ulang (default: ON)
  --no-clean         Jangan hapus/clone ulang, gunakan source yang ada
  --build            Build image wartelpas (default: ON)
  --no-build         Skip build
  --no-cache         Build tanpa cache (default: ON)
  --cache            Build dengan cache
  --recreate         Recreate container wartelpas (default: ON)
  --no-recreate      Skip recreate container
  --recreate-only    Shortcut: --no-clean --no-build --recreate
  --strict           Validasi host/path target agar tidak melebar
  --help             Tampilkan bantuan

Contoh:
  ./deploy_wartelpas_production.sh --clean --strict --no-cache --recreate
  ./deploy_wartelpas_production.sh --recreate-only --strict
EOF
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Error: command '$1' tidak ditemukan"
    exit 1
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --clean) CLEAN=1 ;;
    --no-clean) CLEAN=0 ;;
    --build) BUILD=1 ;;
    --no-build) BUILD=0 ;;
    --no-cache) NO_CACHE=1 ;;
    --cache) NO_CACHE=0 ;;
    --recreate) RECREATE=1 ;;
    --no-recreate) RECREATE=0 ;;
    --recreate-only)
      CLEAN=0
      BUILD=0
      NO_CACHE=0
      RECREATE=1
      ;;
    --strict) STRICT=1 ;;
    --help)
      usage
      exit 0
      ;;
    *)
      echo "Error: flag tidak dikenali: $1"
      usage
      exit 1
      ;;
  esac
  shift
done

require_cmd git
require_cmd ssh

if [[ "$STRICT" -eq 1 ]]; then
  if [[ "$SSH_USER_HOST" != "abdullah@159.89.192.31" ]]; then
    echo "Error strict mode: SSH target harus abdullah@159.89.192.31"
    exit 1
  fi
  if [[ "$SSH_PORT" != "1983" ]]; then
    echo "Error strict mode: SSH port harus 1983"
    exit 1
  fi
fi

print_step "Deploy wartelpas only via SSH ($SSH_USER_HOST:$SSH_PORT)"

ssh -i "$SSH_KEY" -p "$SSH_PORT" "$SSH_USER_HOST" \
  CLEAN="$CLEAN" \
  BUILD="$BUILD" \
  NO_CACHE="$NO_CACHE" \
  RECREATE="$RECREATE" \
  STRICT="$STRICT" \
  REPO_URL="$REPO_URL" \
  REMOTE_BASE="$REMOTE_BASE" \
  REMOTE_APP="$REMOTE_APP" \
  REMOTE_BACKUP="$REMOTE_BACKUP" \
  PROXY_NETWORK="$PROXY_NETWORK" \
  APP_CONTAINER_NAME="$APP_CONTAINER_NAME" \
  APP_SERVICE_NAME="$APP_SERVICE_NAME" \
  'bash -s' <<'REMOTE_SCRIPT'
set -euo pipefail

print_step() {
  echo
  echo "==> $1"
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

RUNTIME_FILES=(
  ".htaccess"
  "htaccess-templated"
  "custom.ini"
  "include/env.php"
  "include/config.php"
  "include/config_legacy.php"
  "include/quickbt.php"
)

if [[ "$STRICT" -eq 1 ]]; then
  [[ "$REMOTE_APP" == "/home/abdullah/lpsaring/wartelpas" ]] || {
    echo "Error strict mode: path target harus /home/abdullah/lpsaring/wartelpas"
    exit 1
  }
fi

mkdir -p "$REMOTE_BASE"

if [[ "$CLEAN" -eq 1 ]]; then
  print_step "Backup runtime files"
  rm -rf "$REMOTE_BACKUP"
  mkdir -p "$REMOTE_BACKUP"

  if [[ -d "$REMOTE_APP" ]]; then
    for rel_path in "${RUNTIME_FILES[@]}"; do
      src="$REMOTE_APP/$rel_path"
      dst="$REMOTE_BACKUP/$rel_path"
      if [[ -f "$src" ]]; then
        mkdir -p "$(dirname "$dst")"
        cp -f "$src" "$dst"
        echo "Backup: $rel_path"
      fi
    done
  fi

  print_step "Hapus source lama + clone fresh"
  rm -rf "$REMOTE_APP"
  git clone "$REPO_URL" "$REMOTE_APP"

  print_step "Restore runtime files"
  for rel_path in "${RUNTIME_FILES[@]}"; do
    src="$REMOTE_BACKUP/$rel_path"
    dst="$REMOTE_APP/$rel_path"
    if [[ -f "$src" ]]; then
      mkdir -p "$(dirname "$dst")"
      cp -f "$src" "$dst"
      echo "Restore: $rel_path"
    fi
  done
fi

copy_if_missing "$REMOTE_APP/custom.ini.example" "$REMOTE_APP/custom.ini"
copy_if_missing "$REMOTE_APP/include/env.example.php" "$REMOTE_APP/include/env.php"
copy_if_missing "$REMOTE_APP/include/config.example.php" "$REMOTE_APP/include/config.php"
copy_if_missing "$REMOTE_APP/include/config_legacy.example.php" "$REMOTE_APP/include/config_legacy.php"

cd "$REMOTE_APP"

if [[ "$BUILD" -eq 1 ]]; then
  print_step "Build image wartelpas"
  if [[ "$NO_CACHE" -eq 1 ]]; then
    docker compose build --no-cache "$APP_SERVICE_NAME"
  else
    docker compose build "$APP_SERVICE_NAME"
  fi
fi

if [[ "$RECREATE" -eq 1 ]]; then
  print_step "Recreate container wartelpas"
  docker compose up -d --force-recreate "$APP_SERVICE_NAME"

  print_step "Connect ke proxy-network"
  if docker network inspect "$PROXY_NETWORK" >/dev/null 2>&1; then
    docker network connect "$PROXY_NETWORK" "$APP_CONTAINER_NAME" >/dev/null 2>&1 || true
    echo "Network OK: $PROXY_NETWORK"
  else
    echo "Warning: network '$PROXY_NETWORK' tidak ditemukan"
  fi
fi

print_step "Status akhir"
docker compose ps
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep -E "NAMES|^${APP_CONTAINER_NAME}[[:space:]]" || true

echo
echo "Selesai: hanya Wartelpas yang diproses, container lain tidak disentuh."
REMOTE_SCRIPT
