<?php

return [

    /*
    |--------------------------------------------------------------------------
    | El tope del fichero de proyecto, en BYTES
    |--------------------------------------------------------------------------
    |
    | Decisión 5 de la §10.2 de `docs/migracion/23-horarios.md`, contestada por
    | Joseth el 6 sep 2026: por encima de esto la subida devuelve 422 en vez de
    | dejar que la base guarde el fichero cortado.
    |
    | **16.777.215 es lo que cabe en un `MEDIUMTEXT`, y por eso es el valor de
    | serie**: quien manda aquí es la columna, no este fichero. Subirlo por encima
    | NO amplía nada — `HorarioController` lo recorta contra el tope de la columna
    | de todas formas—, porque un tope configurado por encima del real devolvería
    | exactamente el fallo que la decisión 5 vino a cerrar: MySQL truncando en
    | silencio con un `201` delante.
    |
    | **Está aquí y no clavado en el controlador por una razón medida**, y conviene
    | que se lea antes de «simplificarlo» otra vez a una constante: con el tope
    | clavado, los casos que lo ejercitan tienen que mandar **16 MB de verdad**, y
    | eso **tumbó la suite entera** el 6 sep 2026 —`Allowed memory size of
    | 268435456 bytes exhausted` dentro de `MySqlConnection`, con el `Tests:` sin
    | llegar a imprimirse y el código de salida en 0—. Bajándolo, esos casos
    | prueban **el mismo mecanismo** por unos pocos KB, y que el valor de serie sea
    | el de la columna lo fija su propio test.
    |
    */

    'maximo_del_proyecto' => 16777215,

];
