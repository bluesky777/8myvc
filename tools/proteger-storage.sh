#!/bin/bash
#
# Cierra storage/ a las peticiones HTTP en cada colegio.
#
# POR QUÉ. `8myvc/` cuelga entero del docroot y sólo `public/` debería ser
# alcanzable, así que `…/8myvc/storage/logs/laravel.log` se lee desde internet:
# medido el 30 ago 2026 con `curl` en seis colegios —lal, casb, coab, cads,
# coljordan y coal— y los seis devolvieron 200 con el contenido del log.
#
# POR QUÉ ESTO NO PUEDE ROMPER NADA. Laravel no sirve ficheros de `storage/` por
# HTTP: los entrega por PHP desde `public/index.php`. Denegar la carpeta cierra la
# lectura directa y no toca ninguna ruta de la aplicación. (El arreglo completo
# —denegar la raíz de `8myvc/` y abrir `public/` con `Require all granted`— es más
# limpio y MUCHO más arriesgado: `public/.htaccess` está versionado y si el
# `granted` no surte efecto la API entera da 403. Ése se prueba antes; éste no.)
#
# LO QUE NO ARREGLA, y se comprobó para no dejarlo escrito al revés: los `.php`
# NO se filtran. `bootstrap/cache/config.php` da 200 con cuerpo VACÍO y
# `config/database.php` da 500 — el servidor los ejecuta, no los descarga. Y
# `.env` da 403 por la regla de dotfiles. Aquí no hay credenciales expuestas.
#
# USO
#   tools/proteger-storage.sh                     # sólo dice qué haría
#   tools/proteger-storage.sh --aplicar
#   tools/proteger-storage.sh --aplicar /home/micolevi/public_html/8myvc
#
# Sin rutas extra usa el patrón de la cuenta `micolev1`, que alcanza a los catorce
# Y a `demo`. La otra cuenta de cPanel (`lalvirtual.edu.co`, usuario `micolevi`)
# NO la alcanza ningún glob: se pasa a mano como argumento, o se corre allí.
#
# Es idempotente: no toca un `.htaccess` que ya exista.

set -u
shopt -s nullglob

APLICAR=0
[ "${1:-}" = "--aplicar" ] && { APLICAR=1; shift; }

RUTAS=("$@")
[ ${#RUTAS[@]} -eq 0 ] && RUTAS=(/home/micolev1/*.micolevirtual.com/8myvc)

CONTENIDO='# Nadie lee storage/ por HTTP. Laravel entrega estos ficheros por PHP.
# El porqué y la medición: docs/migracion/ESTADO-ACTUAL.md, casilla 2nonies.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>'

total=0; creados=0; ya=0; revisar=0; sin_storage=0

for d in "${RUTAS[@]}"; do
    total=$((total + 1))
    nombre=$(basename "$(dirname "$d")")
    destino="$d/storage/.htaccess"

    if [ ! -d "$d/storage" ]; then
        printf '%-32s SIN storage/ — no es un 8myvc desplegado\n' "$nombre"
        sin_storage=$((sin_storage + 1)); continue
    fi

    if [ -f "$destino" ]; then
        if grep -qiE 'denied|Deny from all' "$destino"; then
            printf '%-32s ya estaba\n' "$nombre"; ya=$((ya + 1))
        else
            printf '%-32s TIENE .htaccess Y NO DENIEGA — MIRARLO A MANO\n' "$nombre"
            revisar=$((revisar + 1))
        fi
        continue
    fi

    if [ "$APLICAR" -eq 1 ]; then
        printf '%s\n' "$CONTENIDO" > "$destino" && chmod 644 "$destino"
        printf '%-32s CREADO\n' "$nombre"
    else
        printf '%-32s se crearía\n' "$nombre"
    fi
    creados=$((creados + 1))
done

echo
echo "Población: $total rutas. Creados/por crear: $creados · ya estaban: $ya · a revisar: $revisar · sin storage/: $sin_storage"
[ "$APLICAR" -eq 0 ] && echo "En seco. Repite con --aplicar para escribirlos."
echo
echo "Comprobar después, que es lo único que lo demuestra (debe dar 403):"
echo "  for h in casb coab cads coljordan coal demo; do printf '%-10s ' \"\$h\"; curl -sI \"https://\$h.micolevirtual.com/8myvc/storage/logs/laravel.log\" | head -1; done"
echo "  curl -sI https://lalvirtual.edu.co/8myvc/storage/logs/laravel.log | head -1"
