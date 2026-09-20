#!/usr/bin/env bash
#
# ¿El `ALTER` de la casilla vacía bloquea el guardado de notas en MariaDB?
#
# Contesta la única pregunta que quedaba abierta de la Fase 0 de
# `docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md`: la migración
# `2026_09_19_500000_la_casilla_vacia` quita el `NOT NULL` de `notas.nota`, y eso
# **reconstruye la tabla entera** —123 MB y 1.166.608 filas en la copia de
# desarrollo, dos tercios de la base—. Los 8 s que había medidos son del **MySQL
# 8.0.46 del docker**; producción corre **MariaDB 10.5.25**, y ahí ni el
# algoritmo ni el tiempo estaban medidos.
#
# **Lo que decide no son los segundos: es si MariaDB lo hace `INPLACE`.** Si cae
# a `ALGORITHM=COPY`, `notas` queda de sólo lectura mientras dure, y un docente
# guardando notas a esa hora recibe un error. La migración **no escribe
# `ALGORITHM=` a propósito** —si MariaDB no pudiera hacerlo así, con la cláusula
# puesta fallaría; sin ella cae a copia y termina— así que lo que hay que saber
# es cuál elige.
#
# Uso (desde el host, con el docker de desarrollo levantado):
#
#     tools/ensayo-del-alter-en-maria.sh            # monta, mide y DEJA la copia en pie
#     tools/ensayo-del-alter-en-maria.sh --limpiar  # y borra el contenedor al final
#
# Variables:
#   DB_ORIGEN=simonbolivar      la base de trabajo; aquí es de SÓLO LECTURA
#   IMAGEN=mariadb:10.5         el motor contra el que se mide
#   CONTENEDOR=maria105-ensayo
#
# **No toca la base de trabajo.** Vuelca cuatro tablas, las carga en un
# contenedor aparte y mide allí.
#
# ─────────────────────────────────────────────────────────────────────────────
# LAS DOS TRAMPAS, que es la razón de que esto sea un guion y no cuatro órdenes:
#
# 1. **Hay que REBOBINAR antes de medir.** La Fase 0 ya está aplicada a la base
#    de desarrollo, así que una copia recién hecha trae `nota` anulable y las
#    20.655 filas ya vaciadas: medir ahí cronometra un `ALTER` que no hace nada.
#    El rebobinado es el `down()` de la migración, y se comprueba —el proxy tiene
#    que volver a seleccionar exactamente esas 20.655 filas— antes de cronometrar.
#
# 2. **La señal NO es que la escritura falle.** Con `LOCK=NONE` la escritura
#    pasa; con `LOCK=SHARED` tampoco falla: **espera** a que el `ALTER` termine.
#    Lo que distingue los dos casos es la LATENCIA, y por eso esto mide
#    milisegundos y trae un **control que sí bloquea** (`COPY, LOCK=SHARED`).
#    Sin ese control, un resultado verde no distingue «no bloquea» de «mi bucle
#    no llegó a correr ni una vez».
#
#    La primera sonda que se escribió para esto contó **40 escrituras fallidas
#    con las diez filas escritas**: el `if` miraba el código de salida de un
#    `grep -vi warning`, que devuelve 1 cuando no selecciona nada. *El primer
#    sitio donde mirar cuando el número sale raro es el detector.*
#
# Medido el 20 sep 2026 en un Mac con Docker Desktop, MariaDB 10.5.29
# (`innodb_buffer_pool_size` 128 MB, el de fábrica), sobre la copia de
# desarrollo de `simonbolivar` — **UN colegio, no los dieciséis**. Esto NO es
# CloudLinux: allí hay límites de I/O por cuenta y los segundos serán otros. Lo
# que viaja de aquí es **el algoritmo**, que es lo que decidía.
set -euo pipefail

DB_ORIGEN="${DB_ORIGEN:-simonbolivar}"
IMAGEN="${IMAGEN:-mariadb:10.5}"
CONTENEDOR="${CONTENEDOR:-maria105-ensayo}"
DB_ENSAYO=simonbolivar_ensayo
CLAVE=ensayo
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VOLCADO="${TMPDIR:-/tmp}/ensayo-alter-maria.sql"

maria() { docker exec "$CONTENEDOR" mariadb -uroot -p"$CLAVE" -N -B "$DB_ENSAYO" -e "$1" 2>/dev/null; }
ahora() { python3 -c 'import time;print(time.time())'; }

echo "== 1 · volcando $DB_ORIGEN (sólo lectura) =="
CLAVE_ORIGEN=$(grep -E '^DB_PASSWORD=' "$RAIZ/.env" | cut -d= -f2-)
docker exec 8myvc-database-1 mysqldump -uroot -p"$CLAVE_ORIGEN" \
    --single-transaction --no-tablespaces --skip-add-locks --disable-keys \
    --set-gtid-purged=OFF "$DB_ORIGEN" notas subunidades unidades periodos \
    2>/dev/null > "$VOLCADO"
echo "   $(du -h "$VOLCADO" | cut -f1) en $VOLCADO"

echo "== 2 · levantando $IMAGEN =="
docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true
docker run -d --name "$CONTENEDOR" -e MYSQL_ROOT_PASSWORD="$CLAVE" \
    -e MYSQL_DATABASE="$DB_ENSAYO" "$IMAGEN" >/dev/null
for i in $(seq 1 60); do maria "SELECT 1" >/dev/null 2>&1 && break; sleep 2; done
echo "   $(maria "SELECT VERSION()")  ·  buffer pool $(maria "SELECT @@innodb_buffer_pool_size/1024/1024") MB"

echo "== 3 · cargando =="
docker exec -i "$CONTENEDOR" mariadb -uroot -p"$CLAVE" "$DB_ENSAYO" < "$VOLCADO" 2>/dev/null
echo "   notas: $(maria "SELECT COUNT(*) FROM notas") filas · $(maria "SELECT ROUND((data_length+index_length)/1024/1024) FROM information_schema.tables WHERE table_schema='$DB_ENSAYO' AND table_name='notas'") MB"

echo "== 4 · REBOBINANDO al estado de antes de la Fase 0 (el down() de la migración) =="
maria "UPDATE notas SET nota = 0 WHERE nota IS NULL" >/dev/null
maria "ALTER TABLE notas MODIFY COLUMN nota int NOT NULL DEFAULT 0" >/dev/null
PROXY=$(maria "SELECT COUNT(*) FROM notas n
   INNER JOIN subunidades s ON s.id=n.subunidad_id AND s.deleted_at IS NULL
   INNER JOIN unidades    u ON u.id=s.unidad_id    AND u.deleted_at IS NULL
   INNER JOIN periodos    p ON p.id=u.periodo_id   AND p.deleted_at IS NULL
 WHERE n.deleted_at IS NULL AND p.profes_pueden_editar_notas=1
   AND n.updated_by IS NULL AND n.created_at <=> n.updated_at")
echo "   nota es anulable: $(maria "SELECT is_nullable FROM information_schema.columns WHERE table_schema='$DB_ENSAYO' AND table_name='notas' AND column_name='nota'") (tiene que decir NO)"
echo "   filas que el relleno va a vaciar: $PROXY"

echo "== 5 · ¿qué algoritmos acepta MariaDB para esta operación? =="
for alg in INSTANT NOCOPY INPLACE; do
    if maria "ALTER TABLE notas MODIFY COLUMN nota int NULL DEFAULT NULL, ALGORITHM=$alg, LOCK=NONE" >/dev/null 2>&1; then
        echo "   $alg  -> SÍ"
        maria "ALTER TABLE notas MODIFY COLUMN nota int NOT NULL DEFAULT 0" >/dev/null
    else
        echo "   $alg  -> no"
    fi
done

echo "== 6 · ¿se pueden guardar notas mientras corre? (con control que sí bloquea) =="
bajo_alter() {   # $1 = cláusula, $2 = rótulo
    maria "ALTER TABLE notas MODIFY COLUMN nota int NOT NULL DEFAULT 0" >/dev/null
    local id; id=$(maria "SELECT id FROM notas WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")
    local t0; t0=$(ahora)
    maria "ALTER TABLE notas MODIFY COLUMN nota int NULL DEFAULT NULL, $1" >/dev/null &
    local pid=$! n=0 peor=0 suma=0 a b ms
    while kill -0 $pid 2>/dev/null; do
        a=$(ahora); maria "UPDATE notas SET nota = 7 WHERE id = $id" >/dev/null; b=$(ahora)
        ms=$(python3 -c "print(int(($b-$a)*1000))")
        n=$((n+1)); suma=$((suma+ms)); [ "$ms" -gt "$peor" ] && peor=$ms
    done
    wait $pid
    local t1; t1=$(ahora)
    python3 -c "
d = $t1 - $t0
print(f'   $2')
print(f'     el ALTER tardó         {d:.1f} s')
print(f'     escrituras completadas {$n}  ({$n/d:.1f}/s)')
print(f'     latencia media / PEOR  {$suma//max($n,1)} ms / {$peor} ms')"
}
echo "   linea base, sin nada corriendo (es casi todo overhead de docker exec):"
ID=$(maria "SELECT id FROM notas WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")
for i in 1 2 3; do
    A=$(ahora); maria "UPDATE notas SET nota=7 WHERE id=$ID" >/dev/null; B=$(ahora)
    python3 -c "print(f'     {int(($B-$A)*1000)} ms')"
done
bajo_alter "ALGORITHM=INPLACE, LOCK=NONE" "LO QUE ELIGE MARIADB (INPLACE, LOCK=NONE)"
bajo_alter "ALGORITHM=COPY, LOCK=SHARED"  "CONTROL QUE SÍ BLOQUEA (COPY, LOCK=SHARED)"

echo "== 7 · el ALTER tal como lo escribe la migración, y el UPDATE del relleno =="
maria "ALTER TABLE notas MODIFY COLUMN nota int NOT NULL DEFAULT 0" >/dev/null
T0=$(ahora); maria "ALTER TABLE notas MODIFY COLUMN nota int NULL DEFAULT NULL" >/dev/null; T1=$(ahora)
python3 -c "print(f'   ALTER sin clausula ALGORITHM: {$T1-$T0:.2f} s')"
T0=$(ahora)
VACIADAS=$(maria "UPDATE notas n
   INNER JOIN subunidades s ON s.id=n.subunidad_id AND s.deleted_at IS NULL
   INNER JOIN unidades    u ON u.id=s.unidad_id    AND u.deleted_at IS NULL
   INNER JOIN periodos    p ON p.id=u.periodo_id   AND p.deleted_at IS NULL
     SET n.nota = NULL
   WHERE n.deleted_at IS NULL AND p.profes_pueden_editar_notas = 1
     AND n.updated_by IS NULL AND n.created_at <=> n.updated_at;
 SELECT ROW_COUNT()")
T1=$(ahora)
python3 -c "print(f'   UPDATE del relleno:           {$T1-$T0:.2f} s  ·  $VACIADAS filas (esperadas: $PROXY)')"

if [ "${1:-}" = "--limpiar" ]; then
    docker rm -f "$CONTENEDOR" >/dev/null; rm -f "$VOLCADO"
    echo "== contenedor y volcado borrados =="
else
    echo "== la copia se deja en pie: docker rm -f $CONTENEDOR para borrarla =="
fi
