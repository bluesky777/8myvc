<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Las dos piezas que la pantalla nueva de informes necesita guardar por usuario:
 * **los informes que sacó hace poco** y **sus accesos favoritos del menú**.
 *
 * Encargo de Joseth (17 sep 2026) por la sesión de `myvc_front`, que está
 * rehaciendo `/informes` en `app2`. Hoy no hay dónde guardar ninguna de las dos:
 * de las 90 tablas, ninguna es un almacén de preferencias de usuario.
 *
 * ## DOS tablas y no una, aunque las dos sean «preferencias del usuario»
 *
 * Es la pregunta que llegó con el encargo. Son dos, y las razones son de
 * comportamiento y no de forma:
 *
 * - **Ciclos de vida opuestos**: `informes_recientes` es un log que se escribe
 *   solo y **se poda**; `accesos_favoritos` es una lista corta que el usuario
 *   ordena y borra **a mano**. Nada se poda en la segunda.
 * - **Frecuencias opuestas**: la primera se escribe en el camino caliente —cada
 *   carga de informe—, la segunda casi nunca.
 * - Y la que decide: juntarlas obliga a una columna `tipo`, y entonces **la poda
 *   del historial tiene que acordarse de no borrar favoritos**. Un invariante que
 *   vive en un `WHERE` que hay que recordar escribir es el que se rompe solo.
 *
 * ## `UNIQUE` de verdad, y no un `GROUP BY` en la lectura
 *
 * La deduplicación por «clave + parámetros» **es un índice único, no un
 * `GROUP BY`**. Sin él, dos pestañas cargando el mismo informe a la vez dejan dos
 * filas —comprobar-y-luego-insertar no es atómico— y la fila de «lo que sacaste
 * esta semana» enseña el mismo informe dos veces.
 *
 * No es una precaución teórica: **la mitad del [10](../../docs/migracion/10-definitivas.md)
 * de este repositorio existe porque `notas_finales` es una caché sin clave única**,
 * con seis escritores de «comprueba y luego inserta». Una tabla de caché nueva sin
 * índice único nace con ese mismo fallo dentro, y cuesta mil veces más quitarlo
 * después que ponerlo ahora.
 *
 * Como los parámetros son de longitud variable y no caben en un índice, lo que se
 * indexa es su **huella**: un `sha256` en hexadecimal de la tupla **normalizada y
 * ordenada**. Lo de «ordenada» no es un detalle — `{grupo:1,periodo:2}` y
 * `{periodo:2,grupo:1}` son el mismo informe, y sin normalizar producen dos
 * huellas y dos filas, o sea el duplicado que el índice venía a impedir.
 *
 * ## `year_id` en el historial, y NO en los favoritos
 *
 * En el historial va porque **repetir de un clic un informe del año pasado saca el
 * papel equivocado**, y es un clic que la pantalla ofrece precisamente para no
 * pensar. En los favoritos no va: una ruta del menú no es de un año.
 *
 * ## `parametros` es `text` y no `json`
 *
 * Producción corre **MariaDB 10.5** y el docker MySQL 8 (ver CLAUDE.md). El tipo
 * `json` no es el mismo en las dos —en MariaDB es un alias de `longtext` con un
 * `CHECK`— y aquí no se gana nada con el tipo nativo: **nadie consulta dentro**,
 * se lee entero y se decodifica en PHP. Un `text` se comporta igual en las dos y
 * no deja que una diferencia del motor se cuele en el esquema.
 *
 * ## La poda va en la escritura
 *
 * ~20 por usuario y año. En la escritura y no en un cron: es el único momento en
 * que se sabe que acaba de entrar uno, y un cron aquí sería un barrido sobre una
 * tabla que crece con 2.358 cuentas. El `GET` **además** limita, que no es
 * redundante — protege de una tabla que venga sucia de antes.
 *
 * ## Los favoritos guardan una CADENA de ruta, y eso tiene un filo
 *
 * El día que `app2` renombre una ruta, los favoritos de todo el mundo apuntan a
 * nada **y no se pone nada rojo**. Está asumido con `myvc_front`: el menú descarta
 * en silencio lo que no resuelva. La alternativa —un registro de pantallas con
 * identificadores estables— cuesta más que el fallo.
 *
 * ## Volver atrás
 *
 * Aditiva pura: dos tablas nuevas, ninguna referenciada por nada existente.
 * `down()` las tira y no se pierde ningún dato del colegio — sólo comodidades de
 * cada usuario, que se vuelven a formar solas al usar la pantalla.
 */
class InformesRecientesYFavoritos extends Migration
{
    public function up()
    {
        Schema::create('informes_recientes', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('user_id');
            $tabla->unsignedInteger('year_id');

            // Qué informe: la clave que usa el front para resolverlo, la etiqueta
            // que enseña y la ruta a la que vuelve. Se guardan las tres porque el
            // renglón tiene que poder pintarse **sin resolver nada**: es una fila
            // de «repetir esto», no una consulta.
            $tabla->string('clave', 64);
            $tabla->string('etiqueta');
            $tabla->string('ruta');

            $tabla->text('parametros')->nullable();

            // `char(64)` y no `string`: un sha256 en hexadecimal mide exactamente
            // eso, y fijarlo deja el índice de abajo del tamaño que se espera.
            $tabla->char('huella_parametros', 64);

            $tabla->timestamps();

            $tabla->unique(
                ['user_id', 'year_id', 'clave', 'huella_parametros'],
                'informes_recientes_unico'
            );

            // Para el «últimos N de este usuario», que es la única lectura.
            $tabla->index(['user_id', 'year_id', 'updated_at'], 'informes_recientes_ultimos');

            $tabla->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $tabla->foreign('year_id')->references('id')->on('years')->onDelete('cascade');
        });

        Schema::create('accesos_favoritos', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('user_id');

            $tabla->string('ruta');
            $tabla->string('etiqueta');
            $tabla->string('icono')->nullable();
            $tabla->unsignedSmallInteger('orden')->default(0);

            $tabla->timestamps();

            // La misma ruta dos veces en el menú de una persona no significa nada.
            $tabla->unique(['user_id', 'ruta'], 'accesos_favoritos_unico');

            $tabla->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('accesos_favoritos');
        Schema::dropIfExists('informes_recientes');
    }
}
