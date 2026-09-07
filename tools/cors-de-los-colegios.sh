#!/usr/bin/env bash
#
# ¿Deja CORS entrar a la app de escritorio, colegio a colegio?
#
# USO
#   tools/cors-de-los-colegios.sh --env [RAIZ]     # lee los .env  (en el SERVIDOR)
#   tools/cors-de-los-colegios.sh --url URL [URL…] # pregunta al servidor (desde cualquier sitio)
#   tools/cors-de-los-colegios.sh --origenes       # imprime la lista mínima, para pegar en un .env
#
#   RAIZ por defecto: /home/micolev1/*.micolevirtual.com/8myvc  (el bucle de docs/DESPLIEGUE.md).
#   Se le puede pasar otra para probarlo en local sin servidor.
#
# ─────────────────────────────────────────────────────────────────────────────
# POR QUÉ EXISTE
#
# `myvc_horarios` es un programa de escritorio (Tauri) que habla con esta API
# desde un WebView, o sea **desde un navegador**, o sea cruzando origen. Si
# `CORS_ALLOWED_ORIGINS` de un colegio lleva una lista y el origen del programa
# no está en ella, el navegador de la ventana bloquea la petición y **aquí no se
# entera nadie**: el servidor contesta 204 y la culpa parece del programa.
#
# Medido el 6 sep 2026 contra el docker, con la lista puesta a mano:
#
#     lista = [https://simonbolivar.micolevirtual.com]
#     Origin: tauri://localhost      -> 204 SIN Access-Control-Allow-Origin  (bloqueado)
#     Origin: http://tauri.localhost -> 204 SIN Access-Control-Allow-Origin  (bloqueado)
#
# Y con los dos orígenes dentro de la lista, los dos vuelven con su ACAO: el
# mecanismo **sí** admite un esquema propio como `tauri://`, no hay que inventar
# nada. Lo único que falta es saber en qué colegios está puesta la variable, y
# eso es lo que este guion cuenta.
#
# ─────────────────────────────────────────────────────────────────────────────
# SON DOS PREGUNTAS Y ESTE GUION LAS SEPARA A PROPÓSITO
#
#   --env   ¿qué dice el `.env` de cada colegio?
#   --url   ¿qué contesta de verdad ese servidor a un preflight?
#
# No son la misma. `config/cors.php` es lo que hace Laravel, pero delante hay un
# Apache o un nginx que puede añadir su propia `Access-Control-Allow-Origin` —o
# comerse el OPTIONS antes de que PHP lo vea—. Un `.env` correcto con un vhost
# que mete la suya da **dos** cabeceras y el navegador rechaza las dos. Por eso
# la respuesta del servidor se mide aparte y no se deduce del fichero.
#
# ─────────────────────────────────────────────────────────────────────────────
# UNA LISTA DE **UN** ORIGEN NO SE COMPORTA COMO UNA DE DOS
#
# Medido el 6 sep 2026 leyendo `vendor/fruitcake/php-cors`, `isSingleOriginAllowed()`:
# si la lista tiene **exactamente un** elemento y no hay patrones, la libreria
# escribe ese origen en `Access-Control-Allow-Origin` **sin mirar quien pregunta**.
#
#     lista = [https://x.edu.co]      Origin: tauri://localhost
#     -> 204 con `Access-Control-Allow-Origin: https://x.edu.co`
#
# El efecto para el usuario es el mismo --el navegador compara, no casa, bloquea--
# pero **la cabecera esta ahi**, y eso rompe a cualquier detector que pregunte
# "?vino una ACAO?" en vez de "?vino la mia?". Este guion lo tuvo mal en su primera
# version y por eso esta escrito aqui: la comparacion es contra `*` o contra el
# origen exacto, nunca contra "hay algo".
#
# Y no es un caso de laboratorio: `.env.example` recomienda literalmente
# `p.ej: https://lalvirtual.edu.co`, o sea **una sola entrada**, que es el caso
# que dispara esta rama.
#
# ─────────────────────────────────────────────────────────────────────────────
# LO QUE ESTE GUION **NO** CONTESTA, Y HAY QUE DECIRLO CADA VEZ
#
# `--url` manda un `Origin` **tecleado por nosotros**. Contesta *«¿lo acepta la
# lista?»* y **no** *«¿funciona el programa?»*: el `Origin` de verdad lo pone la
# ventana y no se puede falsificar desde un cliente —es un *forbidden header
# name* del Fetch, el navegador lo tira—. Las dos preguntas se parecen y sólo
# una la contesta esto. Está escrito en docs/migracion/29 §5 y se repite aquí
# porque es donde se va a leer.
#
# Y una comprobación que corra desde Node **no mide CORS en absoluto**, aunque
# pase: sin `Origin` el middleware no tiene nada que comparar.
#
# ─────────────────────────────────────────────────────────────────────────────
# POBLACIÓN: SIEMPRE, Y ANTES DEL RESULTADO
#
# Regla del repo (CLAUDE.md, «Herramientas de medición»): un «0 encontrados» no
# distingue *«revisé 17 y ninguno lo era»* de *«no revisé nada»*, y de las dos
# lecturas la falsa es la que hace archivar el asunto. Aquí eso no es teórico:
# el bucle de `micolev1` es un glob, y un glob que no casa **se expande a sí
# mismo** — el guion sale con código 2 y lo dice, en vez de imprimir un cero.
#
# NO le pases Pint: es bash. Para los .php de tools/ sí, y con `stan` detrás.

set -uo pipefail

# Los orígenes que manda un Tauri empaquetado, LEÍDOS DEL CRATE que compila
# `myvc_horarios` (tauri 2.11.5, src/manager/mod.rs:339-346 `tauri_protocol_url`,
# y el test de :778-799 que los fija):
#
#     if cfg!(windows) || cfg!(target_os = "android") { "{http|https}://tauri.localhost" }
#     else                                            { "tauri://localhost" }
#
# O sea **DOS entradas para tres plataformas**, y ésa es toda la trampa: quien
# ponga sólo `tauri://localhost` —que es el único que se ha visto, y en un mac—
# deja fuera Windows, que es donde va a estar el que cuadra el horario.
#
# El `https` sólo sale con `useHttpsScheme`, que `tauri.conf.json` de ese repo
# NO tiene puesto; se comprueba igual porque cambiarlo allí es una línea y aquí
# no nos enteraríamos.
ORIGENES_DEL_ESCRITORIO=('tauri://localhost' 'http://tauri.localhost')
ORIGENES_QUE_VIGILAR=('tauri://localhost' 'http://tauri.localhost' 'https://tauri.localhost')

modo="${1:---env}"

# ─── --origenes ───────────────────────────────────────────────────────────────
if [ "$modo" = '--origenes' ]; then
    printf 'Los %d orígenes que tiene que llevar CORS_ALLOWED_ORIGINS para que\n' "${#ORIGENES_DEL_ESCRITORIO[@]}"
    printf 'la app de escritorio entre en las tres plataformas:\n\n'
    printf '    %s\n' "$(IFS=,; echo "${ORIGENES_DEL_ESCRITORIO[*]}")"
    printf '\nVan DETRÁS de los del front del colegio, separados por comas y sin espacios.\n'
    printf 'Fuente: crate tauri 2.11.5, src/manager/mod.rs:339-346. No es una suposición.\n'
    exit 0
fi

# ─── --url: preguntarle al servidor ───────────────────────────────────────────
if [ "$modo" = '--url' ]; then
    shift
    if [ "$#" -eq 0 ]; then
        echo 'NO MEDIDO: --url necesita al menos una URL base (p. ej. https://x.micolevirtual.com).' >&2
        exit 2
    fi

    printf 'POBLACIÓN: %d servidor(es), %d origen(es) por servidor = %d preflights.\n' \
        "$#" "${#ORIGENES_QUE_VIGILAR[@]}" "$(( $# * ${#ORIGENES_QUE_VIGILAR[@]} ))"
    printf 'Ruta sondeada: POST api/login/credentials (la que usa el escritorio para entrar).\n\n'

    pasan=0; bloquean=0; sinRespuesta=0

    for base in "$@"; do
        printf '%s\n' "${base%/}"
        for origen in "${ORIGENES_QUE_VIGILAR[@]}"; do
            # -i y no -I: algunos vhosts contestan distinto a HEAD que a OPTIONS.
            respuesta=$(curl -s -i -m 15 -X OPTIONS "${base%/}/api/login/credentials" \
                -H "Origin: $origen" \
                -H 'Access-Control-Request-Method: POST' \
                -H 'Access-Control-Request-Headers: authorization,content-type' 2>/dev/null)

            if [ -z "$respuesta" ]; then
                printf '    %-30s NO MEDIDO  (no contestó: red, DNS o tiempo agotado)\n' "$origen"
                sinRespuesta=$((sinRespuesta + 1))
                continue
            fi

            codigo=$(printf '%s' "$respuesta" | awk 'NR==1{print $2}')
            # Todas las ACAO que vengan: DOS es un fallo distinto de NINGUNA —lo
            # normal cuando el vhost añade la suya encima de la de Laravel— y el
            # navegador rechaza las dos.
            acao=$(printf '%s' "$respuesta" | grep -i '^access-control-allow-origin:' | sed 's/^[^:]*: *//' | tr -d '\r')
            cuantas=$(printf '%s' "$acao" | grep -c . )

            if [ "$cuantas" -gt 1 ]; then
                printf '    %-30s %s  DOS CABECERAS ACAO -> el navegador bloquea igual: %s\n' \
                    "$origen" "$codigo" "$(printf '%s' "$acao" | tr '\n' ' ')"
                bloquean=$((bloquean + 1))
            elif [ "$acao" = '*' ] || [ "$acao" = "$origen" ]; then
                printf '    %-30s %s  pasa   (ACAO: %s)\n' "$origen" "$codigo" "$acao"
                pasan=$((pasan + 1))
            elif [ -n "$acao" ]; then
                # ESTE CASO NO ES UN MATIZ Y ESTA HERRAMIENTA LO TUVO MAL.
                #
                # Con **exactamente un** origen en la lista, la libreria devuelve
                # ese origen a TODO EL MUNDO sin mirar quien pregunta
                # (vendor/fruitcake/php-cors, `isSingleOriginAllowed()`: si la
                # lista tiene un elemento y no hay patrones, lo escribe tal cual).
                # O sea que un colegio con `CORS_ALLOWED_ORIGINS=https://x.edu.co`
                # --que es literalmente el ejemplo de `.env.example`-- contesta
                # una ACAO **a la peticion del escritorio tambien**, y el
                # navegador la compara con su propio origen, ve que no casa y la
                # bloquea.
                #
                # La primera version de este guion preguntaba "?hay ACAO?" y decia
                # `pasa`. Es el fallo del que avisa CLAUDE.md: el detector contaba
                # bien un sintoma y no estaba contando la causa. Lo unico que vale
                # es `*` o el origen exacto.
                printf '    %-30s %s  BLOQUEADO (ACAO para OTRO origen: %s)\n' "$origen" "$codigo" "$acao"
                bloquean=$((bloquean + 1))
            else
                printf '    %-30s %s  BLOQUEADO (sin Access-Control-Allow-Origin)\n' "$origen" "$codigo"
                bloquean=$((bloquean + 1))
            fi
        done
    done

    printf '\nDe %d preflights: %d pasan, %d bloqueados, %d no medidos.\n' \
        "$(( $# * ${#ORIGENES_QUE_VIGILAR[@]} ))" "$pasan" "$bloquean" "$sinRespuesta"
    printf 'RECUERDA: esto dice si la LISTA acepta esa cadena. No dice que el programa funcione.\n'

    # NO MEDIDO va ANTES que bloqueado, y no es un matiz: un servidor que no
    # contesta salía con codigo 0 —o sea verde— porque no habia bloqueos que
    # contar. Un verde parcial es exactamente la lectura falsa contra la que
    # existe esta herramienta, asi que lo no medido manda sobre lo medido.
    if [ "$sinRespuesta" -gt 0 ]; then
        printf 'SALIDA 2: %d preflight(s) NO MEDIDOS. Esto no es un verde con excepciones.\n' "$sinRespuesta"
        exit 2
    fi
    [ "$bloquean" -gt 0 ] && exit 1
    exit 0
fi

# ─── --env: leer los .env, en el servidor ─────────────────────────────────────
if [ "$modo" != '--env' ]; then
    echo "Modo desconocido: $modo. Usa --env, --url o --origenes." >&2
    exit 2
fi

raiz="${2:-}"
if [ -n "$raiz" ]; then
    # shellcheck disable=SC2206
    carpetas=($raiz)
else
    # shellcheck disable=SC2206
    carpetas=(/home/micolev1/*.micolevirtual.com/8myvc)
fi

# Un glob que no casa se expande a sí mismo. Sin esto, el bucle recorrería una
# ruta con asterisco dentro, no encontraría .env y saldría «17 ausentes» — que
# es exactamente la lectura falsa contra la que existe este bloque.
reales=()
for d in "${carpetas[@]}"; do
    [ -d "$d" ] && reales+=("$d")
done

if [ "${#reales[@]}" -eq 0 ]; then
    printf 'NO MEDIDO: 0 carpetas de colegio bajo «%s».\n' "${raiz:-/home/micolev1/*.micolevirtual.com/8myvc}" >&2
    printf 'Esto NO es «ningún colegio la tiene puesta»: es que no se revisó nada.\n' >&2
    printf 'Este modo se corre EN EL SERVIDOR, o con una raíz de prueba como segundo argumento.\n' >&2
    exit 2
fi

printf 'POBLACIÓN: %d carpeta(s) de colegio bajo «%s».\n' \
    "${#reales[@]}" "${raiz:-/home/micolev1/*.micolevirtual.com/8myvc}"
printf 'Recuerda que el bucle alcanza a los dieciséis colegios Y a `demo`: 17 es lo esperado.\n\n'

ausentes=0; vacias=0; cubren=0; noCubren=0; sinEnv=0

for d in "${reales[@]}"; do
    nombre=$(basename "$(dirname "$d")")

    if [ ! -r "$d/.env" ]; then
        printf '%-42s NO MEDIDO  (.env ausente o sin permiso de lectura)\n' "$nombre"
        sinEnv=$((sinEnv + 1))
        continue
    fi

    linea=$(grep -E '^[[:space:]]*CORS_ALLOWED_ORIGINS=' "$d/.env" | tail -1)

    if [ -z "$linea" ]; then
        printf '%-42s ausente        -> ["*"], el escritorio PASA\n' "$nombre"
        ausentes=$((ausentes + 1))
        continue
    fi

    valor=${linea#*=}
    valor=$(printf '%s' "$valor" | tr -d '"'\''' | tr -d '[:space:]')

    if [ -z "$valor" ]; then
        # Ausente y vacía son lo mismo: `config/cors.php` termina en `?: ['*']`.
        # Está medido en docs/migracion/29 §5, donde la primera lectura del
        # fichero dedujo lo contrario por parar en la línea de antes.
        printf '%-42s presente y VACÍA -> ["*"], el escritorio PASA\n' "$nombre"
        vacias=$((vacias + 1))
        continue
    fi

    falta=()
    for origen in "${ORIGENES_DEL_ESCRITORIO[@]}"; do
        case ",$valor," in
            *",$origen,"*) ;;
            *) falta+=("$origen") ;;
        esac
    done

    if [ "${#falta[@]}" -eq 0 ]; then
        printf '%-42s lista, y CUBRE las tres plataformas\n' "$nombre"
        cubren=$((cubren + 1))
    else
        printf '%-42s lista, NO CUBRE -> falta %s\n' "$nombre" "$(IFS=' '; echo "${falta[*]}")"
        printf '%-42s   valor: %s\n' '' "$valor"
        noCubren=$((noCubren + 1))
    fi
done

printf '\nDe %d carpetas: %d ausente, %d vacía, %d con lista que cubre, %d con lista que NO cubre, %d no medidas.\n' \
    "${#reales[@]}" "$ausentes" "$vacias" "$cubren" "$noCubren" "$sinEnv"
printf 'Los %d de «ausente» + «vacía» dejan pasar al escritorio HOY y lo dejarán de hacer\n' "$((ausentes + vacias))"
printf 'el día que alguien cierre CORS en ellos, que es una tarea abierta (29 §5).\n'

# Mismo criterio que en --url: un .env que no se pudo leer no es un colegio limpio.
if [ "$sinEnv" -gt 0 ]; then
    printf 'SALIDA 2: %d carpeta(s) NO MEDIDAS. Esto no es un verde con excepciones.\n' "$sinEnv"
    exit 2
fi
[ "$noCubren" -gt 0 ] && exit 1
exit 0
