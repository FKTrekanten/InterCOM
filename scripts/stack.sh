#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-intercom}"
case "${1:-up}" in
up)
  docker compose up -d --wait
  docker compose exec -T --user www-data -e INTERCOM_ADMIN_PASSWORD joomla sh -c 'if [ ! -f /var/www/html/configuration.php ]; then php installation/joomla.php install --site-name="Intercom development" --admin-user="Intercom Admin" --admin-username=intercom --admin-password="${INTERCOM_ADMIN_PASSWORD:-Intercom-local-2026!}" --admin-email=admin@example.invalid --db-type=mysqli --db-host=db --db-user=intercom --db-pass=intercom-local-only --db-name=intercom --db-prefix=ic_ --db-encryption=0; fi; test -f /var/www/html/configuration.php'
  docker compose exec -T --user www-data joomla php /workspace/scripts/dev-mail.php
  ;;
stop) docker compose stop ;;
*) echo "Usage: scripts/stack.sh up|stop" >&2; exit 2 ;;
esac
