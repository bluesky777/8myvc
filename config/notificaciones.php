<?php

/*
|--------------------------------------------------------------------------
| Notificaciones push
|--------------------------------------------------------------------------
|
| El plan entero, con el porqué de cada decisión, está en
| ~/DESARROLLOS/myvc_flutter/docs/notificaciones.md. Aquí sólo lo que cambia
| por colegio.
|
| **Ninguno de estos valores es obligatorio para desplegar.** Sin credenciales
| el comando `notificaciones:enviar` no manda nada y lo dice; sin secreto propio
| se deriva de `APP_KEY`. Es a propósito: `app/` es copia por colegio y son
| dieciséis `.env`, así que un despliegue que exigiera tocarlos los dieciséis se
| queda a medias en el primero que se olvide.
|
*/

return [

    /*
     * El secreto con el que se deriva el nombre del tema de cada alumno.
     *
     * **Por defecto es `APP_KEY`, y eso es una decisión, no una pereza.** Lo que
     * hace falta es un secreto *distinto en cada colegio* y *que no salga del
     * servidor*, y `APP_KEY` ya es las dos cosas: la genera `key:generate` por
     * instalación y vive sólo en el `.env`. Derivar con HMAC no la expone —del
     * tema no se vuelve a la clave— y evita tener que editar dieciséis `.env`
     * antes de que esto sirva de algo.
     *
     * Se puede poner uno propio con `NOTIFICACIONES_SECRETO` si algún día
     * conviene rotarlo sin rotar `APP_KEY`, que es justo el caso que separa las
     * dos cosas: **cambiar esto renombra todos los temas**, y los teléfonos ya
     * suscritos a los viejos dejan de recibir hasta que vuelvan a entrar y se
     * suscriban a los nuevos.
     */
    'secreto' => env('NOTIFICACIONES_SECRETO') ?: env('APP_KEY'),

    /*
     * La cuenta de servicio de Firebase: el id del proyecto y la ruta al JSON.
     *
     * El JSON **no va en el repositorio** — `app/` es copia por colegio y el
     * repositorio es común, así que meterlo dentro sería publicar la credencial
     * de push de los dieciséis. Va en `storage/`, que sí es propia de cada
     * colegio, o donde diga esta variable.
     */
    'fcm' => [
        'proyecto' => env('FCM_PROYECTO'),
        'credenciales' => env('FCM_CREDENCIALES', storage_path('app/firebase.json')),
    ],

    /*
     * Cada cuánto corre el comando, en minutos. Sólo se usa para explicarse en
     * la salida: quien lo decide de verdad es `app/Console/Kernel.php`.
     */
    'cada_minutos' => 15,

];
