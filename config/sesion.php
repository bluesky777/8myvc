<?php

/*
|--------------------------------------------------------------------------
| Sesión: vidas de los tokens
|--------------------------------------------------------------------------
|
| Todo en MINUTOS salvo donde se diga. Va por colegio, en su .env, porque
| config/ es copia real en cada uno (ver docs/DESPLIEGUE.md).
|
| El contrato completo, y por qué son dos tokens y no uno, está en
| docs/migracion/07-sesion.md.
|
*/

return [

    /*
    | El token de acceso: el que viaja en CADA petición. Es el más expuesto, y
    | por eso es el que menos dura. No se puede renovar a sí mismo.
    |
    | 60 minutos con renovación del front a la mitad (30 min) es el acuerdo con
    | la sesión de myvc_front, que programa el refresco con `expira_en`.
    */
    'acceso_ttl' => (int) env('SESION_ACCESO_TTL', 60),

    /*
    | El token de refresco: solo se manda a POST /api/auth/refresh, y rota en
    | cada uso. Es lo que decide cuánta inactividad aguanta la sesión antes de
    | obligar a entrar de nuevo.
    |
    | 14 días es lo que ya valía JWT_REFRESH_TTL, así que no encoge nada
    | respecto a lo de hoy. El mínimo que pidió Joseth eran 8 horas.
    |
    | El límite por inactividad NO se impone aquí: lo cuenta el front (fase 7b
    | de myvc_front). Mientras el refresco valga, el backend refresca.
    */
    'refresco_ttl' => (int) env('SESION_REFRESCO_TTL', 20160),

    /*
    | Gracia del refresco recién rotado, en SEGUNDOS.
    |
    | Sin esto, la rotación cierra sesiones sola en multipestaña: la pestaña A
    | renueva y guarda el par nuevo, la pestaña B manda el viejo un segundo
    | después y se come un 401. En informes se trabaja con varias pestañas
    | abiertas —el listado en una y el certificado en otra—, así que no es un
    | caso raro.
    |
    | Durante la gracia, el refresco ya rotado se sigue aceptando y devuelve un
    | par nuevo. No reabre el agujero que cierra la rotación: pasada la gracia,
    | presentarlo es un 401 y queda anotado en `bitacoras`.
    */
    'gracia_refresco' => (int) env('SESION_GRACIA_REFRESCO', 30),

    /*
    | Vida del token que emiten las rutas viejas (POST login/credentials).
    |
    | Esas rutas no devuelven refresco: un front que aún no conoce /api/auth/*
    | no sabría qué hacer con él. Para que su sesión dure lo mismo que hoy, su
    | token dura lo que duraba el JWT (JWT_TTL=1440, o sea 24 h).
    |
    | Esto se puede bajar el día que todos los colegios tengan el front nuevo.
    */
    'legado_ttl' => (int) env('SESION_LEGADO_TTL', 1440),

    /*
    | ¿Se siguen aceptando los JWT de tymon/jwt-auth?
    |
    | En el momento de desplegar la Fase 3 hay tokens JWT vivos en el navegador
    | de todo el mundo, con hasta 24 h por delante. Aceptarlos evita expulsar a
    | todos a la vez; ponerlo en false los mata en el acto.
    |
    | Es también el interruptor de emergencia: un JWT NO se puede revocar —esa
    | es justo la razón de la Fase 3—, así que si hubiera que invalidar tokens
    | viejos por seguridad, esto es lo único que lo hace.
    |
    | Se queda en false definitivamente cuando se quite tymon/jwt-auth.
    */
    'acepta_jwt' => filter_var(env('SESION_ACEPTA_JWT', true), FILTER_VALIDATE_BOOLEAN),

];
