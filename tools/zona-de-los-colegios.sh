#!/usr/bin/env bash
#
# QUÉ HORA CREE QUE ES EL MySQL DE CADA COLEGIO.
#
# Contesta una pregunta que desde el repositorio no se puede contestar, y que el
# censo de los relojes (docs/migracion/53) dejó abierta: `config/database.php`
# **no fija la zona de la sesión**, así que `@@session.time_zone` vale `SYSTEM` y
# la hereda del servidor. Son dieciséis cuentas de cPanel distintas y **nadie ha
# mirado nunca qué zona tiene cada una**.
#
# USO — EN EL SERVIDOR
#   tools/zona-de-los-colegios.sh                # las raíces por defecto
#   tools/zona-de-los-colegios.sh RAIZ [RAIZ…]   # otras raíces (globs de …/8myvc)
#
# ES DE SÓLO LECTURA: un `SELECT` de variables y nada más. No escribe en ninguna
# base ni en ningún `.env`.
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ IMPORTA, Y POR QUÉ NO BASTA CON QUITAR LOS `NOW()`
#
# Dos cosas distintas cuelgan de esta zona, y sólo una se arregla desde el
# código:
#
#   1. `NOW()` en SQL crudo devuelve la hora del SERVIDOR. Eso se fue del código
#      el 21 sep 2026 —las 17 escrituras pasaron a `App\Support\Reloj`— y por esa
#      ya no entra ninguna hora nueva.
#
#   2. **Las 225 columnas `TIMESTAMP`.** Ésas no dependen del código: MySQL las
#      convierte al escribir y al leer usando la zona de la SESIÓN. Mientras la
#      del servidor no cambie, la ida y la vuelta se cancelan y no se nota nada.
#      **El día que cambie —una migración de hosting, un cPanel reinstalado— se
#      mueven todas las filas de esa base a la vez**, y no hay nada en el esquema
#      que lo frene ni nada en la fila que lo diga después.
#
# O sea que esto no se corre para arreglar algo hoy: se corre para **tener
# apuntado qué zona tenía cada colegio**, que es el dato sin el cual ese
# desplazamiento es indistinguible de un error de la aplicación.
#
# ─────────────────────────────────────────────────────────────────────────────
# CÓMO MIENTE ESTA MEDICIÓN
#
#   - **`SYSTEM` no es una respuesta.** Es «la del sistema operativo», así que
#      hay que resolverla, y por eso esto compara `NOW()` con `UTC_TIMESTAMP()`
#      en vez de creerse la variable. La cifra que vale es el DESFASE.
#   - **La zona del CLI puede no ser la de la web.** Esto usa el PHP del CLI. Si
#      el servidor tiene dos `php.ini`, lo medido aquí describe el CLI. La zona
#      de MySQL no depende de eso —viene de su propio `my.cnf`/systemd— pero la
#      de PHP sí, y las dos salen impresas para poder verlo.
#   - **Un glob que no casa se expande a sí mismo**, así que 0 instalaciones
#      sale como `NO MEDIDO` y con salida 2, nunca como «todas bien».
#
# Y la regla de la casa: **ninguna cifra se publica sin su población**. Aquí la
# población son las carpetas encontradas, y se imprime siempre.

set -u

RAICES_POR_DEFECTO=('/home/micolev1/*/8myvc' "$HOME/public_html/8myvc")

if [ "${1:-}" = '--help' ] || [ "${1:-}" = '-h' ]; then
    sed -n '2,56p' "$0" | sed 's/^#\{1,2\} \{0,1\}//'
    exit 0
fi

if [ "$#" -gt 0 ]; then
    patrones=("$@")
else
    patrones=("${RAICES_POR_DEFECTO[@]}")
fi

carpetas=()
for patron in "${patrones[@]}"; do
    # El glob se expande aquí a propósito: los patrones llegan como cadena para
    # poder pasarlos entrecomillados desde fuera.
    # shellcheck disable=SC2206
    candidatas=($patron)
    [ "${#candidatas[@]}" -eq 0 ] && continue
    for d in "${candidatas[@]}"; do
        [ -d "$d" ] && carpetas+=("$d")
    done
done

if [ "${#carpetas[@]}" -eq 0 ]; then
    printf 'NO MEDIDO: 0 instalaciones bajo «%s».\n' "$(IFS=' '; echo "${patrones[*]}")" >&2
    printf 'Esto NO es «todas están en la misma zona»: es que no se revisó nada. Comprueba\n' >&2
    printf 'que esto se corre EN EL SERVIDOR, o pásale una raíz como argumento.\n' >&2
    exit 2
fi

if ! command -v php >/dev/null 2>&1; then
    printf 'NO MEDIDO: no hay `php` en el PATH de esta sesión.\n' >&2
    exit 2
fi

printf 'POBLACIÓN: %d instalación(es) bajo «%s», cuenta %s.\n' \
    "${#carpetas[@]}" "$(IFS=' '; echo "${patrones[*]}")" "$(id -un 2>/dev/null || echo '?')"
printf 'En `micolev1` lo esperado son 17: los dieciséis colegios Y `demo`.\n'
printf 'ZONA DEL PHP DEL CLI: %s\n\n' "$(php -r 'echo date_default_timezone_get();' 2>/dev/null || echo '?')"

# Devuelve 1 si la línea no existe, que NO es lo mismo que existir vacía.
valor_de() {
    local var="$1" fichero="$2" linea valor
    linea=$(grep -E "^[[:space:]]*${var}=" "$fichero" 2>/dev/null | tail -1)
    [ -z "$linea" ] && return 1
    valor=${linea#*=}
    case "$valor" in
        '"'*) valor=${valor#'"'}; valor=${valor%%'"'*} ;;
        "'"*) valor=${valor#"'"}; valor=${valor%%"'"*} ;;
        *)    valor=${valor%%#*} ;;
    esac
    valor="${valor#"${valor%%[![:space:]]*}"}"
    valor="${valor%"${valor##*[![:space:]]}"}"
    printf '%s' "$valor"
}

printf '%-18s %-10s %-10s %8s %-7s %s\n' COLEGIO SESION SISTEMA DESFASE H.VERANO BASE
printf '%-18s %-10s %-10s %8s %-7s %s\n' ------- ------ ------- ------- -------- ----

zonas=''
noMedido=0
medidos=0
conDst=0

for d in "${carpetas[@]}"; do
    padre=$(dirname "$d")
    nombre=$(basename "$padre")
    [ "$nombre" = 'public_html' ] && nombre="public_html"

    if [ ! -f "$d/.env" ]; then
        printf '%-18s NO MEDIDO: no hay .env\n' "$nombre"
        noMedido=$((noMedido + 1))
        continue
    fi

    host=$(valor_de DB_HOST "$d/.env" || echo '127.0.0.1')
    puerto=$(valor_de DB_PORT "$d/.env" || echo '3306')
    base=$(valor_de DB_DATABASE "$d/.env" || echo '')
    usuario=$(valor_de DB_USERNAME "$d/.env" || echo '')
    clave=$(valor_de DB_PASSWORD "$d/.env" || echo '')

    if [ -z "$base" ] || [ -z "$usuario" ]; then
        printf '%-18s NO MEDIDO: el .env no trae DB_DATABASE o DB_USERNAME\n' "$nombre"
        noMedido=$((noMedido + 1))
        continue
    fi

    # El desfase, y no la variable: `SYSTEM` no dice qué hora es. Se calcula
    # contra `UTC_TIMESTAMP()` en la MISMA consulta, que es lo único que no
    # depende de cuándo se leyó cada cosa.
    salida=$(DBH="$host" DBP="$puerto" DBN="$base" DBU="$usuario" DBW="$clave" php -r '
        try {
            $p = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DBH"), getenv("DBP"), getenv("DBN")),
                getenv("DBU"), getenv("DBW"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            // El desfase de ahora, y ADEMÁS si la zona tiene horario de verano.
            // Lo segundo no se ve en la variable y es lo que de verdad muerde: se
            // mide llevando la MISMA hora de pared a enero y a julio. Sin horario
            // de verano la distancia entre las dos es el número exacto de segundos
            // civiles; con él, falta o sobra una hora.
            //
            // `MAKEDATE` y no un literal `'2026-07-15'`: el código PHP viaja dentro
            // de comillas simples de bash, así que una comilla simple en el SQL
            // cierra la cadena y el intérprete recibe medio programa. Pasó en la
            // primera versión, y el error de sintaxis de PHP salió IMPRESO EN LA
            // COLUMNA, donde parecía un fallo de la base y no del entrecomillado.
            // (Y este comentario tampoco puede llevar una comilla simple dentro,
            // por lo mismo: vive dentro de la cadena que bash le pasa a PHP.)
            // Los días son el 15 de enero (15) y el 15 de julio (196), 181 días.
            $r = $p->query("SELECT @@session.time_zone s, @@system_time_zone sys,
                            TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) d,
                            UNIX_TIMESTAMP(MAKEDATE(2026,196) + INTERVAL 12 HOUR)
                          - UNIX_TIMESTAMP(MAKEDATE(2026,15)  + INTERVAL 12 HOUR) verano")->fetch(PDO::FETCH_ASSOC);
            printf("%s|%s|%s|%s", $r["s"], $r["sys"], $r["d"], $r["verano"]);
        } catch (\Throwable $e) {
            printf("ERROR|%s||", substr(str_replace("\n", " ", $e->getMessage()), 0, 48));
        }
    ' 2>/dev/null)

    sesion=${salida%%|*}
    resto=${salida#*|}
    sistema=${resto%%|*}
    resto=${resto#*|}
    desfase=${resto%%|*}
    verano=${resto#*|}

    if [ "$sesion" = 'ERROR' ] || [ -z "$salida" ]; then
        printf '%-18s NO MEDIDO: %s\n' "$nombre" "${sistema:-sin conexión}"
        noMedido=$((noMedido + 1))
        continue
    fi

    # El desfase en horas, con signo. Bogotá es UTC−5, o sea −18000 s.
    #
    # En bash y NO con `php -r 'código' "$arg"`: sin un `--` delante, el PHP del
    # CLI lee el argumento como una opción suya y escupe su modo de empleo
    # entero. Así salió la primera corrida en el servidor, el 21 sep 2026: la
    # columna DESFASE traía veinte líneas de ayuda de PHP por colegio y tapaba la
    # tabla. El dato se salvó de casualidad porque `SISTEMA` iba antes.
    horas=$(awk -v s="$desfase" 'BEGIN { printf "%+.1f h", s / 3600 }' 2>/dev/null)

    # Y la marca de horario de verano, que es lo que esta herramienta vino a
    # buscar sin saberlo. 181 días entre el 15 de enero y el 15 de julio de 2026:
    # 15.638.400 segundos. Si salen 3.600 menos, la zona adelanta en verano.
    if [ "$verano" = '15638400' ]; then
        marca='—'
    elif [ -n "$verano" ] && [ "$verano" != '' ]; then
        marca='VERANO'
        conDst=$((conDst + 1))
    else
        marca='?'
    fi

    medidos=$((medidos + 1))
    zonas="$zonas$desfase
"
    printf '%-18s %-10s %-10s %8s %-7s %s\n' "$nombre" "$sesion" "$sistema" "$horas" "$marca" "$base"
done

printf '\n'
distintas=$(printf '%s' "$zonas" | sort -u | grep -c .)

printf 'MEDIDOS %d, NO MEDIDOS %d.\n' "$medidos" "$noMedido"

if [ "$medidos" -eq 0 ]; then
    printf 'Ninguna instalación contestó: esto NO dice nada sobre las zonas.\n'
    exit 2
fi

if [ "$conDst" -gt 0 ]; then
    printf '%d de %d instalaciones corren sobre una zona CON HORARIO DE VERANO.\n' "$conDst" "$medidos"
    printf '\n'
    printf 'Colombia no lo tiene, así que el servidor y el colegio NO van a la misma hora\n'
    printf 'la mitad del año, y van igual la otra mitad. Un desfase que aparece en marzo y\n'
    printf 'desaparece en noviembre es peor que uno fijo: nadie lo atribuye a la zona.\n'
    printf '\n'
    printf 'Qué toca mirar, y en este orden:\n'
    printf '  1. Que no quede NINGÚN `NOW()` desplegado: escribe la hora del servidor.\n'
    printf '     `grep -rn "NOW()" app/` en cada colegio, no sólo en el repositorio.\n'
    printf '  2. Las columnas TIMESTAMP aguantan el cambio —MySQL convierte por instante,\n'
    printf '     así que la ida y la vuelta se cancelan fila a fila—, PERO el día del\n'
    printf '     salto de marzo hay una hora de pared que en esa zona NO EXISTE. Una\n'
    printf '     escritura con esa hora la ajusta o la rechaza el motor, sin avisar.\n'
    printf '  3. Apúntalo en el 53 §3 con la fecha de hoy.\n'
    exit 1
fi

if [ "$distintas" -gt 1 ]; then
    printf 'HAY %d DESFASES DISTINTOS entre los colegios medidos.\n' "$distintas"
    printf 'O sea que la MISMA columna `TIMESTAMP` no significa lo mismo en dos colegios, y\n'
    printf 'cualquier comparación entre bases —o cualquier informe que las junte— está\n'
    printf 'restando horas que no se pueden restar. Apúntalo con la fecha en el 53 §3.\n'
    exit 1
fi

printf 'Todas las medidas van al mismo desfase. Apúntalo con la fecha en el 53 §3: el\n'
printf 'valor importa menos que tenerlo escrito ANTES de que cambie.\n'
