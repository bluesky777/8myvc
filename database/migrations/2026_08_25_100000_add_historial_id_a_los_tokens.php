<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata el token al ingreso: `personal_access_tokens.historial_id`.
 *
 * Es la **fase 2** de [18-auditoria.md](../../docs/migracion/18-auditoria.md), y
 * la que le faltaba a la fase 3 para dejar de escribir NULL.
 *
 * ## Qué arregla, que no es lo que parece
 *
 * Hoy el token y la fila de `historiales` **se crean en el mismo login y luego no
 * vuelven a hablarse**, así que los nueve sitios que escriben `historial_id` lo
 * resuelven con `order by id desc limit 1` sobre `historiales`: **el último login
 * de esa persona, no la sesión que está haciendo el cambio**.
 *
 * Y no hace falta el caso raro de dos aparatos. El refresco vive **14 días y rota
 * en cada uso**, así que quien entra a diario **puede llevar meses sin teclear la
 * contraseña**: no hay login nuevo, `historiales` no crece, y **todas sus
 * escrituras de esos meses cuelgan del mismo ingreso de hace meses**. La pantalla
 * «qué hizo en este ingreso» enseña una lista falsa sin ningún error visible.
 *
 * ## Nullable, y es la decisión de la migración
 *
 * **Los tokens que ya existen no tienen ingreso y no se les puede inventar uno.**
 * Se quedan en NULL, que significa «no se sabe» — y `Auditoria` ya sabe qué hacer
 * con eso: escribe NULL y pone `atribucion = 'aproximada'` en vez de adivinar
 * (18 §5.2). **Un NULL dice «no se sabe»; la adivinanza dice «fue ése» y se
 * equivoca sin avisar.**
 *
 * En la práctica eso dura lo que dure el refresco más largo vivo —hasta 14 días—
 * y se cierra solo: cada login nuevo emite tokens que sí lo traen. Quien cierre
 * sesión y vuelva a entrar lo tiene al momento.
 *
 * ## La clave foránea es `ON DELETE SET NULL`, y la diferencia importa
 *
 * **`CASCADE` no**: `historiales` se puede podar —la retención es la fase 6— y con
 * `CASCADE` limpiar ingresos viejos sería **cerrar sesiones vivas**. Es la misma
 * razón por la que `auditoria` no tiene ninguna FK: la que `bitacoras` sí tiene a
 * `historiales` va con `CASCADE`, y por eso borrar un ingreso borra su rastro.
 *
 * **`SET NULL` sí**, y hace falta. Sin ella, podar un ingreso deja tokens vivos
 * apuntando a una fila que ya no existe, y el siguiente `INSERT INTO bitacoras`
 * de esa sesión **revienta contra la FK de `bitacoras`** — un 500 al profesor
 * guardando una nota, por una limpieza que hizo otro. Con `SET NULL` el token
 * simplemente **deja de saber de qué ingreso salió**, que es exactamente lo que ha
 * pasado, y todo lo de abajo ya sabe tratar ese NULL como «no se sabe».
 *
 * Lo destapó `GuardarNotasEnLoteTest`, que borra los historiales del usuario para
 * comprobar que el lote guarda igual. Yo había escrito aquí que una FK «no gana
 * nada que el nullable no dé ya»: **era falso, y lo era por mirar sólo `CASCADE`.**
 *
 * El índice lo crea la propia FK, y es el que contesta «qué tokens salieron de
 * este ingreso» — la pregunta que hace falta para cerrar una sesión entera desde
 * la pantalla de sesiones, que es fase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedInteger('historial_id')->nullable()->after('name');

            $table->foreign('historial_id', 'pat_historial')
                ->references('id')->on('historiales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign('pat_historial');
            $table->dropColumn('historial_id');
        });
    }
};
