#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

test_suite="${1:-api}"
case "$test_suite" in
    api|appearance) ;;
    *) echo '使用方法: bash tests/run.sh [api|appearance]' >&2; exit 2 ;;
esac

# 開発中のデータには触れず、検証専用のDBを毎回作成する。
test_database="custom_todo_test_$(date +%s)_$$"
docker compose exec -T db sh -c 'createdb -U "$POSTGRES_USER" "$1"' sh "$test_database"
cleanup() {
    docker compose exec -T db sh -c 'dropdb -U "$POSTGRES_USER" "$1"' sh "$test_database"
}
trap cleanup EXIT

for migration in database/init/*.sql; do
    docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$1" -v ON_ERROR_STOP=1 --single-transaction' sh "$test_database" < "$migration" > /dev/null
done

docker compose exec -T -e POSTGRES_DB="$test_database" php php < "tests/$test_suite.php"
