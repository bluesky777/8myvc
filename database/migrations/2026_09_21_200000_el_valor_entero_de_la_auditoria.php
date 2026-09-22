<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * **Dos columnas enteras al lado del JSON, para que restaurar sea un `UPDATE` legible.**
 *
 * Encargo de Joseth del 21 sep 2026, decisión 7 de `docs/migracion/18-auditoria.md`.
 * `valor_anterior` y `valor_nuevo` siguen siendo la verdad y no se tocan: esto es una
 * copia del mismo dato cuando **cabe en un entero**, que es el caso de una nota, una
 * definitiva, una falta o un porcentaje.
 *
 * ## Por qué, y no es la velocidad
 *
 * A esta escala leer JSON de veinte mil filas no se nota. Lo que decide es el motor:
 * **en MariaDB 10.5 `json` no es un tipo, es `LONGTEXT` con un `CHECK`**. Un script de
 * restauración escrito con `->>` o `JSON_EXTRACT` pasa la suite entera en el docker con
 * MySQL 8 y se comporta distinto en los dieciséis — es el aviso que ya está en el
 * `CLAUDE.md`, escrito con este mismo ejemplo. Contra una columna `int` el
 * `UPDATE ... JOIN` es idéntico en los dos motores y lo lee una persona a las tres de
 * la mañana, que es cuando se usa.
 *
 * ## `int`, y no `smallint` ni «tres dígitos»
 *
 * La regla es que **la celda espejo tiene el mismo tipo que la columna que espeja**, y
 * `notas.nota` es `int`. Ahorrar dos bytes en una tabla que ya lleva dos columnas `json`
 * es economía falsa, y abre el fallo que este repo ya tiene medido: el docker trunca en
 * silencio y MariaDB aborta. Un valor que no quepa en `int` lo deja el servicio en
 * `NULL` **a propósito**, y el JSON conserva la verdad.
 *
 * ## Sin índice
 *
 * Se restaura por `entidad` + `entidad_id`, que ya es el índice `aud_entidad`. Un índice
 * aquí se mantendría en cada `INSERT` de una tabla que crece con cada acto, para una
 * consulta que nadie hace. Antes de crear ninguno, `EXPLAIN`.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('auditoria', function (Blueprint $tabla) {
            // Nullable sin default: `NULL` significa «este cambio no era un número»,
            // que es distinto de «valía cero». Un default de 0 borraría esa diferencia
            // justo en la columna que existe para restaurar.
            $tabla->integer('valor_anterior_num')->nullable()->after('valor_nuevo');
            $tabla->integer('valor_nuevo_num')->nullable()->after('valor_anterior_num');
        });
    }

    public function down()
    {
        Schema::table('auditoria', function (Blueprint $tabla) {
            $tabla->dropColumn(['valor_anterior_num', 'valor_nuevo_num']);
        });
    }
};
