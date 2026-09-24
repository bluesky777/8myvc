#!/usr/bin/env bash
#
# Baja a ESTE Mac los respaldos de las DOS cuentas de cPanel y los deja en un solo
# fichero con la fecha en el nombre.
#
#     tools/bajar-respaldos.sh                  # la última fecha que tengan las dos
#     tools/bajar-respaldos.sh 2026-09-20       # una fecha concreta
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ SE JUNTAN AQUÍ Y NO EN EL SERVIDOR
#
# Los colegios viven en dos cuentas de cPanel que están en dos máquinas distintas
# (`mi3-ss55` y `mi3-ss54`), y los usuarios de MySQL son locales a cada una:
# `micolevi_great` no existe en la otra. Para que un servidor volcara las bases del
# otro habría que guardarle sus credenciales, y entonces quien entre en una cuenta
# entra en las dieciséis. **El único sitio donde las dos cuentas se juntan sin
# ampliar el daño posible es esta máquina.**
#
# Y se **tira**, no se empuja: el servidor no tiene ninguna credencial para llegar
# aquí. Si un día queda comprometido, los respaldos ya bajados no están a su alcance.
#
# Cuando LAL se traslade a la cuenta única (`docs/TRASLADO-LAL.md`) esto se queda en
# una sola cuenta y el guion no cambia: sobra una línea de CUENTAS.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE ESTE GUION NO ES
#
# **No hace volcados.** Los hace `tools/respaldo-diario-cpanel.sh` de madrugada, en
# cada cuenta, con su cron. Éste sólo los trae. Si el cron no está puesto, aquí no
# hay nada que bajar y el guion lo dice con esas palabras en vez de dejar un tar
# vacío que parece un respaldo.
#
# **No borra nada en el servidor.** La rotación de allí es del guion de allí, que
# sólo rota cuando la tanda quedó limpia. Aquí no hay `--delete` en ningún sitio.
#
# ─────────────────────────────────────────────────────────────────────────────
# LA FECHA SE NEGOCIA, NO SE SUPONE
#
# «Hoy» es la respuesta equivocada: si esto corre a la una de la mañana, el volcado
# de hoy todavía no existe y el guion fallaría sin que nada esté mal. Y coger «la más
# reciente de cada cuenta» es peor, porque junta en el mismo tar el lunes de una y el
# jueves de la otra sin avisar.
#
# Así que se pregunta a las dos qué fechas tienen y se baja **la más reciente que
# tengan LAS DOS**. Si no hay ninguna en común, no se baja nada y se imprime qué
# tiene cada una: eso es un cron parado, y es lo que hay que arreglar.
#
# Variables (con sus valores por defecto):
#   DESTINO=$HOME/DESARROLLOS/respaldos-myvc
#   REMOTO=respaldos/diario          la carpeta del guion de allí, en $HOME de cada cuenta
#   DIAS_LOCAL=30                    cuántos días se guardan AQUÍ (0 = no rotar)
#   CUENTAS='etiqueta usuario@host puerto' (una por línea)

set -u

DESTINO="${DESTINO:-$HOME/DESARROLLOS/respaldos-myvc}"
REMOTO="${REMOTO:-respaldos/diario}"
DIAS_LOCAL="${DIAS_LOCAL:-30}"
CUENTAS="${CUENTAS:-$(cat <<'CUENTAS_FIN'
micolev1 micolev1@70.32.23.72 7822
micolevi micolevi@lalvirtual.edu.co 7822
CUENTAS_FIN
)}"

FECHA_PEDIDA="${1:-}"

# `BatchMode=yes` para que una cuenta que pida contraseña falle en diez segundos en
# vez de quedarse esperando a que alguien teclee algo. Si sale «Permission denied»,
# lo que falta es la clave: `ssh-copy-id -i ~/.ssh/cpanel -p 7822 usuario@host`.
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=15"

mkdir -p "$DESTINO" || exit 1
chmod 700 "$DESTINO" 2>/dev/null || true

# ── 1. Qué fechas tiene cada cuenta ──────────────────────────────────────────
COMUNES=""
PRIMERA=1
while read -r etiqueta destino puerto; do
    [ -n "${etiqueta:-}" ] || continue

    # «No pude entrar» y «no hay respaldos» son DOS respuestas distintas que se
    # arreglan en dos sitios distintos, así que no comparten mensaje. El `exit 0` del
    # comando remoto es para eso: sin él, una carpeta que no existe hace que `ls`
    # devuelva 2, y el guion diría que la clave no entra cuando la clave entró.
    err=$(mktemp "${TMPDIR:-/tmp}/bajar.XXXXXX")
    fechas=$(ssh -p "$puerto" $SSH_OPTS "$destino" \
        "ls -1 ~/${REMOTO} 2>/dev/null; exit 0" 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "BAJAR RESPALDOS: no se pudo entrar en ${etiqueta} (${destino}) — $(head -1 "$err")" >&2
        echo "  Si dice «Permission denied», falta la clave:" >&2
        echo "      ssh-copy-id -i ~/.ssh/cpanel -p ${puerto} ${destino}" >&2
        rm -f "$err"
        exit 2
    fi
    rm -f "$err"

    fechas=$(echo "$fechas" | grep -E '^20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$' | sort)
    if [ -z "$fechas" ]; then
        echo "BAJAR RESPALDOS: se entró en ${etiqueta}, pero ~/${REMOTO} no tiene ninguna fecha." >&2
        echo "  Eso es el cron de allí sin poner: «crontab -l | grep respaldo» en esa cuenta lo dice." >&2
        exit 2
    fi

    echo "${etiqueta}: $(echo "$fechas" | wc -l | tr -d ' ') fecha(s), la última $(echo "$fechas" | tail -1)"

    if [ "$PRIMERA" -eq 1 ]; then
        COMUNES="$fechas"
        PRIMERA=0
    else
        COMUNES=$(comm -12 <(echo "$COMUNES") <(echo "$fechas"))
    fi
done <<< "$CUENTAS"

if [ -n "$FECHA_PEDIDA" ]; then
    if ! echo "$COMUNES" | grep -qx "$FECHA_PEDIDA"; then
        echo "BAJAR RESPALDOS: ${FECHA_PEDIDA} no está en las dos cuentas." >&2
        echo "  En las dos hay: $(echo "$COMUNES" | tr '\n' ' ')" >&2
        exit 3
    fi
    FECHA="$FECHA_PEDIDA"
else
    FECHA=$(echo "$COMUNES" | tail -1)
    if [ -z "$FECHA" ]; then
        echo "BAJAR RESPALDOS: las cuentas NO tienen ninguna fecha en común." >&2
        echo "  Un tar con el lunes de una y el jueves de la otra no es un respaldo. Mira los crons." >&2
        exit 4
    fi
fi

echo "── fecha elegida: ${FECHA} (la más reciente que tienen las dos)"

# ── 2. Bajarlos ──────────────────────────────────────────────────────────────
CARPETA="${DESTINO}/${FECHA}"
MAL=0
while read -r etiqueta destino puerto; do
    [ -n "${etiqueta:-}" ] || continue

    mkdir -p "${CARPETA}/${etiqueta}"
    if ! rsync -az --partial \
        -e "ssh -p ${puerto} ${SSH_OPTS}" \
        "${destino}:~/${REMOTO}/${FECHA}/" "${CARPETA}/${etiqueta}/"; then
        echo "BAJAR RESPALDOS: falló el rsync de ${etiqueta}." >&2
        MAL=$((MAL + 1))
    fi
done <<< "$CUENTAS"

# ── 3. Comprobar lo bajado, que es la mitad del trabajo ──────────────────────
#
# Que el volcado esté COMPLETO ya lo comprobó el guion de allí, que mira el
# `Dump completed` antes de dar una base por respaldada. Lo que puede romperse en
# este trayecto es otra cosa: un fichero cortado a medio camino. Eso lo caza
# `gzip -t`, y es barato.
BIEN=0
for f in "${CARPETA}"/*/*.sql.gz; do
    [ -e "$f" ] || continue
    if gzip -t "$f" 2>/dev/null; then
        BIEN=$((BIEN + 1))
    else
        echo "BAJAR RESPALDOS: ${f} no se descomprime — llegó cortado." >&2
        MAL=$((MAL + 1))
    fi
done

if [ "$BIEN" -eq 0 ]; then
    echo "BAJAR RESPALDOS: no llegó NINGUNA base. ¿Es correcta REMOTO='${REMOTO}'?" >&2
    exit 5
fi

# ── 4. Un solo fichero ───────────────────────────────────────────────────────
#
# `tar` sin `-z`: dentro ya va todo comprimido por `gzip`, así que volver a apretar
# tarda un minuto y ahorra kilobytes. Y si algo salió mal, el nombre lo lleva puesto:
# un tar que se llama INCOMPLETO no se confunde con un respaldo seis meses después.
if [ "$MAL" -eq 0 ]; then
    TAR="${DESTINO}/myvc-bases-${FECHA}.tar"
else
    TAR="${DESTINO}/myvc-bases-${FECHA}-INCOMPLETO.tar"
fi
tar -cf "$TAR" -C "$CARPETA" . || exit 6

# ── 5. Rotación local, y sólo si el día quedó limpio ─────────────────────────
if [ "$MAL" -eq 0 ] && [ "$DIAS_LOCAL" -gt 0 ]; then
    find "$DESTINO" -mindepth 1 -maxdepth 1 -type d -name '20*-*-*' -mtime +"$DIAS_LOCAL" \
        -exec rm -rf {} + 2>/dev/null
    find "$DESTINO" -mindepth 1 -maxdepth 1 -type f -name 'myvc-bases-*.tar' -mtime +"$DIAS_LOCAL" \
        -delete 2>/dev/null
fi

echo "── ${BIEN} base(s) en $(du -h "$TAR" | cut -f1) · ${TAR}"

if [ "$MAL" -gt 0 ]; then
    echo "BAJAR RESPALDOS: ${MAL} problema(s). El tar quedó marcado INCOMPLETO." >&2
    exit 7
fi
exit 0
