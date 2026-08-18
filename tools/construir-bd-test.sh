#!/usr/bin/env bash
#
# Reconstruye la base de datos de tests desde cero:
#   esquema real congelado + semilla anonimizada.
#
# Se corre una vez antes de la tanda de tests, no una vez por test. Los tests
# usan DatabaseTransactions: cada uno abre una transacción y la deshace al
# terminar, así que la semilla sobrevive intacta a toda la tanda.
#
# Uso:
#   tools/construir-bd-test.sh
#
# Variables (con sus valores por defecto para el docker de desarrollo):
#   DB_TEST_DATABASE=simonbolivar_testing
#   MYSQL_EXEC="docker exec -i 8myvc-database-1"   (vacío para hablar con MySQL directamente)

set -euo pipefail

cd "$(dirname "$0")/.."

DB_TEST_DATABASE="${DB_TEST_DATABASE:-simonbolivar_testing}"
DB_USERNAME="${DB_USERNAME:-root}"

# La contraseña sale del .env si no viene por entorno, para no tenerla en dos sitios.
if [ -z "${DB_PASSWORD:-}" ] && [ -f .env ]; then
    DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2- || true)"
fi

MYSQL_EXEC="${MYSQL_EXEC-docker exec -i 8myvc-database-1}"

ESQUEMA="database/schema/mysql-schema.sql"
SEMILLA="database/dumps/test-seed.sql"

for f in "$ESQUEMA" "$SEMILLA"; do
    [ -f "$f" ] || { echo "Falta $f" >&2; exit 1; }
done

# Guardia: que nadie apunte esto por accidente a la base de trabajo.
case "$DB_TEST_DATABASE" in
    *_testing|*_test) ;;
    *)
        echo "DB_TEST_DATABASE='$DB_TEST_DATABASE' no acaba en _testing ni _test." >&2
        echo "Este script BORRA la base entera. Abortando por si acaso." >&2
        exit 1
        ;;
esac

mysql_cmd() { $MYSQL_EXEC mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$@" 2>/dev/null; }

echo "Reconstruyendo '$DB_TEST_DATABASE'..."

mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_TEST_DATABASE\`;
              CREATE DATABASE \`$DB_TEST_DATABASE\`
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "  esquema:  $ESQUEMA"
mysql_cmd "$DB_TEST_DATABASE" < "$ESQUEMA"

echo "  semilla:  $SEMILLA"
mysql_cmd "$DB_TEST_DATABASE" < "$SEMILLA"

tablas=$(mysql_cmd -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_TEST_DATABASE';")
users=$(mysql_cmd -N -e "SELECT COUNT(*) FROM \`$DB_TEST_DATABASE\`.users;")

echo "Listo: $tablas tablas, $users usuarios."
