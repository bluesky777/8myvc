<?php

/*
|--------------------------------------------------------------------------
| La importación de alumnos, troceada por petición
|--------------------------------------------------------------------------
|
| Decisión de Joseth del 20 sep 2026: la importación tiene que ser rápida,
| fiable y sobrevivir a un corte. **La pieza que lo hace posible no es la
| velocidad: es que quepa en varias peticiones.**
|
| No hay cola en esta casa —`QUEUE_CONNECTION` es `sync` y no existe un solo
| `app/Jobs`— porque el hosting es cPanel compartido y ahí no hay demonio que
| la atienda. Así que una importación grande no puede irse a segundo plano: o
| cabe en la petición o se pierde. Lo que sí puede es **caber en varias**, y
| eso es lo que gobiernan estos dos valores.
|
| **Ninguno hace falta para desplegar**, por lo mismo que en
| `config/notificaciones.php`: son dieciséis `.env` y un despliegue que
| exigiera tocarlos todos se queda a medias en el primero que se olvide. Los
| valores por defecto son los que se midieron aquí.
|
*/

return [

    /*
    | Cuánto puede durar UNA petición de importación antes de guardar el punto
    | de control y devolver `terminado: false`.
    |
    | 20 s deja margen bajo cualquier tope razonable —`max_execution_time` está
    | en 300 s en cPanel, pero también hay proxys y navegadores por medio— y
    | hace que el peor caso de un corte sea perder 20 s de trabajo en vez de los
    | 300 que tardaba en morir.
    |
    | El colegio con un servidor más estrecho lo baja; el que tenga uno bueno
    | puede subirlo. **Bajarlo nunca rompe nada**: sólo significa más viajes.
    */
    'segundos_por_peticion' => (float) env('IMPORTACION_SEGUNDOS_POR_PETICION', 20),

    /*
    | Cuántas filas entran en una transacción, y por tanto cada cuánto se
    | escribe la marca de avance.
    |
    | **Es el grano del failover.** Con 25, un corte pierde como mucho 25 filas
    | de trabajo —que se rehacen solas al reanudar, porque el importador es
    | idempotente por documento— y la marca se escribe 25 veces menos: medido,
    | la marca era 1 de las 6,7 consultas por fila.
    |
    | Se queda corto a propósito. Rehacer 25 filas cuesta milisegundos; una
    | transacción larga sobre `alumnos` en una MariaDB compartida la pagan los
    | demás.
    */
    'filas_por_lote' => (int) env('IMPORTACION_FILAS_POR_LOTE', 25),

    /*
    | Y lo mismo para el ENSAYO, que es otra cosa y por eso tiene su propio valor.
    |
    | El ensayo no escribe, así que **no puede reanudarse: o cabe o se recorta**.
    | Lo que hace al agotarse es devolver un plan sobre las primeras N filas
    | marcado como incompleto, que es infinitamente mejor que el 500 por
    | `max_execution_time` que daba antes — y que además llegaba al navegador sin
    | cabeceras de CORS, así que la pantalla lo confundía con un fichero ilegible.
    |
    | Va aparte del de la importación porque los topes que los aprietan son
    | distintos: aquél se parte en varias peticiones y éste se conforma con una.
    */
    'segundos_del_ensayo' => (float) env('IMPORTACION_SEGUNDOS_DEL_ENSAYO', 20),

];
