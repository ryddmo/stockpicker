#!/usr/bin/env bash
#
# Deploy stockpicker to Loopia. See docs/deploy.md for the full runbook.
#
# Usage:
#   bin/deploy.sh                     # rsync source, build vendor/ on the server, migrate
#   bin/deploy.sh --with-local-vendor # build vendor/ locally and sync it (fallback)
#   bin/deploy.sh --no-migrate        # skip the phinx migrate step
#
# Config via env (defaults shown):
#   STOCKPICKER_SSH_HOST=loopia-stockpicker   # ~/.ssh/config Host alias
#   STOCKPICKER_REMOTE_DIR=stockpicker        # path under $HOME on Loopia
#
# bash-3.2 compatible (macOS /bin/bash).

set -euo pipefail

SSH_HOST="${STOCKPICKER_SSH_HOST:-loopia-stockpicker}"
REMOTE_DIR="${STOCKPICKER_REMOTE_DIR:-stockpicker}"

usage() {
  cat >&2 <<'EOF'
usage: bin/deploy.sh [--with-local-vendor] [--no-migrate]

  --with-local-vendor  build vendor/ locally and rsync it (fallback for when the
                       server has no composer); the default builds it on the server
  --no-migrate         skip the "vendor/bin/phinx migrate -e production" step

Config via env:
  STOCKPICKER_SSH_HOST     ssh host / ~/.ssh/config alias  (default: loopia-stockpicker)
  STOCKPICKER_REMOTE_DIR   path under $HOME on Loopia      (default: stockpicker)

See docs/deploy.md for the full runbook.
EOF
}

with_local_vendor=0
run_migrate=1
for arg in "$@"; do
  case "$arg" in
    --with-local-vendor) with_local_vendor=1 ;;
    --no-migrate)        run_migrate=0 ;;
    -h|--help)           usage; exit 0 ;;
    *) echo "deploy: unknown option: $arg" >&2; usage; exit 2 ;;
  esac
done

cd "$(dirname "$0")/.."

# --- Preflight: never start a --delete sync without knowing what and where -----
echo "==> Preflight"

if git rev-parse --git-dir >/dev/null 2>&1; then
  commit="$(git rev-parse --short HEAD)"
  branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
  echo "    commit:  ${commit} (${branch})"
  if [ -n "$(git status --porcelain)" ]; then
    echo "    tree:    DIRTY -- uncommitted changes below will be rsynced as-is:"
    git status --porcelain | sed 's/^/               /'
  else
    echo "    tree:    clean"
  fi
else
  commit="(no git checkout)"
  echo "    commit:  ${commit}"
fi

echo "    ssh:     probing ${SSH_HOST} ..."
if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$SSH_HOST" 'true' >/dev/null 2>&1; then
  echo "deploy: cannot reach '${SSH_HOST}' over SSH -- aborting before any rsync --delete." >&2
  echo "        Check ~/.ssh/config, the deploy key, and that SSH is enabled in Kundzon." >&2
  exit 1
fi
echo "    ssh:     ${SSH_HOST} reachable"

# --- Sync --------------------------------------------------------------------
excludes=(
  --exclude '.git/'
  --exclude '.github/'
  --exclude '_bmad-output/'
  --exclude '_bmad/'
  --exclude 'docs/'
  --exclude 'tests/'
  --exclude 'config.php'
  --exclude '.env'
  --exclude '*.pub'
  --exclude 'stockpicker-loopia'
  --exclude '.DS_Store'
)
if [[ $with_local_vendor -eq 0 ]]; then
  excludes+=(--exclude 'vendor/')
else
  echo "==> Building vendor/ locally"
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> Syncing source to ${SSH_HOST}:~/${REMOTE_DIR}/ (${commit})"
rsync -az --delete "${excludes[@]}" ./ "${SSH_HOST}:${REMOTE_DIR}/"

if [[ $with_local_vendor -eq 0 ]]; then
  echo "==> Installing dependencies on the server"
  # ${REMOTE_DIR} is deliberately expanded client-side (it is our local config).
  # shellcheck disable=SC2029
  ssh "$SSH_HOST" "cd ${REMOTE_DIR} && (composer install --no-dev --optimize-autoloader --no-interaction \
    || php composer.phar install --no-dev --optimize-autoloader --no-interaction)"
fi

if [[ $run_migrate -eq 1 ]]; then
  echo "==> Running migrations (vendor/bin/phinx migrate -e production)"
  # shellcheck disable=SC2029
  ssh "$SSH_HOST" "cd ${REMOTE_DIR} && vendor/bin/phinx migrate -e production"
fi

echo "==> Done. Deployed ${commit}. config.php and vendor/ on the server were not touched."
