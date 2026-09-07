<?php

/*
|--------------------------------------------------------------------------
| La app: `myvc_flutter` — y desde el 6 sep 2026, también el escritorio
|--------------------------------------------------------------------------
|
| Va por colegio, en su .env, porque `config/` es copia real en cada uno (ver
| docs/DESPLIEGUE-REFERENCIA.md). Y **las apps no**: `myvc_flutter` es UNA SOLA
| para los dieciséis colegios, así que aquí cada colegio decide sobre un binario
| que no es suyo. Ésa es toda la dificultad de este fichero.
|
| **Y son dos binarios, no uno**: el campo viaja también a la app de escritorio
| del horario, que **no puede obedecerlo**. El recuadro de abajo lo dice donde
| hace falta; el título decía «la app» y ya no es una.
|
| El porqué y la ceremonia están en docs/migracion/noche-2026-08-25/login-ver.md.
|
*/

return [

    /*
    | La versión mínima de la app que este colegio acepta.
    |
    | Es el **`versionCode`** —el `+N` de `pubspec.yaml`—, no la versión con
    | puntos: `1.4.2+37` es **37**. Se manda tal cual en la respuesta del login y
    | del refresco, bajo `version_minima_app`, y es la app la que decide qué hace
    | con él.
    |
    | **Vacío = no se manda el campo = no se bloquea a nadie.** Es como se
    | despliega y es lo que hace que este cambio sea inerte hasta que alguien
    | escriba un número.
    |
    | ────────────────────────────────────────────────────────────────────────
    | ANTES DE ESCRIBIR UN NÚMERO AQUÍ, LEER ESTO
    |
    | El número tiene que ser el `versionCode` de una versión que **exista en la
    | tienda**. Poner uno mayor que el publicado **deja al colegio entero fuera y
    | sin salida**: la pantalla de bloqueo manda a Play, y en Play no hay nada a
    | lo que actualizar.
    |
    | Y no es hipotético: medido el 25 ago 2026, `pubspec.yaml` dice `1.0.0+1` y
    | la app **nunca se ha subido a Play**. O sea que hoy **cualquier número
    | mayor que 1 bloquea a todos los usuarios de este colegio**, incluido un 5 o
    | un 10 puestos «por si acaso».
    |
    | La única salida que existe para el usuario es la opción «¿Tienes cuenta en
    | otro colegio?» de la pantalla de bloqueo, que al cerrar sesión olvida el
    | número. **Al que sólo tiene cuenta en este colegio no le sirve**: su única
    | salida es que alguien toque el servidor.
    |
    | Por eso se sube **una vez por retirada, con la misma ceremonia que un
    | despliegue, y nunca se copia de un colegio a otro sin mirar**.
    |
    | Y HAY UN QUINTO CLIENTE QUE RECIBE ESTE NÚMERO Y NO PUEDE OBEDECERLO
    |
    | `version_minima_app` viaja también por `login/credentials`, que es la ruta
    | por la que entra la app de escritorio del horario (`myvc_horarios`). Para
    | ella **no existe ninguna versión que satisfaga este número**: su versionado
    | es independiente del de Flutter y no está en ninguna tienda. Y la salida
    | del párrafo de arriba —«¿Tienes cuenta en otro colegio?»— **no existe en un
    | programa de escritorio**.
    |
    | Hoy no le pasa nada porque ese programa lee `el_token` y tira el resto. O
    | sea que lo único que lo protege **no está en este servidor**, y falta una
    | sola de las dos condiciones. El porqué entero, en
    | docs/migracion/32-la-entrada-de-la-app-de-escritorio.md §4.3.
    | ────────────────────────────────────────────────────────────────────────
    */
    'version_minima' => env('APP_MOVIL_VERSION_MINIMA'),

];
