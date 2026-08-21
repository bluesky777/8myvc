#!/usr/bin/env bash
#
# Reconstruye la base de datos de tests desde cero:
#   esquema real congelado + seed anonimizado.
#
# Se corre una vez antes de la tanda de tests, no una vez por test. Los tests
# usan DatabaseTransactions: cada uno abre una transacción y la deshace al
# terminar, así que el seed sobrevive intacto a toda la tanda.
#
# Uso:
#   tools/construir-bd-test.sh
#
# Variables (con sus valores por defecto para el docker de desarrollo):
#   DB_TEST_DATABASE=simonbolivar_testing        (o ..._testing_b, una por sesión)
#   DB_TEST_HOST, DB_TEST_PORT                     (solo si no es el host por defecto)
#   MYSQL_EXEC="docker exec -i 8myvc-database-1"   (vacío para hablar con MySQL directamente)
#   PHP_EXEC="docker exec -i 8myvc-app-1"          (vacío para usar el php de este equipo)

set -euo pipefail

cd "$(dirname "$0")/.."

DB_TEST_DATABASE="${DB_TEST_DATABASE:-simonbolivar_testing}"
DB_USERNAME="${DB_USERNAME:-root}"

# La contraseña sale del .env si no viene por entorno, para no tenerla en dos sitios.
if [ -z "${DB_PASSWORD:-}" ] && [ -f .env ]; then
    DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2- || true)"
fi

MYSQL_EXEC="${MYSQL_EXEC-docker exec -i 8myvc-database-1}"
PHP_EXEC="${PHP_EXEC-docker exec -i 8myvc-app-1}"

ESQUEMA="database/schema/mysql-schema.sql"
SEED="database/dumps/test-seed.sql"

for f in "$ESQUEMA" "$SEED"; do
    [ -f "$f" ] || { echo "Falta $f" >&2; exit 1; }
done

# Guardia: que nadie apunte esto por accidente a la base de trabajo.
#
# El sufijo (`..._testing_b`) es para tener una base por sesión: varias sesiones
# corriendo tests contra la MISMA base se bloquean entre sí —el `insert` de
# `personal_access_tokens` de `CasoDeContrato::token()` da deadlock, medido el
# 21 ago 2026—, y con `DatabaseTransactions` una corrida ve a medias lo que la
# otra está escribiendo. Ver docs/migracion/03-tests.md.
case "$DB_TEST_DATABASE" in
    *_testing|*_test|*_testing_*|*_test_*) ;;
    *)
        echo "DB_TEST_DATABASE='$DB_TEST_DATABASE' no acaba en _testing ni _test." >&2
        echo "Este script BORRA la base entera. Abortando por si acaso." >&2
        exit 1
        ;;
esac

# Host y puerto solo se pasan si están definidos: dentro del contenedor de
# desarrollo el cliente ya habla con el MySQL local y añadir -h lo rompería.
CONEXION=""
[ -n "${DB_TEST_HOST:-}" ] && CONEXION="$CONEXION -h $DB_TEST_HOST"
[ -n "${DB_TEST_PORT:-}" ] && CONEXION="$CONEXION -P $DB_TEST_PORT"

# Los errores NO se silencian: solo el aviso de la contraseña en la línea de
# órdenes, que MySQL imprime siempre. Silenciarlos enteros —como estaba hasta el
# 20 ago 2026— deja el peor fallo posible: el script imprime sus tres pasos, se
# corta a la mitad por `set -e`, y la base queda a medio cargar sin que nada lo
# diga. Se descubrió cargando el seed a mano para ver por qué la suite entera
# fallaba con "la base está vacía".
mysql_cmd() {
    $MYSQL_EXEC mysql $CONEXION -u"$DB_USERNAME" -p"$DB_PASSWORD" "$@" \
        2> >(grep -v '^mysql: \[Warning\] Using a password' >&2)
}

echo "Reconstruyendo '$DB_TEST_DATABASE'..."

mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_TEST_DATABASE\`;
              CREATE DATABASE \`$DB_TEST_DATABASE\`
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "  esquema:  $ESQUEMA"
mysql_cmd "$DB_TEST_DATABASE" < "$ESQUEMA"

# El esquema congelado es el de PRODUCCIÓN, y producción va por detrás de la
# rama: le faltan las tablas y columnas que añaden las migraciones nuevas.
# Correrlas aquí es lo que hará cada colegio al desplegar, y comprueba lo que
# hay que comprobar: que la migración se aplica sobre el esquema de verdad.
#
# **Van ANTES del seed**, y el orden costó una tarde. El seed se genera desde la
# base de desarrollo, que sí está migrada, así que trae las columnas nuevas
# dentro: cargarlo sobre el esquema pelado muere con "Unknown column
# 'firmantes_acta'". Con las migraciones ya aplicadas encaja.
#
# Lo que este orden NO comprueba es una migración que transforme datos que ya
# estaban: corre sobre la base vacía. Las de hoy son aditivas —una tabla nueva y
# una columna nullable—, pero la primera que toque datos existentes necesita su
# propio test, no este script.
echo "  migraciones:"
# `env` va aquí porque `$PHP_EXEC` es un `docker exec`, y un `docker exec` NO
# hereda el entorno de quien lo llama: la variable se queda fuera del
# contenedor. Sin esto, pedir una base distinta con DB_TEST_DATABASE construye
# esa base con `mysql` —que sí corre desde aquí— pero migra **la de por
# defecto**, y luego el seed muere con "Unknown column 'firmantes_acta'" sobre
# un esquema pelado. Falla ruidoso, pero acusa al fichero equivocado.
# Con `env` la variable se pone dentro, y sirve igual si PHP_EXEC está vacío.
$PHP_EXEC env \
    DB_TEST_DATABASE="$DB_TEST_DATABASE" \
    ${DB_TEST_HOST:+DB_TEST_HOST="$DB_TEST_HOST"} \
    ${DB_TEST_PORT:+DB_TEST_PORT="$DB_TEST_PORT"} \
    php artisan migrate --force --database=mysql_testing --no-interaction

echo "  seed:     $SEED"
mysql_cmd "$DB_TEST_DATABASE" < "$SEED"

tablas=$(mysql_cmd -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_TEST_DATABASE';")
users=$(mysql_cmd -N -e "SELECT COUNT(*) FROM \`$DB_TEST_DATABASE\`.users;")

echo "Listo: $tablas tablas, $users usuarios."
