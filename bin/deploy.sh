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

set -euo pipefail

SSH_HOST="${STOCKPICKER_SSH_HOST:-loopia-stockpicker}"
REMOTE_DIR="${STOCKPICKER_REMOTE_DIR:-stockpicker}"

with_local_vendor=0
run_migrate=1
for arg in "$@"; do
  case "$arg" in
    --with-local-vendor) with_local_vendor=1 ;;
    --no-migrate)        run_migrate=0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

cd "$(dirname "$0")/.."

excludes=(
  --exclude '.git/'
  --exclude '_bmad-output/'
  --exclude '_bmad/'
  --exclude 'docs/'
  --exclude 'tests/'
  --exclude '.github/'
  --exclude 'config.php'
  --exclude '.env'
  --exclude '*.pub'
  --exclude 'stockpicker-loopia'
)
if [[ $with_local_vendor -eq 0 ]]; then
  excludes+=(--exclude 'vendor/')
else
  echo "==> Building vendor/ locally"
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> Syncing source to ${SSH_HOST}:~/${REMOTE_DIR}/"
rsync -az --delete "${excludes[@]}" ./ "${SSH_HOST}:${REMOTE_DIR}/"

if [[ $with_local_vendor -eq 0 ]]; then
  echo "==> Installing dependencies on the server"
  ssh "$SSH_HOST" "cd ${REMOTE_DIR} && (composer install --no-dev --optimize-autoloader --no-interaction \
    || php composer.phar install --no-dev --optimize-autoloader --no-interaction)"
fi

if [[ $run_migrate -eq 1 ]]; then
  echo "==> Running migrations"
  ssh "$SSH_HOST" "cd ${REMOTE_DIR} && vendor/bin/phinx migrate -e production"
fi

echo "==> Done. config.php is managed by hand on the server and was not touched."
