<?php

return [

    /*
    |--------------------------------------------------------------------------
    | La clave privada con la que se firman las licencias del horario
    |--------------------------------------------------------------------------
    |
    | Esto es una RUTA A UN FICHERO, no la clave. Es deliberado, y el motivo es
    | la topología: `.env` es una copia real en cada uno de los diecisiete
    | despliegues, viaja en cada respaldo y se lee entero en cualquier volcado de
    | configuración. Una ruta permite que la clave **no esté** en los servidores,
    | que es hoy la respuesta recomendada — ver la §3 de
    | `docs/migracion/31-licencia-del-horario.md`.
    |
    | Y hay una razón que no es de higiene sino de aritmética: el binario de Rust
    | lleva incrustada UNA SOLA clave pública, así que en todo el sistema existe
    | EXACTAMENTE UNA clave privada. No es «una por colegio»: es una que, puesta
    | en los diecisiete `.env`, convierte a cualquiera de los diecisiete hostings
    | compartidos en el sitio desde donde se fabrican licencias para todos los
    | demás. Por eso lo normal es que esta variable esté SIN DEFINIR en
    | producción y sólo tenga valor en la máquina desde la que se emite.
    |
    | El fichero son los 64 bytes de la clave secreta de Ed25519 en crudo. Cómo
    | se genera y dónde debería vivir está en la §3 del documento; **la decisión
    | sigue siendo de Joseth y a 6 sep 2026 no está tomada**.
    |
    */

    'clave_privada' => env('LICENCIA_CLAVE_PRIVADA'),

];
