#!/usr/bin/env bash
#
# Baja al Mac los respaldos que `desplegar.sh` deja en los servidores antes de migrar
# (`~/respaldos/antes-de-migrar/` de cada cuenta), y lleva la cuenta de los que hay.
#
#     tools/bajar-respaldos-del-despliegue.sh                 # los de hoy + inventario
#     tools/bajar-respaldos-del-despliegue.sh 2026-09-24      # los de esa fecha
#     tools/bajar-respaldos-del-despliegue.sh --inventario    # sólo el inventario
#     tools/bajar-respaldos-del-despliegue.sh --borrar FECHA  # borra esa carpeta local
#
# No es `bajar-respaldos.sh`: aquél trae los volcados diarios del cron en un tar. Éste trae
# la foto de cada base justo antes de un `migrate`, que es la que se usa si una migración
# sale mal. Quedan en ~/DESARROLLOS/respaldos-myvc/antes-de-migrar/<fecha>/, un .sql.gz por
# colegio, y cada uno pasa `gzip -t`: el que no pasa se dice con su nombre y el guion sale 1.
#
# EL INVENTARIO NO BORRA NADA. Con más de MAX_FECHAS carpetas propone cuáles se podrían
# borrar y ahí se queda: borrar es `--borrar FECHA`, y eso lo decide Joseth. Hay dos que
# no se proponen nunca y que `--borrar` se niega a tocar: la más reciente y la más cercana
# a hace un mes. Ésa es la que sirve en una emergencia para preguntar «¿esto ya estaba así
# hace un mes?», y se guarda aunque sobren todas las demás.

set -euo pipefail

DESTINO="${DESTINO:-$HOME/DESARROLLOS/respaldos-myvc/antes-de-migrar}"
LLAVE="$HOME/.ssh/cpanel"
PUERTO=7822
MAX_FECHAS=4
CUENTAS=(
	"micolev1@70.32.23.72:/home/micolev1/respaldos/antes-de-migrar"
	"micolevi@lalvirtual.edu.co:/home/micolevi/respaldos/antes-de-migrar"
)

fechas_locales() {
	[ -d "$DESTINO" ] || return 0
	find "$DESTINO" -mindepth 1 -maxdepth 1 -type d -name '20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]' \
		-exec basename {} \; | sort
}

# La más reciente y la más cercana a hace 30 días (macOS: `date -j`).
protegidas() {
	[ $# -eq 0 ] && return 0
	local hace_un_mes mejor="" mejor_dist=999999 f d ultima
	hace_un_mes=$(date -j -v-30d +%s)
	for f in "$@"; do
		d=$(( ( $(date -j -f %F "$f" +%s) - hace_un_mes ) / 86400 ))
		d=${d#-}
		if [ "$d" -lt "$mejor_dist" ]; then mejor_dist=$d; mejor=$f; fi
		ultima=$f
	done
	printf '%s\n%s\n' "$ultima" "$mejor" | sort -u
}

inventario() {
	local fechas=() prot f marca
	while IFS= read -r f; do fechas+=("$f"); done < <(fechas_locales)
	echo "── En $DESTINO: ${#fechas[@]} fecha(s)"
	[ ${#fechas[@]} -eq 0 ] && return 0
	prot=$(protegidas "${fechas[@]}")
	for f in "${fechas[@]}"; do
		marca=""
		grep -qx "$f" <<<"$prot" && marca="  (se guarda siempre)"
		printf '   %s  %3s ficheros  %6s%s\n' "$f" \
			"$(find "$DESTINO/$f" -name '*.sql.gz' | wc -l | tr -d ' ')" \
			"$(du -sh "$DESTINO/$f" | cut -f1)" "$marca"
	done
	if [ ${#fechas[@]} -gt $MAX_FECHAS ]; then
		echo
		echo "── Hay más de $MAX_FECHAS. Se podrían borrar:"
		for f in "${fechas[@]}"; do
			grep -qx "$f" <<<"$prot" || printf '   %s  %s\n' "$f" "$(du -sh "$DESTINO/$f" | cut -f1)"
		done
		echo "   No se ha borrado nada. Para borrar una: $0 --borrar FECHA"
	fi
}

case "${1:-}" in
	--inventario) inventario; exit 0 ;;
	--borrar)
		f="${2:?falta la fecha}"
		[ -d "$DESTINO/$f" ] || { echo "No existe $DESTINO/$f"; exit 1; }
		fechas=(); while IFS= read -r x; do fechas+=("$x"); done < <(fechas_locales)
		if protegidas "${fechas[@]}" | grep -qx "$f"; then
			echo "No se borra: $f es la más reciente o la de hace un mes."; exit 1
		fi
		rm -r "${DESTINO:?}/$f"; echo "Borrada $DESTINO/$f"; echo; inventario; exit 0 ;;
esac

FECHA="${1:-$(date +%F)}"
mkdir -p "$DESTINO/$FECHA"
chmod 700 "$DESTINO" 2>/dev/null || true
for c in "${CUENTAS[@]}"; do
	scp -q -i "$LLAVE" -P "$PUERTO" "$c/*-$FECHA-*.sql.gz" "$DESTINO/$FECHA/" 2>&1 \
		| grep -vE 'locale|perl:|LANG|LC_|are supported' || true
done

malos=0 n=0
for g in "$DESTINO/$FECHA"/*.sql.gz; do
	[ -e "$g" ] || { echo "No hay respaldos de $FECHA en los servidores."; rmdir "$DESTINO/$FECHA" 2>/dev/null; exit 1; }
	n=$((n + 1))
	gzip -t "$g" 2>/dev/null || { echo "CORRUPTO: $g"; malos=$((malos + 1)); }
done
echo "── $FECHA: $n respaldos, $(du -sh "$DESTINO/$FECHA" | cut -f1), $malos corrupto(s)"
echo
inventario
[ $malos -eq 0 ]
