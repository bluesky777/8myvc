<?php

/*
|--------------------------------------------------------------------------
| El portal de la Unión — adónde manda este colegio su resumen de la noche
|--------------------------------------------------------------------------
|
| Diseño completo: `myvc_ucn/docs/01-diseno-tecnico.md` §2 a §4.
|
| **Sin las dos no es un error**: `portal:enviar` lo dice y sale con 0 (§3.0).
| Es el estado normal de un colegio hasta que se le reparta su clave, y un
| código de error aquí llenaría el registro del cron de un fallo que no lo es.
|
| `PORTAL_CLAVE` es propia y dedicada, NO derivada de `APP_KEY` (§3.1): nadie
| ha comparado las diecisiete `APP_KEY` y un colegio nuevo se crea copiando
| otro, así que dos podrían compartirla y firmar el uno como el otro.
|
*/

return [

    // Sin barra final: se le pega `/api/envios`. Una barra de más no rompe
    // nada porque se recorta al usarla.
    'url' => env('PORTAL_URL'),

    // 32 bytes aleatorios, tal cual los entrega el portal al dar de alta.
    'clave' => env('PORTAL_CLAVE'),

    // Segundos entre intentos cuando el POST falla por red o por un 5xx: tres
    // intentos, esperas crecientes y tope de un par de minutos en total
    // (§3.2). Repetir es gratis —el receptor reemplaza la foto de la misma
    // noche—, así que no hace falta más. Los tests lo ponen a cero.
    'esperas' => [15, 60],

    // Segundos que espera cada petición. El portal contesta en milisegundos;
    // esto sólo existe para que una red colgada no deje el cron vivo.
    'timeout' => 30,

];
