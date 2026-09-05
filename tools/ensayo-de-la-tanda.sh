#!/usr/bin/env bash
#
# Ensaya la tanda de migraciones pendientes sobre una COPIA de la base de
# trabajo, y contesta las tres preguntas que nadie puede contestar leyendo el
# código:
#
#   1. ¿corren las ocho de una vez, y cuánto tardan sobre datos de verdad?
#   2. ¿la comprobación de `docs/DESPLIEGUE.md` sabe fallar, y sabe acertar?
#   3. ¿la comprobación pregunta por TODO lo que la tanda cambia, o se le ha
#      quedado corta? — que es lo que pasó el 4 sep 2026 con `profesores.tono`
#   4. ¿y contesta el módulo de horario lo que tiene que contestar en un colegio
#      recién migrado? — `tools/comprobar-el-horario.php` sobre la copia
#
# **Por qué existe.** `8myvc-06` ensayó estas ocho el 3 sep 2026 sobre una copia
# de `simonbolivar` y midió ~1,0 s las ocho. Luego **borró la copia**, así que lo
# que quedó fue una cifra sin forma de repetirla: ni el estado de partida, ni el
# rebobinado, ni los controles. Una cifra que nadie puede volver a sacar no es
# una medición, es un recuerdo. Esto es el procedimiento.
#
# **No toca la base de trabajo.** Copia, rebobina la copia hasta el estado que
# hay desplegado, migra la copia y compara la copia. El guard de abajo aborta si
# el nombre de destino no lleva `_ensayo` dentro.
#
# Uso (desde el host, con el docker de desarrollo levantado):
#
#     tools/ensayo-de-la-tanda.sh                 # ensaya y DEJA la copia en pie
#     tools/ensayo-de-la-tanda.sh --limpiar       # ensaya y borra la copia al final
#     tools/ensayo-de-la-tanda.sh --solo-limpiar  # sólo borra la copia
#
# La copia se deja en pie a propósito: es una base recién migrada, que es justo
# lo que hace falta para la comprobación del módulo de horario —`GET
# horario/versiones` tiene que dar `200` con `total: 0`— y para cualquier otra
# pregunta que se le quiera hacer a un colegio recién desplegado.
#
# Variables (con sus valores por defecto):
#   DB_ORIGEN=simonbolivar               la base de trabajo, de sólo lectura aquí
#   DB_ENSAYO=simonbolivar_ensayo        la copia; tiene que llevar `_ensayo`
#   BASE_DESPLEGADA=9474b50              el último commit desplegado en los colegios
#   CHEQUEO_EN=docs/DESPLIEGUE.md        de dónde se saca la comprobación de esquema;
#                                        se cambia para probar el detector contra
#                                        una copia mutilada a propósito
#   MYSQL_EXEC="docker exec -i 8myvc-database-1"
#   PHP_EXEC="docker exec -i 8myvc-app-1"
#
# ## El control de ESTE script, que no cabe en un `--control`
#
# La pieza que puede mentir aquí es el detector de cobertura del punto 5: si se
# equivoca, deja pasar una comprobación corta y el día del despliegue alguien lee
# un `OK` en un colegio al que le falta una columna. **Se ha visto en rojo**, y
# se vuelve a ver así — necesita la copia y el docker, por eso no está en la
# suite:
#
#     sed 's/,\["profesores","tono"\]//' docs/DESPLIEGUE.md > /tmp/sin-tono.md
#     CHEQUEO_EN=/tmp/sin-tono.md DB_ENSAYO=simonbolivar_ensayo_control \
#         tools/ensayo-de-la-tanda.sh --limpiar
#
# Tiene que cantar `CORTA: columna-de:profesores` y salir con **1**. Ese hueco es
# el de verdad: la comprobación del documento estuvo así hasta el 4 sep 2026.
#
# Códigos de salida, y son TRES: `0` el ensayo cuadra, `1` el ensayo encontró
# algo (una migración que falla, un control que no salta, una cobertura corta),
# `2` NO MEDIDO — no se pudo llegar a medir. La diferencia entre el 1 y el 2 es
# la de siempre en este repo: un `0` limpio de una corrida que no midió nada es
# la respuesta que archiva el asunto.

set -uo pipefail

cd "$(dirname "$0")/.."

DB_ORIGEN="${DB_ORIGEN:-simonbolivar}"
DB_ENSAYO="${DB_ENSAYO:-simonbolivar_ensayo}"
BASE_DESPLEGADA="${BASE_DESPLEGADA:-9474b50}"
CHEQUEO_EN="${CHEQUEO_EN:-docs/DESPLIEGUE.md}"
DB_USERNAME="${DB_USERNAME:-root}"

if [ -z "${DB_PASSWORD:-}" ] && [ -f .env ]; then
    DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2- || true)"
fi

MYSQL_EXEC="${MYSQL_EXEC-docker exec -i 8myvc-database-1}"
PHP_EXEC="${PHP_EXEC-docker exec -i 8myvc-app-1}"

LIMPIAR=0
SOLO_LIMPIAR=0
for arg in "$@"; do
    case "$arg" in
        --limpiar) LIMPIAR=1 ;;
        --solo-limpiar) SOLO_LIMPIAR=1 ;;
        *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
    esac
done

# Guard: este script BORRA y RECREA la base de destino. Que nadie la apunte por
# accidente a la de trabajo ni a una base de tests de otra sesión.
case "$DB_ENSAYO" in
    *_ensayo|*_ensayo_*) ;;
    *)
        echo "DB_ENSAYO='$DB_ENSAYO' no lleva '_ensayo' dentro." >&2
        echo "Este script BORRA esa base entera. Abortando." >&2
        exit 2
        ;;
esac
if [ "$DB_ENSAYO" = "$DB_ORIGEN" ]; then
    echo "DB_ENSAYO y DB_ORIGEN son la misma base. Abortando." >&2
    exit 2
fi

mysql_cmd() {
    $MYSQL_EXEC mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$@" \
        2> >(grep -v '^mysql: \[Warning\] Using a password' >&2)
}

# `sh -c` y no `mysql_cmd`: el volcado y la carga van dentro del MISMO
# contenedor, sin pasar los 210 MB por el host. Con la tubería fuera, copiar la
# base tardaba lo que tarda el docker en mover los bytes, que no es lo que se
# quiere medir aquí.
copiar_base() {
    $MYSQL_EXEC sh -c "mysqldump -u'$DB_USERNAME' -p'$DB_PASSWORD' \
            --single-transaction --quick --routines --triggers \
            --set-gtid-purged=OFF '$DB_ORIGEN' 2>/dev/null \
        | mysql -u'$DB_USERNAME' -p'$DB_PASSWORD' '$DB_ENSAYO' 2>/dev/null"
}

artisan_ensayo() {
    $PHP_EXEC env DB_DATABASE="$DB_ENSAYO" php artisan "$@"
}

titulo() { printf '\n\033[1m%s\033[0m\n' "$1"; }

if [ "$SOLO_LIMPIAR" = "1" ]; then
    mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_ENSAYO\`;" || exit 2
    echo "Copia '$DB_ENSAYO' borrada."
    exit 0
fi

HALLAZGOS=0

# ─────────────────────────────────────────────────────────────────────────────
# 0. LA POBLACIÓN, ANTES DE NADA
#
# Ninguna herramienta de este repo imprime OK sin decir sobre cuántas filas
# midió: «las ocho en 1,0 s» no significa lo mismo sobre 1,17 M notas que sobre
# la base de tests, que tiene el seed. Y aquí importa el doble, porque lo que se
# ensaya es si la tanda aguanta el tamaño de un colegio de verdad.
# ─────────────────────────────────────────────────────────────────────────────
titulo "ENSAYO DE LA TANDA — la copia, el rebobinado, las ocho y los controles"

POBLACION=$(mysql_cmd -N -B -e "
    SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_ORIGEN'),
           (SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables WHERE table_schema='$DB_ORIGEN'),
           (SELECT COUNT(*) FROM \`$DB_ORIGEN\`.notas),
           (SELECT COUNT(*) FROM \`$DB_ORIGEN\`.notas_finales),
           (SELECT COUNT(*) FROM \`$DB_ORIGEN\`.migrations),
           (SELECT MAX(migration) FROM \`$DB_ORIGEN\`.migrations);" 2>/dev/null)

if [ -z "$POBLACION" ]; then
    echo "NO MEDIDO: la base '$DB_ORIGEN' no contesta." >&2
    exit 2
fi
read -r P_TABLAS P_MB P_NOTAS P_FINALES P_MIGR P_ULTIMA <<< "$POBLACION"

printf '\n0. LA POBLACIÓN MIRADA — la copia sale de aquí\n'
printf '   Base de origen                        %s\n' "$DB_ORIGEN"
printf '   Tablas                             %6s\n' "$P_TABLAS"
printf '   Tamaño                             %6s MB\n' "$P_MB"
printf '   Filas en `notas`                   %6s\n' "$P_NOTAS"
printf '   Filas en `notas_finales`           %6s\n' "$P_FINALES"
printf '   Migraciones aplicadas              %6s — la última, %s\n' "$P_MIGR" "$P_ULTIMA"

# ─────────────────────────────────────────────────────────────────────────────
# 1. QUÉ ES LA TANDA — se pregunta a git, no a una lista escrita a mano
#
# La lista de migraciones pendientes se saca del rango `<desplegado>..HEAD`, que
# es la misma orden que `docs/DESPLIEGUE.md` manda correr el día del despliegue.
# Escribirla a mano aquí la habría dejado envejecer en cuanto entrara la novena.
# ─────────────────────────────────────────────────────────────────────────────
if ! git rev-parse --verify "$BASE_DESPLEGADA" >/dev/null 2>&1; then
    echo "NO MEDIDO: el commit base '$BASE_DESPLEGADA' no existe en este árbol." >&2
    exit 2
fi

PENDIENTES=$(git diff --name-only "$BASE_DESPLEGADA" HEAD -- database/migrations/ \
    | grep '\.php$' | xargs -n1 basename 2>/dev/null | sed 's/\.php$//' | sort)
N_PENDIENTES=$(printf '%s\n' "$PENDIENTES" | grep -c . || true)

printf '\n1. LA TANDA, PREGUNTADA A GIT — rango %s..HEAD (%s)\n' \
    "$BASE_DESPLEGADA" "$(git rev-parse --short HEAD)"
printf '   Migraciones que entran             %6s\n' "$N_PENDIENTES"
printf '%s\n' "$PENDIENTES" | sed 's/^/     - /'

if [ "$N_PENDIENTES" -eq 0 ]; then
    echo "NO MEDIDO: no hay migraciones pendientes en ese rango; no hay nada que ensayar." >&2
    exit 2
fi

EN_LISTA=$(printf '%s\n' "$PENDIENTES" | sed "s/.*/'&'/" | paste -sd, -)

# ─────────────────────────────────────────────────────────────────────────────
# 2. LA COPIA
# ─────────────────────────────────────────────────────────────────────────────
printf '\n2. LA COPIA — `%s` -> `%s`\n' "$DB_ORIGEN" "$DB_ENSAYO"
mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_ENSAYO\`;
              CREATE DATABASE \`$DB_ENSAYO\`
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit 2

T0=$(date +%s)
copiar_base || { echo "NO MEDIDO: la copia falló." >&2; exit 2; }
T1=$(date +%s)

COPIADAS=$(mysql_cmd -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_ENSAYO';" 2>/dev/null)
printf '   Tablas copiadas                    %6s de %s, en %s s\n' "$COPIADAS" "$P_TABLAS" "$((T1 - T0))"
if [ "$COPIADAS" != "$P_TABLAS" ]; then
    echo "NO MEDIDO: la copia salió incompleta ($COPIADAS de $P_TABLAS tablas)." >&2
    exit 2
fi

# El esquema del origen, para compararlo al final. Esto es el control que
# ninguna comprobación escrita a mano puede dar: dice lo que la tanda cambia DE
# VERDAD, columna a columna, en vez de lo que alguien se acordó de preguntar.
esquema_de() {
    mysql_cmd -N -B -e "SELECT CONCAT(table_name,'.',column_name,' ',column_type)
        FROM information_schema.columns WHERE table_schema='$1'
        ORDER BY table_name, column_name;" 2>/dev/null
}
ESQ_HOY=$(mktemp); ESQ_DESPLEGADO=$(mktemp); ESQ_ENSAYADO=$(mktemp)
trap 'rm -f "$ESQ_HOY" "$ESQ_DESPLEGADO" "$ESQ_ENSAYADO"' EXIT
esquema_de "$DB_ORIGEN" > "$ESQ_HOY"

# ─────────────────────────────────────────────────────────────────────────────
# 3. EL REBOBINADO — dejar la copia como está el servidor HOY
#
# `migrate:rollback` deshace por LOTES, y aquí los lotes no cuadran con la
# tanda: en esta base el lote 14 lleva dentro `notas_finales_en_decimal`, que
# **ya está desplegada**. Rebobinar por lotes la deshace también, y entonces el
# `migrate` de abajo correría NUEVE migraciones y mediría un despliegue que no
# es el que va a pasar — además de rehacer un cambio de tipo sobre 127.000 filas
# por gusto.
#
# Por eso las pendientes se reagrupan en un lote propio, el último, antes de
# rebobinar: es una escritura en la tabla `migrations` **de la copia**, no del
# origen, y deja el rollback exactamente sobre las que entran.
#
# Y `--step` de `migrate:rollback` **cuenta migraciones, no lotes**, que es lo
# contrario de lo que sugiere el nombre y de lo que hace `--step` en `migrate`.
# Con `--step=1` se deshace UNA —la última por orden de nombre dentro del último
# lote— y el ensayo mediría siete de ocho creyéndose completo. Se le pasa el
# número de la tanda, que con el reagrupado de arriba son exactamente ésas.
# ─────────────────────────────────────────────────────────────────────────────
printf '\n3. EL REBOBINADO — la copia vuelve al estado de `%s`\n' "$BASE_DESPLEGADA"

APLICADAS_ANTES=$(mysql_cmd -N -B -e "SELECT COUNT(*) FROM \`$DB_ENSAYO\`.migrations WHERE migration IN ($EN_LISTA);" 2>/dev/null)
printf '   De las %s, aplicadas en la copia    %6s\n' "$N_PENDIENTES" "$APLICADAS_ANTES"
if [ "$APLICADAS_ANTES" != "$N_PENDIENTES" ]; then
    echo "NO MEDIDO: la base de origen no tiene la tanda entera aplicada; no hay nada que rebobinar." >&2
    exit 2
fi

# El lote se calcula en su propia consulta y se pasa como número literal, y esto
# NO es un rodeo. Con `SET batch = (SELECT MAX(batch)+1 FROM migrations)` —aun
# envuelto en una tabla derivada para que MySQL lo acepte— el `MAX` se reevalúa
# fila a fila **sobre la tabla que se está escribiendo**: las ocho salen con
# ocho lotes distintos y crecientes, el rollback deshace sólo el último, y el
# ensayo mide una migración en vez de ocho. Pasó a la primera, el 4 sep 2026.
LOTE_NUEVO=$(mysql_cmd -N -B -e "SELECT MAX(batch)+1 FROM \`$DB_ENSAYO\`.migrations;" 2>/dev/null)
case "$LOTE_NUEVO" in
    ''|*[!0-9]*) echo "NO MEDIDO: no se pudo calcular el lote del rebobinado." >&2; exit 2 ;;
esac
mysql_cmd -e "UPDATE \`$DB_ENSAYO\`.migrations SET batch = $LOTE_NUEVO
              WHERE migration IN ($EN_LISTA);" || exit 2

EN_EL_LOTE=$(mysql_cmd -N -B -e "SELECT COUNT(*) FROM \`$DB_ENSAYO\`.migrations WHERE batch = $LOTE_NUEVO;" 2>/dev/null)
printf '   Reagrupadas en el lote %-4s        %6s (tienen que ser %s)\n' "$LOTE_NUEVO" "$EN_EL_LOTE" "$N_PENDIENTES"
if [ "$EN_EL_LOTE" != "$N_PENDIENTES" ]; then
    echo "NO MEDIDO: el lote $LOTE_NUEVO no contiene exactamente la tanda." >&2
    exit 2
fi

SALIDA_ROLLBACK=$(artisan_ensayo migrate:rollback --step="$N_PENDIENTES" --force --no-interaction 2>&1)
echo "$SALIDA_ROLLBACK" | grep -E 'Rolling back|DONE|FAIL|[0-9]+(\.[0-9]+)? ?ms' | sed 's/^/     /'

QUEDAN=$(mysql_cmd -N -B -e "SELECT COUNT(*) FROM \`$DB_ENSAYO\`.migrations WHERE migration IN ($EN_LISTA);" 2>/dev/null)
TOTAL_TRAS=$(mysql_cmd -N -B -e "SELECT COUNT(*) FROM \`$DB_ENSAYO\`.migrations;" 2>/dev/null)
printf '   De las %s, quedan aplicadas        %6s (tiene que ser 0)\n' "$N_PENDIENTES" "$QUEDAN"
printf '   Migraciones en la copia            %6s (eran %s)\n' "$TOTAL_TRAS" "$P_MIGR"
if [ "$QUEDAN" != "0" ]; then
    echo "NO MEDIDO: el rebobinado no deshizo la tanda entera." >&2
    echo "$SALIDA_ROLLBACK" | tail -20 >&2
    exit 2
fi

esquema_de "$DB_ENSAYO" > "$ESQ_DESPLEGADO"

# ─────────────────────────────────────────────────────────────────────────────
# 4. EL DELTA REAL — lo que la tanda cambia, medido y no recordado
# ─────────────────────────────────────────────────────────────────────────────
COL_NUEVAS=$(comm -13 <(cut -d' ' -f1 "$ESQ_DESPLEGADO") <(cut -d' ' -f1 "$ESQ_HOY"))
COL_RETIRADAS=$(comm -23 <(cut -d' ' -f1 "$ESQ_DESPLEGADO") <(cut -d' ' -f1 "$ESQ_HOY"))
TABLAS_HOY=$(cut -d. -f1 "$ESQ_HOY" | sort -u)
TABLAS_DESPLEGADAS=$(cut -d. -f1 "$ESQ_DESPLEGADO" | sort -u)
TABLAS_NUEVAS=$(comm -13 <(printf '%s\n' "$TABLAS_DESPLEGADAS") <(printf '%s\n' "$TABLAS_HOY"))

# Las columnas de una tabla NUEVA no se cuentan como «columnas nuevas de una
# tabla que ya estaba»: la tabla entera es el cambio, y contarlas aparte haría
# creer que la comprobación tiene que preguntar por las 47 de `rubricas`.
COL_NUEVAS_EN_TABLAS_VIEJAS=$(printf '%s\n' "$COL_NUEVAS" | grep -v '^$' \
    | grep -vFf <(printf '%s\n' "$TABLAS_NUEVAS" | grep -v '^$' | sed 's/$/./') || true)
TABLAS_TOCADAS=$(printf '%s\n' "$COL_NUEVAS_EN_TABLAS_VIEJAS" | grep -v '^$' | cut -d. -f1 | sort -u)

printf '\n4. EL DELTA REAL — lo que la tanda cambia en el esquema\n'
printf '   Tablas nuevas                      %6s %s\n' \
    "$(printf '%s\n' "$TABLAS_NUEVAS" | grep -c . || true)" "$(printf '%s\n' "$TABLAS_NUEVAS" | paste -sd' ' -)"
printf '   Columnas nuevas en tablas viejas   %6s, repartidas en %s tabla(s)\n' \
    "$(printf '%s\n' "$COL_NUEVAS_EN_TABLAS_VIEJAS" | grep -c . || true)" \
    "$(printf '%s\n' "$TABLAS_TOCADAS" | grep -c . || true)"
printf '%s\n' "$COL_NUEVAS_EN_TABLAS_VIEJAS" | grep -v '^$' | sed 's/^/     + /'
printf '   Columnas RETIRADAS                 %6s\n' "$(printf '%s\n' "$COL_RETIRADAS" | grep -c . || true)"
printf '%s\n' "$COL_RETIRADAS" | grep -v '^$' | sed 's/^/     - /'

# ─────────────────────────────────────────────────────────────────────────────
# 5. EL CONTROL NEGATIVO — la comprobación de DESPLIEGUE.md tiene que FALLAR
#
# La comprobación no se copia aquí: se SACA del documento. Copiarla dejaría dos
# textos que envejecen por separado, y el que se ejecuta el día del despliegue
# es el del documento. Sacándola de ahí, este ensayo comprueba **la de verdad**.
# ─────────────────────────────────────────────────────────────────────────────
CHEQUEO=$(grep -h 'artisan tinker --execute=' "$CHEQUEO_EN" 2>/dev/null | head -1)
N_CHEQUEOS=$(grep -hc 'artisan tinker --execute=' "$CHEQUEO_EN" 2>/dev/null || true)
printf '\n5. LOS CONTROLES DE LA COMPROBACIÓN DE `%s`\n' "$CHEQUEO_EN"
if [ -z "$CHEQUEO" ] || [ "$N_CHEQUEOS" != "1" ]; then
    echo "   NO MEDIDO: en $CHEQUEO_EN hay $N_CHEQUEOS comprobaciones de esquema, se esperaba 1." >&2
    exit 2
fi

SIN_MIGRAR=$($PHP_EXEC env DB_DATABASE="$DB_ENSAYO" sh -c "$CHEQUEO" 2>&1 | tail -1)
printf '   Contra la copia SIN migrar         %s\n' "$SIN_MIGRAR"
case "$SIN_MIGRAR" in
    FALTA*) ;;
    *) printf '   \033[31m^ el control negativo NO saltó: un OK que no sabe fallar archiva el asunto\033[0m\n'
       HALLAZGOS=$((HALLAZGOS + 1)) ;;
esac

# La cobertura: ¿pregunta la comprobación por todo lo que la tanda cambia?
#
# Ésta es la pregunta que costó una fila del documento el 4 sep 2026. La
# comprobación preguntaba por siete columnas cuando la tanda cambiaba ocho
# tablas, y `profesores.tono` no salía: en un colegio al que le faltara esa
# columna, la comprobación habría contestado «OK» con la misma cara.
#
# La regla que se comprueba NO es «que pregunte por todas las columnas» —serían
# decenas—, sino: **de cada tabla que cambia, al menos una**. Con esa regla el
# hueco de `profesores.tono` habría salido en rojo el día que entró.
CORTA=""
for t in $TABLAS_NUEVAS; do
    printf '%s' "$CHEQUEO" | grep -q "\"$t\"" || CORTA="$CORTA tabla:$t"
done
for t in $TABLAS_TOCADAS; do
    encontrada=0
    for c in $(printf '%s\n' "$COL_NUEVAS_EN_TABLAS_VIEJAS" | grep "^$t\." | cut -d. -f2-); do
        if printf '%s' "$CHEQUEO" | grep -q "\[\"$t\",\"$c\"\]"; then encontrada=1; break; fi
    done
    [ "$encontrada" = "1" ] || CORTA="$CORTA columna-de:$t"
done
for c in $COL_RETIRADAS; do
    tabla="${c%%.*}"; col="${c#*.}"
    printf '%s' "$CHEQUEO" | grep -q "\"$tabla\",\"$col\"" || CORTA="$CORTA retirada:$c"
done

if [ -z "$CORTA" ]; then
    printf '   Cobertura de la comprobación       cuadra: de cada tabla que cambia pregunta al menos una cosa\n'
else
    printf '   \033[31mCobertura de la comprobación       CORTA:%s\033[0m\n' "$CORTA"
    printf '   \033[31m^ contestaría OK en un colegio al que le falte eso\033[0m\n'
    HALLAZGOS=$((HALLAZGOS + 1))
fi

# ─────────────────────────────────────────────────────────────────────────────
# 6. LA TANDA, CRONOMETRADA — y se cronometra DOS veces, porque son dos cifras
#
# La suma de los ms que imprime Laravel es **lo que tardan las migraciones**; el
# reloj de punta a punta es **lo que tarda el comando**, arranque del framework
# incluido. La que sirve para decir «la ventana del colegio dura esto» es la
# segunda; la primera es la comparable con lo que midió `8myvc-06`.
#
# El reloj se toma DENTRO del contenedor, y con `EPOCHREALTIME` y no con `date`:
# el `date` de esta imagen es el de BusyBox y **no conoce `%N`** —contesta los
# segundos y se queda tan ancho—, así que `(f-i)/1000000` daba `0 ms` para una
# tanda de segundo y pico. Un cronómetro que contesta cero es peor que no
# cronometrar: parece una medición buenísima.
# ─────────────────────────────────────────────────────────────────────────────
printf '\n6. LA TANDA — `migrate --force` sobre %s filas de `notas`\n' "$P_NOTAS"
SALIDA_MIGRATE=$($PHP_EXEC env DB_DATABASE="$DB_ENSAYO" bash -c \
    'i=${EPOCHREALTIME/./}; php artisan migrate --force --no-interaction; s=$?; f=${EPOCHREALTIME/./}; echo "TOTAL_MS $(( (f-i)/1000 ))"; exit $s' 2>&1)
CODIGO_MIGRATE=$?
echo "$SALIDA_MIGRATE" | grep -Ev '^\s*$|TOTAL_MS' | sed 's/^/     /'
TOTAL_MS=$(echo "$SALIDA_MIGRATE" | grep '^TOTAL_MS' | awk '{print $2}')
SUMA_MS=$(echo "$SALIDA_MIGRATE" | grep -oE '[0-9]+\.[0-9]+ms' | tr -d 'ms' \
    | awk '{s+=$1} END {printf "%.0f", s}')
N_CORRIDAS=$(echo "$SALIDA_MIGRATE" | grep -c 'DONE' || true)

if [ "$CODIGO_MIGRATE" != "0" ]; then
    printf '   \033[31mLa tanda FALLÓ (código %s)\033[0m\n' "$CODIGO_MIGRATE"
    HALLAZGOS=$((HALLAZGOS + 1))
fi
printf '\n   Migraciones corridas               %6s de %s\n' "$N_CORRIDAS" "$N_PENDIENTES"
printf '   Suma de las que imprime Laravel    %6s ms — sólo las migraciones\n' "${SUMA_MS:-?}"
printf '   De punta a punta                   %6s ms — el comando entero\n' "${TOTAL_MS:-?}"
if [ "$N_CORRIDAS" != "$N_PENDIENTES" ]; then
    printf '   \033[31m^ no corrieron las %s: la copia no partía del estado desplegado\033[0m\n' "$N_PENDIENTES"
    HALLAZGOS=$((HALLAZGOS + 1))
fi
if [ "${TOTAL_MS:-0}" -le 0 ] 2>/dev/null; then
    printf '   \033[31m^ el cronómetro contestó cero: NO MEDIDO, no «instantáneo»\033[0m\n'
    HALLAZGOS=$((HALLAZGOS + 1))
fi

# ─────────────────────────────────────────────────────────────────────────────
# 7. LOS CONTROLES DE SALIDA
# ─────────────────────────────────────────────────────────────────────────────
printf '\n7. DESPUÉS DE MIGRAR\n'

MIGRADA=$($PHP_EXEC env DB_DATABASE="$DB_ENSAYO" sh -c "$CHEQUEO" 2>&1 | tail -1)
printf '   La comprobación del despliegue     %s\n' "$MIGRADA"
case "$MIGRADA" in
    OK*) ;;
    *) HALLAZGOS=$((HALLAZGOS + 1)) ;;
esac

# El control que ninguna lista escrita a mano da: la copia migrada tiene que
# quedar con el MISMO esquema que la base de trabajo, columna a columna y tipo a
# tipo. Si sobra o falta una, la tanda no reproduce lo que hay en desarrollo.
esquema_de "$DB_ENSAYO" > "$ESQ_ENSAYADO"
DIF=$(diff "$ESQ_HOY" "$ESQ_ENSAYADO" || true)
N_COLUMNAS=$(wc -l < "$ESQ_HOY" | tr -d ' ')
if [ -z "$DIF" ]; then
    printf '   La copia contra `%s`     idénticas: %s columnas, mismo tipo\n' "$DB_ORIGEN" "$N_COLUMNAS"
else
    printf '   \033[31mLa copia contra `%s`     NO son idénticas:\033[0m\n' "$DB_ORIGEN"
    printf '%s\n' "$DIF" | sed 's/^/     /'
    HALLAZGOS=$((HALLAZGOS + 1))
fi

FALTAN=$(artisan_ensayo migrate:status --no-interaction 2>/dev/null | grep -c 'Pending' || true)
printf '   Migraciones pendientes             %6s\n' "$FALTAN"
[ "$FALTAN" = "0" ] || HALLAZGOS=$((HALLAZGOS + 1))

# ─────────────────────────────────────────────────────────────────────────────
# 8. LA PREGUNTA DEL MÓDULO, SOBRE LA COPIA RECIÉN MIGRADA
#
# El esquema puede estar entero y la ruta contestar otra cosa: la comprobación
# de columnas pregunta por la base, no por el router. Y la copia acaba de quedar
# en el estado exacto que hace falta para la única pregunta que distingue «el
# módulo está y no han subido nada» de «el módulo no llegó» — `total: 0`, que
# sobre la base de trabajo daría 6 y no probaría nada.
# ─────────────────────────────────────────────────────────────────────────────
printf '\n8. EL MÓDULO DE HORARIO SOBRE LA COPIA — `tools/comprobar-el-horario.php`\n'
SALIDA_HORARIO=$($PHP_EXEC env DB_DATABASE="$DB_ENSAYO" php tools/comprobar-el-horario.php 2>&1)
CODIGO_HORARIO=$?
echo "$SALIDA_HORARIO" | grep -E 'GET horario|total|oficial_id|403|LLEGO|FALLA|NO MEDIDO' | sed 's/^ */   /'
case "$CODIGO_HORARIO" in
    0) ;;
    2) printf '   \033[31m^ NO MEDIDO: no se pudo preguntar\033[0m\n'; HALLAZGOS=$((HALLAZGOS + 1)) ;;
    *) HALLAZGOS=$((HALLAZGOS + 1)) ;;
esac

# ─────────────────────────────────────────────────────────────────────────────
printf '\n──────────────────────────────────────────────────────────────────────────────\n'
if [ "$LIMPIAR" = "1" ]; then
    mysql_cmd -e "DROP DATABASE IF EXISTS \`$DB_ENSAYO\`;"
    echo "Copia '$DB_ENSAYO' borrada (--limpiar)."
else
    echo "La copia '$DB_ENSAYO' se queda en pie: es un colegio RECIÉN MIGRADO."
    echo "  docker exec -e DB_DATABASE=$DB_ENSAYO 8myvc-app-1 php artisan tinker"
    echo "  tools/ensayo-de-la-tanda.sh --solo-limpiar   # para borrarla"
fi

if [ "$HALLAZGOS" -eq 0 ]; then
    echo "ENSAYO LIMPIO: las $N_PENDIENTES corren en ${SUMA_MS:-?} ms (${TOTAL_MS:-?} ms el comando) sobre $P_NOTAS notas, y los dos controles saltan."
    exit 0
fi
echo "ENSAYO CON $HALLAZGOS HALLAZGO(S): mira los renglones en rojo."
exit 1
