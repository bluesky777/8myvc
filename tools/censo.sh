#!/usr/bin/env bash
#
# Qué versión tiene puesta cada colegio, preguntándoselo a los servidores.
#
#     tools/censo.sh                 # la tabla
#     tools/censo.sh --json          # además, censo.json para el panel y para colegios.json
#     tools/censo.sh --migraciones   # añade cuántas migraciones tiene aplicadas cada uno (lento)
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ HACE FALTA ESTO
#
# **Mergeado no es desplegado.** Cada colegio es una copia propia del código, y una
# tanda puede dejar a unos con una versión y a otros con otra sin que nadie lo vea;
# el 20 sep, catorce colegios amanecieron con el mismo estropicio y se supo porque
# CADS-Itagüí lo reportó, no porque nadie mirara. La pregunta «¿tienen todos el mismo
# hash?» se contesta hoy con dos bucles por SSH escritos a mano cada vez.
#
# Y se contesta **mal** si se pregunta por host: `fortul` se sirve como `coaf`,
# `bethelexplora` son dos hosts del mismo colegio y `lal` vive en otra cuenta. Eso ya
# costó dos mediciones falsas (`docs/DESPLIEGUE-REFERENCIA.md`). Por eso este guion va
# **por carpeta**, que es como van los bucles de verdad, y trae el nombre de la base
# de cada una para que el inventario deje de ser una tabla en un `.md`.
#
# ─────────────────────────────────────────────────────────────────────────────
# NO ESCRIBE NADA
#
# Todo lo que hace en el servidor es `git rev-parse`, `ls`, leer `DB_DATABASE` del
# `.env` y `php -v`. No toca el árbol, no toca el índice, no toca la base. Se puede
# correr en horario de clase.
#
# **Una sola sesión SSH por cuenta**, no una por colegio: el bucle va dentro del
# servidor. Diecisiete conexiones tardarían diecisiete veces más y pedirían
# diecisiete veces la contraseña si algún día no hubiera clave.
#
# Variables (con sus valores por defecto):
#   CUENTAS='etiqueta usuario@host puerto patrón-de-carpetas' (una por línea)
#   SALIDA=censo.json          dónde se escribe con --json

set -u

CUENTAS="${CUENTAS:-$(cat <<'CUENTAS_FIN'
micolev1 micolev1@70.32.23.72 7822 $HOME/*.micolevirtual.com
micolevi micolevi@lalvirtual.edu.co 7822 $HOME/public_html
CUENTAS_FIN
)}"
SALIDA="${SALIDA:-censo.json}"
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=15"

JSON=0
MIGRACIONES=0
for arg in "$@"; do
    case "$arg" in
        --json) JSON=1 ;;
        --migraciones) MIGRACIONES=1 ;;
        *) echo "No conozco la opción ${arg}." >&2; exit 64 ;;
    esac
done

# ─────────────────────────────────────────────────────────────────────────────
# El bloque que corre ALLÍ. Sale por la salida estándar en columnas separadas por
# tabuladores, una línea por carpeta, para que aquí no haya que adivinar nada.
#
# `git -C` en vez de `cd`: si una carpeta no es un repositorio, contesta con un `-` y
# sigue, en vez de dejar el bucle en otro sitio. Y todo lleva `|| echo -`, porque un
# colegio al que le falte `up2` no puede cortar el censo de los otros dieciséis.
# OJO al editar aquí dentro: este heredoc NO está entrecomillado —tiene que interpolar
# ${patron}—, así que una comilla invertida en un comentario SE EJECUTA aquí, en tu
# máquina, al generar el bloque. Dentro del bloque no se escribe ni una.
remoto() {
    local patron="$1" con_migraciones="$2"
    cat <<REMOTO
php_cuenta=\$(php -v 2>/dev/null | head -1 | awk '{print \$2}')
for d in ${patron}; do
    [ -d "\$d/8myvc" ] || continue
    carpeta=\$(basename "\$d")
    hash8=\$(git -C "\$d/8myvc" rev-parse --short HEAD 2>/dev/null || echo -)
    rama=\$(git -C "\$d/8myvc" rev-parse --abbrev-ref HEAD 2>/dev/null || echo -)
    hup=\$(git -C "\$d/up" rev-parse --short HEAD 2>/dev/null || echo -)
    hup2=\$(git -C "\$d/up2" rev-parse --short HEAD 2>/dev/null || echo -)
    base=\$(grep -m1 '^DB_DATABASE=' "\$d/8myvc/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r"'"'" || echo -)
    fb=no; [ -f "\$d/8myvc/storage/app/firebase.json" ] && fb=si
    resp=no; [ -x "\$d/8myvc/tools/respaldo-diario-cpanel.sh" ] && resp=si
    mig=-
    if [ "${con_migraciones}" = "1" ]; then
        # Nada de "| grep -c ... || echo -": grep -c sale con 1 cuando cuenta cero, y ese
        # || añadía una segunda línea, con lo que la fila salía partida en dos.
        salida_mig=""
        # Y el -f de abajo no sobra: php artisan, en una carpeta sin artisan, imprime su
        # queja por la salida ESTÁNDAR, no por la de error. Sin comprobar que el fichero
        # existe, un colegio con el código a medias contaría 0 migraciones en vez de
        # decir que no se pudo preguntar.
        [ -f "\$d/8myvc/artisan" ] && salida_mig=\$(cd "\$d/8myvc" && php artisan migrate:status 2>/dev/null)
        if [ -n "\$salida_mig" ]; then
            mig=\$(printf '%s\\n' "\$salida_mig" | grep -c '\[[0-9]')
        fi
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \\
        "\$carpeta" "\${hash8:--}" "\${rama:--}" "\${hup:--}" "\${hup2:--}" \\
        "\${base:--}" "\$fb" "\$resp" "\${mig:--}" "\${php_cuenta:--}"
done
REMOTO
}

CRUDO=$(mktemp "${TMPDIR:-/tmp}/censo.XXXXXX")
trap 'rm -f "$CRUDO"' EXIT

while read -r etiqueta destino puerto patron; do
    [ -n "${etiqueta:-}" ] || continue

    err=$(mktemp "${TMPDIR:-/tmp}/censo-err.XXXXXX")
    salida=$(remoto "$patron" "$MIGRACIONES" | ssh -p "$puerto" $SSH_OPTS "$destino" 'bash -s' 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "CENSO: no se pudo entrar en ${etiqueta} (${destino}) — $(head -1 "$err")" >&2
        echo "  Si dice «Permission denied», falta la clave:" >&2
        echo "      ssh-copy-id -i ~/.ssh/cpanel -p ${puerto} ${destino}" >&2
        rm -f "$err"
        exit 2
    fi
    rm -f "$err"

    echo "$salida" | while IFS= read -r linea; do
        [ -n "$linea" ] && printf '%s\t%s\n' "$etiqueta" "$linea"
    done >> "$CRUDO"
done <<< "$CUENTAS"

if [ ! -s "$CRUDO" ]; then
    echo "CENSO: no contestó ninguna carpeta. ¿Son correctos los patrones de CUENTAS?" >&2
    exit 3
fi

# ── La tabla ─────────────────────────────────────────────────────────────────
# El `.micolevirtual.com` lo llevan todas y no distingue a ninguna: en la tabla estorba,
# en el JSON se queda entero porque ahí el nombre tiene que ser el de verdad.
printf '%-10s %-18s %-9s %-9s %-9s %-4s %-4s %s\n' CUENTA CARPETA 8MYVC UP UP2 FCM RESP MIG
awk -F'\t' '{ carpeta=$2; sub(/\.micolevirtual\.com$/, "", carpeta);
    printf "%-10s %-18s %-9s %-9s %-9s %-4s %-4s %s\n", $1,carpeta,$3,$5,$6,$8,$9,$10 }' "$CRUDO"

# Lo que de verdad se venía a preguntar, y que la tabla no contesta de un vistazo
# cuando son diecisiete filas: **¿están todos iguales?**
echo
for col in 3:8myvc 5:up 6:up2; do
    campo="${col%%:*}"; nombre="${col#*:}"
    distintos=$(cut -d$'\t' -f"$campo" "$CRUDO" | grep -v '^-$' | sort -u)
    cuantos=$(echo "$distintos" | grep -c . )
    if [ "$cuantos" -le 1 ]; then
        echo "${nombre}: todos en $(echo "$distintos" | tr -d '\n' | sed 's/^$/—/')"
    else
        echo "${nombre}: ${cuantos} versiones distintas — $(echo "$distintos" | tr '\n' ' ')"
    fi
done

# ── El JSON, que es lo que leen el panel y colegios.json ─────────────────────
if [ "$JSON" -eq 1 ]; then
    python3 - "$CRUDO" "$SALIDA" <<'PY'
import json, sys, datetime
crudo, salida = sys.argv[1], sys.argv[2]
campos = ['cuenta','carpeta','hash_8myvc','rama','hash_up','hash_up2','base',
          'firebase','guion_respaldo','migraciones','php']
filas = []
for linea in open(crudo):
    partes = linea.rstrip('\n').split('\t')
    if len(partes) < len(campos):
        partes += ['-'] * (len(campos) - len(partes))
    fila = dict(zip(campos, partes[:len(campos)]))
    fila['firebase'] = fila['firebase'] == 'si'
    fila['guion_respaldo'] = fila['guion_respaldo'] == 'si'
    filas.append(fila)
json.dump({
    'medido': datetime.datetime.now().astimezone().isoformat(timespec='seconds'),
    'colegios': filas,
}, open(salida, 'w'), indent=2, ensure_ascii=False)
print(f"\n── {len(filas)} carpeta(s) en {salida}")
PY
fi
