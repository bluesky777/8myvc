#!/usr/bin/env bash
#
# El respaldo de ESTE colegio, justo antes de `php artisan migrate`.
#
#     cd /home/micolev1/COLEGIO.micolevirtual.com/8myvc
#     tools/respaldo-antes-de-migrar.sh
#     tools/respaldo-antes-de-migrar.sh --tablas=notas,notas_finales   # el rápido
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ ESTE Y NO EL DE CPANEL
#
# El respaldo del alojamiento —si es que lo hay, y eso hay que preguntárselo al
# proveedor— es **de anoche**. Lo que hace falta aquí es de hace treinta segundos:
# entre el respaldo de anoche y el `migrate` de ahora hay un día de clases, y
# restaurar el de anoche para deshacer una migración **borra las notas que los
# docentes pusieron hoy**. Un respaldo que obliga a elegir qué día pierdes no es una
# vuelta atrás.
#
# Por eso este guion corre pegado al despliegue, colegio por colegio, y no de noche.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE HACE Y LO QUE COMPRUEBA
#
# 1. Saca usuario, clave y base del `.env` **de esta carpeta**. En cPanel cada colegio
#    tiene su base y casi siempre su propio usuario, así que no hay una credencial
#    que sirva para los dieciséis: el respaldo se hace desde dentro de cada carpeta.
#
# 2. La clave va a un fichero temporal con permisos `600` y de ahí a
#    `--defaults-extra-file`. **Nunca en la línea de comando**: en un alojamiento
#    compartido el `ps` lo ve cualquiera de los demás inquilinos del servidor.
#
# 3. Comprueba el resultado, que es la mitad del trabajo. Un `mysqldump` que se queda
#    a medias —cuota llena, `max_execution_time`, la conexión que se cae— **devuelve
#    un fichero .gz perfectamente válido y perfectamente inútil**, y eso no se
#    descubre el día del despliegue: se descubre el día que hay que restaurar. Las
#    tres comprobaciones son: el código de salida del `mysqldump` (leído de
#    `PIPESTATUS`, no del `gzip` que va después), que el `.gz` se descomprima entero,
#    y que la última línea diga `Dump completed`, que es lo que `mysqldump` sólo
#    escribe cuando llegó al final.
#
# 4. Imprime el comando de restauración con el nombre del fichero ya puesto. Se
#    imprime **antes** de que haga falta, porque el día que haga falta nadie está en
#    condiciones de componerlo de memoria.
#
# `--single-transaction` es lo que evita bloquear la tabla `notas` entera mientras
# dura el volcado: con InnoDB da una foto coherente sin cerrarle la puerta a los
# docentes que estén calificando. `--no-tablespaces` está porque en cPanel el usuario
# de la base no suele tener el privilegio `PROCESS` y sin esa opción `mysqldump` 8.0
# aborta.
#
# Variables:
#   DESTINO=$HOME/respaldos/antes-de-migrar    dónde se deja el fichero
#   MYSQLDUMP=mysqldump                        la ruta al binario, si no está en PATH

set -u -o pipefail

DESTINO="${DESTINO:-$HOME/respaldos/antes-de-migrar}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
TABLAS=""

for argumento in "$@"; do
    case "$argumento" in
        --tablas=*) TABLAS="${argumento#--tablas=}" ;;
        --destino=*) DESTINO="${argumento#--destino=}" ;;
        -h|--ayuda)
            sed -n '3,6p' "$0"
            exit 0
            ;;
        *)
            echo "opción desconocida: $argumento" >&2
            exit 1
            ;;
    esac
done

if [ ! -f .env ]; then
    echo "AQUÍ NO HAY .env — corre esto desde la carpeta del colegio (la que tiene artisan)." >&2
    exit 1
fi

# `cut -d= -f2-` y no `-f2`: una clave con un `=` dentro es normal y partirla por el
# primero la deja a medias sin decir nada.
#
# Y el `tr -d '\r'` no es precaución: los `.env` de la cuenta de `lalvirtual` tienen
# fin de línea de Windows (visto el 22 sep 2026), así que el nombre de la base salía
# como `micolevi_lalvirtual\r`. El error que da MySQL es «Incorrect database name», y
# el retorno de carro **se come la mitad del propio mensaje** al imprimirlo, así que
# ni siquiera se lee entero. Los de `micolev1` son LF y ahí no pasaba nada.
leer_del_env() {
    local clave="$1" valor
    valor=$(grep -m1 "^${clave}=" .env | cut -d= -f2- | tr -d '\r')
    valor="${valor%\"}"
    valor="${valor#\"}"
    valor="${valor%\'}"
    valor="${valor#\'}"
    printf '%s' "$valor"
}

BASE=$(leer_del_env DB_DATABASE)
USUARIO=$(leer_del_env DB_USERNAME)
CLAVE=$(leer_del_env DB_PASSWORD)
SERVIDOR=$(leer_del_env DB_HOST)
PUERTO=$(leer_del_env DB_PORT)

if [ -z "$BASE" ] || [ -z "$USUARIO" ]; then
    echo "El .env de esta carpeta no dice DB_DATABASE o DB_USERNAME." >&2
    exit 1
fi

mkdir -p "$DESTINO"
chmod 700 "$DESTINO" 2>/dev/null || true

CREDENCIALES=$(mktemp "${TMPDIR:-/tmp}/respaldo.XXXXXX")
chmod 600 "$CREDENCIALES"
trap 'rm -f "$CREDENCIALES"' EXIT INT TERM

{
    echo '[client]'
    echo "user=${USUARIO}"
    echo "password=${CLAVE}"
    [ -n "$SERVIDOR" ] && echo "host=${SERVIDOR}"
    [ -n "$PUERTO" ] && echo "port=${PUERTO}"
} > "$CREDENCIALES"

SELLO=$(date +%Y-%m-%d-%H%M%S)
COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo sin-git)
FICHERO="${DESTINO}/${BASE}-${SELLO}-${COMMIT}.sql.gz"

echo "Respaldando \`${BASE}\` -> ${FICHERO}"
[ -n "$TABLAS" ] && echo "  sólo estas tablas: ${TABLAS//,/ }"

# shellcheck disable=SC2086
"$MYSQLDUMP" --defaults-extra-file="$CREDENCIALES" \
    --single-transaction --quick --no-tablespaces \
    --routines --triggers --events \
    --default-character-set=utf8mb4 \
    "$BASE" ${TABLAS//,/ } 2>"${FICHERO}.err" | gzip -c > "$FICHERO"

SALIDA_DUMP=${PIPESTATUS[0]}

if [ "$SALIDA_DUMP" -ne 0 ]; then
    echo "EL RESPALDO FALLÓ (mysqldump salió con ${SALIDA_DUMP}). NO MIGRES." >&2
    sed -n '1,5p' "${FICHERO}.err" >&2
    exit 1
fi

if ! gzip -t "$FICHERO" 2>/dev/null; then
    echo "EL RESPALDO ESTÁ ROTO (el .gz no se descomprime). NO MIGRES." >&2
    exit 1
fi

if ! gzip -dc "$FICHERO" | tail -5 | grep -q 'Dump completed'; then
    echo "EL RESPALDO ESTÁ INCOMPLETO (no llega al final del volcado). NO MIGRES." >&2
    exit 1
fi

rm -f "${FICHERO}.err"

TAMANO=$(du -h "$FICHERO" | cut -f1)
FILAS=$(gzip -dc "$FICHERO" | grep -c '^INSERT INTO' || true)

echo
echo "  LISTO — ${TAMANO}, ${FILAS} sentencias INSERT, volcado completo."
echo
echo "  Para volver atrás con esto:"
echo
echo "      gzip -dc ${FICHERO} | mysql --defaults-extra-file=<credenciales> ${BASE}"
echo
echo "  Y para no dejarlo sólo aquí: bájatelo del servidor. Un respaldo que vive en el"
echo "  mismo disco que la base no cubre el caso en que el problema sea el disco."
echo
