#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .env ]; then
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=' .env; then
  echo 'APP_KEY=' >> .env
fi

if ! grep -q '^CRON_SHARED_KEY=' .env; then
  echo 'CRON_SHARED_KEY=' >> .env
fi

CURRENT_APP_KEY=$(grep '^APP_KEY=' .env | cut -d'=' -f2- || true)
if [ -z "$CURRENT_APP_KEY" ]; then
  NEW_KEY=$(openssl rand -hex 32)
  if [[ "$OSTYPE" == darwin* ]]; then
    sed -i '' "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
  else
    sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
  fi
fi

CURRENT_CRON_KEY=$(grep '^CRON_SHARED_KEY=' .env | cut -d'=' -f2- || true)
if [ -z "$CURRENT_CRON_KEY" ]; then
  NEW_CRON_KEY=$(openssl rand -hex 24)
  if [[ "$OSTYPE" == darwin* ]]; then
    sed -i '' "s|^CRON_SHARED_KEY=.*|CRON_SHARED_KEY=${NEW_CRON_KEY}|" .env
  else
    sed -i "s|^CRON_SHARED_KEY=.*|CRON_SHARED_KEY=${NEW_CRON_KEY}|" .env
  fi
fi

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
else
  echo "未检测到 docker compose，请先安装 Docker Desktop。"
  exit 1
fi

$COMPOSE_CMD up -d --build
$COMPOSE_CMD exec -T app php scripts/install.php

echo "安装完成。"
echo "访问地址: http://localhost:8088/health"
