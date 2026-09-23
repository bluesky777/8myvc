#!/usr/bin/env bash
#
# QUÉ COMPARTE CADA COLEGIO, Y A QUÉ `app/` APUNTA SU AUTOCARGADOR.
#
# Contesta dos preguntas que desde el repositorio no se pueden contestar, y que
# `docs/DESPLIEGUE-REFERENCIA.md` llevaba abiertas desde el 19 ago 2026:
#
#   1. ¿Qué carpetas de cada instalación son un symlink a algo compartido?
#      `vendor/` se sabía; `storage/`, `public/` y `bootstrap/cache/` NUNCA se
#      han mirado, y la lista de quién comparte `vendor/` ya salió mal dos veces.
#
#   2. ¿A qué `app/` apunta el autocargador de cada colegio? **Que no es la
#      misma pregunta**, y ésa es toda la razón de que este guion exista.
#
# USO — EN EL SERVIDOR
#   tools/lo-que-comparte-un-colegio.sh                # las raíces por defecto
#   tools/lo-que-comparte-un-colegio.sh RAIZ [RAIZ…]   # otras raíces (globs de …/8myvc)
#
# ES DE SÓLO LECTURA: `ls`, `readlink` y un `grep` sobre un fichero generado. No
# escribe nada, no borra ningún symlink y NO corre `composer`.
#
# SALIDA: 0 = nadie comparte y cada autocargador apunta a su casa · 1 = hay algo
# compartido o mal apuntado (las filas lo dicen) · 2 = NO MEDIDO (no encontró
# ninguna instalación: casi siempre es que no se está en el servidor).
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ DOS PREGUNTAS Y NO UNA: EL FALLO DEL 23 SEP 2026
#
# Medido ese día por la sesión `myvc-flutter-75`: en `coal`, `colbosque`,
# `comad-san-andres`, `demo`, `eal` y `lal`, `php artisan list` **no devolvía un
# solo comando propio** del proyecto — ni `notificaciones:enviar`, ni
# `sesion:limpiar`, ni `importaciones:marcar-abandonadas`, ni `colegio:parte`, ni
# `correo:probar`. Nunca los tuvieron.
#
# La causa no era el classmap ni la caché de `bootstrap/` —las dos hipótesis
# razonables, las dos falsas—. Era esta línea del `vendor/` compartido:
#
#     $baseDir = dirname($vendorDir).'/maranathaarauca.micolevirtual.com/8myvc';
#
# Los seis cargaban sus clases `App\` del `app/` de **maranathaarauca**. Y
# funcionaba, porque el código es idéntico en los diecisiete: mismo commit. Por
# eso nadie lo notó en meses.
#
# Lo que NO funcionaba es el descubrimiento de comandos: **Laravel no registra
# los comandos por nombre, escanea un directorio** y deriva la clase restándole
# `app_path()`. El escaneo caía en el árbol de maranathaarauca, la resta se hacía
# contra el `app_path()` propio, no casaba, y el comando se descartaba **sin un
# solo error**.
#
# De ahí la moraleja que este guion codifica: **`class_exists()` decía `true`**.
# Cargar por nombre funcionaba y descubrir por ruta no, así que la comprobación
# obvia —«¿existe la clase?»— daba verde sobre un sistema roto.
#
# ─────────────────────────────────────────────────────────────────────────────
# Y LO QUE ESTO MIDE Y UNA MIRADA A `ls -l` NO
#
# **Tener `vendor/` propio NO es tener autocargador propio.** El arreglo de ese
# día fue `rm vendor` + `cp -a` del compartido + `composer dump-autoload -o`
# desde cada colegio. Si alguien repite sólo las dos primeras —copiar la carpeta
# y no regenerar—, `ls -l` deja de ver symlinks, la trampa del despliegue parece
# resuelta, y `$baseDir` sigue apuntando al `app/` de otro colegio: **el mismo
# fallo mudo, ahora invisible al censo que lo encontró**.
#
# Por eso las dos columnas van juntas en la misma fila y ninguna de las dos se
# publica sola.
#
# ─────────────────────────────────────────────────────────────────────────────
# CÓMO MIENTE ESTA MEDICIÓN
#
#   · **No prueba que los comandos se descubran, prueba a dónde apunta la ruta.**
#     La comprobación de verdad es `php artisan list | grep -c 'notificaciones:'`
#     en cada colegio, que cuesta un arranque de Laravel por instalación. Esto
#     dice dónde mirar; esa orden dice si funciona. Se imprime al final.
#
#   · **La instalación viva de `lal` está en la otra cuenta de cPanel** y el
#     bucle de `/home/micolev1/*` no la alcanza. Por eso la segunda raíz por
#     defecto es `$HOME/public_html/8myvc`: corriendo esto como `micolev1` mide
#     diecisiete (los dieciséis y `demo`) y NO mide `lal`. Lo dice al final en vez
#     de dar un total que se leería como completo.
#
#   · **Un `0 compartidos` no distingue «miré diecisiete» de «no miré nada»**, así
#     que la población va impresa siempre y sin instalaciones sale `2`, no `0`.
#
#   · **El total no es el censo.** El 23 sep la lista de los seis estaba mal por
#     los dos lados —sobraba `maranathaarauca`, faltaba `demo`— y seguía sumando
#     seis: dos errores que se cancelaban. Este guion imprime **los nombres**, y
#     la comparación se hace por miembros.
#
set -u

CARPETAS=(vendor storage public bootstrap/cache node_modules)

RAICES_POR_DEFECTO=('/home/micolev1/*/8myvc' "$HOME/public_html/8myvc")

if [ "$#" -gt 0 ]; then
    patrones=("$@")
else
    patrones=("${RAICES_POR_DEFECTO[@]}")
fi

instalaciones=()

for patron in "${patrones[@]}"; do
    # Sin comillas a propósito: el patrón ES un glob y aquí se expande.
    # shellcheck disable=SC2206
    candidatas=($patron)

    for d in "${candidatas[@]}"; do
        [ -d "$d" ] && instalaciones+=("$d")
    done
done

if [ "${#instalaciones[@]}" -eq 0 ]; then
    printf 'NO MEDIDO: ninguna instalación en %s\n' "${patrones[*]}"
    printf 'Esto se corre EN EL SERVIDOR. Desde el repositorio no hay nada que medir.\n'
    exit 2
fi

printf 'Instalaciones encontradas: %d\n' "${#instalaciones[@]}"
printf 'Raíces: %s\n\n' "${patrones[*]}"

printf '%-26s  %-34s  %s\n' COLEGIO 'COMPARTIDO POR SYMLINK' 'A QUÉ app/ APUNTA EL AUTOCARGADOR'
printf '%-26s  %-34s  %s\n' '--------------------------' \
    '----------------------------------' '---------------------------------'

con_symlink=()
mal_apuntados=()
sin_autoload=()

for d in "${instalaciones[@]}"; do
    # El nombre del colegio es la carpeta que contiene a `8myvc`.
    colegio=$(basename "$(dirname "$d")")

    compartidas=''

    for c in "${CARPETAS[@]}"; do
        if [ -L "$d/$c" ]; then
            compartidas="$compartidas $c->$(readlink "$d/$c")"
        fi
    done

    if [ -n "$compartidas" ]; then
        con_symlink+=("$colegio")
        # En la tabla van sólo los NOMBRES de las carpetas: un destino absoluto
        # recortado a 34 columnas enseña el prefijo común y esconde justo la
        # parte que distingue un compartido de otro. Los destinos van debajo.
        resumen=$(printf '%s' "${compartidas# }" | tr ' ' '\n' | sed 's/->.*//' | tr '\n' ' ')
    else
        resumen='(nada)'
    fi

    psr4="$d/vendor/composer/autoload_psr4.php"

    if [ ! -f "$psr4" ]; then
        destino='SIN autoload_psr4.php'
        sin_autoload+=("$colegio")
    else
        # La línea es `$baseDir = dirname($vendorDir).'/<ruta>';` cuando apunta
        # fuera, y `dirname($vendorDir)` a secas cuando apunta a su propia casa.
        cola=$(grep -m1 '^\$baseDir' "$psr4" | sed "s/.*dirname(\$vendorDir)//; s/[';]//g")

        if [ -z "$cola" ]; then
            destino='su propia casa'
        else
            # El `.` de delante es el operador de concatenación de PHP, no la ruta.
            duenio=$(printf '%s' "$cola" | sed 's#^\.##; s#^/##; s#/8myvc.*##')

            if [ "$duenio" = "$colegio" ]; then
                destino='su propia casa'
            else
                destino="EL app/ DE $duenio"
                mal_apuntados+=("$colegio -> $duenio")
            fi
        fi
    fi

    printf '%-26s  %-34s  %s\n' "$colegio" "$resumen" "$destino"
done

printf '\n'

# El detalle largo de los symlinks, aparte, para no truncar dentro de la tabla.
if [ "${#con_symlink[@]}" -gt 0 ]; then
    printf 'SYMLINKS, sin recortar:\n'

    for d in "${instalaciones[@]}"; do
        colegio=$(basename "$(dirname "$d")")

        for c in "${CARPETAS[@]}"; do
            [ -L "$d/$c" ] && printf '  %-26s %-16s -> %s\n' "$colegio" "$c" "$(readlink "$d/$c")"
        done
    done

    printf '\n'
fi

printf 'COMPARTEN algo por symlink: %d  %s\n' "${#con_symlink[@]}" "${con_symlink[*]-}"
printf 'AUTOCARGADOR de otro colegio: %d  %s\n' "${#mal_apuntados[@]}" "${mal_apuntados[*]-}"
printf 'SIN autoload_psr4.php: %d  %s\n' "${#sin_autoload[@]}" "${sin_autoload[*]-}"

printf '\nLo que esto NO ha medido, y hay que correr aparte en cada colegio:\n'
printf '  php artisan list | grep -cE "notificaciones:|sesion:|importaciones:|colegio:|correo:"\n'
printf 'Un 0 ahí con la tabla en verde es un fallo distinto; un 0 con el autocargador\n'
printf 'apuntando a otro colegio es ESTE, y entonces no falta un comando: faltan todos.\n'

if [ "${#instalaciones[@]}" -lt 17 ]; then
    printf '\nAVISO: %d instalaciones, menos de 17. En `micolev1` lo esperado son 17 (los\n' \
        "${#instalaciones[@]}"
    printf 'dieciséis y `demo`), y `lal` vive en la OTRA cuenta de cPanel: si no se corrió\n'
    printf 'allí también, `lal` no está en esta tabla y su ausencia no es un verde.\n'
fi

if [ "${#con_symlink[@]}" -gt 0 ] || [ "${#mal_apuntados[@]}" -gt 0 ] || [ "${#sin_autoload[@]}" -gt 0 ]; then
    exit 1
fi

exit 0
