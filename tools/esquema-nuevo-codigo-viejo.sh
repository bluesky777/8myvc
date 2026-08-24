#!/usr/bin/env bash
#
# La suite con el ESQUEMA NUEVO y el CÓDIGO VIEJO.
#
#   tools/esquema-nuevo-codigo-viejo.sh <commit-base> [sufijo-de-sesion]
#   tools/esquema-nuevo-codigo-viejo.sh 0dc21d7 9e
#
# Contesta una pregunta que ninguna otra pasada contesta: **qué rompe un
# `ALTER TABLE` por sí solo**, sin los arreglos que lo acompañan delante.
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ EXISTE, y por qué son DOS pasadas y no una
#
# La noche del 24 ago 2026, la migración de `unidades.alumno_id` rompió consultas
# de boletines de dos maneras distintas, y **ninguna pasada encuentra las dos**:
#
#   esquema nuevo + código VIEJO  ->  `1052 Column '…' is ambiguous`
#       El SQL viejo nombraba `alumno_id` sin alias delante y funcionaba porque
#       sólo `notas` tenía esa columna. En cuanto la tiene `unidades`, MySQL
#       aborta. Lo rompe el ALTER, no el código: en un colegio donde la migración
#       corra antes de que llegue el `app/` nuevo, son 500 en los boletines.
#
#   esquema nuevo + código NUEVO  ->  un `SELECT *` mete la columna nueva en la
#       respuesta y mueve su instantánea de contrato. No depende de qué código
#       haya: depende de que la consulta diga `*`.
#
# Ésta es la primera. La segunda es correr la suite normalmente.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE ESTE GUION *NO* PUEDE CONTESTAR, y hay que leerlo antes del número
#
# **Su alcance es el de la suite, no el del código.** Medido el 24 ago sobre las
# cuatro consultas ambiguas que existían: **la suite ejerció dos.** De las otras
# dos, una era código muerto y la otra —la rama `fortaleza_debilidad` de
# `Unidad::deAsignaturaCalculada`— está detrás de un interruptor por colegio
# (`years.show_fortaleza_bol`), y **el seed tiene un año con ese interruptor
# encendido pero ningún test lo usa**.
#
#     Un colegio con ese interruptor puesto recibe un 500 que esta pasada no ve.
#
# Así que un `0` aquí significa «la suite no encontró nada», nunca «no hay nada».
# El complemento es un barrido estático del patrón —ver
# `tools/unidades-sin-alcance.py`, que cuenta los predicados `alumno_id` sin
# alias— y **los dos números se publican juntos**.
set -uo pipefail

BASE="${1:?falta el commit base: el código de antes de tus cambios}"
SUF="${2:-}"
ARBOL="$(cd "$(dirname "$0")/.." && pwd)"
DB="simonbolivar_testing${SUF:+_$SUF}"
DENTRO="/app${ARBOL##*/8myvc}"          # la ruta del árbol dentro del contenedor
[ -n "$SUF" ] && DENTRO="/app/.worktrees/$SUF"
SALIDA="${TMPDIR:-/tmp}/solo-esquema-$(date +%Y%m%d-%H%M%S).txt"

# ── 1. Nada de huérfanos: es lo que invalidó la primera pasada de esto ───────
# Tres trampas de la misma noche: un `ps` del HOST no ve los procesos del
# contenedor; matar el `docker exec` del host NO mata el `php` de dentro; y dos
# suites contra la misma base dan deadlocks — y las dos pueden ser tuyas. La
# primera pasada salió con 141 rojos y tests de 0,5 s tardando 79 s.
vivos=$(docker exec 8myvc-app-1 ps -ax -o pid,args 2>/dev/null \
        | grep "phpunit" | grep -v grep | awk '{print $1}')
if [ -n "$vivos" ]; then
    echo "ABORTA: hay phpunit vivo DENTRO del contenedor: $(echo $vivos | tr '\n' ' ')"
    echo "        míralo con: docker exec 8myvc-app-1 ps -ax | grep phpunit"
    exit 1
fi

# ── 2. `app/` se restaura pase lo que pase ──────────────────────────────────
# Sin el `trap`, un corte deja el árbol con el código de otro commit y el commit
# siguiente se lo lleva dentro.
restaurar() { git -C "$ARBOL" checkout HEAD -- app/ && echo "app/ restaurado a HEAD"; }
trap restaurar EXIT INT TERM

git -C "$ARBOL" checkout "$BASE" -- app/
echo "app/ en $BASE (código viejo). Esquema: el de $DB, ya migrado."

# ── 3. La población, antes del resultado ────────────────────────────────────
docker exec -w "$DENTRO" 8myvc-app-1 php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b=$argv[1];
printf("base %s: %d tablas, %d usuarios\n", $b,
  DB::selectOne("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=?",[$b])->n,
  DB::selectOne("SELECT COUNT(*) n FROM `$b`.users")->n);' "$DB" 2>&1 | grep -v "^PHP\|Warning"

docker exec -w "$DENTRO" -e DB_TEST_DATABASE="$DB" \
    8myvc-app-1 php artisan test > "$SALIDA" 2>&1
echo "exit=$?   salida=$SALIDA"

# Sin línea de resumen, la corrida se cortó y el número NO se publica.
if ! grep -qE "^  Tests: " "$SALIDA"; then
    echo "VOID: la corrida no llegó al resumen. No se publica ningún número."
    exit 1
fi
grep -E "^  Tests: |^  Duration" "$SALIDA"

# ── 4. El reparto por CAUSA, que es lo único que aísla la pregunta ──────────
# Contar rojos NO sirve: con el código viejo se ven las DOS formas a la vez,
# porque los `SELECT *` tampoco están congelados en ese commit.
echo
echo "── reparto por causa ──"
printf "  1052 ambiguous (lo rompe el ALTER contra el código viejo) : %s\n" \
    "$(grep -c '1052' "$SALIDA")"
printf "  instantánea movida (lo rompe el `SELECT *`)               : %s\n" \
    "$(grep -oE "La respuesta de '[a-z0-9-]+'" "$SALIDA" | sort -u | grep -c .)"
echo
grep -oE "Column '[a-z_]+' in [a-z ]+ is ambiguous" "$SALIDA" | sort | uniq -c
echo "  instantáneas movidas:"
grep -oE "La respuesta de '[a-z0-9-]+'" "$SALIDA" | sort -u | sed 's/^/    /'
echo
# `0-9` y `_` en la clase de caracteres: sin los dígitos se caen
# `Boletines2Controller` y `Boletines3Controller` —el 22% de los sitios— y la
# lista sale corta SIN DECIRLO. Misma falta que un `\b` que falta en un nombre de
# tabla: una clase de caracteres que omite justo lo que hace falta.
sitios=$(grep -oE "app/[A-Za-z0-9_/]+\.php\([0-9]+\)" "$SALIDA" | sort -u)
printf "  sitios nombrados en las trazas: %s\n" "$(echo "$sitios" | grep -c .)"
echo "$sitios" | sed 's/^/    /'

# ── 5. El centinela del instrumento ────────────────────────────────────────
echo
echo "── centinela del instrumento ──"
# Sólo líneas de test: la línea `Duration:` es el total de la tanda, y contarla
# disparaba el centinela SIEMPRE. Un centinela que se dispara solo enseña a
# ignorarlo, que es peor que no tenerlo.
lentos=$(grep '⨯\|✓' "$SALIDA" | grep -oE '[0-9]+\.[0-9]+s' | tr -d 's' \
         | awk '$1>20' | wc -l | tr -d ' ')
echo "  tests de más de 20 s: $lentos   (>0 con la máquina limpia = sospechoso)"
echo "  primera clase de la tanda: $(grep -m1 -E 'PASS|FAIL' "$SALIDA" | sed 's/^ *//')"
rojas=$(grep 'FAIL ' "$SALIDA" | sed 's/.*Contrato.//;s/.*Feature.//;s/.*Unit.//')
echo "  clases en rojo ($(echo "$rojas" | grep -c .)):"
echo "$rojas" | sed 's/^/    /'
echo "  Si las rojas arrancan en la PRIMERA clase de la tanda y siguen el"
echo "  alfabeto sin hueco, es el instrumento y no el esquema: dilo void."
