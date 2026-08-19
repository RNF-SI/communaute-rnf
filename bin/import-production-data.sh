#!/usr/bin/env bash
#
# Loads a copy of the production database into the current environment and
# strips it of every personal identity. See docs/donnees-reelles.md.
#
# Usage: bin/import-production-data.sh <dump.sql> [--keep-email a@b.c ...]

set -euo pipefail

if [ $# -lt 1 ]; then
	echo "Usage: $0 <dump.sql> [--keep-email a@b.c ...]" >&2
	exit 1
fi

DUMP="$1"
shift

if [ ! -r "$DUMP" ]; then
	echo "Dump not readable: $DUMP" >&2
	exit 1
fi

if [ "${APP_ENV:-dev}" = "prod" ]; then
	echo "Refusing to run against a production environment." >&2
	exit 1
fi

if [ -z "${DATABASE_URL:-}" ]; then
	echo "DATABASE_URL is not set. Load your .env.local first." >&2
	exit 1
fi

# mysql://user:password@host:port/database?params
URL="${DATABASE_URL#mysql://}"
URL="${URL%%\?*}"
CREDENTIALS="${URL%%@*}"
LOCATION="${URL#*@}"
DB_USER="${CREDENTIALS%%:*}"
DB_PASSWORD="${CREDENTIALS#*:}"
DB_HOST="${LOCATION%%/*}"
DB_NAME="${LOCATION#*/}"
DB_PORT=3306

case "$DB_HOST" in
	*:*)
		DB_PORT="${DB_HOST##*:}"
		DB_HOST="${DB_HOST%%:*}"
		;;
esac

if [ "$DB_PASSWORD" = "$CREDENTIALS" ]; then
	DB_PASSWORD=""
fi

echo "==> Database $DB_NAME on $DB_HOST:$DB_PORT will be REPLACED by $DUMP"
printf "    Type 'yes' to continue: "
read -r CONFIRM
[ "$CONFIRM" = "yes" ] || { echo "Aborted."; exit 1; }

mysql_run () {
	if [ -n "$DB_PASSWORD" ]; then
		mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$@"
	else
		mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"
	fi
}

echo "==> Recreating the schema"
mysql_run -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Loading the dump"
mysql_run "$DB_NAME" < "$DUMP"

echo "==> Applying the migrations the copy does not have yet"
php bin/console doctrine:migrations:migrate --no-interaction

echo "==> Anonymising the accounts"
php bin/console app:db:anonymize "$@"

echo "==> Rebuilding the search indexes"
php bin/console search:reindex:all

echo
echo "Done. Files are NOT part of this import, see docs/donnees-reelles.md."
