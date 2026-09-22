#!/usr/bin/env bash
#
# El respaldo de todas las bases de UNA cuenta de cPanel, pensado para un cron.
#
# Se instala una vez por cuenta, en **Advanced → Cron Jobs → Add New Cron Job**, y
# no se vuelve a tocar el panel:
#
#     5 2 * * *  /home/micolev1/demo.micolevirtual.com/8myvc/tools/respaldo-diario-cpanel.sh
#
# En la otra cuenta (`lalvirtual.edu.co`) se instala otra vez, con su propia ruta y su
# propia `RAIZ`: el `for` de una cuenta no ve las carpetas de la otra. Es la misma
# repetición a mano que ya lleva el Paso 1 de `docs/DESPLIEGUE.md`.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE ESTE GUION NO ES
#
# **No es el respaldo del despliegue.** Éste corre de madrugada; el del despliegue es
# `tools/respaldo-antes-de-migrar.sh` y corre pegado al `migrate`. Hacen falta los
# dos, y por razones distintas: éste cubre *«ayer funcionaba»*, el otro cubre *«esta
# migración acaba de pisar 12.632 notas»*. Restaurar el de madrugada para deshacer un
# `migrate` del mediodía **borra lo que los docentes hicieron esa mañana**.
#
# **No sustituye al respaldo del proveedor.** Esto vive en el mismo disco y en la
# misma cuenta que las bases: cubre el borrado, el `UPDATE` de más y la migración que
# se pasó de lista; **no cubre** que se pierda la cuenta o el servidor. Para eso hay
# que bajárselo fuera, y por eso el guion imprime el tamaño total: es la cifra que
# hace falta para decidir cómo sacarlo.
#
# ─────────────────────────────────────────────────────────────────────────────
# LAS DOS COSAS QUE HACEN QUE UN CRON DE RESPALDO SIRVA
#
# **1. Callar cuando sale bien.** cPanel manda por correo TODO lo que el cron
# imprima. Un respaldo que escribe cuatro líneas cada noche son 365 correos al año
# que nadie abre, y el día que una de esas líneas diga «falló» tampoco se abre. Aquí
# la salida normal es **vacía**: todo va a la bitácora. Si algo falla, escribe en
# `stderr` y sale con código distinto de cero, y *eso* sí llega al correo.
#
# **2. Comprobar el fichero, no la ausencia de error.** Un `mysqldump` interrumpido
# por cuota, por `max_execution_time` o por la conexión **deja un `.gz` válido con
# media base dentro**. Las tres comprobaciones de `respaldo-antes-de-migrar.sh` están
# aquí también, y un colegio que las falle no cuenta como respaldado aunque el
# fichero exista.
#
# La rotación borra por días, y **sólo borra si el respaldo de hoy quedó bien**: el
# día que falle, lo viejo se queda. Es lo contrario de lo cómodo y es lo correcto —
# rotar primero es cómo se llega a no tener ninguno.
#
# Variables (con sus valores por defecto):
#   RAIZ='/home/micolev1/*.micolevirtual.com/8myvc'   las carpetas de esta cuenta
#   DESTINO=$HOME/respaldos/diario
#   DIAS=7                                            cuántos días se guardan
#   TOPE_MB=4000                                      si la carpeta pasa de aquí, avisa

set -u

RAIZ="${RAIZ:-/home/micolev1/*.micolevirtual.com/8myvc}"
DESTINO="${DESTINO:-$HOME/respaldos/diario}"
DIAS="${DIAS:-7}"
TOPE_MB="${TOPE_MB:-4000}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"

HOY=$(date +%Y-%m-%d)
CARPETA="${DESTINO}/${HOY}"
BITACORA="${DESTINO}/bitacora.log"
CERROJO="${DESTINO}/.corriendo"

mkdir -p "$DESTINO"
chmod 700 "$DESTINO" 2>/dev/null || true

# `mkdir` es atómico; `[ -e ]` seguido de `touch` no lo es. Si la tanda de anoche
# sigue viva, la de hoy no arranca encima: dos `mysqldump` a la vez sobre el mismo
# alojamiento compartido es cómo se tumba a los dieciséis colegios a las dos de la
# mañana.
if ! mkdir "$CERROJO" 2>/dev/null; then
    echo "Ya hay un respaldo corriendo (${CERROJO}). Si no es verdad, borra esa carpeta." >&2
    exit 1
fi
trap 'rmdir "$CERROJO" 2>/dev/null' EXIT INT TERM

mkdir -p "$CARPETA"

apuntar() {
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$BITACORA"
}

apuntar "── empieza la tanda ($HOY)"

BIEN=0
MAL=0
FALLADOS=""

for carpeta in $RAIZ; do
    [ -f "${carpeta}/.env" ] || continue

    base=$(grep -m1 '^DB_DATABASE=' "${carpeta}/.env" | cut -d= -f2- | tr -d '\r' | tr -d '"'"'"'')
    usuario=$(grep -m1 '^DB_USERNAME=' "${carpeta}/.env" | cut -d= -f2- | tr -d '\r' | tr -d '"'"'"'')
    clave=$(grep -m1 '^DB_PASSWORD=' "${carpeta}/.env" | cut -d= -f2- | tr -d '\r' | tr -d '"'"'"'')
    servidor=$(grep -m1 '^DB_HOST=' "${carpeta}/.env" | cut -d= -f2- | tr -d '\r' | tr -d '"'"'"'')

    # El `tr -d '\r'` de arriba: los `.env` de la cuenta de `lalvirtual` vienen con
    # fin de línea de Windows, y sin quitarlo la base se llama `nombre\r` y `mysqldump`
    # contesta «Incorrect database name» con el mensaje medio pisado por el retorno.
    if [ -z "$base" ] || [ -z "$usuario" ]; then
        apuntar "SIN .env legible: ${carpeta}"
        MAL=$((MAL + 1))
        FALLADOS="${FALLADOS} ${carpeta}"
        continue
    fi

    credenciales=$(mktemp "${TMPDIR:-/tmp}/respaldo.XXXXXX")
    chmod 600 "$credenciales"
    {
        echo '[client]'
        echo "user=${usuario}"
        echo "password=${clave}"
        [ -n "$servidor" ] && echo "host=${servidor}"
    } > "$credenciales"

    fichero="${CARPETA}/${base}.sql.gz"

    "$MYSQLDUMP" --defaults-extra-file="$credenciales" \
        --single-transaction --quick --no-tablespaces \
        --routines --triggers --events \
        --default-character-set=utf8mb4 \
        "$base" 2>"${fichero}.err" | gzip -c > "$fichero"
    salida=${PIPESTATUS[0]}

    rm -f "$credenciales"

    if [ "$salida" -ne 0 ]; then
        apuntar "FALLÓ mysqldump (${salida}) en ${base}: $(head -1 "${fichero}.err")"
        MAL=$((MAL + 1))
        FALLADOS="${FALLADOS} ${base}"
        continue
    fi

    if ! gzip -t "$fichero" 2>/dev/null; then
        apuntar "FALLÓ: el .gz de ${base} no se descomprime"
        MAL=$((MAL + 1))
        FALLADOS="${FALLADOS} ${base}"
        continue
    fi

    if ! gzip -dc "$fichero" | tail -5 | grep -q 'Dump completed'; then
        apuntar "FALLÓ: el volcado de ${base} está incompleto"
        MAL=$((MAL + 1))
        FALLADOS="${FALLADOS} ${base}"
        continue
    fi

    rm -f "${fichero}.err"
    apuntar "ok ${base} ($(du -h "$fichero" | cut -f1))"
    BIEN=$((BIEN + 1))
done

# Sólo se rota si HOY quedó entero. Un día malo no se lleva por delante los días
# buenos que quedan.
if [ "$MAL" -eq 0 ] && [ "$BIEN" -gt 0 ]; then
    find "$DESTINO" -mindepth 1 -maxdepth 1 -type d -name '20*-*-*' -mtime +"$DIAS" \
        -exec rm -rf {} + 2>/dev/null
    apuntar "rotación hecha: se guardan ${DIAS} días"
else
    apuntar "SIN ROTAR: la tanda no quedó limpia, lo viejo se queda"
fi

OCUPADO_MB=$(du -sm "$DESTINO" 2>/dev/null | cut -f1)
apuntar "── termina: ${BIEN} bien, ${MAL} mal · ${OCUPADO_MB} MB en total"

if [ "$MAL" -gt 0 ]; then
    echo "RESPALDO DIARIO: ${MAL} base(s) SIN RESPALDAR —${FALLADOS}" >&2
    echo "Mira ${BITACORA}" >&2
    exit 2
fi

if [ "$BIEN" -eq 0 ]; then
    echo "RESPALDO DIARIO: no se respaldó NINGUNA base. ¿Es correcta RAIZ='${RAIZ}'?" >&2
    exit 3
fi

if [ "${OCUPADO_MB:-0}" -gt "$TOPE_MB" ]; then
    echo "RESPALDO DIARIO: ${OCUPADO_MB} MB ocupados en ${DESTINO}, por encima de ${TOPE_MB}." >&2
    echo "Baja DIAS o saca los respaldos de la cuenta antes de que la cuota tumbe el colegio." >&2
    exit 4
fi

exit 0
