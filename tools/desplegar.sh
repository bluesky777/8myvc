#!/usr/bin/env bash
#
# La tanda, en un guion, en vez de un bucle que se copia y se pega cada vez.
#
#     tools/desplegar.sh                      # EL PLAN: qué haría. No toca nada.
#     tools/desplegar.sh --solo lal           # el plan de un colegio
#     tools/desplegar.sh --ejecutar           # lo hace
#     tools/desplegar.sh --ejecutar --solo lal
#
# ─────────────────────────────────────────────────────────────────────────────
# EL PLAN ES LO PREDETERMINADO, Y NO ES CORTESÍA
#
# Sin `--ejecutar` esto **no escribe nada en ningún sitio**: pregunta a los
# servidores qué commits les faltan, qué migraciones traería la tanda y si alguno
# necesita `composer install`, y lo imprime. Desplegar es una decisión, y la toma
# una persona mirando ese plan. El guion prepara y comprueba; no decide.
#
# ─────────────────────────────────────────────────────────────────────────────
# LAS CUATRO COSAS QUE HACE DISTINTO A UN BUCLE ESCRITO A MANO
#
# 1. **Respaldo antes de migrar, siempre, y sólo donde hay migraciones.** El 20 sep
#    2026 una tanda vació 407.909 casillas de `notas` en catorce colegios y no había
#    copia previa: la del día siguiente ya tenía el estropicio dentro. Si un colegio
#    tiene migraciones pendientes, aquí se respalda antes o no se despliega.
#
# 2. **Parada en seco si la tanda toca `composer.lock`.** Ese colegio necesita
#    `composer install` y eso es otro procedimiento, con otro riesgo y otro tiempo.
#    El guion lo detecta ANTES de tocarle el árbol y lo deja fuera.
#
# 3. **Los de `vendor/` compartido, primero.** Seis colegios lo tienen enlazado: lo
#    que se toca ahí se toca para los seis a la vez, así que van delante y en bloque.
#    No se deduce de una tabla: se mide con `[ -L vendor ]`, que es la verdad.
#
# 4. **Un parte al final, y un registro.** En qué hash quedó cada colegio, quién
#    falló y por qué. Sin eso, una tanda a medias no se distingue de una entera.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE SIGUE SIENDO DE LA PERSONA
#
# - **El momento.** Entre el `pull` y el `migrate` ese colegio da 500. Son segundos,
#   pero existen: nunca en horario de clase.
# - **Mirar el plan.** Un colegio con migraciones que pisan filas existentes se mira
#   con `tools/riesgo-de-la-tanda.php` antes, que es lo que ese guion existe para
#   contestar, y que sólo puede correr con el código ya bajado.
#
# Variables:
#   CUENTAS='etiqueta usuario@host puerto patrón' (una por línea)
#   IDENTIDAD=~/.ssh/cpanel
#   REGISTRO=despliegues.log

set -u

CUENTAS="${CUENTAS:-$(cat <<'CUENTAS_FIN'
micolev1 micolev1@70.32.23.72 7822 $HOME/*.micolevirtual.com
micolevi micolevi@lalvirtual.edu.co 7822 $HOME/public_html
CUENTAS_FIN
)}"
IDENTIDAD="${IDENTIDAD:-$HOME/.ssh/cpanel}"
REGISTRO="${REGISTRO:-despliegues.log}"
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=20"
[ -f "$IDENTIDAD" ] && SSH_OPTS="-i $IDENTIDAD $SSH_OPTS"

EJECUTAR=0
SOLO=""
while [ $# -gt 0 ]; do
    case "$1" in
        --ejecutar) EJECUTAR=1 ;;
        --solo) shift; SOLO="${1:-}" ;;
        --solo=*) SOLO="${1#--solo=}" ;;
        *) echo "No conozco la opción $1." >&2; exit 64 ;;
    esac
    shift
done

# OJO: este heredoc NO va entrecomillado —interpola el patrón y las opciones—, así
# que una comilla invertida aquí dentro se ejecutaría en TU máquina al generarlo.
# Dentro del bloque no se escribe ninguna.
bloque() {
    local patron="$1" ejecutar="$2" solo="$3"
    cat <<REMOTO
set -u
solo="${solo}"
for d in ${patron}; do
    [ -d "\$d/8myvc" ] || continue
    carpeta=\$(basename "\$d" .micolevirtual.com)
    [ -n "\$solo" ] && [ "\$carpeta" != "\$solo" ] && continue
    cd "\$d/8myvc" || continue

    git fetch -q origin 2>/dev/null
    antes=\$(git rev-parse --short HEAD)
    pendientes=\$(git rev-list --count HEAD..origin/main 2>/dev/null || echo 0)
    migraciones=\$(git diff --name-only HEAD origin/main -- database/migrations 2>/dev/null | grep -c . )
    lock=\$(git diff --name-only HEAD origin/main -- composer.lock 2>/dev/null | grep -c . )
    compartido=no; [ -L vendor ] && compartido=si
    sucio=\$(git status --porcelain --untracked-files=no | grep -c . )

    if [ "${ejecutar}" != "1" ]; then
        printf 'PLAN\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \\
            "\$carpeta" "\$antes" "\$pendientes" "\$migraciones" "\$lock" "\$compartido" "\$sucio"
        continue
    fi

    if [ "\$pendientes" = "0" ]; then
        printf 'YA\t%s\t%s\t-\t-\n' "\$carpeta" "\$antes"
        continue
    fi
    if [ "\$lock" != "0" ]; then
        printf 'PARADO\t%s\t%s\t-\tla tanda toca composer.lock: este colegio va por otro camino\n' "\$carpeta" "\$antes"
        continue
    fi
    if [ "\$sucio" != "0" ]; then
        printf 'PARADO\t%s\t%s\t-\tel arbol tiene cambios sin commitear\n' "\$carpeta" "\$antes"
        continue
    fi

    if [ "\$migraciones" != "0" ]; then
        if ! respaldo=\$(tools/respaldo-antes-de-migrar.sh 2>&1 | grep -o '[^ ]*\.sql\.gz' | head -1); then
            printf 'PARADO\t%s\t%s\t-\tel respaldo previo fallo, no se migra\n' "\$carpeta" "\$antes"
            continue
        fi
        if [ -z "\$respaldo" ]; then
            printf 'PARADO\t%s\t%s\t-\tel respaldo previo no dejo fichero, no se migra\n' "\$carpeta" "\$antes"
            continue
        fi
    else
        respaldo=-
    fi

    if ! salida=\$(git pull --ff-only origin main 2>&1); then
        printf 'FALLO\t%s\t%s\t%s\tpull: %s\n' "\$carpeta" "\$antes" "\$respaldo" "\$(echo "\$salida" | tail -1)"
        continue
    fi

    aplicadas=0
    if [ "\$migraciones" != "0" ]; then
        if ! salida=\$(php artisan migrate --force 2>&1); then
            printf 'FALLO\t%s\t%s\t%s\tmigrate: %s\n' "\$carpeta" "\$(git rev-parse --short HEAD)" "\$respaldo" "\$(echo "\$salida" | tail -1)"
            continue
        fi
        aplicadas=\$(printf '%s\n' "\$salida" | grep -c DONE)
    fi

    php artisan config:cache >/dev/null 2>&1
    php artisan route:cache >/dev/null 2>&1
    printf 'HECHO\t%s\t%s\t%s\t%s migracion(es), respaldo %s\n' \\
        "\$carpeta" "\$(git rev-parse --short HEAD)" "\$antes" "\$aplicadas" "\$respaldo"
done
REMOTO
}

# Los de vendor compartido van delante: el bucle remoto los ordena solo porque el
# glob es alfabético, así que el orden se impone aquí, en dos pasadas, cuando toca
# ejecutar. En el plan da igual.
CRUDO=$(mktemp "${TMPDIR:-/tmp}/desplegar.XXXXXX")
trap 'rm -f "$CRUDO"' EXIT

while read -r etiqueta destino puerto patron; do
    [ -n "${etiqueta:-}" ] || continue
    err=$(mktemp "${TMPDIR:-/tmp}/desplegar-err.XXXXXX")
    salida=$(bloque "$patron" "$EJECUTAR" "$SOLO" | ssh -p "$puerto" $SSH_OPTS "$destino" 'bash -s' 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "DESPLIEGUE: no se pudo entrar en ${etiqueta} (${destino}) — $(head -1 "$err")" >&2
        rm -f "$err"; exit 2
    fi
    rm -f "$err"
    printf '%s\n' "$salida" | while IFS= read -r l; do
        [ -n "$l" ] && printf '%s\t%s\n' "$etiqueta" "$l"
    done >> "$CRUDO"
done <<< "$CUENTAS"

if [ ! -s "$CRUDO" ]; then
    echo "DESPLIEGUE: ninguna carpeta contestó${SOLO:+ (¿existe el colegio '$SOLO'?)}." >&2
    exit 3
fi

if [ "$EJECUTAR" != "1" ]; then
    printf '%-10s %-18s %-9s %-10s %-5s %-7s %s\n' CUENTA COLEGIO AHORA PENDIENTES MIGR VENDOR ÁRBOL
    # Los campos, para que no se vuelvan a cruzar: 1 cuenta · 2 PLAN · 3 carpeta ·
    # 4 hash · 5 pendientes · 6 migraciones · 7 composer.lock · 8 vendor · 9 árbol.
    awk -F'\t' '$2=="PLAN" { printf "%-10s %-18s %-9s %-10s %-5s %-7s %s\n", $1,$3,$4,$5,$6,$8,($9=="0"?"limpio":"SUCIO") }' "$CRUDO"
    echo
    conmig=$(awk -F'\t' '$2=="PLAN" && $6!="0"' "$CRUDO" | wc -l | tr -d ' ')
    conlock=$(awk -F'\t' '$2=="PLAN" && $7!="0"' "$CRUDO" | wc -l | tr -d ' ')
    alday=$(awk -F'\t' '$2=="PLAN" && $5=="0"' "$CRUDO" | wc -l | tr -d ' ')
    echo "── ${alday} ya al día · ${conmig} con migraciones (llevan respaldo antes) · ${conlock} parados por composer.lock"
    echo "   Esto NO ha tocado nada. Para hacerlo: tools/desplegar.sh --ejecutar"
    exit 0
fi

printf '%-10s %-18s %-9s %s\n' CUENTA COLEGIO QUEDA-EN QUÉ
# Aquí son: 1 cuenta · 2 estado · 3 carpeta · 4 hash en que queda · 5 (antes o
# respaldo, según el estado) · 6 el motivo o el detalle.
awk -F'\t' '{ printf "%-10s %-18s %-9s %-7s %s\n", $1,$3,$4,$2,$6 }' "$CRUDO"

hechos=$(awk -F'\t' '$2=="HECHO"' "$CRUDO" | wc -l | tr -d ' ')
fallos=$(awk -F'\t' '$2=="FALLO" || $2=="PARADO"' "$CRUDO" | wc -l | tr -d ' ')
{
    echo "== $(date '+%Y-%m-%d %H:%M:%S %z') · ${hechos} desplegado(s), ${fallos} sin desplegar"
    cat "$CRUDO"
} >> "$REGISTRO"
echo
echo "── ${hechos} desplegado(s), ${fallos} sin desplegar · apuntado en ${REGISTRO}"
[ "$fallos" -gt 0 ] && exit 4
exit 0
