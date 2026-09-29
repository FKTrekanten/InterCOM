#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer validate --strict
composer install --no-interaction --prefer-dist
composer lint
composer style
composer test
python3 -m unittest discover -s tests/tooling
node --check src/component/media/js/app.js
node --check src/component/media/js/options.js
python3 scripts/build.py
# Ephemeral CI credentials are generated at runtime and never committed or printed.
export INTERCOM_ADMIN_PASSWORD="$(python3 -c 'import secrets; print(secrets.token_urlsafe(32))')"
export INTERCOM_TEST_CLIENT_ID="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
export INTERCOM_TEST_CLIENT_SECRET="$(python3 -c 'import secrets; print(secrets.token_urlsafe(32) + "<&>+")')"
export INTERCOM_TEST_ACCESS_TOKEN="$(python3 -c 'import secrets; print(secrets.token_urlsafe(48))')"
# Isolated named project and volumes; never resets the developer database.
export COMPOSE_PROJECT_NAME="intercom-ci-${GITHUB_RUN_ID:-$$}"
export INTERCOM_PORT="${INTERCOM_TEST_PORT:-18088}"
cleanup() { docker compose down --volumes --remove-orphans > /dev/null 2>&1; }
trap cleanup EXIT
bash scripts/stack.sh up
docker compose exec -T --user www-data -w /workspace joomla php vendor/bin/phpunit --do-not-cache-result
version=$(cat VERSION)
docker compose exec -T --user www-data joomla php /workspace/tests/joomla/install.php "/workspace/dist/pkg_intercom-$version.zip"
docker compose exec -T --user www-data -e INTERCOM_CI=1 joomla php /workspace/tests/joomla/integration.php
# Exercise an actual schema change before the first public release exists.
docker compose exec -T --user www-data joomla php /workspace/tests/joomla/upgrade-baseline.php
docker compose exec -T --user www-data joomla php /workspace/tests/joomla/install.php "/workspace/dist/pkg_intercom-$version.zip"
docker compose exec -T --user www-data joomla php /workspace/tests/joomla/upgrade.php
docker compose exec -T --user www-data joomla php /workspace/tests/joomla/scheduler.php
python3 tests/http-smoke.py
docker compose exec -T --user www-data -e INTERCOM_CI=1 -e INTERCOM_TEST_CLIENT_SECRET -e INTERCOM_TEST_ACCESS_TOKEN joomla php /workspace/tests/joomla/settings-http.php
echo 'LOCAL CI PASSED'
