#!/usr/bin/env bash

set -euo pipefail

SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_raspi_ed25519}"
SSH_USER_HOST="${SSH_USER_HOST:-abdullah@159.89.192.31}"
SSH_PORT="${SSH_PORT:-1983}"

REPO_URL="${REPO_URL:-https://github.com/babahdigital/wartelpas.git}"
DEPLOY_REF="${DEPLOY_REF:-main}"
REMOTE_BASE="/home/abdullah/lpsaring"
REMOTE_APP="$REMOTE_BASE/wartelpas"
REMOTE_BACKUP="$REMOTE_BASE/.wartelpas-runtime-backup"
PROXY_NETWORK="proxy-network"
APP_CONTAINER_NAME="wartelpas"
APP_SERVICE_NAME="mikhmon"
PUBLIC_BASE_URL="https://wartelpas.babahdigital.net"
ORIGIN_BASE_URL="http://127.0.0.1:8081"
PROXY_CONF_SRC_REL="nginx/conf.d"
PROXY_CONF_DST="/home/abdullah/nginx/conf.d"

NGINX_SYNC_FILES_BASE=(
  "wartelpas.conf"
)

NGINX_SYNC_FILES_ALL=(
  "default.conf"
  "lpsaring.conf"
  "wartelpas.conf"
)

ALL_NGINX_SYNC=0

STRICT=0
CLEAN=1
BUILD=1
NO_CACHE=1
RECREATE=1
DRY_RUN=0

RUNTIME_FILES=(
  "custom.ini"
  "include/env.php"
)

RUNTIME_DIRS=(
  "db_data"
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
  --dry-run          Simulasi langkah deploy tanpa perubahan
  --sync-all-nginx   Sinkronisasi semua conf nginx (default+lpsaring+wartelpas)
  --sync-wartelpas-only
                     Sinkronisasi hanya wartelpas.conf (default)
  --strict           Validasi host/path target agar tidak melebar
  --help             Tampilkan bantuan

Contoh:
  ./deploy_wartelpas_production.sh --clean --strict --no-cache --recreate
  ./deploy_wartelpas_production.sh --clean --strict --dry-run
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
    --sync-all-nginx) ALL_NGINX_SYNC=1 ;;
    --sync-wartelpas-only) ALL_NGINX_SYNC=0 ;;
    --strict) STRICT=1 ;;
    --dry-run) DRY_RUN=1 ;;
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

if [[ "$DRY_RUN" -eq 1 ]]; then
  print_step "Mode DRY-RUN aktif: tidak ada perubahan yang akan dieksekusi"
fi

ssh -i "$SSH_KEY" -p "$SSH_PORT" "$SSH_USER_HOST" \
  CLEAN="$CLEAN" \
  BUILD="$BUILD" \
  NO_CACHE="$NO_CACHE" \
  RECREATE="$RECREATE" \
  ALL_NGINX_SYNC="$ALL_NGINX_SYNC" \
  DRY_RUN="$DRY_RUN" \
  STRICT="$STRICT" \
  REPO_URL="$REPO_URL" \
  DEPLOY_REF="$DEPLOY_REF" \
  REMOTE_BASE="$REMOTE_BASE" \
  REMOTE_APP="$REMOTE_APP" \
  REMOTE_BACKUP="$REMOTE_BACKUP" \
  PROXY_NETWORK="$PROXY_NETWORK" \
  APP_CONTAINER_NAME="$APP_CONTAINER_NAME" \
  APP_SERVICE_NAME="$APP_SERVICE_NAME" \
  PUBLIC_BASE_URL="$PUBLIC_BASE_URL" \
  ORIGIN_BASE_URL="$ORIGIN_BASE_URL" \
  PROXY_CONF_SRC_REL="$PROXY_CONF_SRC_REL" \
  PROXY_CONF_DST="$PROXY_CONF_DST" \
  'bash -s' <<'REMOTE_SCRIPT'
set -euo pipefail

print_step() {
  echo
  echo "==> $1"
}

run_cmd() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] $*"
  else
    "$@"
  fi
}

require_file() {
  local path="$1"
  if [[ ! -f "$path" ]]; then
    echo "Error: file wajib produksi tidak ditemukan: $path"
    exit 1
  fi
}

http_code() {
  local url="$1"
  curl -k -sS -o /dev/null -w '%{http_code}' "$url"
}

code_ok() {
  local code="$1"
  [[ "$code" == "200" || "$code" == "302" ]]
}

wait_http_ok() {
  local label="$1"
  local url="$2"
  local max_try="${3:-20}"
  local sleep_sec="${4:-2}"
  local i=1
  local code="000"

  while (( i <= max_try )); do
    code="$(http_code "$url" || echo 000)"
    if code_ok "$code"; then
      echo "$label OK ($code): $url"
      return 0
    fi
    echo "$label wait [$i/$max_try] code=$code url=$url"
    sleep "$sleep_sec"
    ((i++))
  done

  echo "Error: $label gagal, code terakhir=$code url=$url"
  return 1
}

RUNTIME_FILES=(
  "custom.ini"
  "include/env.php"
)

RUNTIME_DIRS=(
  "db_data"
)

NGINX_SYNC_FILES_BASE=(
  "wartelpas.conf"
)

NGINX_SYNC_FILES_ALL=(
  "default.conf"
  "lpsaring.conf"
  "wartelpas.conf"
)

if [[ "${ALL_NGINX_SYNC:-0}" -eq 1 ]]; then
  NGINX_SYNC_FILES=("${NGINX_SYNC_FILES_ALL[@]}")
else
  NGINX_SYNC_FILES=("${NGINX_SYNC_FILES_BASE[@]}")
fi

if [[ "$STRICT" -eq 1 ]]; then
  [[ "$REMOTE_APP" == "/home/abdullah/lpsaring/wartelpas" ]] || {
    echo "Error strict mode: path target harus /home/abdullah/lpsaring/wartelpas"
    exit 1
  }
  [[ "$PROXY_CONF_DST" == "/home/abdullah/nginx/conf.d" ]] || {
    echo "Error strict mode: nginx conf path harus /home/abdullah/nginx/conf.d"
    exit 1
  }
fi

run_cmd mkdir -p "$REMOTE_BASE"

if [[ "$CLEAN" -eq 1 ]]; then
  print_step "Backup runtime files"
  run_cmd rm -rf "$REMOTE_BACKUP"
  run_cmd mkdir -p "$REMOTE_BACKUP"

  if [[ -d "$REMOTE_APP" ]]; then
    for rel_path in "${RUNTIME_FILES[@]}"; do
      src="$REMOTE_APP/$rel_path"
      dst="$REMOTE_BACKUP/$rel_path"
      if [[ -f "$src" ]]; then
        run_cmd mkdir -p "$(dirname "$dst")"
        run_cmd cp -f "$src" "$dst"
        echo "Backup: $rel_path"
      fi
    done

    for rel_dir in "${RUNTIME_DIRS[@]}"; do
      src="$REMOTE_APP/$rel_dir"
      dst="$REMOTE_BACKUP/$rel_dir"
      if [[ -d "$src" ]]; then
        run_cmd rm -rf "$dst"
        run_cmd mkdir -p "$(dirname "$dst")"
        run_cmd cp -a "$src" "$dst"
        echo "Backup dir: $rel_dir"
      fi
    done
  fi

  print_step "Hapus source lama + clone fresh"
  run_cmd rm -rf "$REMOTE_APP"
  run_cmd git clone "$REPO_URL" "$REMOTE_APP"

  print_step "Restore runtime files"
  for rel_path in "${RUNTIME_FILES[@]}"; do
    src="$REMOTE_BACKUP/$rel_path"
    dst="$REMOTE_APP/$rel_path"
    if [[ -f "$src" ]]; then
      run_cmd mkdir -p "$(dirname "$dst")"
      run_cmd cp -f "$src" "$dst"
      echo "Restore: $rel_path"
    fi
  done

  for rel_dir in "${RUNTIME_DIRS[@]}"; do
    src="$REMOTE_BACKUP/$rel_dir"
    dst="$REMOTE_APP/$rel_dir"
    if [[ -d "$src" ]]; then
      run_cmd rm -rf "$dst"
      run_cmd mkdir -p "$(dirname "$dst")"
      run_cmd cp -a "$src" "$dst"
      echo "Restore dir: $rel_dir"
    fi
  done
fi

if [[ "$CLEAN" -eq 0 ]]; then
  print_step "Sinkronisasi source terbaru (tanpa clean)"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] git -C $REMOTE_APP fetch --all --prune"
    echo "[DRY-RUN] git -C $REMOTE_APP reset --hard origin/$DEPLOY_REF"
  else
    if [[ ! -d "$REMOTE_APP/.git" ]]; then
      echo "Error: repo tidak ditemukan di $REMOTE_APP (.git missing)"
      exit 1
    fi
    git -C "$REMOTE_APP" fetch --all --prune
    git -C "$REMOTE_APP" reset --hard "origin/$DEPLOY_REF"
  fi
fi

print_step "Validasi file runtime produksi (tanpa fallback .example)"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] require_file $REMOTE_APP/custom.ini"
  echo "[DRY-RUN] require_file $REMOTE_APP/include/env.php"
else
  require_file "$REMOTE_APP/custom.ini"
  require_file "$REMOTE_APP/include/env.php"
fi

print_step "Pastikan folder runtime bind-mount ada"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] mkdir -p $REMOTE_APP/session"
  echo "[DRY-RUN] chmod 777 $REMOTE_APP/session"
else
  mkdir -p "$REMOTE_APP/session"
  chmod 777 "$REMOTE_APP/session" || true
fi

print_step "Pastikan file database writable"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] mkdir -p $REMOTE_APP/db_data"
  echo "[DRY-RUN] chmod 777 $REMOTE_APP/db_data"
  echo "[DRY-RUN] chmod 666 $REMOTE_APP/db_data/*.db"
  echo "[DRY-RUN] chmod 666 $REMOTE_APP/db_data/*.db-wal"
  echo "[DRY-RUN] chmod 666 $REMOTE_APP/db_data/*.db-shm"
else
  mkdir -p "$REMOTE_APP/db_data"
  chmod 777 "$REMOTE_APP/db_data" || true
  find "$REMOTE_APP/db_data" -maxdepth 1 -type f \( -name '*.db' -o -name '*.db-wal' -o -name '*.db-shm' \) -exec chmod 666 {} \; || true
fi

cd "$REMOTE_APP"

if [[ "${ALL_NGINX_SYNC:-0}" -eq 1 ]]; then
  print_step "Sinkronisasi nginx conf ke host proxy (SEMUA conf)"
else
  print_step "Sinkronisasi nginx conf ke host proxy (hanya wartelpas.conf)"
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  for f in "${NGINX_SYNC_FILES[@]}"; do
    echo "[DRY-RUN] cp -f $REMOTE_APP/$PROXY_CONF_SRC_REL/$f $PROXY_CONF_DST/$f"
  done
  echo "[DRY-RUN] docker exec global-nginx-proxy nginx -t"
  echo "[DRY-RUN] docker exec global-nginx-proxy nginx -s reload"
else
  mkdir -p "$PROXY_CONF_DST"
  for f in "${NGINX_SYNC_FILES[@]}"; do
    src="$REMOTE_APP/$PROXY_CONF_SRC_REL/$f"
    dst="$PROXY_CONF_DST/$f"
    require_file "$src"
    cp -f "$src" "$dst"
    echo "Sync nginx: $f"
  done
  docker exec global-nginx-proxy nginx -t
  docker exec global-nginx-proxy nginx -s reload
fi

if [[ "$BUILD" -eq 1 ]]; then
  print_step "Build image wartelpas"
  if [[ "$NO_CACHE" -eq 1 ]]; then
    run_cmd docker compose build --no-cache "$APP_SERVICE_NAME"
  else
    run_cmd docker compose build "$APP_SERVICE_NAME"
  fi
fi

if [[ "$RECREATE" -eq 1 ]]; then
  print_step "Recreate container wartelpas"
  run_cmd docker compose up -d --force-recreate "$APP_SERVICE_NAME"

  print_step "Connect ke proxy-network"
  if docker network inspect "$PROXY_NETWORK" >/dev/null 2>&1; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
      echo "[DRY-RUN] docker network connect $PROXY_NETWORK $APP_CONTAINER_NAME"
    else
      docker network connect "$PROXY_NETWORK" "$APP_CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    echo "Network OK: $PROXY_NETWORK"
  else
    echo "Warning: network '$PROXY_NETWORK' tidak ditemukan"
  fi
fi

print_step "Validasi koneksi network + health endpoint"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] docker inspect $APP_CONTAINER_NAME --format '{{json .NetworkSettings.Networks}}'"
  echo "[DRY-RUN] wait_http_ok ORIGIN_ROOT ${ORIGIN_BASE_URL}/"
  echo "[DRY-RUN] wait_http_ok ORIGIN_SESSION ${ORIGIN_BASE_URL}/?session=S3c7x9_LB"
  echo "[DRY-RUN] wait_http_ok ORIGIN_ADMIN ${ORIGIN_BASE_URL}/admin.php?id=sessions"
  echo "[DRY-RUN] wait_http_ok PUBLIC_ROOT ${PUBLIC_BASE_URL}/"
  echo "[DRY-RUN] wait_http_ok PUBLIC_ADMIN ${PUBLIC_BASE_URL}/admin.php?id=sessions"
else
  network_json="$(docker inspect "$APP_CONTAINER_NAME" --format '{{json .NetworkSettings.Networks}}' 2>/dev/null || true)"
  echo "Networks: $network_json"
  if [[ -n "$network_json" && "$network_json" == *"\"$PROXY_NETWORK\""* ]]; then
    echo "Attach check OK: $APP_CONTAINER_NAME terhubung ke $PROXY_NETWORK"
  else
    echo "Error: $APP_CONTAINER_NAME belum terhubung ke $PROXY_NETWORK"
    exit 1
  fi

  wait_http_ok "ORIGIN_ROOT" "${ORIGIN_BASE_URL}/"
  wait_http_ok "ORIGIN_SESSION" "${ORIGIN_BASE_URL}/?session=S3c7x9_LB"
  wait_http_ok "ORIGIN_ADMIN" "${ORIGIN_BASE_URL}/admin.php?id=sessions"

  if ! wait_http_ok "PUBLIC_ROOT" "${PUBLIC_BASE_URL}/" 5 2; then
    echo "Warning: PUBLIC_ROOT belum OK dari sisi server deploy (bisa dipengaruhi policy edge/WAF)."
  fi
  if ! wait_http_ok "PUBLIC_ADMIN" "${PUBLIC_BASE_URL}/admin.php?id=sessions" 5 2; then
    echo "Warning: PUBLIC_ADMIN belum OK dari sisi server deploy (bisa dipengaruhi policy edge/WAF)."
  fi
fi

print_step "Status akhir"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] docker compose ps"
  echo "[DRY-RUN] docker ps --format 'table {{.Names}}\\t{{.Status}}\\t{{.Ports}}' | grep -E 'NAMES|^${APP_CONTAINER_NAME}[[:space:]]'"
else
  docker compose ps
  docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep -E "NAMES|^${APP_CONTAINER_NAME}[[:space:]]" || true
fi

echo
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Selesai DRY-RUN: tidak ada perubahan yang diterapkan."
else
  echo "Selesai: hanya Wartelpas yang diproses, container lain tidak disentuh."
fi
REMOTE_SCRIPT
