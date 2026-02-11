#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

printf "\n==> NativePHP bootstrap (Calvary Caravan API)\n"

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

# Ensure base app setup exists
composer install --no-interaction
php artisan key:generate --force
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate --force

# Install NativePHP package if needed
if ! composer show nativephp/mobile >/dev/null 2>&1; then
  composer require nativephp/mobile -W --no-interaction
fi

# Ensure required NativePHP keys exist in .env
ensure_env_key() {
  local key="$1"
  local default_value="$2"

  if grep -qE "^${key}=" .env; then
    return
  fi

  echo "${key}=${default_value}" >> .env
  echo "Added ${key}=${default_value} to .env"
}

ensure_env_key "NATIVEPHP_APP_ID" "com.calvarybaptist.calvarycaravan"
ensure_env_key "NATIVEPHP_APP_VERSION" "DEBUG"
ensure_env_key "NATIVEPHP_APP_VERSION_CODE" "1"
ensure_env_key "NATIVEPHP_START_URL" "/mobile/index.html"

# Install / refresh native projects
php artisan native:install both --without-icu --force --no-interaction
php artisan native:version

echo "\nBootstrap complete. Next steps:"
echo "  1) ./scripts/nativephp-preflight.sh"
echo "  2) php artisan native:run ios <simulator-udid> --build=debug --no-tty"
echo "  3) php artisan native:run android --build=debug --no-tty"
