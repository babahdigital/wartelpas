#!/usr/bin/env bash

set -euo pipefail

SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_raspi_ed25519}"
SSH_USER_HOST="${SSH_USER_HOST:-abdullah@194.233.80.163}"
SSH_PORT="${SSH_PORT:-1983}"

REPO_URL="${REPO_URL:-https://github.com/babahdigital/wartelpas.git}"
DEPLOY_REF="${DEPLOY_REF:-main}"
REMOTE_BASE="/home/abdullah/lpsaring"
REMOTE_APP="$REMOTE_BASE/wartelpas"
REMOTE_BACKUP="$REMOTE_BASE/.wartelpas-runtime-backup"
REMOTE_BACKUP_KEEP="${REMOTE_BACKUP_KEEP:-10}"
PROXY_NETWORK="proxy-network"
APP_CONTAINER_NAME="wartelpas"
APP_SERVICE_NAME="mikhmon"
PUBLIC_BASE_URL="https://wartelpas.babahdigital.net"
ORIGIN_BASE_URL="http://127.0.0.1:8081"
PROXY_CONF_SRC_DIR="${PROXY_CONF_SRC_DIR:-/home/abdullah/nginx/conf.d}"
PROXY_CONF_DST="/home/abdullah/nginx/conf.d"

NGINX_SYNC_FILES_BASE=(
  "wartelpas.conf"
)

NGINX_SYNC_FILES_ALL=(
  "wartelpas.conf"
)

ALL_NGINX_SYNC=0

STRICT=1
CLEAN=0
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
  --clean            Hapus folder wartelpas lalu clone ulang (default: OFF)
  --no-clean         Jangan hapus/clone ulang, gunakan source yang ada
  --build            Build image wartelpas (default: ON)
  --no-build         Skip build
  --no-cache         Build tanpa cache (default: ON)
  --cache            Build dengan cache
  --recreate         Recreate container wartelpas (default: ON)
  --no-recreate      Skip recreate container
  --recreate-only    Shortcut: --no-clean --no-build --recreate
  --dry-run          Simulasi langkah deploy tanpa perubahan
  --sync-all-nginx   Sinkronisasi semua conf nginx yang diizinkan (saat ini: wartelpas.conf)
  --sync-wartelpas-only
                     Sinkronisasi hanya wartelpas.conf (default)
  --strict           Validasi host/path target agar tidak melebar (default: ON)
  --no-strict        Nonaktifkan strict mode (tidak disarankan)
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
    --no-strict) STRICT=0 ;;
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
  if [[ "$SSH_USER_HOST" != "abdullah@194.233.80.163" ]]; then
    echo "Error strict mode: SSH target harus abdullah@194.233.80.163"
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
  REMOTE_BACKUP_KEEP="$REMOTE_BACKUP_KEEP" \
  PROXY_NETWORK="$PROXY_NETWORK" \
  APP_CONTAINER_NAME="$APP_CONTAINER_NAME" \
  APP_SERVICE_NAME="$APP_SERVICE_NAME" \
  PUBLIC_BASE_URL="$PUBLIC_BASE_URL" \
  ORIGIN_BASE_URL="$ORIGIN_BASE_URL" \
  PROXY_CONF_SRC_DIR="$PROXY_CONF_SRC_DIR" \
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

run_quiet_cmd() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] $*"
    return 0
  else
    if "$@" >/dev/null 2>&1; then
      return 0
    fi
    return 1
  fi
}

require_file() {
  local path="$1"
  if [[ ! -f "$path" ]]; then
    echo "Error: file wajib produksi tidak ditemukan: $path"
    exit 1
  fi
}

verify_file_copy() {
  local src_file="$1"
  local dst_file="$2"
  local rel_name="$3"

  if [[ ! -f "$dst_file" ]]; then
    echo "Error: backup file hilang setelah copy: $rel_name"
    return 1
  fi

  if ! cmp -s "$src_file" "$dst_file"; then
    echo "Error: backup file tidak identik: $rel_name"
    return 1
  fi

  return 0
}

verify_dir_copy() {
  local src_dir="$1"
  local dst_dir="$2"
  local rel_name="$3"

  if [[ ! -d "$dst_dir" ]]; then
    echo "Error: backup dir hilang setelah copy: $rel_name"
    return 1
  fi

  if [[ "$rel_name" == "db_data" ]]; then
    local src_db_count
    local dst_db_count
    src_db_count="$(find "$src_dir" -maxdepth 1 -type f -name '*.db' | wc -l | tr -d '[:space:]')"
    dst_db_count="$(find "$dst_dir" -maxdepth 1 -type f -name '*.db' | wc -l | tr -d '[:space:]')"

    if [[ "$dst_db_count" == "0" ]]; then
      echo "Error: backup dir db_data tidak punya file .db"
      return 1
    fi

    if [[ "$src_db_count" != "0" ]] && (( dst_db_count < src_db_count )); then
      echo "Error: backup dir db_data kehilangan file .db (src=$src_db_count dst=$dst_db_count)"
      return 1
    fi

    if command -v sqlite3 >/dev/null 2>&1; then
      local db_file
      local quick_check
      while IFS= read -r db_file; do
        quick_check="$(sqlite3 "$db_file" 'PRAGMA quick_check;' 2>/dev/null | head -n 1 | tr '[:upper:]' '[:lower:]')"
        if [[ "$quick_check" != "ok" ]]; then
          echo "Error: quick_check sqlite gagal untuk $(basename "$db_file")"
          return 1
        fi
      done < <(find "$dst_dir" -maxdepth 1 -type f -name '*.db' -print)
    fi

    return 0
  fi

  local src_count
  local dst_count
  src_count="$(find "$src_dir" -type f | wc -l | tr -d '[:space:]')"
  dst_count="$(find "$dst_dir" -type f | wc -l | tr -d '[:space:]')"

  if [[ "$src_count" != "$dst_count" ]]; then
    echo "Error: backup dir mismatch file count ($rel_name): src=$src_count dst=$dst_count"
    return 1
  fi

  if [[ "$src_count" != "0" ]]; then
    local manifest
    manifest="$(mktemp)"
    (cd "$src_dir" && find . -type f -print0 | sort -z | xargs -0 sha256sum > "$manifest")
    if ! (cd "$dst_dir" && sha256sum -c "$manifest" >/dev/null 2>&1); then
      rm -f "$manifest"
      echo "Error: backup dir checksum tidak cocok: $rel_name"
      return 1
    fi
    rm -f "$manifest"
  fi

  return 0
}

cleanup_backup_retention() {
  local keep="${REMOTE_BACKUP_KEEP:-10}"
  if ! [[ "$keep" =~ ^[0-9]+$ ]] || (( keep < 1 )); then
    keep=10
  fi

  if [[ ! -d "$REMOTE_BACKUP" ]]; then
    return 0
  fi

  local -a dirs=()
  while IFS= read -r dir; do
    dirs+=("$dir")
  done < <(find "$REMOTE_BACKUP" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | awk '{print $2}')

  if (( ${#dirs[@]} <= keep )); then
    return 0
  fi

  local idx
  for (( idx=keep; idx<${#dirs[@]}; idx++ )); do
    run_cmd rm -rf "${dirs[$idx]}"
    echo "Pruned old backup: ${dirs[$idx]}"
  done
}

sync_git_bind_mount_ownership() {
  local uid gid
  uid="$(id -u)"
  gid="$(id -g)"

  local chown_targets="/var/www/html/img /var/www/html/report /var/www/html/settings /var/www/html/voucher"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] docker ps --format '{{.Names}}' | grep -Fx '$APP_CONTAINER_NAME'"
    echo "[DRY-RUN] docker exec $APP_CONTAINER_NAME sh -lc 'chown -R $uid:$gid $chown_targets'"
    return 0
  fi

  if docker ps --format '{{.Names}}' | grep -Fxq "$APP_CONTAINER_NAME"; then
    if docker exec "$APP_CONTAINER_NAME" sh -lc "chown -R $uid:$gid $chown_targets" >/dev/null 2>&1; then
      echo "Ownership sync OK untuk bind-mount tracked git"
    else
      echo "Info: ownership sync bind-mount dilewati (container tidak mengizinkan chown)"
    fi
  else
    echo "Info: container $APP_CONTAINER_NAME tidak aktif, ownership sync bind-mount dilewati"
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
  [[ "$PROXY_CONF_SRC_DIR" == "/home/abdullah/nginx/conf.d" ]] || {
    echo "Error strict mode: nginx conf source harus /home/abdullah/nginx/conf.d"
    exit 1
  }
  [[ "$PROXY_CONF_DST" == "/home/abdullah/nginx/conf.d" ]] || {
    echo "Error strict mode: nginx conf path harus /home/abdullah/nginx/conf.d"
    exit 1
  }
fi

run_cmd mkdir -p "$REMOTE_BASE"

RUNTIME_BACKUP_DIR=""
BACKUP_VERIFIED=0
NEED_RUNTIME_BACKUP=0
if [[ "$CLEAN" -eq 1 || "$STRICT" -eq 1 ]]; then
  NEED_RUNTIME_BACKUP=1
fi

if [[ "$NEED_RUNTIME_BACKUP" -eq 1 ]]; then
  print_step "Backup runtime files (wajib sebelum clean/strict)"
  run_cmd mkdir -p "$REMOTE_BACKUP"

  BACKUP_STAMP="$(date +%Y%m%d_%H%M%S)"
  RUNTIME_BACKUP_DIR="$REMOTE_BACKUP/$BACKUP_STAMP"
  run_cmd mkdir -p "$RUNTIME_BACKUP_DIR"

  if [[ -d "$REMOTE_APP" ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
      echo "[DRY-RUN] write backup metadata to $RUNTIME_BACKUP_DIR/backup.meta"
    else
      {
        echo "backup_at=$BACKUP_STAMP"
        echo "source=$REMOTE_APP"
        echo "strict=$STRICT"
        echo "clean=$CLEAN"
      } > "$RUNTIME_BACKUP_DIR/backup.meta"
    fi

    for rel_path in "${RUNTIME_FILES[@]}"; do
      src="$REMOTE_APP/$rel_path"
      dst="$RUNTIME_BACKUP_DIR/$rel_path"
      if [[ -f "$src" ]]; then
        run_cmd mkdir -p "$(dirname "$dst")"
        run_cmd cp -f "$src" "$dst"
        if [[ "$DRY_RUN" -eq 0 ]]; then
          verify_file_copy "$src" "$dst" "$rel_path"
        fi
        echo "Backup file verified: $rel_path"
      fi
    done

    for rel_dir in "${RUNTIME_DIRS[@]}"; do
      src="$REMOTE_APP/$rel_dir"
      dst="$RUNTIME_BACKUP_DIR/$rel_dir"
      if [[ -d "$src" ]]; then
        run_cmd rm -rf "$dst"
        run_cmd mkdir -p "$(dirname "$dst")"
        if [[ "$rel_dir" == "db_data" ]]; then
          if [[ "$DRY_RUN" -eq 1 ]]; then
            echo "[DRY-RUN] mkdir -p $dst"
            echo "[DRY-RUN] (cd $src && tar --exclude='*.db-wal' --exclude='*.db-shm' -cf - .) | (cd $dst && tar -xf -)"
            echo "[DRY-RUN] cp -f $src/*.db-wal $dst/*.db-wal (best-effort)"
            echo "[DRY-RUN] cp -f $src/*.db-shm $dst/*.db-shm (best-effort)"
          else
            mkdir -p "$dst"
            if ! (cd "$src" && tar --warning=no-file-changed --exclude='*.db-wal' --exclude='*.db-shm' -cf - .) | (cd "$dst" && tar -xf -); then
              echo "Warning: snapshot db_data berubah saat dibaca, lanjutkan dengan hasil backup terbaru."
            fi
            shopt -s nullglob
            for live_file in "$src"/*.db-wal "$src"/*.db-shm; do
              cp -f "$live_file" "$dst/$(basename "$live_file")" 2>/dev/null || true
            done
            shopt -u nullglob
          fi
        else
          run_cmd cp -a "$src" "$dst"
        fi
        if [[ "$DRY_RUN" -eq 0 ]]; then
          verify_dir_copy "$src" "$dst" "$rel_dir"
        fi
        echo "Backup dir verified: $rel_dir"
      fi
    done

    if [[ "$DRY_RUN" -eq 1 ]]; then
      echo "[DRY-RUN] touch $RUNTIME_BACKUP_DIR/.verified"
    else
      touch "$RUNTIME_BACKUP_DIR/.verified"
    fi

    BACKUP_VERIFIED=1
  else
    echo "Info: source $REMOTE_APP belum ada, backup runtime dilewati."
    BACKUP_VERIFIED=1
  fi

  print_step "Retention backup runtime"
  cleanup_backup_retention
fi

if [[ "$CLEAN" -eq 1 ]]; then
  if [[ "$BACKUP_VERIFIED" -ne 1 ]]; then
    echo "Error: backup runtime belum terverifikasi, clean dibatalkan."
    exit 1
  fi

  print_step "Hapus source lama + clone fresh"
  run_cmd rm -rf "$REMOTE_APP"
  run_cmd git clone "$REPO_URL" "$REMOTE_APP"

  print_step "Restore runtime files"
  for rel_path in "${RUNTIME_FILES[@]}"; do
    src="$RUNTIME_BACKUP_DIR/$rel_path"
    dst="$REMOTE_APP/$rel_path"
    if [[ -f "$src" ]]; then
      run_cmd mkdir -p "$(dirname "$dst")"
      run_cmd cp -f "$src" "$dst"
      echo "Restore: $rel_path"
    fi
  done

  for rel_dir in "${RUNTIME_DIRS[@]}"; do
    src="$RUNTIME_BACKUP_DIR/$rel_dir"
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
    echo "[DRY-RUN] sync_git_bind_mount_ownership"
    echo "[DRY-RUN] git -C $REMOTE_APP fetch --all --prune"
    echo "[DRY-RUN] git -C $REMOTE_APP reset --hard origin/$DEPLOY_REF"
  else
    if [[ ! -d "$REMOTE_APP/.git" ]]; then
      echo "Error: repo tidak ditemukan di $REMOTE_APP (.git missing)"
      exit 1
    fi

    sync_git_bind_mount_ownership

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
  echo "[DRY-RUN] chmod 777 $REMOTE_APP/session (best-effort quiet)"
else
  mkdir -p "$REMOTE_APP/session"
  if ! run_quiet_cmd chmod 777 "$REMOTE_APP/session"; then
    echo "Info: chmod session dilewati (operation not permitted)"
  fi
fi

print_step "Pastikan file database writable"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] mkdir -p $REMOTE_APP/db_data"
  echo "[DRY-RUN] chmod 777 $REMOTE_APP/db_data (best-effort quiet)"
  echo "[DRY-RUN] chmod 666 untuk *.db,*.db-wal,*.db-shm (best-effort quiet)"
else
  db_perm_failed=0
  mkdir -p "$REMOTE_APP/db_data"
  run_quiet_cmd chmod 777 "$REMOTE_APP/db_data" || db_perm_failed=1
  while IFS= read -r db_file; do
    run_quiet_cmd chmod 666 "$db_file" || db_perm_failed=1
  done < <(find "$REMOTE_APP/db_data" -maxdepth 1 -type f \( -name '*.db' -o -name '*.db-wal' -o -name '*.db-shm' \) -print)

  if [[ "$db_perm_failed" -eq 1 ]]; then
    echo "Info: sebagian chmod db_data dilewati (operation not permitted)"
  fi
fi

cd "$REMOTE_APP"

if [[ "${ALL_NGINX_SYNC:-0}" -eq 1 ]]; then
  print_step "Sinkronisasi nginx conf ke host proxy (SEMUA conf)"
else
  print_step "Sinkronisasi nginx conf ke host proxy (hanya wartelpas.conf)"
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  for f in "${NGINX_SYNC_FILES[@]}"; do
    echo "[DRY-RUN] cp -f $PROXY_CONF_SRC_DIR/$f $PROXY_CONF_DST/$f"
  done
  echo "[DRY-RUN] docker exec global-nginx-proxy nginx -t"
  echo "[DRY-RUN] docker exec global-nginx-proxy nginx -s reload"
else
  mkdir -p "$PROXY_CONF_DST"
  for f in "${NGINX_SYNC_FILES[@]}"; do
    src="$PROXY_CONF_SRC_DIR/$f"
    dst="$PROXY_CONF_DST/$f"
    require_file "$src"
    if [[ "$src" == "$dst" ]]; then
      echo "Sync nginx: $f (source=target, skip copy)"
    else
      cp -f "$src" "$dst"
      echo "Sync nginx: $f"
    fi
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

print_step "Smoke test runtime MikroTik (apply + audit profile)"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[DRY-RUN] php $REMOTE_APP/tools/router_apply_profiles_runtime.php"
  echo "[DRY-RUN] php $REMOTE_APP/tools/router_audit_runtime.php"
  echo "[DRY-RUN] php $REMOTE_APP/tools/wa_runtime_profile_alert.php --missing-count <N> --missing-profiles <profiles> --targets <targets>"
else
  PHP_ENTRY=()
  APPLY_SCRIPT=""
  AUDIT_SCRIPT=""
  ALERT_SCRIPT=""
  if command -v php >/dev/null 2>&1; then
    PHP_ENTRY=(php)
    APPLY_SCRIPT="$REMOTE_APP/tools/router_apply_profiles_runtime.php"
    AUDIT_SCRIPT="$REMOTE_APP/tools/router_audit_runtime.php"
    ALERT_SCRIPT="$REMOTE_APP/tools/wa_runtime_profile_alert.php"
  elif docker ps --format '{{.Names}}' | grep -Fxq "$APP_CONTAINER_NAME"; then
    PHP_ENTRY=(docker exec "$APP_CONTAINER_NAME" php)
    APPLY_SCRIPT="/var/www/html/tools/router_apply_profiles_runtime.php"
    AUDIT_SCRIPT="/var/www/html/tools/router_audit_runtime.php"
    ALERT_SCRIPT="/var/www/html/tools/wa_runtime_profile_alert.php"
    echo "Info: php host tidak tersedia, fallback smoke test via container $APP_CONTAINER_NAME."
  else
    echo "Error: php CLI host tidak tersedia dan container $APP_CONTAINER_NAME tidak aktif."
    exit 1
  fi

  APPLY_OUT="$("${PHP_ENTRY[@]}" "$APPLY_SCRIPT" 2>&1 || true)"
  printf '%s\n' "$APPLY_OUT"

  if printf '%s\n' "$APPLY_OUT" | grep -q '^CONNECT|FAIL'; then
    echo "Error: gagal konek MikroTik saat apply runtime profile."
    exit 1
  fi
  if printf '%s\n' "$APPLY_OUT" | grep -q '^HOOK_SCRIPT|updated|.*|status=TRAP'; then
    echo "Error: update hook script MikroTik terkena TRAP."
    exit 1
  fi
  if printf '%s\n' "$APPLY_OUT" | grep -q '^PROFILE|UPDATED|.*|set=TRAP'; then
    echo "Error: set on-login/on-logout profile terkena TRAP."
    exit 1
  fi
  if printf '%s\n' "$APPLY_OUT" | grep -q '^PROFILE|NOT_FOUND|'; then
    echo "Error: profile target tidak ditemukan saat apply runtime profile."
    exit 1
  fi

  AUDIT_OUT="$("${PHP_ENTRY[@]}" "$AUDIT_SCRIPT" 2>&1 || true)"
  printf '%s\n' "$AUDIT_OUT"

  if printf '%s\n' "$AUDIT_OUT" | grep -q '^CONNECT|FAIL'; then
    echo "Error: gagal konek MikroTik saat audit runtime profile."
    exit 1
  fi

  missing_count="$(printf '%s\n' "$AUDIT_OUT" | awk -F'|' '/^PROFILE_MISSING_COUNT\|/ {print $2; exit}')"
  if [[ -z "$missing_count" ]]; then
    echo "Error: output audit runtime tidak memuat PROFILE_MISSING_COUNT."
    exit 1
  fi
  if (( missing_count > 0 )); then
    missing_profiles="$(printf '%s\n' "$AUDIT_OUT" | awk -F'|' '/^PROFILE_MISSING\|/ {print $2}' | paste -sd ',' -)"
    if [[ -z "$missing_profiles" ]]; then
      missing_profiles="-"
    fi
    target_profiles="$(printf '%s\n' "$AUDIT_OUT" | awk -F'|' '/^PROFILE_TARGETS\|/ {print $2; exit}')"
    if [[ -z "$target_profiles" ]]; then
      target_profiles="10Menit,30Menit"
    fi

    alert_host="$(hostname 2>/dev/null || echo '-')"
    ALERT_OUT="$("${PHP_ENTRY[@]}" "$ALERT_SCRIPT" --missing-count "$missing_count" --missing-profiles "$missing_profiles" --targets "$target_profiles" --host "$alert_host" --mode "deploy_smoke" 2>&1 || true)"
    if [[ -n "$ALERT_OUT" ]]; then
      printf '%s\n' "$ALERT_OUT"
    fi

    echo "Error: masih ada profile yang belum memenuhi syarat runtime (count=$missing_count)."
    exit 1
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
