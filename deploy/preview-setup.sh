#!/usr/bin/env bash
# Sets up / updates the hidden preview of the new website on the DigitalOcean server.
#   bash deploy/preview-setup.sh /root/djk_stage.sql.gz   (first time, with data)
#   bash deploy/preview-setup.sh                           (update code only)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
DUMP="${1:-}"
ENVF=deploy/.env

# Secrets: generated once, stored outside git (deploy/.env, app/config.local.php)
if [ ! -f "$ENVF" ]; then
  echo "DJK_DB_PASS=$(openssl rand -hex 16)" > "$ENVF"
  echo "PREVIEW_PASS=$(openssl rand -base64 9 | tr -dc 'A-Za-z0-9' | head -c 10)" >> "$ENVF"
  chmod 600 "$ENVF"
fi
source "$ENVF"
# Preview address: PREVIEW_HOST in deploy/.env (e.g. new.djakimita.com), else a free sslip.io address
HOST="${PREVIEW_HOST:-djk.$(curl -fsS -4 https://api.ipify.org | tr . -).sslip.io}"

cat > app/config.local.php <<PHP
<?php
return array_merge(require __DIR__ . '/config.example.php', [
    'db_host' => 'djk-db',
    'db_name' => 'djk_stage',
    'db_user' => 'djk',
    'db_pass' => '${DJK_DB_PASS}',
    'base_url' => 'https://${HOST}',
    'preview_user' => 'preview',
    'preview_pass' => '${PREVIEW_PASS}',
]);
PHP
chmod 644 app/config.local.php

docker compose -p djk --env-file "$ENVF" -f deploy/docker-compose.preview.yml up -d --build

if [ -n "$DUMP" ]; then
  echo "Waiting for the database..."
  for i in $(seq 1 60); do
    docker compose -p djk --env-file "$ENVF" -f deploy/docker-compose.preview.yml exec -T djk-db mariadb -udjk -p"$DJK_DB_PASS" -e 'select 1' djk_stage >/dev/null 2>&1 && break
    sleep 2
  done
  echo "Importing data (no customer data in this file)..."
  gunzip -c "$DUMP" | docker compose -p djk --env-file "$ENVF" -f deploy/docker-compose.preview.yml exec -T djk-db mariadb --max-allowed-packet=256M -udjk -p"$DJK_DB_PASS" djk_stage
fi

# Route the preview address through the existing Caddy (HTTPS)
CADDY=/root/sss/Caddyfile
if ! grep -q "$HOST" "$CADDY"; then
  printf '\n%s {\n\tencode gzip\n\treverse_proxy djk-web:80\n}\n' "$HOST" >> "$CADDY"
  (cd /root/sss && docker compose restart caddy)
fi

echo
echo "=============================================="
echo " Preview:  https://${HOST}"
echo " Login:    preview / ${PREVIEW_PASS}"
echo "=============================================="
