#!/usr/bin/env bash
#
# QUÉ INSTALACIONES NO PUEDEN MANDAR CORREO, leído de su `.env`.
#
# Contesta una pregunta que desde el repositorio no se puede contestar: en qué
# colegios está aplicado el bloque de correo del PR #3 y en cuáles no. La única
# función que manda correo en toda la API es el reseteo de contraseña
# (LoginController.php, Mail::to(...)->send(new ResetPassword(...))), así que
# «el correo de este colegio está mal» significa «el reseteo devuelve 500 y
# nadie se entera».
#
# USO — EN EL SERVIDOR
#   tools/correo-de-los-colegios.sh                # las raíces por defecto
#   tools/correo-de-los-colegios.sh RAIZ [RAIZ…]   # otras raíces (globs de …/8myvc)
#   tools/correo-de-los-colegios.sh --arreglo      # imprime el bloque que hay que pegar
#
# ES DE SÓLO LECTURA. No escribe en ningún `.env`: eso lo decide y lo ejecuta
# Joseth a mano, que es la regla de docs/migracion/29 y no una precaución de
# este guion.
#
# NO es un paso de despliegue. El `.env` no viaja en el despliegue —no está en
# el repositorio—, así que desplegar no rompe el correo ni lo arregla. Esto se
# corre el día que se quiera saber en qué estado están, y después de tocar un
# `.env`.
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ EXISTE
#
# Censado el 3 sep 2026 sobre las diecisiete carpetas de `micolev1`: DIECISÉIS
# tenían el andamiaje de desarrollo intacto —`MAIL_MAILER=smtp`,
# `MAIL_HOST=mailhog`, `MAIL_FROM_ADDRESS=null`— y el único configurado era
# `demo`, apuntando a un dominio que no existe. O sea que **ningún colegio real
# había enviado un correo nunca**, y la documentación llevaba meses dando el
# cambio por aplicado. Los diecisiete se arreglaron ese día.
#
# Lo que hace falta ahora no es repetir aquel arreglo, es **poder comprobarlo
# sin volver a descubrirlo**: queda al menos una instalación fuera (abajo), y un
# `.env` lo puede volver a mover cualquiera.
#
# «Cada uno tiene lo suyo» y «todos tienen lo mismo» son igual de
# indistinguibles desde el repositorio. Lo único que las separa es el bucle.
#
# ─────────────────────────────────────────────────────────────────────────────
# LAS CUATRO FORMAS DE ESTAR ROTO, Y NINGUNA DA ERROR EN NINGÚN SITIO
#
# 1. `MAIL_FROM_ADDRESS=null`. Laravel lee la CADENA `null` como un null DE
#    VERDAD, así que el valor por defecto de `config/mail.php` no llega a
#    aplicarse y `Mail` **rechaza el envío antes de intentarlo**. Medido
#    ejecutándolo el 2 sep 2026: `X=null` -> NULL, `X=` -> ''. No es lo mismo
#    que vacío y se comporta igual de mal.
#
# 2. `MAIL_MAILER=smtp` con `MAIL_HOST=mailhog`. Es el capturador de correo del
#    docker; en el servidor ese host no existe y el transporte no conecta.
#
# 3. El remitente en `lalvirtual.com`, que **NO ESTÁ REGISTRADO** (dig ->
#    NXDOMAIN, 2 sep 2026). Para el que recibe eso no es un SPF que falla, es
#    «sender domain does not exist», que es un rechazo más duro. Salió de la
#    cabecera que el `mail()` viejo llevaba incrustada.
#
# 4. **La configuración cacheada.** Ésta no se ve mirando el `.env` y es la que
#    mordió en `demo` el 15 sep 2026: si `bootstrap/cache/config.php` es MÁS
#    VIEJO que el `.env`, la aplicación sirve los valores anteriores y el
#    fichero que estás leyendo no describe lo que hace el colegio. Por eso ese
#    caso sale **NO MEDIDO** y no «OK»: no es que esté bien ni mal, es que el
#    `.env` no es la fuente de la verdad ahí.
#
# ─────────────────────────────────────────────────────────────────────────────
# LA INSTALACIÓN NÚMERO DIECIOCHO
#
# El bucle canónico barre `/home/micolev1/*`, y **la instalación viva de `lal`
# está en la otra cuenta de cPanel** (`micolevi`, `lalvirtual.edu.co`, docroot
# `~/public_html`). El censo del 3 sep no la alcanzó, así que su correo sigue
# como estaba el de los otros dieciséis: caído.
#
# Un barrido que diga «17 de 17 correctos» y salga verde es exactamente la
# lectura falsa contra la que existe esta herramienta. Así que mientras no se
# haya mirado nada fuera de `/home/micolev1/`, este guion cuenta esa
# instalación como NO MEDIDA y **sale con código 2**. Se cierra corriéndolo
# también en la otra cuenta; allí las raíces por defecto ya la incluyen.
#
# ─────────────────────────────────────────────────────────────────────────────
# POBLACIÓN: SIEMPRE, Y ANTES DEL RESULTADO
#
# Regla del repo (CLAUDE.md, «Herramientas de medición»): un «0 encontrados» no
# distingue «revisé 17 y ninguno lo era» de «no revisé nada», y de las dos
# lecturas la falsa es la que hace archivar el asunto. Un glob que no casa se
# expande a sí mismo, así que aquí eso no es teórico.
#
# Y el barrido va por CARPETA, no por host: las carpetas no se llaman como el
# subdominio que sirven (`fortul` se sirve como `coaf`, `cads-itagui` como
# `cads`). El mapa está en docs/DESPLIEGUE-REFERENCIA.md.
#
# NO le pases Pint: es bash.

set -uo pipefail

# Lo que decidió el PR #3 y se aplicó el 3 sep 2026. `sendmail` reproduce el
# camino que usaba la función mail() de PHP, que es el que menos cambia el
# comportamiento anterior, y NO lleva usuario ni contraseña: config/mail.php
# sólo lee MAIL_USERNAME y MAIL_PASSWORD dentro del bloque `smtp`.
MAILER_BUENO='sendmail'
REMITENTE_BUENO='admin@micolevirtual.com'
NOMBRE_BUENO='MiColegioVirtual'
DOMINIO_MUERTO='lalvirtual.com'

# Dónde buscar. La primera es el bucle canónico de DESPLIEGUE.md pero con `*` en
# vez de `*.micolevirtual.com`: la segunda forma dejaría fuera a cualquier
# colegio con dominio propio, y las carpetas que no son colegios no tienen
# `8myvc/` dentro, así que el filtro lo hace el propio glob. La segunda es el
# docroot de la cuenta vieja, para que correr esto ALLÍ no necesite argumentos.
RAICES_POR_DEFECTO=('/home/micolev1/*/8myvc' "$HOME/public_html/8myvc")

# ─── --arreglo: qué hay que pegar, y en qué orden ─────────────────────────────
if [ "${1:-}" = '--arreglo' ]; then
    cat <<'AYUDA'
ESTO NO LO HACE EL GUION. Se pega a mano en el .env del colegio que salga rojo.

    MAIL_MAILER=sendmail
    MAIL_SENDMAIL_PATH=
    MAIL_HOST=
    MAIL_PORT=
    MAIL_USERNAME=
    MAIL_PASSWORD=
    MAIL_ENCRYPTION=
    MAIL_FROM_ADDRESS=admin@micolevirtual.com
    MAIL_FROM_NAME="MiColegioVirtual"

NO HAY CONTRASEÑA, y no es que falte: con `sendmail` el mensaje se le entrega al
binario local del servidor y NO hay autenticación SMTP. config/mail.php lee
MAIL_USERNAME y MAIL_PASSWORD sólo dentro del bloque `smtp`, así que con este
transporte esas dos líneas no las mira nadie. Se dejan vacías.

Los pasos, y el tercero EN ESTE ORDEN:

    1.  mkdir -p ~/respaldos-env && chmod 700 ~/respaldos-env
        cp "$d/.env" ~/respaldos-env/"$colegio".env.$(date +%F)

        El respaldo va FUERA del docroot. La carpeta del colegio SÍ se sirve por
        web: está comprobado que `.env` no se descarga, pero eso no dice nada de
        un `.env.bak-*`, y averiguarlo exige crear uno.

    2.  Editar el bloque de arriba en "$d/.env".

    3.  cd "$d" && php artisan config:clear && php artisan config:cache

        DESPUÉS de editar, nunca antes: cachear antes de tocar el .env deja al
        colegio sirviendo la configuración vieja sin ningún síntoma que lo
        delate (docs/DESPLIEGUE.md).

    4.  php artisan correo:probar TU-CORREO@ejemplo.com

        Es un DIAGNÓSTICO, no la reparación: lee la configuración y manda un
        mensaje. El propio comando avisa al terminar de que «sin errores»
        significa que el transporte lo aceptó, no que haya llegado.
AYUDA
    exit 0
fi

# ─── La población ─────────────────────────────────────────────────────────────
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
    printf 'Esto NO es «ninguna está rota»: es que no se revisó nada. Un glob que no casa\n' >&2
    printf 'se expande a sí mismo, así que comprueba que esto se corre EN EL SERVIDOR o\n' >&2
    printf 'pásale una raíz de prueba como argumento.\n' >&2
    exit 2
fi

printf 'POBLACIÓN: %d instalación(es) bajo «%s», cuenta %s.\n' \
    "${#carpetas[@]}" "$(IFS=' '; echo "${patrones[*]}")" "$(id -un 2>/dev/null || echo '?')"
printf 'En `micolev1` lo esperado son 17: los dieciséis colegios Y `demo`.\n'

# El sendmail del servidor, una vez y no por colegio: es del php.ini, no del
# .env. Si el binario no existe, `MAIL_MAILER=sendmail` está bien escrito y no
# sale nada igual, que es un fallo que ningún .env delata.
if command -v php >/dev/null 2>&1; then
    ruta_php=$(php -r 'echo (string) ini_get("sendmail_path");' 2>/dev/null)
    binario=${ruta_php%% *}
    if [ -z "$ruta_php" ]; then
        printf 'SENDMAIL DEL SERVIDOR: sendmail_path sin definir en el php.ini del CLI.\n'
    elif [ -x "$binario" ]; then
        printf 'SENDMAIL DEL SERVIDOR: %s  (binario presente)\n' "$ruta_php"
    else
        printf 'SENDMAIL DEL SERVIDOR: %s  <-- EL BINARIO NO EXISTE O NO ES EJECUTABLE\n' "$ruta_php"
    fi
    printf '  (es el php.ini del CLI; el de la web se comprobó igual el 17 ago 2026)\n'
else
    printf 'SENDMAIL DEL SERVIDOR: NO MEDIDO (no hay `php` en el PATH de esta sesión).\n'
fi
printf '\n'

# ─── Leer una variable del .env ───────────────────────────────────────────────
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

existe_var() { grep -qE "^[[:space:]]*${1}=" "$2" 2>/dev/null; }

ok=0; caidos=0; revisar=0; noMedido=0
fuera_de_micolev1=0
pendientes=()

for d in "${carpetas[@]}"; do
    padre=$(dirname "$d")
    nombre=$(basename "$padre")
    # `~/public_html/8myvc` da «public_html», que no dice de quién es.
    [ "$nombre" = 'public_html' ] && nombre="public_html ($(id -un 2>/dev/null || echo '?'))"
    case "$padre" in
        /home/micolev1/*) ;;
        *) fuera_de_micolev1=1 ;;
    esac

    if [ ! -r "$d/.env" ]; then
        printf '%-34s NO MEDIDO   .env ausente o sin permiso de lectura\n' "$nombre"
        noMedido=$((noMedido + 1))
        pendientes+=("$nombre — no se pudo leer el .env")
        continue
    fi

    # La caché manda sobre el fichero: si es más vieja, lo que sirve la
    # aplicación no es esto. Se dice ANTES de dar ningún veredicto del .env.
    cache="$d/bootstrap/cache/config.php"
    if [ -f "$cache" ] && [ "$cache" -ot "$d/.env" ]; then
        printf '%-34s NO MEDIDO   config cacheada MÁS VIEJA que el .env\n' "$nombre"
        printf '%-34s             la app sirve lo de antes; arréglalo con `config:clear && config:cache`\n' ''
        noMedido=$((noMedido + 1))
        pendientes+=("$nombre — caché de configuración vieja: config:clear && config:cache")
        continue
    fi

    mailer=$(valor_de MAIL_MAILER "$d/.env") || mailer=''
    tiene_mailer=0; existe_var MAIL_MAILER "$d/.env" && tiene_mailer=1
    host=$(valor_de MAIL_HOST "$d/.env") || host=''
    remitente=$(valor_de MAIL_FROM_ADDRESS "$d/.env") || remitente=''
    tiene_remitente=0; existe_var MAIL_FROM_ADDRESS "$d/.env" && tiene_remitente=1
    nombre_from=$(valor_de MAIL_FROM_NAME "$d/.env") || nombre_from=''

    estado='OK'; motivo=''

    # 1. El remitente, que es el que tumba el envío antes de intentarlo.
    if [ "$tiene_remitente" -eq 0 ]; then
        estado='CAÍDO'; motivo='MAIL_FROM_ADDRESS no está en el fichero'
    elif [ -z "$remitente" ]; then
        estado='CAÍDO'; motivo='MAIL_FROM_ADDRESS vacío: Laravel rechaza el envío antes de intentarlo'
    elif [ "$remitente" = 'null' ] || [ "$remitente" = '(null)' ]; then
        estado='CAÍDO'; motivo='MAIL_FROM_ADDRESS=null: la CADENA se lee como null de verdad, no como el valor por defecto'
    elif [ "${remitente#*@}" = "$DOMINIO_MUERTO" ]; then
        estado='CAÍDO'; motivo="remitente en $DOMINIO_MUERTO, que NO está registrado (NXDOMAIN): rechazo duro en destino"
    fi

    # 2. El transporte.
    if [ "$estado" = 'OK' ]; then
        if [ "$tiene_mailer" -eq 0 ] || [ -z "$mailer" ]; then
            estado='REVISAR'; motivo='MAIL_MAILER ausente o vacío: cae al valor por defecto de config/mail.php'
        elif [ "$mailer" = 'log' ] || [ "$mailer" = 'array' ]; then
            estado='CAÍDO'; motivo="MAIL_MAILER=$mailer: el correo no sale del servidor y no da ningún error"
        elif [ "$mailer" = 'smtp' ]; then
            if [ -z "$host" ] || [ "$host" = 'mailhog' ]; then
                estado='CAÍDO'; motivo="MAIL_MAILER=smtp con MAIL_HOST=${host:-(vacío)}: el andamiaje del docker, aquí no conecta"
            else
                estado='REVISAR'; motivo="MAIL_MAILER=smtp contra $host: no es lo decidido (sendmail), pero puede funcionar"
            fi
        elif [ "$mailer" != "$MAILER_BUENO" ]; then
            estado='REVISAR'; motivo="MAIL_MAILER=$mailer, que no es $MAILER_BUENO ni smtp"
        fi
    fi

    # 3. Detalles que no tumban el envío pero salen en el correo o lo harán.
    if [ "$estado" = 'OK' ]; then
        sendmail_path=$(valor_de MAIL_SENDMAIL_PATH "$d/.env") || sendmail_path=''
        if [ -n "$sendmail_path" ]; then
            bin=${sendmail_path%% *}
            [ -x "$bin" ] || { estado='REVISAR'; motivo="MAIL_SENDMAIL_PATH apunta a $bin, que no es ejecutable"; }
        fi
    fi
    if [ "$estado" = 'OK' ]; then
        case "$nombre_from" in
            *'${'*)
                interpolada=$(printf '%s' "$nombre_from" | sed -n 's/.*${\([A-Za-z_][A-Za-z0-9_]*\)}.*/\1/p')
                if [ -n "$interpolada" ] && ! existe_var "$interpolada" "$d/.env"; then
                    estado='REVISAR'
                    motivo="MAIL_FROM_NAME interpola \${$interpolada}, que no está definida: se queda literal en el correo"
                fi
                ;;
        esac
    fi

    case "$estado" in
        'OK')
            detalle="$mailer · $remitente"
            [ "$remitente" != "$REMITENTE_BUENO" ] && detalle="$detalle  (no es $REMITENTE_BUENO)"
            printf '%-34s ok          %s\n' "$nombre" "$detalle"
            ok=$((ok + 1))
            ;;
        'CAÍDO')
            printf '%-34s CAÍDO       %s\n' "$nombre" "$motivo"
            caidos=$((caidos + 1))
            pendientes+=("$nombre — $motivo")
            ;;
        'REVISAR')
            printf '%-34s REVISAR     %s\n' "$nombre" "$motivo"
            revisar=$((revisar + 1))
            pendientes+=("$nombre — $motivo")
            ;;
    esac
done

# ─── La número dieciocho ──────────────────────────────────────────────────────
if [ "$fuera_de_micolev1" -eq 0 ]; then
    printf '%-34s NO MEDIDO   no alcanzada: la instalación viva vive en la cuenta `micolevi`, ~/public_html/8myvc\n' 'lal (lalvirtual.edu.co)'
    noMedido=$((noMedido + 1))
    pendientes+=('lal (lalvirtual.edu.co) — fuera de este barrido: córrelo en la cuenta `micolevi`')
fi

# Singular y plural a mano: «De 1 instalaciones» es la clase de renglón que hace
# dudar de si la herramienta sabe contar, justo en la línea que importa.
if [ "${#carpetas[@]}" -eq 1 ]; then
    printf '\nDe 1 instalación barrida: %d ok, %d caída(s), %d a revisar, %d no medida(s).\n' \
        "$ok" "$caidos" "$revisar" "$noMedido"
else
    printf '\nDe %d instalaciones barridas: %d ok, %d caída(s), %d a revisar, %d no medida(s).\n' \
        "${#carpetas[@]}" "$ok" "$caidos" "$revisar" "$noMedido"
fi

if [ "${#pendientes[@]}" -gt 0 ]; then
    printf '\nLO QUE FALTA POR ARREGLAR (%d):\n' "${#pendientes[@]}"
    printf '  · %s\n' "${pendientes[@]}"
    printf '\nEl bloque que hay que pegar y en qué orden: %s --arreglo\n' "$0"
else
    printf '\nNinguna pendiente. Recuerda que esto lee el .env: que la configuración sea\n'
    printf 'correcta no es lo mismo que que el correo llegue, y eso sólo lo dice mirar\n'
    printf 'la bandeja después de `php artisan correo:probar`.\n'
fi

# Lo no medido manda sobre lo medido: un verde parcial es la lectura falsa
# contra la que existe esto.
if [ "$noMedido" -gt 0 ]; then
    printf 'SALIDA 2: %d NO MEDIDA(S). Esto no es un verde con excepciones.\n' "$noMedido"
    exit 2
fi
[ "$((caidos + revisar))" -gt 0 ] && exit 1
exit 0
