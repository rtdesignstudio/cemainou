#!/bin/sh
# Suita de teste pe MySQL și MariaDB (pe lângă SQLite, implicit): fiecare într-un container Docker local, pornit la nevoie.
# Bazele sunt de unică folosință, doar pe 127.0.0.1: parola de mai jos nu protejează nimic real.
#   scripts/test-db.sh            → MySQL 8.4 și MariaDB 11.4
#   scripts/test-db.sh mysql      → doar MySQL (sau: mariadb); restul argumentelor merg la `php artisan test` (de ex. --filter=Restore)
set -eu
cd "$(dirname "$0")/.."
WHICH=${1:-all}
[ $# -gt 0 ] && shift
PASS=cemainou-test

start() {                                                     # $1 = numele, $2 = imaginea, $3 = portul
  if ! docker ps --format '{{.Names}}' | grep -qx "$1"; then
    docker rm -f "$1" >/dev/null 2>&1 || true
    case $2 in
      mysql*) docker run -d --name "$1" -p "127.0.0.1:$3:3306" -e MYSQL_ROOT_PASSWORD=$PASS -e MYSQL_DATABASE=cemainou_test -e MYSQL_USER=cemainou -e MYSQL_PASSWORD=$PASS "$2" >/dev/null ;;
      *) docker run -d --name "$1" -p "127.0.0.1:$3:3306" -e MARIADB_ROOT_PASSWORD=$PASS -e MARIADB_DATABASE=cemainou_test -e MARIADB_USER=cemainou -e MARIADB_PASSWORD=$PASS "$2" >/dev/null ;;
    esac
  fi
  i=0
  until php -r "new PDO('mysql:host=127.0.0.1;port=$3;dbname=cemainou_test', 'cemainou', '$PASS');" >/dev/null 2>&1; do
    i=$((i + 1)); [ $i -gt 60 ] && { echo "$1 nu pornește" >&2; exit 1; }
    sleep 2
  done
}

run() {                                                       # $1 = mysql|mariadb, $2 = portul
  n=$1; p=$2; shift 2
  echo "== $n"
  DB_CONNECTION=$n DB_HOST=127.0.0.1 DB_PORT=$p DB_DATABASE=cemainou_test DB_USERNAME=cemainou DB_PASSWORD=$PASS php artisan test "$@"
}

FAIL=0
if [ "$WHICH" = all ] || [ "$WHICH" = mysql ]; then start cemainou-test-mysql mysql:8.4 3327; run mysql 3327 "$@" || FAIL=1; fi
if [ "$WHICH" = all ] || [ "$WHICH" = mariadb ]; then start cemainou-test-mariadb mariadb:11.4 3328; run mariadb 3328 "$@" || FAIL=1; fi
exit $FAIL
